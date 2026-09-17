<?php
/**
 * Tests for converting Slack message text into entry content.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Slack_Content_Processor;

/**
 * Slack message text is written by anyone in a linked channel and ends up as
 * published post content, so the conversion has to leave nothing executable
 * and nothing that can break out of the paragraph block.
 */
class Test_Slack_Content_Processor extends Rolling_Coverage_TestCase {

	/**
	 * Slack markup and the plain text it should resolve to.
	 *
	 * @return array[]
	 */
	public function slack_markup_provider() {
		return [
			'user mention with a display name'    => [ 'Thanks <@U123ABC|alice>!', 'Thanks @alice!' ],
			'user mention without a display name' => [ 'Thanks <@U123ABC>!', 'Thanks @U123ABC!' ],
			'channel mention with a name'         => [ 'See <#C123ABC|newsroom>', 'See #newsroom' ],
			'channel mention without a name'      => [ 'See <#C123ABC>', 'See #C123ABC' ],
			'link with a label'                   => [ 'Read <https://example.test/story|the story>', 'Read the story' ],
			'bare link'                           => [ 'Read <https://example.test/story>', 'Read https://example.test/story' ],
			'mailto link'                         => [ 'Write to <mailto:tips@example.test|the tips desk>', 'Write to tips@example.test' ],
			'broadcast mention'                   => [ '<!here> polls are closed', '@here polls are closed' ],
			'several kinds in one message'        => [ '<@U1|bo> filed <https://example.test/a|a story> in <#C9|metro>', '@bo filed a story in #metro' ],
		];
	}

	/**
	 * Slack's angle-bracket markup resolves to readable text.
	 *
	 * @dataProvider slack_markup_provider
	 *
	 * @param string $slack_text Raw Slack message text.
	 * @param string $expected   Expected plain text.
	 */
	public function test_resolves_slack_markup_to_readable_text( $slack_text, $expected ) {
		$processor = new Slack_Content_Processor();

		$this->assertSame( $expected, $processor->to_plain_text( $slack_text ) );
	}

	/**
	 * Entry content is a single paragraph block holding the message text.
	 */
	public function test_wraps_the_message_in_a_single_paragraph_block() {
		$processor = new Slack_Content_Processor();

		$blocks = parse_blocks( $processor->process( 'Polls close at 8pm.' ) );

		$this->assertCount( 1, $blocks, 'A message should produce exactly one block.' );
		$this->assertSame( 'core/paragraph', $blocks[0]['blockName'], 'The block should be a paragraph.' );
		$this->assertSame( '<p>Polls close at 8pm.</p>', trim( $blocks[0]['innerHTML'] ), 'The paragraph should hold the message text.' );
	}

	/**
	 * HTML in a message never reaches the entry content.
	 */
	public function test_strips_html_from_the_message() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process( 'Results <script>alert( 1 )</script>are <b onmouseover="x()">in</b>' );

		$this->assertStringNotContainsString( '<script', $content, 'Script tags should be removed.' );
		$this->assertStringNotContainsString( 'alert', $content, 'Script contents should be removed along with the tag.' );
		$this->assertStringNotContainsString( 'onmouseover', $content, 'Attributes should not survive.' );
		$this->assertStringContainsString( '<p>Results are in</p>', $content, 'The text around the markup should be kept.' );
	}

	/**
	 * Angle-bracket markup that is not a supported Slack token is dropped.
	 *
	 * Only http(s) and mailto links are resolved, so a `javascript:` link is
	 * left as a tag and removed rather than rendered.
	 */
	public function test_drops_links_with_an_unsupported_scheme() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process( 'Click <javascript:alert(1)|here> now' );

		$this->assertStringNotContainsString( 'javascript', $content, 'The unsupported link should not be rendered.' );
	}

	/**
	 * Block delimiters typed into a message cannot add blocks to the entry.
	 */
	public function test_block_delimiters_in_a_message_do_not_create_blocks() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process( 'Update --> <!-- /wp:paragraph --><!-- wp:html --><iframe src="https://example.test"></iframe><!-- /wp:html -->' );

		$block_names = wp_list_pluck( parse_blocks( $content ), 'blockName' );

		$this->assertSame( [ 'core/paragraph' ], $block_names, 'The message should still produce a single paragraph block.' );
		$this->assertStringNotContainsString( '<iframe', $content, 'Embedded markup should be removed.' );
	}

	/**
	 * The text used for the entry title has markup resolved and tags removed,
	 * but is not HTML-escaped, since `wp_insert_post()` handles the title.
	 */
	public function test_title_text_is_tag_free_and_not_html_escaped() {
		$processor = new Slack_Content_Processor();

		$title_text = $processor->to_plain_text_sanitized( '<@U1|bo>: "Turnout > 60%" <i>so far</i>' );

		$this->assertSame( '@bo: "Turnout > 60%" so far', $title_text );
	}
}
