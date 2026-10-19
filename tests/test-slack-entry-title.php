<?php
/**
 * Tests for Slack entry titles in layouts that show no titles.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * A Slack entry's title is the first line of the reporter's message, so a
 * layout without titles shows it rather than dropping the line. A title an
 * editor wrote stays hidden there, as those layouts intend.
 */
class Test_Slack_Entry_Title extends Rolling_Coverage_TestCase {

	/**
	 * A Slack entry whose title came from its message, unless told otherwise.
	 *
	 * @param string $title        Entry title.
	 * @param string $content      Entry content.
	 * @param bool   $is_slack     Whether the entry came from Slack.
	 * @param bool   $from_message Whether its title came from the message.
	 * @param array  $args         More post fields.
	 * @return WP_Post
	 */
	private static function entry( string $title, string $content, bool $is_slack = true, bool $from_message = true, array $args = [] ): WP_Post {
		$entry_id = self::create_entry(
			self::create_coverage(),
			array_merge(
				[
					'post_title'   => $title,
					'post_content' => $content,
					'post_excerpt' => '',
				],
				$args
			)
		);

		if ( $is_slack ) {
			update_post_meta( $entry_id, Post_Type::META_ENTRY_SOURCE, 'slack' );
		}

		if ( $is_slack && $from_message ) {
			update_post_meta( $entry_id, Post_Type::META_TITLE_FROM_SOURCE, 1 );
		}

		return get_post( $entry_id );
	}

	/**
	 * In a layout whose entries show only their content, as Stream and
	 * Minute do, a Slack entry's title heads its content.
	 */
	public function test_slack_title_heads_the_content_where_no_title_shows() {
		$html = Rolling_Coverage_Block::render_entry( self::entry( 'Polls closed', '<!-- wp:paragraph --><p>Ward 1 is counted.</p><!-- /wp:paragraph -->' ), parse_blocks( '<!-- wp:post-content /-->' ) );

		$this->assertMatchesRegularExpression( '#<h4 class="wp-block-heading">Polls closed</h4>.*Ward 1 is counted\.#s', $html );
	}

	/**
	 * A layout that shows titles shows a Slack entry's title once, in its
	 * own title block.
	 */
	public function test_slack_title_shows_once_where_the_layout_has_a_title() {
		$html = Rolling_Coverage_Block::render_entry( self::entry( 'Polls closed', '<!-- wp:paragraph --><p>Ward 1 is counted.</p><!-- /wp:paragraph -->' ), parse_blocks( '<!-- wp:post-title /--><!-- wp:post-content /-->' ) );

		$this->assertSame( 1, substr_count( $html, 'Polls closed' ) );
	}

	/**
	 * A title an editor wrote stays hidden in a layout without titles.
	 */
	public function test_editor_title_stays_hidden_where_no_title_shows() {
		$html = Rolling_Coverage_Block::render_entry( self::entry( 'Editor headline', '<!-- wp:paragraph --><p>Ward 1 is counted.</p><!-- /wp:paragraph -->', false ), parse_blocks( '<!-- wp:post-content /-->' ) );

		$this->assertStringNotContainsString( 'Editor headline', $html );
	}

	/**
	 * In a layout that shows an excerpt and no title, as Flash does, a Slack
	 * entry with no content shows its title as the excerpt. With a title
	 * block too, the excerpt stays empty, so the title isn't shown twice.
	 */
	public function test_title_only_slack_entry_uses_its_title_as_the_excerpt_where_no_title_shows() {
		$entry = self::entry( 'Polls closed', '' );

		$this->assertStringContainsString( 'Polls closed', Rolling_Coverage_Block::render_entry( $entry, parse_blocks( '<!-- wp:post-date /--><!-- wp:post-excerpt /-->' ) ) );
		$this->assertSame( 1, substr_count( Rolling_Coverage_Block::render_entry( $entry, parse_blocks( '<!-- wp:post-title /--><!-- wp:post-excerpt /-->' ) ), 'Polls closed' ) );
	}

	/**
	 * The title is shown as text: encoded characters stay encoded and a
	 * shortcode in it doesn't run.
	 */
	public function test_slack_title_is_shown_as_text() {
		add_shortcode( 'rcprobe', fn() => 'SHORTCODE_RAN' );

		$html = do_shortcode( Rolling_Coverage_Block::render_entry( self::entry( 'Results &lt;live&gt; &#91;rcprobe&#93;', '' ), parse_blocks( '<!-- wp:post-content /-->' ) ) );

		$this->assertStringContainsString( 'Results &lt;live&gt;', $html );
		$this->assertStringNotContainsString( 'SHORTCODE_RAN', $html );

		remove_shortcode( 'rcprobe' );
	}

	/**
	 * A title an editor gave a Slack entry stays hidden in a layout without
	 * titles, as it did before titles came from messages.
	 */
	public function test_editor_title_on_a_slack_entry_stays_hidden_where_no_title_shows() {
		$html = Rolling_Coverage_Block::render_entry( self::entry( 'Editor headline', '<!-- wp:paragraph --><p>Ward 1 is counted.</p><!-- /wp:paragraph -->', true, false ), parse_blocks( '<!-- wp:post-content /-->' ) );

		$this->assertStringNotContainsString( 'Editor headline', $html );
	}

	/**
	 * An untitled password-protected entry gets no heading, though core
	 * gives it the title "Protected: ".
	 */
	public function test_untitled_protected_entry_gets_no_heading() {
		$html = Rolling_Coverage_Block::render_entry( self::entry( '', '<!-- wp:paragraph --><p>Ward 1 is counted.</p><!-- /wp:paragraph -->', true, true, [ 'post_password' => 'secret' ] ), parse_blocks( '<!-- wp:post-content /-->' ) );

		$this->assertStringNotContainsString( 'wp-block-heading', $html );
	}

	/**
	 * A titled Slack entry holding only a photo shows its title as the
	 * excerpt, not the photo's name.
	 */
	public function test_titled_slack_photo_entry_uses_its_title_as_the_excerpt_where_no_title_shows() {
		$photo = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/fire.jpg" alt=""/></figure><!-- /wp:image -->';
		$html  = Rolling_Coverage_Block::render_entry( self::entry( 'Fire on Main St', $photo ), parse_blocks( '<!-- wp:post-date /--><!-- wp:post-excerpt /-->' ) );

		$this->assertStringContainsString( 'Fire on Main St', $html );
	}

	/**
	 * When something empties an entry's excerpt, as a content gate with no
	 * free preview does, the title stands in, though the entry has words.
	 */
	public function test_slack_title_stands_in_for_an_emptied_excerpt_where_no_title_shows() {
		add_filter( 'get_the_excerpt', '__return_empty_string', 11 );

		$html = Rolling_Coverage_Block::render_entry( self::entry( 'Polls closed', '<!-- wp:paragraph --><p>Ward 1 is counted.</p><!-- /wp:paragraph -->' ), parse_blocks( '<!-- wp:post-date /--><!-- wp:post-excerpt /-->' ) );

		$this->assertStringContainsString( 'Polls closed', $html );
	}

	/**
	 * A feed placed in the entry's content doesn't show the entry's title
	 * above its own entries.
	 */
	public function test_feed_nested_in_the_entry_does_not_get_its_title() {
		$this->register_feed_block();
		$inner_coverage = self::create_coverage();
		self::create_entry(
			$inner_coverage,
			[
				'post_title'   => '',
				'post_content' => '<!-- wp:paragraph --><p>Inner text.</p><!-- /wp:paragraph -->',
			]
		);
		$outer = self::entry(
			'Outer title',
			'<!-- wp:paragraph --><p>Outer text.</p><!-- /wp:paragraph --><!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $inner_coverage . '} --><!-- wp:post-content /--><!-- /wp:newspack-rolling-coverage/rolling-coverage -->'
		);

		$html = Rolling_Coverage_Block::render_entry( $outer, parse_blocks( '<!-- wp:post-content /-->' ) );

		$this->assertStringContainsString( 'Inner text.', $html, 'The nested feed should render.' );
		$this->assertSame( 1, substr_count( $html, 'Outer title' ) );
	}
}
