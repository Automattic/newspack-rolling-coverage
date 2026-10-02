<?php
/**
 * Tests for the core buttons bound to each entry.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Entry_Bindings;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Push_Notifications;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * The entry template's "Read more" and share buttons are core buttons whose
 * link and label come from the entry being rendered.
 */
class Test_Entry_Bindings extends Rolling_Coverage_TestCase {

	/**
	 * The entry template's buttons, as the editor saves them.
	 */
	const BUTTONS_MARKUP = '<!-- wp:buttons --><div class="wp-block-buttons">'
		. '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"breakoutUrl"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Full story</a></div><!-- /wp:button -->'
		. '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"shareUrl"}}}},"className":"newspack-rolling-coverage-share-link"} --><div class="wp-block-button newspack-rolling-coverage-share-link"><a class="wp-block-button__link wp-element-button">Share</a></div><!-- /wp:button -->'
		. '</div><!-- /wp:buttons -->';

	/**
	 * Render an entry through the buttons template.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return string Rendered entry.
	 */
	private static function render( int $entry_id ): string {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( self::BUTTONS_MARKUP ) );
	}

	/**
	 * Link the entry to a new breakout post with the given status.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param string $status   Breakout post status.
	 * @param array  $args     Further post factory arguments.
	 * @return int Breakout post ID.
	 */
	private static function add_breakout( int $entry_id, string $status, array $args = [] ): int {
		$breakout_id = self::factory()->post->create( array_merge( [ 'post_status' => $status ], $args ) );
		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );
		update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $entry_id );

		return $breakout_id;
	}

	/**
	 * "Read more" stays hidden until the breakout post is published.
	 */
	public function test_read_more_is_hidden_until_the_breakout_is_published() {
		$entry_id = self::create_entry( self::create_coverage() );

		$html = self::render( $entry_id );

		$this->assertStringNotContainsString( 'Full story', $html, 'No breakout: no button.' );
		$this->assertStringContainsString( 'data-rc-share', $html, 'The rest of the template should still render.' );

		self::add_breakout( $entry_id, 'draft' );

		$this->assertStringNotContainsString( 'Full story', self::render( $entry_id ), 'A draft breakout: no button.' );
	}

	/**
	 * A published breakout gives "Read more" its link, keeping the text
	 * written in the template.
	 */
	public function test_read_more_links_to_the_published_breakout_with_the_template_text() {
		$entry_id    = self::create_entry( self::create_coverage() );
		$breakout_id = self::add_breakout( $entry_id, 'publish' );

		$html = self::render( $entry_id );

		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $breakout_id ) ) . '"', $html, 'The button should link to the breakout.' );
		$this->assertStringContainsString( '>Full story</a>', $html, "The template's text should be used." );
	}

	/**
	 * The editor preview knows which entries have a published breakout, so
	 * it shows "Read more" only on those.
	 */
	public function test_the_editor_preview_flags_entries_with_a_published_breakout() {
		$coverage_id = self::create_coverage();
		$published   = self::create_entry( $coverage_id );
		$drafted     = self::create_entry( $coverage_id );
		$none        = self::create_entry( $coverage_id );
		self::add_breakout( $published, 'publish' );
		self::add_breakout( $drafted, 'draft' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/coverages/' . $coverage_id . '/entries-preview' ) );
		$flags    = wp_list_pluck( $response->get_data(), 'hasBreakout', 'id' );

		$this->assertTrue( $flags[ $published ], 'A published breakout should be flagged.' );
		$this->assertFalse( $flags[ $drafted ], 'A draft breakout should not be.' );
		$this->assertFalse( $flags[ $none ], 'An entry without a breakout should not be.' );
	}

	/**
	 * An entry's title links to its breakout post once that post is published.
	 */
	public function test_the_title_links_to_the_published_breakout() {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_title' => 'Repair plan announced' ] );
		$title    = parse_blocks( '<!-- wp:post-title {"level":4} /-->' );

		$this->assertStringNotContainsString( '<a ', Rolling_Coverage_Block::render_entry( get_post( $entry_id ), $title ), 'No breakout: a plain title.' );

		$breakout_id = self::add_breakout( $entry_id, 'draft' );

		$this->assertStringNotContainsString( '<a ', Rolling_Coverage_Block::render_entry( get_post( $entry_id ), $title ), 'A draft breakout: a plain title.' );

		wp_publish_post( $breakout_id );

		$this->assertStringContainsString(
			'<a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Repair plan announced</a></h4>',
			Rolling_Coverage_Block::render_entry( get_post( $entry_id ), $title ),
			'A published breakout: the title links to it.'
		);
	}

	/**
	 * A title set to link to the entry links to the breakout post instead.
	 */
	public function test_a_linked_title_points_at_the_breakout_instead() {
		$entry_id    = self::create_entry( self::create_coverage(), [ 'post_title' => 'Repair plan announced' ] );
		$breakout_id = self::add_breakout( $entry_id, 'publish' );

		$html = Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( '<!-- wp:post-title {"isLink":true} /-->' ) );

		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $breakout_id ) ) . '"', $html, 'The title should link to the breakout.' );
		$this->assertStringNotContainsString( 'href="' . esc_url( get_permalink( $entry_id ) ) . '"', $html, 'The title should no longer link to the entry.' );
	}

	/**
	 * A title whose text holds a link of its own isn't wrapped in another,
	 * and a title rendered as a paragraph is linked like a heading.
	 */
	public function test_titles_with_their_own_link_stay_as_they_are() {
		$coverage_id = self::create_coverage();
		$with_link   = self::create_entry( $coverage_id, [ 'post_title' => 'See <a href="https://example.com/">the map</a>' ] );
		$paragraph   = self::create_entry( $coverage_id, [ 'post_title' => 'Repair plan announced' ] );
		self::add_breakout( $with_link, 'publish' );
		$breakout_id = self::add_breakout( $paragraph, 'publish' );

		$html = Rolling_Coverage_Block::render_entry( get_post( $with_link ), parse_blocks( '<!-- wp:post-title /-->' ) );

		$this->assertStringContainsString( 'href="https://example.com/"', $html, "The title's own link should be kept." );
		$this->assertSame( 1, substr_count( $html, '<a ' ), 'No second link should be added.' );

		$this->assertStringContainsString(
			'<a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Repair plan announced</a></p>',
			Rolling_Coverage_Block::render_entry( get_post( $paragraph ), parse_blocks( '<!-- wp:post-title {"level":0} /-->' ) )
		);
	}

	/**
	 * A title outside an entry is left alone.
	 */
	public function test_titles_outside_entries_are_left_alone() {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Elsewhere' ] );
		update_post_meta( $post_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, self::factory()->post->create() );
		$title = parse_blocks( '<!-- wp:post-title /-->' );

		$html = ( new WP_Block(
			$title[0],
			[
				'postId'   => $post_id,
				'postType' => 'post',
			]
		) )->render();

		$this->assertStringNotContainsString( '<a ', $html );
	}

	/**
	 * The share button links to the entry and is marked for the share script.
	 */
	public function test_share_links_to_the_entry_as_a_button() {
		$entry_id = self::create_entry( self::create_coverage() );

		$this->assertMatchesRegularExpression(
			'#<a (?=[^>]*href="' . preg_quote( esc_url( get_permalink( $entry_id ) ), '#' ) . '")(?=[^>]*data-rc-share)(?=[^>]*role="button")#',
			self::render( $entry_id )
		);
	}

	/**
	 * An entry's relative date is marked so the front end keeps it current;
	 * a date in any other format, or outside an entry, is left alone.
	 */
	public function test_only_relative_entry_dates_are_marked_for_refresh() {
		$entry_id = self::create_entry( self::create_coverage() );
		$entry    = get_post( $entry_id );
		$relative = parse_blocks( '<!-- wp:post-date {"format":"human-diff"} /-->' );
		$absolute = parse_blocks( '<!-- wp:post-date /-->' );

		$this->assertStringContainsString( 'data-rc-relative', Rolling_Coverage_Block::render_entry( $entry, $relative ), 'A relative entry date should be marked.' );
		$this->assertStringNotContainsString( 'data-rc-relative', Rolling_Coverage_Block::render_entry( $entry, $absolute ), 'An absolute entry date should not be marked.' );

		$post_id = self::factory()->post->create();
		$html    = ( new WP_Block(
			$relative[0],
			[
				'postId'   => $post_id,
				'postType' => 'post',
			]
		) )->render();

		$this->assertStringNotContainsString( 'data-rc-relative', $html, 'A relative date outside an entry should not be marked.' );
	}

	/**
	 * An entry's Buttons block renders nothing when none of its buttons do,
	 * and is left alone outside an entry.
	 */
	public function test_empty_entry_buttons_render_nothing() {
		$read_more = '<!-- wp:buttons --><div class="wp-block-buttons">'
			. '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"breakoutUrl"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Read more</a></div><!-- /wp:button -->'
			. '</div><!-- /wp:buttons -->';
		$entry_id  = self::create_entry( self::create_coverage() );

		$this->assertStringNotContainsString( 'wp-block-buttons', Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( $read_more ) ), 'No breakout: no empty row.' );

		self::add_breakout( $entry_id, 'publish' );

		$this->assertStringContainsString( 'wp-block-buttons', Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( $read_more ) ), 'A published breakout keeps the row.' );
		$this->assertStringContainsString( 'wp-block-buttons', render_block( parse_blocks( '<!-- wp:buttons --><div class="wp-block-buttons"></div><!-- /wp:buttons -->' )[0] ), 'An empty Buttons block outside an entry is left alone.' );
	}

	/**
	 * The share button shows the link icon alone and is named after the
	 * entry it shares, or the entry's first words when it has no title.
	 */
	public function test_share_shows_the_icon_named_after_the_entry() {
		$coverage_id = self::create_coverage();
		$titled      = self::render( self::create_entry( $coverage_id, [ 'post_title' => 'Polls close at 8pm' ] ) );
		$untitled    = self::render(
			self::create_entry(
				$coverage_id,
				[
					'post_title'   => '',
					'post_content' => 'Turnout is running well ahead of the last election in every ward.',
				]
			)
		);

		$this->assertStringContainsString( 'aria-label="Share: Polls close at 8pm"', $titled, 'The name should include the title.' );
		$this->assertMatchesRegularExpression( '#<a [^>]*data-rc-share[^>]*><svg[^>]*>.*</svg></a>#s', $titled, 'The link should hold the icon and no text.' );
		$this->assertStringContainsString( 'aria-label="Share: Turnout is running well ahead of the last', $untitled, 'An untitled entry should be named by its first words.' );
	}

	/**
	 * The share button's name reads the title as text: the curly apostrophe
	 * and ampersand core puts in titles are encoded once, not twice.
	 */
	public function test_share_name_decodes_entities_in_the_title() {
		$html = self::render( self::create_entry( self::create_coverage(), [ 'post_title' => "Biden's plan & more" ] ) );

		$this->assertStringContainsString( "aria-label=\"Share: Biden\u{2019}s plan &amp; more\"", $html );
	}

	/**
	 * Buttons not bound to an entry render untouched, anywhere on the site.
	 */
	public function test_other_buttons_are_left_alone() {
		$markup = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.test/">Donate</a></div><!-- /wp:button -->';

		$this->assertSame( trim( render_block( parse_blocks( $markup )[0] ) ), trim( parse_blocks( $markup )[0]['innerHTML'] ) );
	}

	/**
	 * A coverage block with no saved inner blocks renders the same buttons
	 * from the server's fallback template.
	 */
	public function test_fallback_template_renders_the_bound_buttons() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		$breakout_id = self::add_breakout( $entry_id, 'publish' );

		$attributes = [ 'coverageId' => $coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];

		// Called directly: the block type registers from the built assets, which the test run doesn't have.
		$html = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $breakout_id ) ) . '"', $html, '"Read more" should link to the breakout.' );
		$this->assertStringContainsString( 'data-rc-share', $html, 'Share should be marked for the share script.' );
	}

	/**
	 * The follow button, as the editor saves it.
	 */
	const FOLLOW_MARKUP = '<!-- wp:buttons --><div class="wp-block-buttons">'
		. '<!-- wp:button {"tagName":"button","metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"followTag"}}}}} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Follow</button></div><!-- /wp:button -->'
		. '</div><!-- /wp:buttons -->';

	/**
	 * Stand in an active, configured OneSignal.
	 */
	private static function configure_onesignal(): void {
		require_once __DIR__ . '/mocks/onesignal.php';
		update_option(
			'OneSignalWPSetting',
			[
				'app_id'           => 'test-app-id',
				'app_rest_api_key' => 'test-rest-api-key',
			]
		);
	}

	/**
	 * Render a coverage block holding the follow button and the entry buttons.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string Rendered block.
	 */
	private static function render_coverage_with_follow( int $coverage_id ): string {
		self::configure_onesignal();

		$attributes = [ 'coverageId' => $coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . self::FOLLOW_MARKUP . self::BUTTONS_MARKUP . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * The follow button renders once, above the entries, carrying the
	 * coverage's notification tag for the follow script.
	 */
	public function test_follow_button_renders_once_with_the_coverage_tag() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		self::create_entry( $coverage_id );

		$html = self::render_coverage_with_follow( $coverage_id );

		$this->assertSame( 1, substr_count( $html, 'data-rc-follow' ), 'The follow button should render once.' );
		$this->assertSame( 4, substr_count( $html, 'class="wp-block-buttons' ), 'Only the follow button, the jump to latest button and one row per entry should render, so entries hold no follow button.' );
		$this->assertStringContainsString( 'data-tag="' . esc_attr( Push_Notifications::follow_tag( $coverage_id ) ) . '"', $html );
	}

	/**
	 * A Buttons block holding both a follow button and a "Jump to latest"
	 * button is the jump control, not the follow button: it renders once.
	 */
	public function test_latest_button_takes_precedence_over_follow_in_one_buttons_block() {
		self::configure_onesignal();

		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$combined   = str_replace(
			'</div><!-- /wp:buttons -->',
			'<!-- wp:button {"metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"latestUrl"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Back to live</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
			self::FOLLOW_MARKUP
		);
		$attributes = [ 'coverageId' => $coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $combined . '<!-- wp:post-title /--><!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		$html = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		$this->assertSame( 1, substr_count( $html, 'Back to live' ), 'The block should render once, as the control.' );
		$this->assertSame( 1, substr_count( $html, 'class="wp-block-buttons' ) );
		$this->assertStringContainsString( 'newspack-rolling-coverage-new-entries', $html );
		$this->assertStringNotContainsString( 'data-rc-follow', $html, 'Rendered as the control, its follow button has no coverage to follow.' );
	}

	/**
	 * An archived coverage can't be followed, so the follow binding has no tag
	 * and its button doesn't render.
	 */
	public function test_follow_button_is_hidden_for_an_archived_coverage() {
		$coverage_id = self::create_coverage( 'archived' );

		$this->assertStringNotContainsString( 'data-rc-follow', self::render_coverage_with_follow( $coverage_id ), 'The rendered coverage should have no follow button.' );

		$button          = new WP_Block( parse_blocks( self::FOLLOW_MARKUP )[0]['innerBlocks'][0] );
		$button->context = [
			Entry_Bindings::COVERAGE_ID_CONTEXT     => $coverage_id,
			Entry_Bindings::COVERAGE_STATUS_CONTEXT => 'archived',
		];

		$this->assertNull( Entry_Bindings::get_value( [ 'key' => 'followTag' ], $button ), 'An archived coverage has no follow tag.' );

		$button->context[ Entry_Bindings::COVERAGE_STATUS_CONTEXT ] = 'active';

		$this->assertSame( Push_Notifications::follow_tag( $coverage_id ), Entry_Bindings::get_value( [ 'key' => 'followTag' ], $button ), 'An active one does.' );
	}

	/**
	 * The pin icon and pinned label, as the editor saves them.
	 */
	const PINNED_ROW_MARKUP = '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} --><div class="wp-block-group">'
		. '<!-- wp:icon {"icon":"newspack-rolling-coverage/pin-small"} /-->'
		. '<!-- wp:paragraph {"className":"use-header-font newspack-rolling-coverage-pinned-label","fontSize":"small"} --><p class="use-header-font newspack-rolling-coverage-pinned-label has-small-font-size">Top story</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->'
		. '<!-- wp:paragraph --><p>Entry body</p><!-- /wp:paragraph -->';

	/**
	 * Only pinned entries show the pinned row, labeled with the layout's
	 * text, and are marked for the theme.
	 */
	public function test_pinned_row_shows_only_on_pinned_entries() {
		$coverage_id = self::create_coverage();
		$pinned_id   = self::create_entry( $coverage_id );
		$other_id    = self::create_entry( $coverage_id );
		$template    = parse_blocks( self::PINNED_ROW_MARKUP );

		Post_Type::pin_entry( $pinned_id );

		$pinned = Rolling_Coverage_Block::render_entry( get_post( $pinned_id ), $template );
		$other  = Rolling_Coverage_Block::render_entry( get_post( $other_id ), $template );

		$this->assertStringContainsString( '>Top story</p>', $pinned, "A pinned entry should show the layout's label." );
		$this->assertStringContainsString( 'data-pinned', $pinned, 'A pinned entry should be marked.' );
		$this->assertStringNotContainsString( 'wp-block-group', $other, 'An unpinned entry should have no pinned row.' );
		$this->assertStringNotContainsString( 'data-pinned', $other, 'An unpinned entry should not be marked.' );
		$this->assertStringContainsString( 'Entry body', $other, 'The rest of the template should still render.' );
	}

	/**
	 * A pinned label moved out of its row still shows only on pinned entries.
	 */
	public function test_pinned_label_outside_a_row_is_hidden_on_unpinned_entries() {
		$template = parse_blocks( '<!-- wp:paragraph {"className":"newspack-rolling-coverage-pinned-label"} --><p class="newspack-rolling-coverage-pinned-label">Saved text</p><!-- /wp:paragraph -->' );

		$this->assertStringNotContainsString( 'Saved text', Rolling_Coverage_Block::render_entry( get_post( self::create_entry( self::create_coverage() ) ), $template ) );
	}

	/**
	 * A group holding the pinned label alongside other blocks keeps rendering
	 * them on unpinned entries; only the label goes.
	 */
	public function test_group_holding_the_label_and_other_blocks_keeps_them() {
		$template = parse_blocks(
			'<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph {"className":"newspack-rolling-coverage-pinned-label"} --><p class="newspack-rolling-coverage-pinned-label">Saved text</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>Entry byline</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
		);
		$html     = Rolling_Coverage_Block::render_entry( get_post( self::create_entry( self::create_coverage() ) ), $template );

		$this->assertStringContainsString( 'Entry byline', $html, 'The group and its other blocks should render.' );
		$this->assertStringNotContainsString( 'Saved text', $html, 'The label should not.' );
	}

	/**
	 * The Feed group's Block spacing sets the space between the coverage's
	 * items on the block's wrapper; unset or invalid, the wrapper sets none
	 * and the stylesheet's `spacing-50` applies.
	 *
	 * @dataProvider data_block_spacing
	 *
	 * @param array  $style    The Feed group's style attribute.
	 * @param string $expected The wrapper's style attribute, or '' for none.
	 */
	public function test_feed_spacing_sets_the_space_between_items( array $style, string $expected ) {
		$attributes = [ 'coverageId' => self::create_coverage() ];
		$feed_attrs = [ 'className' => 'newspack-rolling-coverage-feed' ];

		if ( $style ) {
			$feed_attrs['style'] = $style;
		}

		$feed       = '<!-- wp:group ' . wp_json_encode( $feed_attrs ) . ' --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. '<!-- wp:paragraph --><p>Entry text</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $feed . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];
		$html       = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
		$wrapper    = new WP_HTML_Tag_Processor( $html );
		$wrapper->next_tag();

		$this->assertSame( $expected, (string) $wrapper->get_attribute( 'style' ) );
	}

	/**
	 * Everything the coverage shows renders inside the Feed group, and the
	 * Feed's blocks are its entry template, not the Feed itself.
	 */
	public function test_feed_group_holds_the_coverage() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$attributes = [ 'coverageId' => $coverage_id ];
		$feed       = '<!-- wp:group {"className":"newspack-rolling-coverage-feed"} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. '<!-- wp:paragraph --><p>Entry text</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $feed . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];
		$html       = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		$this->assertSame( 1, substr_count( $html, 'newspack-rolling-coverage-feed' ), 'The Feed should render once.' );
		$this->assertMatchesRegularExpression( '/newspack-rolling-coverage-feed[^>]*>.*<div class="newspack-rolling-coverage-entries"><article [^>]*>.*Entry text/s', $html, 'The entries should render inside it.' );
	}

	/**
	 * Render a Rolling Coverage block with a Feed group holding one paragraph.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered block.
	 */
	private static function render_feed_block( array $attributes ): string {
		$feed  = '<!-- wp:group {"className":"newspack-rolling-coverage-feed"} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. '<!-- wp:paragraph --><p>Entry text</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';
		$block = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $feed . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * The status indicator is off unless the block turns it on.
	 */
	public function test_status_indicator_is_hidden_by_default() {
		$coverage_id = self::create_coverage();

		$this->assertStringNotContainsString( 'status-indicator', self::render_feed_block( [ 'coverageId' => $coverage_id ] ) );
	}

	/**
	 * The indicator opens the Feed, ahead of the archived notice, with a
	 * badge for the coverage's status.
	 */
	public function test_status_indicator_opens_the_feed() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		self::create_entry( $coverage_id );

		$html     = self::render_feed_block(
			[
				'coverageId'          => $coverage_id,
				'statusIndicatorShow' => true,
			]
		);
		$position = strpos( $html, 'newspack-rolling-coverage-status-indicator' );

		$this->assertStringContainsString( '<div class="newspack-rolling-coverage-status-indicator"><span class="newspack-ui__badge newspack-ui__badge--error">Ended</span></div>', $html );
		$this->assertGreaterThan( strpos( $html, 'newspack-rolling-coverage-feed' ), $position, 'The indicator should sit inside the Feed.' );
		$this->assertLessThan( strpos( $html, 'newspack-rolling-coverage-archived-notice' ), $position, 'The indicator should come before the archived notice.' );
	}

	/**
	 * Each status has its own badge, and a status the badge doesn't know
	 * shows as live.
	 */
	public function test_status_indicator_follows_the_coverage_status() {
		$coverage_id = self::create_coverage();
		$attributes  = [
			'coverageId'          => $coverage_id,
			'statusIndicatorShow' => true,
		];

		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--success newspack-ui__badge--dot newspack-ui__badge--pulse">Live</span>', self::render_feed_block( $attributes ), 'Active: a live badge with a pulsing dot.' );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_PAUSED );

		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--secondary">Paused</span>', self::render_feed_block( $attributes ), 'Paused: a secondary badge without a dot.' );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, 'unknown' );

		$this->assertStringContainsString( '>Live</span>', self::render_feed_block( $attributes ), 'An unknown status shows as live.' );
	}

	/**
	 * The block's label for the coverage's status replaces the default,
	 * escaped; a blank label or one that isn't text falls back to it.
	 */
	public function test_status_indicator_uses_the_label_for_the_status() {
		$coverage_id = self::create_coverage();
		$attributes  = [
			'coverageId'            => $coverage_id,
			'statusIndicatorShow'   => true,
			'statusIndicatorLabels' => [
				'active'   => 'On <b>air</b>',
				'archived' => 'Over',
				'paused'   => '  ',
			],
		];

		$this->assertStringContainsString( '>On &lt;b&gt;air&lt;/b&gt;</span>', self::render_feed_block( $attributes ), "The active status's label." );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_PAUSED );

		$this->assertStringContainsString( '>Paused</span>', self::render_feed_block( $attributes ), 'A blank label falls back to the default.' );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$this->assertStringContainsString( '>Over</span>', self::render_feed_block( $attributes ), "The archived status's label." );

		$attributes['statusIndicatorLabels']['archived'] = [ 'Over' ];

		$this->assertStringContainsString( '>Ended</span>', self::render_feed_block( $attributes ), 'A label that is not text falls back to the default.' );

		$attributes['statusIndicatorLabels'] = 'Over';

		$this->assertStringContainsString( '>Ended</span>', self::render_feed_block( $attributes ), 'Labels that are not a list fall back to the defaults.' );
	}

	/**
	 * An archived coverage shows the block's notice once, first in the Feed.
	 */
	public function test_archived_notice_renders_once_above_the_feed() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 10:00:00' ] );
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 11:00:00' ] );
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$html = self::render_feed_block(
			[
				'coverageId'     => $coverage_id,
				'archivedNotice' => 'Coverage <b>ended</b>',
			]
		);

		$this->assertSame( 1, substr_count( $html, '<p class="newspack-rolling-coverage-archived-notice">Coverage &lt;b&gt;ended&lt;/b&gt;</p>' ), 'The escaped notice should render once.' );
		$notice_position = strpos( $html, 'newspack-rolling-coverage-archived-notice' );
		$this->assertNotFalse( $notice_position, 'The notice should render.' );
		$this->assertGreaterThan( strpos( $html, 'newspack-rolling-coverage-feed' ), $notice_position, 'The notice should sit inside the Feed.' );
		$this->assertLessThan( strpos( $html, 'newspack-rolling-coverage-entries' ), $notice_position, 'The notice should come before the entries.' );
		$this->assertSame( 2, substr_count( $html, 'Entry text' ), 'Each entry should still render.' );
	}

	/**
	 * Line breaks typed in the notice carry through to the front end.
	 */
	public function test_archived_notice_keeps_line_breaks() {
		$coverage_id = self::create_coverage();
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$html = self::render_feed_block(
			[
				'coverageId'     => $coverage_id,
				'archivedNotice' => "Coverage ended.\nThanks for following.",
			]
		);

		$this->assertStringContainsString( "<p class=\"newspack-rolling-coverage-archived-notice\">Coverage ended.<br>\nThanks for following.</p>", $html );
	}

	/**
	 * An archived coverage whose block sets no notice shows the default one,
	 * naming the coverage.
	 */
	public function test_archived_notice_falls_back_to_the_default_text() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED, [ 'name' => 'Polls & results' ] );

		$html = self::render_feed_block(
			[
				'coverageId'     => $coverage_id,
				'archivedNotice' => '  ',
			]
		);

		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-archived-notice">Coverage of “Polls &amp; results” has concluded and this feed is now archived.</p>', $html );
	}

	/**
	 * Without a URL or a published breakout post, the notice has no link.
	 */
	public function test_archived_notice_without_a_published_breakout_has_no_link() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		$entry_id    = self::create_entry( $coverage_id );

		$this->assertStringNotContainsString( 'archived-notice__link', self::render_feed_block( [ 'coverageId' => $coverage_id ] ), 'No breakout: no link.' );

		self::add_breakout( $entry_id, 'draft' );

		$this->assertStringNotContainsString( 'archived-notice__link', self::render_feed_block( [ 'coverageId' => $coverage_id ] ), 'A draft breakout: no link.' );
	}

	/**
	 * Without a URL, the notice links to the coverage's most recently
	 * published breakout post, ignoring drafts and other coverages.
	 */
	public function test_archived_notice_links_to_the_latest_published_breakout() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		$other_id    = self::create_coverage();
		$older_entry = self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );
		$newer_entry = self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 10:00:00' ] );
		$draft_entry = self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 13:00:00' ] );
		$other_entry = self::create_entry( $other_id, [ 'post_date' => '2026-01-01 14:00:00' ] );

		self::add_breakout( $older_entry, 'publish', [ 'post_date' => '2026-02-01 10:00:00' ] );
		$newer = self::add_breakout( $newer_entry, 'publish', [ 'post_date' => '2026-02-02 10:00:00' ] );
		self::add_breakout( $draft_entry, 'draft', [ 'post_date' => '2026-02-03 10:00:00' ] );
		self::add_breakout( $other_entry, 'publish', [ 'post_date' => '2026-02-04 10:00:00' ] );

		$html = self::render_feed_block(
			[
				'coverageId'     => $coverage_id,
				'archivedNotice' => 'Coverage ended.',
			]
		);

		$this->assertStringContainsString(
			'<p class="newspack-rolling-coverage-archived-notice">Coverage ended. <a class="newspack-rolling-coverage-archived-notice__link" href="' . esc_url( get_permalink( $newer ) ) . '">Read more</a></p>',
			$html,
			'The notice should link to the latest published breakout post.'
		);
	}

	/**
	 * The cached breakout link follows a breakout published after it was
	 * first looked up.
	 */
	public function test_archived_notice_breakout_link_follows_a_new_breakout() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		$first       = self::add_breakout( self::create_entry( $coverage_id ), 'publish', [ 'post_date' => '2026-02-01 10:00:00' ] );
		$draft       = self::add_breakout( self::create_entry( $coverage_id ), 'draft', [ 'post_date' => '2026-02-02 10:00:00' ] );
		$attributes  = [ 'coverageId' => $coverage_id ];

		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $first ) ) . '"', self::render_feed_block( $attributes ), 'The published breakout should be linked.' );

		wp_update_post(
			[
				'ID'          => $draft,
				'post_status' => 'publish',
			]
		);

		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $draft ) ) . '"', self::render_feed_block( $attributes ), 'A breakout published later should replace the cached link.' );
	}

	/**
	 * A URL set on the block wins over the coverage's breakout post.
	 */
	public function test_archived_notice_url_overrides_the_breakout() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		$breakout_id = self::add_breakout( self::create_entry( $coverage_id ), 'publish' );

		$html = self::render_feed_block(
			[
				'coverageId'            => $coverage_id,
				'archivedNoticeLinkUrl' => 'https://example.com/story',
			]
		);

		$this->assertStringContainsString( 'href="https://example.com/story"', $html, "The block's URL should be used." );
		$this->assertStringNotContainsString( esc_url( get_permalink( $breakout_id ) ), $html, 'The breakout post should not be linked.' );
	}

	/**
	 * A coverage that isn't archived shows no notice.
	 */
	public function test_archived_notice_is_hidden_until_the_coverage_is_archived() {
		$coverage_id = self::create_coverage();
		$attributes  = [
			'coverageId'            => $coverage_id,
			'archivedNotice'        => 'Coverage ended',
			'archivedNoticeLinkUrl' => 'https://example.com/story',
		];

		$this->assertStringNotContainsString( 'archived-notice', self::render_feed_block( $attributes ), 'An active coverage has no notice.' );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_PAUSED );

		$this->assertStringNotContainsString( 'archived-notice', self::render_feed_block( $attributes ), 'Nor does a paused one.' );
	}

	/**
	 * A block that turns the notice off renders none, whatever text and link it sets.
	 */
	public function test_archived_notice_can_be_turned_off() {
		$coverage_id = self::create_coverage();
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$attributes = [
			'coverageId'            => $coverage_id,
			'archivedNotice'        => 'Coverage ended.',
			'archivedNoticeLinkUrl' => 'https://example.com/story',
		];

		$this->assertStringContainsString( 'class="newspack-rolling-coverage-archived-notice"', self::render_feed_block( $attributes ), 'The notice shows by default.' );

		$attributes['archivedNoticeShow'] = false;

		$this->assertStringNotContainsString( 'class="newspack-rolling-coverage-archived-notice"', self::render_feed_block( $attributes ), 'The notice should be hidden.' );
	}

	/**
	 * The notice links on after its text when the block sets a URL, labeled
	 * with the block's link text or "Read more".
	 */
	public function test_archived_notice_link() {
		$coverage_id = self::create_coverage();
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$attributes = [
			'coverageId'              => $coverage_id,
			'archivedNotice'          => 'Coverage ended.',
			'archivedNoticeLinkUrl'   => 'https://example.com/story?a=1&b=2',
			'archivedNoticeLinkLabel' => 'Follow <em>the</em> story',
		];

		$this->assertStringContainsString(
			'<p class="newspack-rolling-coverage-archived-notice">Coverage ended. <a class="newspack-rolling-coverage-archived-notice__link" href="https://example.com/story?a=1&#038;b=2">Follow &lt;em&gt;the&lt;/em&gt; story</a></p>',
			self::render_feed_block( $attributes ),
			"The link should carry the block's text."
		);

		$attributes['archivedNoticeLinkLabel'] = '';

		$this->assertStringContainsString(
			'Coverage ended. <a class="newspack-rolling-coverage-archived-notice__link" href="https://example.com/story?a=1&#038;b=2">Read more</a></p>',
			self::render_feed_block( $attributes ),
			'Without text, the link should read "Read more".'
		);
	}

	/**
	 * With the link turned off, the notice has none, whether the block sets a
	 * URL or the coverage has a published breakout post.
	 */
	public function test_archived_notice_link_can_be_turned_off() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		self::add_breakout( self::create_entry( $coverage_id ), 'publish' );

		$attributes = [
			'coverageId'             => $coverage_id,
			'archivedNotice'         => 'Coverage ended.',
			'archivedNoticeShowLink' => false,
		];

		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-archived-notice">Coverage ended.</p>', self::render_feed_block( $attributes ), 'No link to the breakout post.' );

		$attributes['archivedNoticeLinkUrl'] = 'https://example.com/story';

		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-archived-notice">Coverage ended.</p>', self::render_feed_block( $attributes ), "No link to the block's URL." );

		$attributes['archivedNoticeShowLink'] = true;

		$this->assertStringContainsString( 'href="https://example.com/story"', self::render_feed_block( $attributes ), "Turned back on, the block's URL is linked." );
	}

	/**
	 * Without a URL, or with one esc_url() rejects, the notice has no link.
	 */
	public function test_archived_notice_without_a_url_has_no_link() {
		$coverage_id = self::create_coverage();
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$attributes = [
			'coverageId'              => $coverage_id,
			'archivedNotice'          => 'Coverage ended.',
			'archivedNoticeLinkLabel' => 'Follow <em>the</em> story',
		];

		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-archived-notice">Coverage ended.</p>', self::render_feed_block( $attributes ), 'No URL: no link.' );

		$attributes['archivedNoticeLinkUrl'] = 'javascript:alert(1)';

		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-archived-notice">Coverage ended.</p>', self::render_feed_block( $attributes ), 'A rejected URL: no link.' );
	}

	/**
	 * A coverage that loads with no entries still stores its template's
	 * layout styles, for entries that arrive later by polling.
	 */
	public function test_empty_coverage_stores_the_template_layout_styles() {
		$attributes = [ 'coverageId' => self::create_coverage() ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];

		Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		$this->assertStringContainsString( 'justify-content:space-between', wp_style_engine_get_stylesheet_from_context( 'block-supports', [ 'prettify' => false ] ), 'The header row layout should be stored.' );
	}

	/**
	 * Block spacing settings and the space they give.
	 *
	 * @return array[]
	 */
	public function data_block_spacing(): array {
		return [
			'unset'   => [ [], '' ],
			'preset'  => [ [ 'spacing' => [ 'blockGap' => 'var:preset|spacing|30' ] ], '--newspack-rolling-coverage-gap:var(--wp--preset--spacing--30)' ],
			'custom'  => [ [ 'spacing' => [ 'blockGap' => '2rem' ] ], '--newspack-rolling-coverage-gap:2rem' ],
			'invalid' => [ [ 'spacing' => [ 'blockGap' => '1px;}body{display:none' ] ], '' ],
			'extra'   => [ [ 'spacing' => [ 'blockGap' => '10px;position:fixed' ] ], '--newspack-rolling-coverage-gap:10px' ],
		];
	}

	/**
	 * Entries loaded after the first render keep the layout's pinned label.
	 */
	public function test_load_more_keeps_the_layout_pinned_label() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		Post_Type::pin_entry( $entry_id );

		$attributes = [ 'coverageId' => $coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . self::PINNED_ROW_MARKUP . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];
		$html       = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		$this->assertMatchesRegularExpression( '/data-template-key="([^"]+)"/', $html );
		preg_match( '/data-template-key="([^"]+)"/', $html, $matches );

		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'term_id', $coverage_id );
		$request->set_param( 'template_key', $matches[1] );
		$request->set_param( 'before', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );

		$more = Rolling_Coverage_Block::get_entries( $request )->get_data()['html'];

		$this->assertStringContainsString( '>Top story</p>', $more );
	}

	/**
	 * The built-in layout labels pinned entries "Pinned".
	 */
	public function test_default_layout_labels_pinned_entries() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		Post_Type::pin_entry( $entry_id );

		$attributes = [ 'coverageId' => $coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];
		$html       = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		$this->assertMatchesRegularExpression( '#<p class="[^"]*newspack-rolling-coverage-pinned-label[^"]*"[^>]*>Pinned</p>#', $html );
	}

	/**
	 * Render an entry through the given template markup.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param string $markup   Template markup.
	 * @return string Rendered entry.
	 */
	private static function render_markup( int $entry_id, string $markup ): string {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( $markup ) );
	}

	/**
	 * A "Read more" paragraph, as the editor saves it.
	 *
	 * @param string $classes Classes on the paragraph.
	 * @param string $text    Paragraph HTML.
	 * @return string Block markup.
	 */
	private static function read_more_paragraph( string $classes = 'newspack-rolling-coverage-read-more', string $text = 'Read more' ): string {
		return '<!-- wp:paragraph {"className":"' . $classes . '"} --><p class="' . $classes . '">' . $text . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * A "Read more" paragraph links to the published breakout.
	 */
	public function test_read_more_paragraph_links_to_the_breakout() {
		$entry_id    = self::create_entry( self::create_coverage() );
		$breakout_id = self::add_breakout( $entry_id, 'publish' );

		$html = self::render_markup( $entry_id, self::read_more_paragraph() );

		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-read-more wp-block-paragraph"><a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Read more</a></p>', $html );
	}

	/**
	 * Without a published breakout the paragraph goes.
	 */
	public function test_read_more_paragraph_is_removed_without_a_breakout() {
		$entry_id = self::create_entry( self::create_coverage() );

		$this->assertStringNotContainsString( 'newspack-rolling-coverage-read-more', self::render_markup( $entry_id, self::read_more_paragraph() ) );

		self::add_breakout( $entry_id, 'draft' );

		$this->assertStringNotContainsString( 'newspack-rolling-coverage-read-more', self::render_markup( $entry_id, self::read_more_paragraph() ) );
	}

	/**
	 * Other classes and inner markup survive the link.
	 */
	public function test_read_more_paragraph_keeps_its_markup_and_other_classes() {
		$entry_id    = self::create_entry( self::create_coverage() );
		$breakout_id = self::add_breakout( $entry_id, 'publish' );

		$html = self::render_markup( $entry_id, self::read_more_paragraph( 'foo newspack-rolling-coverage-read-more', '<strong>Read</strong> more' ) );

		$this->assertStringContainsString( '<p class="foo newspack-rolling-coverage-read-more wp-block-paragraph"><a href="' . esc_url( get_permalink( $breakout_id ) ) . '"><strong>Read</strong> more</a></p>', $html );
	}

	/**
	 * A paragraph rendered outside an entry is left alone.
	 */
	public function test_paragraph_outside_entries_is_untouched() {
		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-read-more wp-block-paragraph">Read more</p>', do_blocks( self::read_more_paragraph() ) );
	}

	/**
	 * A title-only entry in the Compact shape renders its time and nothing
	 * empty.
	 */
	public function test_compact_entry_without_content_renders() {
		$entry_id = self::create_entry(
			self::create_coverage(),
			[
				'post_title'   => 'Headline',
				'post_content' => '',
			]
		);
		$markup   = '<!-- wp:columns {"isStackedOnMobile":false} --><div class="wp-block-columns">'
			. '<!-- wp:column {"width":"6rem"} --><div class="wp-block-column" style="flex-basis:6rem"><!-- wp:post-date {"format":"g:i a"} /--></div><!-- /wp:column -->'
			. '<!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:group {"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} --><div class="wp-block-group">'
			. '<!-- wp:post-content /-->'
			. self::read_more_paragraph()
			. '</div><!-- /wp:group -->'
			. '</div><!-- /wp:column --></div><!-- /wp:columns -->';

		$html = self::render_markup( $entry_id, $markup );

		$this->assertStringContainsString( '<time', $html );
		$this->assertStringNotContainsString( 'data-rc-relative', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-read-more', $html, 'No breakout: no Read more.' );

		$breakout_id = self::add_breakout( $entry_id, 'publish' );

		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Read more</a>', self::render_markup( $entry_id, $markup ) );
	}

	/**
	 * Markup a filter put before the paragraph, or a ">" in an attribute,
	 * doesn't move the link.
	 */
	public function test_read_more_link_sits_inside_the_paragraph_tag() {
		$entry_id    = self::create_entry( self::create_coverage() );
		$breakout_id = self::add_breakout( $entry_id, 'publish' );
		$url         = esc_url( get_permalink( $breakout_id ) );

		$filter = static function ( $content, $block ) {
			return 'core/paragraph' === $block['blockName'] ? '<span class="x">a > b</span>' . str_replace( '<p ', '<p title="a>b" ', $content ) : $content;
		};
		add_filter( 'render_block_core/paragraph', $filter, 5, 2 );
		$html = self::render_markup( $entry_id, self::read_more_paragraph() );
		remove_filter( 'render_block_core/paragraph', $filter, 5 );

		$this->assertStringContainsString( '<span class="x">a > b</span><p title="a>b" class="newspack-rolling-coverage-read-more wp-block-paragraph"><a href="' . $url . '">Read more</a></p>', $html );
	}

	/**
	 * A paragraph a filter appended after the "Read more" paragraph stays
	 * outside the link.
	 */
	public function test_read_more_link_ends_at_its_own_paragraph() {
		$entry_id    = self::create_entry( self::create_coverage() );
		$breakout_id = self::add_breakout( $entry_id, 'publish' );
		$url         = esc_url( get_permalink( $breakout_id ) );

		$filter = static function ( $content, $block ) {
			return 'core/paragraph' === $block['blockName'] ? $content . '<p>x</p>' : $content;
		};
		add_filter( 'render_block_core/paragraph', $filter, 5, 2 );
		$html = self::render_markup( $entry_id, self::read_more_paragraph() );
		remove_filter( 'render_block_core/paragraph', $filter, 5 );

		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-read-more wp-block-paragraph"><a href="' . $url . '">Read more</a></p><p>x</p>', $html );
	}

	/**
	 * A global post that isn't an entry never gets a link.
	 */
	public function test_read_more_ignores_a_global_post_that_is_not_an_entry() {
		$entry_id = self::create_entry( self::create_coverage() );
		self::add_breakout( $entry_id, 'publish' );
		$other_id = self::factory()->post->create();

		$filter = static function ( $content ) use ( $other_id ) {
			$GLOBALS['post'] = get_post( $other_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return $content;
		};
		add_filter( 'render_block_core/paragraph', $filter, 5 );
		$html = self::render_markup( $entry_id, self::read_more_paragraph() );
		remove_filter( 'render_block_core/paragraph', $filter, 5 );

		$this->assertStringNotContainsString( 'newspack-rolling-coverage-read-more', $html );
	}

	/**
	 * A link already inside the paragraph is not wrapped in another.
	 */
	public function test_read_more_paragraph_with_its_own_link_is_left_alone() {
		$entry_id = self::create_entry( self::create_coverage() );
		self::add_breakout( $entry_id, 'publish' );

		$html = self::render_markup( $entry_id, self::read_more_paragraph( 'newspack-rolling-coverage-read-more', '<a href="https://example.com/">Elsewhere</a>' ) );

		$this->assertStringContainsString( '<a href="https://example.com/">Elsewhere</a></p>', $html );
		$this->assertSame( 1, substr_count( $html, '<a ' ) );
	}

	/**
	 * A "Share" paragraph, as the editor saves it.
	 *
	 * @param string $classes Classes on the paragraph.
	 * @param string $text    Paragraph HTML.
	 * @return string Block markup.
	 */
	private static function share_paragraph( string $classes = 'newspack-rolling-coverage-share', string $text = 'Share' ): string {
		return '<!-- wp:paragraph {"className":"' . $classes . '"} --><p class="' . $classes . '">' . $text . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * A "Share" paragraph links to the entry, marked for the share script and
	 * named after the entry it shares, keeping its own text.
	 */
	public function test_share_paragraph_links_to_the_entry() {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_title' => 'Polls close at 8pm' ] );

		$html = self::render_markup( $entry_id, self::share_paragraph( 'foo newspack-rolling-coverage-share', '<strong>Share</strong> this' ) );

		$this->assertMatchesRegularExpression(
			'#<p class="foo newspack-rolling-coverage-share wp-block-paragraph"><a (?=[^>]*href="' . preg_quote( esc_url( get_permalink( $entry_id ) ), '#' ) . '")(?=[^>]*data-rc-share)(?=[^>]*role="button")(?=[^>]*aria-label="Share this: Polls close at 8pm")[^>]*><strong>Share</strong> this</a></p>#',
			$html
		);
	}

	/**
	 * An entry that can't be shared renders no "Share" paragraph.
	 */
	public function test_share_paragraph_is_removed_when_the_entry_cannot_be_shared() {
		$entry_id = self::create_entry( self::create_coverage() );
		wp_update_post(
			[
				'ID'          => $entry_id,
				'post_status' => 'draft',
			]
		);

		$this->assertStringNotContainsString( 'newspack-rolling-coverage-share', self::render_markup( $entry_id, self::share_paragraph() ) );
	}

	/**
	 * A "Share" paragraph outside an entry is left alone.
	 */
	public function test_share_paragraph_outside_entries_is_untouched() {
		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-share wp-block-paragraph">Share</p>', do_blocks( self::share_paragraph() ) );
	}

	/**
	 * A placeholder link in a "Read more" paragraph, as the template ships it so
	 * the editor shows a link, points at the published breakout.
	 */
	public function test_read_more_placeholder_link_points_at_the_breakout() {
		$entry_id    = self::create_entry( self::create_coverage() );
		$breakout_id = self::add_breakout( $entry_id, 'publish' );

		$html = self::render_markup( $entry_id, self::read_more_paragraph( 'newspack-rolling-coverage-read-more', '<a href="#">Read more</a>' ) );

		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-read-more wp-block-paragraph"><a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Read more</a></p>', $html );
	}

	/**
	 * A placeholder link in a "Share" paragraph becomes the entry's share link.
	 */
	public function test_share_placeholder_link_becomes_the_share_link() {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_title' => 'Polls close at 8pm' ] );

		$html = self::render_markup( $entry_id, self::share_paragraph( 'newspack-rolling-coverage-share', '<a href="#">Share</a>' ) );

		$this->assertMatchesRegularExpression(
			'#<p class="newspack-rolling-coverage-share wp-block-paragraph"><a (?=[^>]*href="' . preg_quote( esc_url( get_permalink( $entry_id ) ), '#' ) . '")(?=[^>]*data-rc-share)(?=[^>]*role="button")(?=[^>]*aria-label="Share: Polls close at 8pm")[^>]*>Share</a></p>#',
			$html
		);
		$this->assertSame( 1, substr_count( $html, '<a ' ) );
	}
}
