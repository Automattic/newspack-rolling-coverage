<?php
/**
 * Tests for converting Slack messages into entry content.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Slack_Content_Processor;

/**
 * Slack messages are written by anyone in a linked channel and end up as
 * published post content, so the conversion keeps the author's formatting
 * while leaving nothing executable and nothing that can add blocks of its own.
 */
class Test_Slack_Content_Processor extends Rolling_Coverage_TestCase {

	/**
	 * Wrap rich-text elements in a message's `blocks`.
	 *
	 * @param array ...$elements Top-level rich-text elements.
	 * @return array[]
	 */
	private static function rich_text( ...$elements ) {
		return [
			[
				'type'     => 'rich_text',
				'block_id' => 'b1',
				'elements' => $elements,
			],
		];
	}

	/**
	 * A rich-text section holding the given inline elements.
	 *
	 * @param array ...$elements Inline elements.
	 * @return array
	 */
	private static function section( ...$elements ) {
		return [
			'type'     => 'rich_text_section',
			'elements' => $elements,
		];
	}

	/**
	 * A text element.
	 *
	 * @param string $text  Text.
	 * @param array  $style Style flags.
	 * @return array
	 */
	private static function text( $text, $style = [] ) {
		$element = [
			'type' => 'text',
			'text' => $text,
		];

		if ( $style ) {
			$element['style'] = $style;
		}

		return $element;
	}

	/**
	 * A list element with one item per string.
	 *
	 * @param string   $style  'bullet' or 'ordered'.
	 * @param int      $indent Indent level.
	 * @param string[] $items  Item text.
	 * @param int      $offset Items numbered before this list.
	 * @return array
	 */
	private static function list_of( $style, $indent, $items, $offset = 0 ) {
		return [
			'type'     => 'rich_text_list',
			'style'    => $style,
			'indent'   => $indent,
			'offset'   => $offset,
			'elements' => array_map( fn( $item ) => self::section( self::text( $item ) ), $items ),
		];
	}

	/**
	 * Names of the top-level blocks in some block markup.
	 *
	 * @param string $markup Block markup.
	 * @return string[]
	 */
	private static function block_names( $markup ) {
		return array_values( array_filter( wp_list_pluck( parse_blocks( $markup ), 'blockName' ) ) );
	}

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
	 * Blank lines separate paragraphs and single newlines are line breaks.
	 */
	public function test_keeps_paragraphs_and_line_breaks() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process( '', self::rich_text( self::section( self::text( "Polls have closed.\n\nCounting starts at 9pm\nin the town hall." ) ) ) );
		$blocks  = parse_blocks( $content );

		$this->assertSame( [ 'core/paragraph', 'core/paragraph' ], self::block_names( $content ), 'A blank line should start a new paragraph.' );
		$this->assertSame( '<p>Polls have closed.</p>', trim( $blocks[0]['innerHTML'] ) );
		$this->assertSame( '<p>Counting starts at 9pm<br>in the town hall.</p>', trim( $blocks[2]['innerHTML'] ), 'A single newline should be a line break.' );
	}

	/**
	 * Bold, italic, strikethrough and inline code are kept, including when
	 * combined on one piece of text.
	 */
	public function test_keeps_inline_styles() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process(
			'',
			self::rich_text(
				self::section(
					self::text( 'Turnout ' ),
					self::text( 'record', [ 'bold' => true ] ),
					self::text( ', ' ),
					self::text( 'provisional', [ 'italic' => true ] ),
					self::text( ', ' ),
					self::text( '58%', [ 'strike' => true ] ),
					self::text( ' ' ),
					self::text( 'TURNOUT_FINAL', [ 'code' => true ] ),
					self::text( ' ' ),
					self::text(
						'confirmed',
						[
							'bold'   => true,
							'italic' => true,
						] 
					)
				)
			)
		);

		$this->assertStringContainsString(
			'<p>Turnout <strong>record</strong>, <em>provisional</em>, <s>58%</s> <code>TURNOUT_FINAL</code> <strong><em>confirmed</em></strong></p>',
			$content
		);
	}

	/**
	 * Links keep their URL, with the label as the link text.
	 */
	public function test_keeps_links() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process(
			'',
			self::rich_text(
				self::section(
					self::text( 'Results are ' ),
					[
						'type' => 'link',
						'url'  => 'https://example.test/results',
						'text' => 'here',
					],
					self::text( ', or ' ),
					[
						'type' => 'link',
						'url'  => 'https://example.test/live',
					],
					self::text( ', or ' ),
					[
						'type' => 'link',
						'url'  => 'mailto:tips@example.test',
					]
				)
			)
		);

		$this->assertStringContainsString( '<a href="https://example.test/results">here</a>', $content, 'A labelled link should keep its URL.' );
		$this->assertStringContainsString( '<a href="https://example.test/live">https://example.test/live</a>', $content, 'A bare link should show its URL.' );
		$this->assertStringContainsString( '<a href="mailto:tips@example.test">tips@example.test</a>', $content, 'A mailto link should show the address.' );
	}

	/**
	 * A link with an unsafe scheme keeps its text but is not rendered as a
	 * link.
	 */
	public function test_drops_links_with_an_unsupported_scheme() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process(
			'',
			self::rich_text(
				self::section(
					[
						'type' => 'link',
						'url'  => 'javascript:alert(1)',
						'text' => 'here',
					]
				)
			)
		);

		$this->assertStringNotContainsString( 'javascript', $content, 'The unsafe URL should not be rendered.' );
		$this->assertStringContainsString( '<p>here</p>', $content, 'The link text should be kept.' );
	}

	/**
	 * Bulleted and numbered lists become list blocks, nesting included, and
	 * a numbered list that continues after other content keeps its numbering.
	 */
	public function test_keeps_lists() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process(
			'',
			self::rich_text(
				self::list_of( 'bullet', 0, [ 'Ward 1', 'Ward 2' ] ),
				self::list_of( 'bullet', 1, [ 'Precinct A' ] ),
				self::list_of( 'bullet', 0, [ 'Ward 3' ] ),
				self::section( self::text( 'Next steps:' ) ),
				self::list_of( 'ordered', 0, [ 'Count' ], 0 ),
				self::section( self::text( 'Then:' ) ),
				self::list_of( 'ordered', 0, [ 'Certify' ], 1 )
			)
		);
		$blocks  = array_values( array_filter( parse_blocks( $content ), fn( $block ) => null !== $block['blockName'] ) );

		$this->assertSame( [ 'core/list', 'core/paragraph', 'core/list', 'core/paragraph', 'core/list' ], wp_list_pluck( $blocks, 'blockName' ) );

		$bullets = $blocks[0];
		$this->assertCount( 3, $bullets['innerBlocks'], 'The nested list should not split the outer one.' );
		$this->assertSame( 'core/list', $bullets['innerBlocks'][1]['innerBlocks'][0]['blockName'] ?? null, 'Precinct A should be nested under Ward 2.' );
		$this->assertStringContainsString( '<li>Precinct A</li>', render_block( $bullets['innerBlocks'][1] ) );

		$this->assertTrue( $blocks[2]['attrs']['ordered'] ?? false, 'A numbered list should be ordered.' );
		$this->assertArrayNotHasKey( 'start', $blocks[2]['attrs'], 'A list starting at 1 needs no start.' );
		$this->assertSame( 2, $blocks[4]['attrs']['start'] ?? null, 'A continued list should keep counting.' );
		$this->assertStringContainsString( '<ol start="2" class="wp-block-list">', $blocks[4]['innerHTML'] );
	}

	/**
	 * Quotes become quote blocks holding paragraphs.
	 */
	public function test_keeps_quotes() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process(
			'',
			self::rich_text(
				[
					'type'     => 'rich_text_quote',
					'elements' => [ self::text( 'We are confident.', [ 'italic' => true ] ) ],
				]
			)
		);
		$blocks  = parse_blocks( $content );

		$this->assertSame( [ 'core/quote' ], self::block_names( $content ) );
		$this->assertSame( 'core/paragraph', $blocks[0]['innerBlocks'][0]['blockName'] );
		$this->assertStringContainsString( '<p><em>We are confident.</em></p>', $content );
	}

	/**
	 * Code blocks become code blocks with their line breaks and no styling.
	 */
	public function test_keeps_code_blocks() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process(
			'',
			self::rich_text(
				[
					'type'     => 'rich_text_preformatted',
					'elements' => [ self::text( "Ward 1: 1,204\nWard 2: <980>", [ 'bold' => true ] ) ],
				]
			)
		);

		$this->assertSame( [ 'core/code' ], self::block_names( $content ) );
		$this->assertStringContainsString( "<pre class=\"wp-block-code\"><code>Ward 1: 1,204\nWard 2: &lt;980&gt;</code></pre>", $content );
	}

	/**
	 * Emoji become their characters; custom emoji, which have none, keep
	 * their name.
	 */
	public function test_renders_emoji() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process(
			'',
			self::rich_text(
				self::section(
					[
						'type'    => 'emoji',
						'name'    => 'ballot_box_with_ballot',
						'unicode' => '1f5f3-fe0f',
					],
					self::text( ' ' ),
					[
						'type' => 'emoji',
						'name' => 'newsroom-parrot',
					]
				)
			)
		);

		$this->assertStringContainsString( "<p>\u{1F5F3}\u{FE0F} :newsroom-parrot:</p>", $content );
	}

	/**
	 * Mentions show the person's name, falling back to the Slack ID when it
	 * cannot be found, and each person is looked up once.
	 */
	public function test_resolves_mentions_to_names() {
		$lookups   = [];
		$processor = new Slack_Content_Processor(
			function ( $user_id ) use ( &$lookups ) {
				$lookups[] = $user_id;
				return 'U0ALICE' === $user_id ? 'Alice' : '';
			}
		);

		$content = $processor->process(
			'',
			self::rich_text(
				self::section(
					[
						'type'    => 'user',
						'user_id' => 'U0ALICE',
					],
					self::text( ' and ' ),
					[
						'type'    => 'user',
						'user_id' => 'U0GONE',
					],
					self::text( ' thank ' ),
					[
						'type'    => 'user',
						'user_id' => 'U0ALICE',
					]
				)
			)
		);

		$this->assertStringContainsString( '<p>@Alice and @U0GONE thank @Alice</p>', $content );
		$this->assertSame( [ 'U0ALICE', 'U0GONE' ], $lookups, 'Each person should be looked up once.' );
	}

	/**
	 * Name lookups are capped, since each can be an API call inside the
	 * webhook request.
	 */
	public function test_caps_mention_lookups() {
		$lookups   = 0;
		$processor = new Slack_Content_Processor(
			function () use ( &$lookups ) {
				++$lookups;
				return 'Someone';
			}
		);

		$mentions = array_map(
			fn( $n ) => [
				'type'    => 'user',
				'user_id' => 'U0USER' . $n,
			],
			range( 1, Slack_Content_Processor::MAX_MENTION_LOOKUPS + 2 )
		);

		$content = $processor->process( '', self::rich_text( self::section( ...$mentions ) ) );

		$this->assertSame( Slack_Content_Processor::MAX_MENTION_LOOKUPS, $lookups );
		$this->assertStringContainsString( '@U0USER' . ( Slack_Content_Processor::MAX_MENTION_LOOKUPS + 1 ), $content, 'Mentions past the cap should show the Slack ID.' );
	}

	/**
	 * HTML and block delimiters typed into a message are text, never markup.
	 */
	public function test_message_text_cannot_add_markup_or_blocks() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process(
			'',
			self::rich_text(
				self::section(
					self::text( 'Update --> <!-- /wp:paragraph --><!-- wp:html --><iframe src="https://example.test"></iframe><!-- /wp:html --> <script>alert(1)</script>', [ 'bold' => true ] )
				)
			)
		);

		$this->assertSame( [ 'core/paragraph' ], self::block_names( $content ), 'The message should still produce a single paragraph block.' );
		$this->assertStringNotContainsString( '<iframe', $content, 'Embedded markup should be escaped.' );
		$this->assertStringNotContainsString( '<script', $content, 'Script tags should be escaped.' );
	}

	/**
	 * Without rich-text blocks, the mrkdwn text is converted instead, keeping
	 * links, inline styles, paragraphs and line breaks.
	 */
	public function test_falls_back_to_mrkdwn_without_rich_text() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process( "*Polls closed* at 8pm, _turnout_ ~58%~ `62%`.\nResults <https://example.test/results|here> &amp; more.\n\nThanks <@U1|bo>!" );
		$blocks  = parse_blocks( $content );

		$this->assertSame( [ 'core/paragraph', 'core/paragraph' ], self::block_names( $content ) );
		$this->assertSame(
			'<p><strong>Polls closed</strong> at 8pm, <em>turnout</em> <s>58%</s> <code>62%</code>.<br>Results <a href="https://example.test/results">here</a> &amp; more.</p>',
			trim( $blocks[0]['innerHTML'] )
		);
		$this->assertSame( '<p>Thanks @bo!</p>', trim( $blocks[2]['innerHTML'] ) );
	}

	/**
	 * The mrkdwn fallback removes HTML and unsafe links, and cannot add
	 * blocks.
	 */
	public function test_mrkdwn_fallback_strips_markup() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process( 'Results <script>alert( 1 )</script>are <b onmouseover="x()">in</b> <javascript:alert(1)|here> --> <!-- /wp:paragraph --><!-- wp:html --><iframe></iframe>' );

		$this->assertSame( [ 'core/paragraph' ], self::block_names( $content ), 'The message should still produce a single paragraph block.' );
		$this->assertStringNotContainsString( '<script', $content, 'Script tags should be removed.' );
		$this->assertStringNotContainsString( 'alert', $content, 'Script contents and unsafe links should be removed.' );
		$this->assertStringNotContainsString( 'onmouseover', $content, 'Attributes should not survive.' );
		$this->assertStringNotContainsString( '<iframe', $content, 'Embedded markup should be removed.' );
		$this->assertStringContainsString( '<p>Results are in', $content, 'The text around the markup should be kept.' );
	}

	/**
	 * A message with rich-text blocks but no content, and no text, produces
	 * nothing, so the ingestion service skips it.
	 */
	public function test_empty_message_produces_no_content() {
		$processor = new Slack_Content_Processor();

		$this->assertSame( '', $processor->process( '', self::rich_text( self::section( self::text( "  \n " ) ) ) ) );
	}

	/**
	 * Channel and user group mentions show the names Slack gives them in the
	 * mrkdwn text, since rich text carries only their IDs.
	 */
	public function test_channel_and_group_mentions_use_their_names() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process(
			'Ask <#C0METRO|metro> or <!subteam^S0DESK|@photo-desk>, not <#C0GONE>',
			self::rich_text(
				self::section(
					self::text( 'Ask ' ),
					[
						'type'       => 'channel',
						'channel_id' => 'C0METRO',
					],
					self::text( ' or ' ),
					[
						'type'         => 'usergroup',
						'usergroup_id' => 'S0DESK',
					],
					self::text( ', not ' ),
					[
						'type'       => 'channel',
						'channel_id' => 'C0GONE',
					]
				)
			)
		);

		$this->assertStringContainsString( '<p>Ask #metro or @photo-desk, not #C0GONE</p>', $content );
	}

	/**
	 * Shortcodes typed in Slack stay text, in paragraphs and code blocks.
	 */
	public function test_shortcodes_are_not_run() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process(
			'',
			self::rich_text(
				self::section( self::text( 'See [gallery ids="1"]' ) ),
				[
					'type'     => 'rich_text_preformatted',
					'elements' => [ self::text( '[embed]https://example.test[/embed]' ) ],
				]
			)
		);

		$this->assertSame( $content, do_shortcode( $content ), 'No shortcode should run.' );
		$this->assertStringContainsString( '<p>See &#91;gallery ids=&quot;1&quot;&#93;</p>', $content );
		$this->assertStringContainsString( 'See [gallery ids="1"]', wp_specialchars_decode( html_entity_decode( $content ) ), 'The text should read as typed.' );
	}

	/**
	 * A protocol-relative link has no scheme to check, so it is not linked.
	 */
	public function test_drops_protocol_relative_links() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process(
			'',
			self::rich_text(
				self::section(
					[
						'type' => 'link',
						'url'  => '//example.test/login',
						'text' => 'log in',
					]
				)
			)
		);

		$this->assertStringContainsString( '<p>log in</p>', $content );
		$this->assertStringNotContainsString( '<a ', $content );
	}

	/**
	 * In the mrkdwn fallback, markers inside inline code are left alone, and
	 * styles never overlap code, so the tags always nest.
	 */
	public function test_mrkdwn_fallback_keeps_code_intact() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process( 'Run `snake_case_name` then *a `b* c`' );

		$this->assertStringContainsString( '<code>snake_case_name</code>', $content, 'Underscores in code should not become italics.' );
		$this->assertStringContainsString( '<code>b* c</code>', $content, 'Code should keep its markers.' );
		$this->assertStringNotContainsString( '<strong>', $content, 'A bold marker pair split by code should not produce bold.' );
	}

	/**
	 * Headings in some block markup, as their level and inner HTML, with
	 * the level read from the block's attributes the way the editor does.
	 *
	 * @param string $markup Block markup.
	 * @return array[] One `[ level, html ]` pair per heading block.
	 */
	private static function headings( $markup ) {
		$headings = array_filter( parse_blocks( $markup ), fn( $block ) => 'core/heading' === $block['blockName'] );

		return array_values(
			array_map(
				fn( $block ) => [ $block['attrs']['level'] ?? 2, trim( $block['innerHTML'] ) ],
				$headings
			)
		);
	}

	/**
	 * Lines starting with #, ## or ### and a space become headings, one
	 * level apart, starting at the level the processor is given.
	 */
	public function test_turns_marked_lines_into_headings() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process( '', self::rich_text( self::section( self::text( "Polls have closed.\n# Results\n## Ward 1\n### Precinct A" ) ) ) );

		$this->assertSame( [ 'core/paragraph', 'core/heading', 'core/heading', 'core/heading' ], self::block_names( $content ) );
		$this->assertSame(
			[
				[ 2, '<h2 class="wp-block-heading">Results</h2>' ],
				[ 3, '<h3 class="wp-block-heading">Ward 1</h3>' ],
				[ 4, '<h4 class="wp-block-heading">Precinct A</h4>' ],
			],
			self::headings( $content )
		);
		$this->assertStringContainsString( "<!-- wp:heading -->\n<h2", $content, 'Level 2 is the heading block default, so it needs no attribute.' );
		$this->assertStringContainsString( "<!-- wp:heading {\"level\":3} -->\n<h3", $content );
	}

	/**
	 * The highest heading level and the levels each marker gets from it.
	 *
	 * @return array[]
	 */
	public function heading_level_provider() {
		return [
			'Heading 2'                     => [ 2, [ 2, 3, 4 ] ],
			'Heading 4'                     => [ 4, [ 4, 5, 6 ] ],
			'Heading 5 stops at Heading 6'  => [ 5, [ 5, 6, 6 ] ],
			'Heading 6 for every marker'    => [ 6, [ 6, 6, 6 ] ],
			'never a Heading 1'             => [ 1, [ 2, 3, 4 ] ],
			'nothing deeper than Heading 6' => [ 7, [ 6, 6, 6 ] ],
			'off'                           => [ 0, [] ],
		];
	}

	/**
	 * # takes the highest level allowed, and each extra # goes one level
	 * down, never past Heading 6 and never up to Heading 1. Off makes no
	 * headings.
	 *
	 * @dataProvider heading_level_provider
	 *
	 * @param int   $top_level Highest heading level given to the processor, or 0 for off.
	 * @param int[] $expected  Levels for #, ## and ###.
	 */
	public function test_heading_levels_start_at_the_highest_level( $top_level, $expected ) {
		$processor = new Slack_Content_Processor( null, $top_level );

		$content = $processor->process( '', self::rich_text( self::section( self::text( "Update:\n# One\n## Two\n### Three" ) ) ) );

		$this->assertSame( $expected, array_column( self::headings( $content ), 0 ) );
	}

	/**
	 * Without a level, headings are off and a marked line stays as typed, so
	 * reporters' existing use of # doesn't change until a site opts in.
	 */
	public function test_leaves_marked_lines_as_text_by_default() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process( '', self::rich_text( self::section( self::text( '# of precincts reporting: 45' ) ) ) );

		$this->assertSame( [ 'core/paragraph' ], self::block_names( $content ) );
		$this->assertStringContainsString( '<p># of precincts reporting: 45</p>', $content );
		$this->assertSame( '', $processor->get_title(), 'The first line should not become a title either.' );
	}

	/**
	 * A heading is one line: the lines before and after it are paragraphs,
	 * with their line breaks.
	 */
	public function test_heading_splits_the_paragraph_around_it() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process( '', self::rich_text( self::section( self::text( "Polls have closed.\n# Results\nWard 1 is counted.\nWard 2 is next.\n\nMore soon." ) ) ) );
		$blocks  = array_values( array_filter( parse_blocks( $content ), fn( $block ) => null !== $block['blockName'] ) );

		$this->assertSame( [ 'core/paragraph', 'core/heading', 'core/paragraph', 'core/paragraph' ], wp_list_pluck( $blocks, 'blockName' ) );
		$this->assertSame( '<p>Polls have closed.</p>', trim( $blocks[0]['innerHTML'] ) );
		$this->assertSame( '<p>Ward 1 is counted.<br>Ward 2 is next.</p>', trim( $blocks[2]['innerHTML'] ) );
		$this->assertSame( '<p>More soon.</p>', trim( $blocks[3]['innerHTML'] ) );
	}

	/**
	 * Lines that only look like headings and the text they should keep.
	 *
	 * @return array[]
	 */
	public function not_a_heading_provider() {
		return [
			'no space after the marker' => [ [ self::text( '#1 priority is turnout' ) ], '<p>#1 priority is turnout</p>' ],
			'four markers'              => [ [ self::text( '#### Too deep' ) ], '<p>#### Too deep</p>' ],
			'a marker and nothing else' => [ [ self::text( '# ' ) ], '<p>#</p>' ],
			'a marker mid-line'         => [ [ self::text( 'Ward 1 # Ward 2' ) ], '<p>Ward 1 # Ward 2</p>' ],
			'a channel mention'         => [
				[
					[
						'type'       => 'channel',
						'channel_id' => 'C123ABC',
					],
					self::text( ' is busy' ),
				],
				'<p>#C123ABC is busy</p>',
			],
			'a marker in inline code'   => [ [ self::text( '# not a heading', [ 'code' => true ] ) ], '<p><code># not a heading</code></p>' ],
			'only the marker styled'    => [ [ self::text( '# ', [ 'bold' => true ] ), self::text( 'Results' ) ], '<p><strong># </strong>Results</p>' ],
			'a non-breaking space only' => [ [ self::text( "# \u{00A0}" ) ], '<p>#' ],
			'styled spaces only'        => [ [ self::text( '# ' ), self::text( ' ', [ 'bold' => true ] ) ], '<p># <strong> </strong></p>' ],
		];
	}

	/**
	 * A marker needs a space and some visible text after it, at the start of
	 * the line and outside inline code.
	 *
	 * @dataProvider not_a_heading_provider
	 *
	 * @param array  $elements Inline elements of the section.
	 * @param string $expected Paragraph the line should stay as.
	 */
	public function test_leaves_lines_that_are_not_headings_as_text( $elements, $expected ) {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process( '', self::rich_text( self::section( ...$elements ) ) );

		$this->assertSame( [ 'core/paragraph' ], self::block_names( $content ) );
		$this->assertStringContainsString( $expected, $content );
	}

	/**
	 * A heading keeps the inline styles and links of its line, including a
	 * style that covers the marker.
	 */
	public function test_headings_keep_inline_styles() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process(
			'',
			self::rich_text(
				self::section(
					self::text( "Update:\n" ),
					self::text( '# Results are in', [ 'bold' => true ] ),
					self::text( "\n## Turnout " ),
					self::text( 'record', [ 'italic' => true ] ),
					self::text( "\n### " ),
					[
						'type' => 'link',
						'url'  => 'https://example.test/live',
						'text' => 'Live updates',
					]
				)
			)
		);

		$this->assertSame(
			[
				[ 2, '<h2 class="wp-block-heading"><strong>Results are in</strong></h2>' ],
				[ 3, '<h3 class="wp-block-heading">Turnout <em>record</em></h3>' ],
				[ 4, '<h4 class="wp-block-heading"><a href="https://example.test/live">Live updates</a></h4>' ],
			],
			self::headings( $content )
		);
	}

	/**
	 * Quotes, lists and code blocks keep a marked line as text.
	 */
	public function test_no_headings_in_quotes_lists_or_code() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process(
			'',
			self::rich_text(
				[
					'type'     => 'rich_text_quote',
					'elements' => [ self::text( '# Quoted' ) ],
				],
				self::list_of( 'bullet', 0, [ '# Listed' ] ),
				[
					'type'     => 'rich_text_preformatted',
					'elements' => [ self::text( '# Code' ) ],
				]
			)
		);

		$this->assertSame( [], self::headings( $content ) );
		$this->assertStringContainsString( '<p># Quoted</p>', $content );
		$this->assertStringContainsString( '<li># Listed</li>', $content );
		$this->assertStringContainsString( '<code># Code</code>', $content );
	}

	/**
	 * The mrkdwn fallback turns marked lines into headings too.
	 */
	public function test_mrkdwn_fallback_makes_headings() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process( "Update:\n# *Results*\nWard 1 is counted." );

		$this->assertSame( [ 'core/paragraph', 'core/heading', 'core/paragraph' ], self::block_names( $content ) );
		$this->assertSame( [ [ 2, '<h2 class="wp-block-heading"><strong>Results</strong></h2>' ] ], self::headings( $content ) );
	}

	/**
	 * In the mrkdwn fallback a link label's line breaks become spaces, as in
	 * rich text, so a label can't end a paragraph or start a heading inside
	 * the link.
	 */
	public function test_mrkdwn_link_labels_stay_on_one_line() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process( "<https://example.test/live|Live updates\n# Results>" );

		$this->assertSame( [ 'core/paragraph' ], self::block_names( $content ) );
		$this->assertStringContainsString( '<p><a href="https://example.test/live">Live updates # Results</a></p>', $content );
	}

	/**
	 * A first line starting with a single # is the entry's title, taken out
	 * of the content, so the feed layout gives it the level entry titles
	 * have.
	 */
	public function test_first_line_with_one_marker_becomes_the_title() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process( '', self::rich_text( self::section( self::text( "# Results are in\nWard 1 is counted.\n# Ward 2" ) ) ) );

		$this->assertSame( 'Results are in', $processor->get_title() );
		$this->assertSame( [ 'core/paragraph', 'core/heading' ], self::block_names( $content ), 'Only the first line should become the title.' );
		$this->assertStringNotContainsString( 'Results are in', $content );
	}

	/**
	 * A first line starting with ## or ### stays a heading in the content.
	 */
	public function test_deeper_first_line_stays_a_heading() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process( '', self::rich_text( self::section( self::text( "## Ward 1\nCounted." ) ) ) );

		$this->assertSame( '', $processor->get_title() );
		$this->assertSame( [ [ 3, '<h3 class="wp-block-heading">Ward 1</h3>' ] ], self::headings( $content ) );
	}

	/**
	 * The title is plain text. Characters HTML gives meaning to stay encoded,
	 * as WordPress stores them in a title it filters on save, and so do
	 * square brackets, so a shortcode typed in the title shows as text.
	 */
	public function test_title_is_plain_text() {
		$processor = new Slack_Content_Processor( null, 2 );

		$processor->process(
			'',
			self::rich_text(
				self::section(
					self::text( '# Polls\' close, turnout ' ),
					self::text( '<50%', [ 'bold' => true ] ),
					self::text( ' & [rising]' )
				)
			)
		);

		$this->assertSame( "Polls' close, turnout &lt;50% &amp; &#91;rising&#93;", $processor->get_title() );
	}

	/**
	 * A message that is only a title has no content.
	 */
	public function test_title_only_message_has_no_content() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process( '', self::rich_text( self::section( self::text( '# Polls closed' ) ) ) );

		$this->assertSame( 'Polls closed', $processor->get_title() );
		$this->assertSame( '', $content );
	}

	/**
	 * The mrkdwn fallback takes the title from its first line too.
	 */
	public function test_mrkdwn_fallback_takes_the_title() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process( "# *Results*\nWard 1 is counted." );

		$this->assertSame( 'Results', $processor->get_title() );
		$this->assertSame( [ 'core/paragraph' ], self::block_names( $content ) );
	}

	/**
	 * A message that is only a title has no rich-text content, and the title
	 * still comes from the rich text, not from Slack's mrkdwn copy of it.
	 */
	public function test_title_only_message_keeps_the_rich_text_title() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process(
			'# Polls closed :tada:',
			self::rich_text(
				self::section(
					self::text( '# Polls closed ' ),
					[
						'type'    => 'emoji',
						'name'    => 'tada',
						'unicode' => '1f389',
					]
				)
			)
		);

		$this->assertSame( "Polls closed \u{1F389}", $processor->get_title() );
		$this->assertSame( '', $content );
	}

	/**
	 * A header block, as Slack's composer sends a line it turned into a
	 * heading.
	 *
	 * @param string $text  Header text.
	 * @param int    $level Header level, 1 for #.
	 * @return array
	 */
	private static function header( $text, $level ) {
		return [
			'type'  => 'header',
			'text'  => [
				'type'  => 'plain_text',
				'text'  => $text,
				'emoji' => true,
			],
			'level' => $level,
		];
	}

	/**
	 * Slack's composer can turn # lines into header blocks of their own.
	 * They follow the same rules as # lines: a first level-1 header is the
	 * title, and the others are headings, starting at the chosen level.
	 */
	public function test_header_blocks_become_the_title_and_headings() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process(
			"Title Subtitle Heading 3 \nText",
			[
				self::header( 'Title', 1 ),
				self::header( 'Subtitle', 2 ),
				self::header( 'Heading 3', 3 ),
				self::rich_text( self::section( self::text( "\nText" ) ) )[0],
			]
		);

		$this->assertSame( 'Title', $processor->get_title() );
		$this->assertSame(
			[
				[ 2, '<h2 class="wp-block-heading">Subtitle</h2>' ],
				[ 3, '<h3 class="wp-block-heading">Heading 3</h3>' ],
			],
			self::headings( $content )
		);
		$this->assertSame( [ 'core/heading', 'core/heading', 'core/paragraph' ], self::block_names( $content ) );
		$this->assertStringContainsString( '<p>Text</p>', $content );
	}

	/**
	 * A level-1 header after the message's text is a heading, not the title.
	 */
	public function test_header_block_after_text_is_a_heading() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process( 'Polls closed Results', [ self::rich_text( self::section( self::text( 'Polls closed.' ) ) )[0], self::header( 'Results', 1 ) ] );

		$this->assertSame( '', $processor->get_title() );
		$this->assertSame( [ [ 2, '<h2 class="wp-block-heading">Results</h2>' ] ], self::headings( $content ) );
	}

	/**
	 * With headings off, header blocks keep their text as paragraphs rather
	 * than disappearing.
	 */
	public function test_header_blocks_keep_their_text_when_headings_are_off() {
		$processor = new Slack_Content_Processor();

		$content = $processor->process( 'Title Text', [ self::header( 'Title', 1 ), self::header( 'Subtitle', 2 ), self::rich_text( self::section( self::text( 'Text' ) ) )[0] ] );

		$this->assertSame( '', $processor->get_title() );
		$this->assertSame( [ 'core/paragraph', 'core/paragraph', 'core/paragraph' ], self::block_names( $content ) );
		$this->assertStringContainsString( '<p>Title</p>', $content );
		$this->assertStringContainsString( '<p>Subtitle</p>', $content );
	}

	/**
	 * A header's text is escaped like the rest of the message, square
	 * brackets included, in a heading and in the title.
	 */
	public function test_header_block_text_is_escaped() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process( '', [ self::header( 'Live [gallery] <b>now</b>', 1 ), self::header( 'Q&A [x]', 2 ) ] );

		$this->assertSame( 'Live &#91;gallery&#93; &lt;b&gt;now&lt;/b&gt;', $processor->get_title() );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Q&amp;A &#91;x&#93;</h2>', $content );
	}

	/**
	 * Once the first line is the title, the body's headings start at the
	 * highest level allowed: ## takes it and ### the next, so no level is
	 * skipped. A # later in the body can go no higher, so it takes it too.
	 */
	public function test_headings_after_a_title_start_at_the_highest_level() {
		$processor = new Slack_Content_Processor( null, 2 );

		$content = $processor->process( '', self::rich_text( self::section( self::text( "# Heading 1\n## Heading 2\n### Heading 3\n# Heading 1 again" ) ) ) );

		$this->assertSame( 'Heading 1', $processor->get_title() );
		$this->assertSame( [ 2, 3, 2 ], array_column( self::headings( $content ), 0 ) );
	}
}
