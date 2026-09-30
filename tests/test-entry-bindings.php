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
	 * @return int Breakout post ID.
	 */
	private static function add_breakout( int $entry_id, string $status ): int {
		$breakout_id = self::factory()->post->create( [ 'post_status' => $status ] );
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
		. '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"newspack-rolling-coverage/entry","args":{"key":"pinnedLabel"}}}},"fontSize":"small"} --><p class="has-small-font-size"></p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->'
		. '<!-- wp:paragraph --><p>Entry body</p><!-- /wp:paragraph -->';

	/**
	 * Only pinned entries show the pinned row, labelled with the block's
	 * label or the default, and are marked for the theme.
	 */
	public function test_pinned_row_shows_only_on_pinned_entries() {
		$coverage_id = self::create_coverage();
		$pinned_id   = self::create_entry( $coverage_id );
		$other_id    = self::create_entry( $coverage_id );
		$template    = parse_blocks( self::PINNED_ROW_MARKUP );

		Post_Type::pin_entry( $pinned_id );

		$pinned = Rolling_Coverage_Block::render_entry( get_post( $pinned_id ), $template );
		$other  = Rolling_Coverage_Block::render_entry( get_post( $other_id ), $template );

		$this->assertStringContainsString( '>Pinned</p>', $pinned, 'A pinned entry should show the default label.' );
		$this->assertStringContainsString( 'data-pinned', $pinned, 'A pinned entry should be marked.' );
		$this->assertStringNotContainsString( 'wp-block-group', $other, 'An unpinned entry should have no pinned row.' );
		$this->assertStringNotContainsString( 'data-pinned', $other, 'An unpinned entry should not be marked.' );
		$this->assertStringContainsString( 'Entry body', $other, 'The rest of the template should still render.' );
		$this->assertStringContainsString( '>Top story</p>', Rolling_Coverage_Block::render_entry( get_post( $pinned_id ), $template, 'initial', 'Top story' ), "The block's label should win." );
	}

	/**
	 * A pinned label moved out of its row still shows only on pinned entries.
	 */
	public function test_pinned_label_outside_a_row_is_hidden_on_unpinned_entries() {
		$template = parse_blocks( '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"newspack-rolling-coverage/entry","args":{"key":"pinnedLabel"}}}}} --><p>Saved text</p><!-- /wp:paragraph -->' );

		$this->assertStringNotContainsString( 'Saved text', Rolling_Coverage_Block::render_entry( get_post( self::create_entry( self::create_coverage() ) ), $template ) );
	}

	/**
	 * A group holding the pinned label alongside other blocks keeps rendering
	 * them on unpinned entries; only the label goes.
	 */
	public function test_group_holding_the_label_and_other_blocks_keeps_them() {
		$template = parse_blocks(
			'<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"newspack-rolling-coverage/entry","args":{"key":"pinnedLabel"}}}}} --><p>Saved text</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>Entry byline</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
		);
		$html     = Rolling_Coverage_Block::render_entry( get_post( self::create_entry( self::create_coverage() ) ), $template );

		$this->assertStringContainsString( 'Entry byline', $html, 'The group and its other blocks should render.' );
		$this->assertStringNotContainsString( 'Saved text', $html, 'The label should not.' );
	}

	/**
	 * Entries are core flow layouts spaced by the block's Block spacing,
	 * with `spacing-20` when it's unset.
	 *
	 * @dataProvider data_block_spacing
	 *
	 * @param array  $style    The block's style attribute.
	 * @param string $expected The space between an entry's blocks.
	 */
	public function test_block_spacing_lays_out_entries( array $style, string $expected ) {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$attributes = array_filter(
			[
				'coverageId' => $coverage_id,
				'style'      => $style,
			]
		);
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];
		$html       = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		$this->assertMatchesRegularExpression( '/<article [^>]*class="[^"]*is-layout-flow[^"]*(newspack-rolling-coverage-entry-layout-[0-9a-f]+)/', $html );
		preg_match( '/(newspack-rolling-coverage-entry-layout-[0-9a-f]+)/', $html, $matches );

		$this->assertStringContainsString(
			'.' . $matches[1] . ' > * + *{margin-block-start:' . $expected,
			wp_style_engine_get_stylesheet_from_context( 'block-supports', [ 'prettify' => false ] )
		);
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
			'unset'  => [ [], 'var(--wp--preset--spacing--20)' ],
			'preset' => [ [ 'spacing' => [ 'blockGap' => 'var:preset|spacing|30' ] ], 'var(--wp--preset--spacing--30)' ],
		];
	}

	/**
	 * Entries loaded after the first render keep the block's pinned label
	 * and the entries' layout.
	 */
	public function test_load_more_keeps_the_block_pinned_label() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		Post_Type::pin_entry( $entry_id );

		$attributes = [
			'coverageId'  => $coverage_id,
			'pinnedLabel' => 'Top story',
		];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . self::PINNED_ROW_MARKUP . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];
		$html       = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		$this->assertMatchesRegularExpression( '/data-template-key="([^"]+)"/', $html );
		preg_match( '/data-template-key="([^"]+)"/', $html, $matches );

		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'term_id', $coverage_id );
		$request->set_param( 'template_key', $matches[1] );
		$request->set_param( 'before', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );

		$more = Rolling_Coverage_Block::get_entries( $request )->get_data()['html'];

		$this->assertStringContainsString( '>Top story</p>', $more, 'The pinned label should carry over.' );

		preg_match( '/newspack-rolling-coverage-entry-layout-[0-9a-f]+/', $html, $layout );
		$this->assertMatchesRegularExpression( '/class="[^"]*is-layout-flow ' . $layout[0] . '/', $more, 'The entries layout should carry over.' );
	}
}
