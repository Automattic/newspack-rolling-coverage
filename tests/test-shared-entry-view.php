<?php
/**
 * Tests for the feed that opens at a shared entry.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Latest_Label;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Social_Sharing;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * A link to an entry older than the first page opens the feed at that entry,
 * below the coverage's pinned entries; anything else keeps the normal feed.
 */
class Test_Shared_Entry_View extends Rolling_Coverage_TestCase {

	const CONTROL_CLASS = 'newspack-rolling-coverage-new-entries';

	/**
	 * An entry's blocks, as the editor saves them.
	 */
	const ENTRY_MARKUP = '<!-- wp:post-title /--><!-- wp:post-content /-->';

	/**
	 * Coverage term ID.
	 *
	 * @var int
	 */
	private $coverage_id;

	/**
	 * Host page ID.
	 *
	 * @var int
	 */
	private $page_id;

	/**
	 * Entry IDs keyed by slug, entry-1 (oldest) to entry-6.
	 *
	 * @var int[]
	 */
	private $entries = [];

	/**
	 * The request URI before the test.
	 *
	 * @var string|null
	 */
	private $request_uri;

	/**
	 * Slugs of the theme's color palette for the test.
	 *
	 * @var string[]
	 */
	private $palette = [ 'base', 'contrast' ];

	/**
	 * Build a coverage of six entries, one minute apart, and a host page.
	 */
	public function set_up() {
		parent::set_up();

		$this->coverage_id = self::create_coverage();

		for ( $i = 1; $i <= 6; $i++ ) {
			$this->entries[ 'entry-' . $i ] = self::create_entry(
				$this->coverage_id,
				[
					'post_date'  => sprintf( '2026-01-01 10:%02d:00', $i ),
					'post_name'  => 'entry-' . $i,
					'post_title' => 'Entry ' . $i,
				]
			);
		}

		$this->page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stored only to be restored.

		$this->go_to( get_permalink( $this->page_id ) );
		$this->use_page_as_current_post();

		add_filter( 'wp_theme_json_data_theme', [ $this, 'set_theme_palette' ] );
		wp_clean_theme_json_cache();
	}

	/**
	 * Give the theme the test's color palette.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme data.
	 * @return WP_Theme_JSON_Data
	 */
	public function set_theme_palette( $theme_json ) {
		return $theme_json->update_with(
			[
				'version'  => 3,
				'settings' => [
					'color' => [
						'palette' => array_map(
							fn( $slug ) => [
								'slug'  => $slug,
								'name'  => $slug,
								'color' => '#123456',
							],
							$this->palette
						),
					],
				],
			]
		);
	}

	/**
	 * Swap the theme's color palette for the rest of the test.
	 *
	 * @param string[] $slugs Palette slugs.
	 */
	private function use_palette( array $slugs ) {
		$this->palette = $slugs;
		wp_clean_theme_json_cache();
	}

	/**
	 * Make the host page the current post, as it is inside the page's own content.
	 */
	private function use_page_as_current_post() {
		$GLOBALS['post'] = get_post( $this->page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Makes the host page the current post.
		setup_postdata( $GLOBALS['post'] );
	}

	/**
	 * Clear the query var.
	 */
	public function tear_down() {
		set_query_var( Social_Sharing::ENTRY_QUERY_VAR, '' );

		remove_filter( 'wp_theme_json_data_theme', [ $this, 'set_theme_palette' ] );
		wp_clean_theme_json_cache();

		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}

		parent::tear_down();
	}

	/**
	 * Render the block with a shared entry in the query.
	 *
	 * @param string|array $slug        Value of the entry query var.
	 * @param int          $coverage_id Coverage to render; the fixture's when 0.
	 * @return string
	 */
	private function render_with_shared( $slug, int $coverage_id = 0 ): string {
		return $this->render_layout_with_shared( $slug, '', $coverage_id );
	}

	/**
	 * Render the block holding a saved layout, with a shared entry in the query.
	 *
	 * @param string|array $slug        Value of the entry query var.
	 * @param string       $layout      The block's inner blocks, as the editor saves them; the default layout when empty.
	 * @param int          $coverage_id Coverage to render; the fixture's when 0.
	 * @param array        $attributes  Further block attributes.
	 * @return string
	 */
	private function render_layout_with_shared( $slug, string $layout, int $coverage_id = 0, array $attributes = [] ): string {
		set_query_var( Social_Sharing::ENTRY_QUERY_VAR, $slug );

		$attributes = array_merge(
			[
				'coverageId'     => $coverage_id ? $coverage_id : $this->coverage_id,
				'entriesPerPage' => 2,
			],
			$attributes
		);
		$name       = 'wp:newspack-rolling-coverage/rolling-coverage';
		$parsed     = parse_blocks(
			'' === $layout
				? '<!-- ' . $name . ' ' . wp_json_encode( $attributes ) . ' /-->'
				: '<!-- ' . $name . ' ' . wp_json_encode( $attributes ) . ' -->' . $layout . '<!-- /' . $name . ' -->'
		)[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $parsed ) );
	}

	/**
	 * The control above the feed, as rendered: its wrapper and its link.
	 *
	 * @param string $html Rendered HTML.
	 * @return array {
	 *     @type int         $count   How many controls the HTML holds.
	 *     @type string      $tag     The wrapper's tag name.
	 *     @type string      $classes The wrapper's classes.
	 *     @type bool        $hidden  Whether the wrapper is hidden.
	 *     @type string      $link    The link's classes.
	 *     @type string|null $href    The link's URL.
	 *     @type string|null $style   The link's inline style.
	 *     @type string      $text    The link's text.
	 *     @type string|null $live    The wrapper's live feed URL.
	 *     @type int         $marked  How many links the HTML marks for the view script.
	 *     @type string|null $newer   The wrapper's count of newer entries.
	 *     @type string|null $own     The link's own text, kept when the count replaces it.
	 * }
	 */
	private function control( string $html ): array {
		$control = [
			'count'   => substr_count( $html, self::CONTROL_CLASS ),
			'tag'     => '',
			'classes' => '',
			'hidden'  => false,
			'link'    => '',
			'href'    => null,
			'style'   => null,
			'text'    => '',
			'live'    => null,
			'marked'  => substr_count( $html, Rolling_Coverage_Block::LATEST_ATTRIBUTE ),
			'newer'   => null,
			'own'     => null,
		];
		$tags    = new WP_HTML_Tag_Processor( $html );

		if ( ! $tags->next_tag( [ 'class_name' => self::CONTROL_CLASS ] ) ) {
			return $control;
		}

		$control['tag']     = $tags->get_tag();
		$control['classes'] = (string) $tags->get_attribute( 'class' );
		$control['hidden']  = null !== $tags->get_attribute( 'hidden' );
		$control['live']    = $tags->get_attribute( 'data-live-url' );
		$control['newer']   = $tags->get_attribute( 'data-newer-count' );

		while ( $tags->next_tag( 'a' ) ) {
			if ( null === $tags->get_attribute( Rolling_Coverage_Block::LATEST_ATTRIBUTE ) ) {
				continue;
			}

			$control['link']  = (string) $tags->get_attribute( 'class' );
			$control['href']  = $tags->get_attribute( 'href' );
			$control['style'] = $tags->get_attribute( 'style' );
			$control['own']   = $tags->get_attribute( 'data-label' );

			preg_match( '#<a\b[^>]*\b' . Rolling_Coverage_Block::LATEST_ATTRIBUTE . '\b[^>]*>([^<]*)</a>#', $html, $match );

			$control['text'] = html_entity_decode( $match[1] ?? '' );
			break;
		}

		return $control;
	}

	/**
	 * Entry IDs in the order the HTML lists them.
	 *
	 * @param string $html Rendered HTML.
	 * @return int[]
	 */
	private function entry_ids_in( string $html ): array {
		preg_match_all( '/data-entry-id="(\d+)"/', $html, $matches );

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * Entry IDs for slugs.
	 *
	 * @param string ...$slugs Entry slugs.
	 * @return int[]
	 */
	private function ids( string ...$slugs ): array {
		return array_map( fn( $slug ) => $this->entries[ $slug ], $slugs );
	}

	/**
	 * Value of a data attribute on the block wrapper.
	 *
	 * @param string $html Rendered HTML.
	 * @param string $name Attribute name without the data- prefix.
	 * @return string
	 */
	private function data_attribute( string $html, string $name ): string {
		preg_match( '/data-' . preg_quote( $name, '/' ) . '="([^"]*)"/', $html, $match );

		return html_entity_decode( $match[1] ?? '' );
	}

	/**
	 * A link to an entry the first page already shows changes nothing.
	 */
	public function test_recent_shared_entry_keeps_the_normal_view() {
		$html = $this->render_with_shared( 'entry-5' );

		$this->assertStringNotContainsString( 'data-view=', $html );
		$this->assertTrue( $this->control( $html )['hidden'] );
		$this->assertSame( $this->ids( 'entry-6', 'entry-5' ), $this->entry_ids_in( $html ) );
	}

	/**
	 * A link to an older entry starts the feed at that entry and offers a link back to the live feed.
	 */
	public function test_older_shared_entry_opens_the_feed_at_that_entry() {
		$html = $this->render_with_shared( 'entry-3' );

		$this->assertStringContainsString( 'data-view="entry"', $html );
		$this->assertSame( $this->ids( 'entry-3', 'entry-2' ), $this->entry_ids_in( $html ) );
		$this->assertStringContainsString( 'data-has-more="1"', $html );

		$control = $this->control( $html );

		$this->assertSame( 1, $control['count'] );
		$this->assertSame( 'DIV', $control['tag'] );
		$this->assertContains( 'wp-block-buttons', explode( ' ', $control['classes'] ) );
		$this->assertFalse( $control['hidden'] );
		$this->assertSame( get_permalink( $this->page_id ), $control['href'] );
		$this->assertSame( '3', $control['newer'] );
		$this->assertSame( '3 Newer Entries', $control['text'] );
		$this->assertSame( 'Jump to Latest', $control['own'], 'The link keeps its own text for the view script.' );
		$this->assertSame( 'wp-block-button__link wp-element-button', $control['link'] );
		$this->assertSame( 'box-shadow:var(--wp--preset--shadow--elevation-2)', $control['style'] );
		$this->assertSame( get_permalink( $this->page_id ), $control['live'], 'The wrapper carries the live feed URL for the view script.' );
		$this->assertSame( 1, $control['marked'], 'Only the link to the live feed is marked for the view script.' );
		$this->assertContains( 'is-layout-flex', explode( ' ', $control['classes'] ) );
		$this->assertContains( 'is-content-justification-center', explode( ' ', $control['classes'] ) );
	}

	/**
	 * The control takes the theme's button style alone: no palette color, whatever the palette holds.
	 *
	 * @dataProvider palettes
	 *
	 * @param string[] $slugs The theme palette's slugs.
	 */
	public function test_control_takes_no_palette_colors( array $slugs ) {
		$this->use_palette( $slugs );

		foreach ( [ 'entry-3', '' ] as $shared ) {
			$this->assertSame( 'wp-block-button__link wp-element-button', $this->control( $this->render_with_shared( $shared ) )['link'] );
		}
	}

	/**
	 * Theme palettes, block theme and Newspack Theme style.
	 *
	 * @return array[]
	 */
	public static function palettes(): array {
		return [
			'contrast and base'        => [ [ 'accent', 'base', 'contrast' ] ],
			'dark gray and white only' => [ [ 'primary', 'dark-gray', 'medium-gray', 'white' ] ],
		];
	}

	/**
	 * The control reads the site's label in both views; the shared view keeps it on the link when the count replaces it.
	 */
	public function test_control_shows_the_site_label() {
		update_option( Latest_Label::OPTION_KEY, 'Back to live' );

		$normal = $this->control( $this->render_with_shared( '' ) );
		$shared = $this->control( $this->render_with_shared( 'entry-3' ) );

		$this->assertSame( 'Back to live', $normal['text'] );
		$this->assertNull( $normal['own'] );
		$this->assertSame( '3 Newer Entries', $shared['text'] );
		$this->assertSame( 'Back to live', $shared['own'] );
	}

	/**
	 * A blank saved label falls back to "Jump to Latest".
	 */
	public function test_blank_site_label_falls_back_to_the_default() {
		update_option( Latest_Label::OPTION_KEY, '   ' );

		$this->assertSame( 'Jump to Latest', $this->control( $this->render_with_shared( '' ) )['text'] );
		$this->assertSame( 'Jump to Latest', $this->control( $this->render_with_shared( 'entry-3' ) )['own'] );
	}

	/**
	 * The site's label is escaped in the link and in the label kept for the view script.
	 */
	public function test_site_label_is_escaped() {
		update_option( Latest_Label::OPTION_KEY, 'Q < A & "B"' );

		$normal = $this->render_with_shared( '' );

		$this->assertStringContainsString( '>Q &lt; A &amp; &quot;B&quot;</a>', $normal );
		$this->assertSame( 'Q < A & "B"', $this->control( $normal )['text'] );
		$this->assertSame( 'Q < A & "B"', $this->control( $this->render_with_shared( 'entry-3' ) )['own'] );
	}

	/**
	 * The normal view holds the same control, hidden until new entries wait, linking to the page itself.
	 */
	public function test_normal_view_holds_the_same_control_hidden() {
		$shared = $this->control( $this->render_with_shared( 'entry-3' ) );
		$normal = $this->control( $this->render_with_shared( '' ) );

		$this->assertTrue( $normal['hidden'] );
		$this->assertSame( 1, $normal['count'] );
		$this->assertSame( get_permalink( $this->page_id ), $normal['href'] );
		$this->assertSame( 'Jump to Latest', $normal['text'] );
		$this->assertNull( $normal['newer'], 'Only the shared view counts newer entries.' );
		$this->assertNull( $normal['own'] );
		$this->assertSame(
			$shared,
			array_merge(
				$normal,
				[
					'hidden' => false,
					'newer'  => '3',
					'text'   => '3 Newer Entries',
					'own'    => 'Jump to Latest',
				]
			),
			'Both views render the one control; only its visibility and the shared view\'s count differ.'
		);
	}

	/**
	 * In a layout whose items sit in the Feed group, the control renders inside the Feed and never inside an entry.
	 */
	public function test_control_renders_inside_the_feed() {
		$layout = '<!-- wp:group {"className":"newspack-rolling-coverage-feed","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. self::ENTRY_MARKUP
			. '</div><!-- /wp:group -->';
		$html   = $this->render_layout_with_shared( 'entry-3', $layout );
		$shared = $this->control( $html );

		$this->assertSame( 1, $shared['count'] );
		$this->assertSame( 'Jump to Latest', $shared['own'] );
		$this->assertNotNull( $shared['live'] );
		$this->assertMatchesRegularExpression( '/newspack-rolling-coverage-feed[^>]*>.*' . self::CONTROL_CLASS . '/s', $html, 'The control renders inside the Feed.' );

		preg_match_all( '/<article .*?<\/article>/s', $html, $articles );

		$this->assertNotEmpty( $articles[0] );
		$this->assertStringNotContainsString( Rolling_Coverage_Block::LATEST_ATTRIBUTE, implode( '', $articles[0] ), 'No entry holds the control.' );

		$wrapper = new WP_HTML_Tag_Processor( $html );
		$wrapper->next_tag();

		$this->assertSame( '--newspack-rolling-coverage-gap:var(--wp--preset--spacing--40)', $wrapper->get_attribute( 'style' ) );
	}

	/**
	 * Without a request URI, as under WP-CLI or cron, the control links to the host post and nothing warns.
	 */
	public function test_control_links_to_the_host_post_without_a_request_uri() {
		$this->go_to( home_url( '/' ) );
		$this->use_page_as_current_post();

		unset( $_SERVER['REQUEST_URI'] );

		$this->assertNull( get_queried_object() );

		foreach ( [ 'entry-3', '' ] as $shared ) {
			$control = $this->control( $this->render_with_shared( $shared ) );

			$this->assertSame( get_permalink( $this->page_id ), $control['href'] );
			$this->assertSame( get_permalink( $this->page_id ), $control['live'] );
		}
	}

	/**
	 * With no host post and no page request, the live feed URL is the site's front page.
	 */
	public function test_live_feed_url_is_the_front_page_without_a_host_post_or_a_page_request() {
		unset( $_SERVER['REQUEST_URI'] );

		$this->assertSame( 0, Rolling_Coverage_Block::get_host_post_id() );
		$this->assertSame( home_url( '/' ), Rolling_Coverage_Block::live_feed_url() );
	}

	/**
	 * In wp-admin, where the request is not a page's, the control links to the host post.
	 */
	public function test_control_links_to_the_host_post_in_an_admin_request() {
		$this->go_to( home_url( '/' ) );
		$this->use_page_as_current_post();

		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?rolling-coverage-entry=entry-3';

		set_current_screen( 'dashboard' );

		try {
			$this->assertTrue( is_admin() );

			$control = $this->control( $this->render_with_shared( 'entry-3' ) );
		} finally {
			unset( $GLOBALS['current_screen'] );
		}

		$this->assertSame( get_permalink( $this->page_id ), $control['href'] );
		$this->assertSame( get_permalink( $this->page_id ), $control['live'] );
	}

	/**
	 * A coverage block inside an entry's content renders no control.
	 */
	public function test_block_nested_in_an_entry_has_no_control() {
		$html = Rolling_Coverage_Block::render_as_entry( fn() => $this->render_with_shared( '' ) );

		$this->assertNotEmpty( $this->entry_ids_in( $html ) );
		$this->assertSame( 0, $this->control( $html )['count'] );
		$this->assertSame( 1, $this->control( $this->render_with_shared( '' ) )['count'] );
	}

	/**
	 * The default layout's pinned card and entries render with square corners.
	 */
	public function test_default_entries_render_without_a_border_radius() {
		Post_Type::pin_entry( $this->entries['entry-6'] );
		$html = $this->render_with_shared( '' );

		$this->assertStringContainsString( 'newspack-rolling-coverage-pinned-card', $html );
		$this->assertStringContainsString( 'newspack-rolling-coverage-regular-entry', $html );
		$this->assertDoesNotMatchRegularExpression( '/class="wp-block-group newspack-rolling-coverage-(?:regular-entry|pinned-card)[^"]*"[^>]*border-radius/', $html );
	}

	/**
	 * Without an enabled ad placement the block is not flagged as showing ads.
	 */
	public function test_block_without_an_ad_placement_is_not_flagged() {
		$this->assertStringNotContainsString( 'data-ads=', $this->render_with_shared( 'entry-3' ) );
	}

	/**
	 * A feed holds no floating control.
	 */
	public function test_feed_render_has_no_control() {
		$this->go_to( get_feed_link() );
		$this->use_page_as_current_post();

		$this->assertTrue( is_feed() );

		foreach ( [ 'entry-3', '' ] as $shared ) {
			$html = $this->render_with_shared( $shared );

			$this->assertSame( 0, $this->control( $html )['count'] );
			$this->assertNotEmpty( $this->entry_ids_in( $html ) );
		}
	}

	/**
	 * A shared entry with exactly one newer entry counts it in the singular.
	 */
	public function test_single_newer_entry_is_counted_in_the_singular() {
		Post_Type::pin_entry( $this->entries['entry-1'] );

		$html    = $this->render_with_shared( 'entry-5' );
		$control = $this->control( $html );

		$this->assertStringContainsString( 'data-view="entry"', $html );
		$this->assertSame( '1', $control['newer'] );
		$this->assertSame( '1 Newer Entry', $control['text'] );
	}

	/**
	 * Pinned entries are already on the page, so they are not counted as newer posts.
	 */
	public function test_pinned_newer_entries_are_not_counted() {
		Post_Type::pin_entry( $this->entries['entry-4'] );

		$this->assertSame( '2', $this->control( $this->render_with_shared( 'entry-3' ) )['newer'] );
	}

	/**
	 * The count stops at the cap, one past a hundred, which reads as more than 100, and a newer pinned entry does not take a place under it.
	 */
	public function test_newer_count_is_capped() {
		$newer = self::factory()->post->create_many(
			Rolling_Coverage_Block::NEWER_COUNT_CAP + 1,
			[
				'post_type'   => Post_Type::CPT_SLUG,
				'post_status' => 'publish',
				'post_date'   => '2026-01-02 10:00:00',
			]
		);

		foreach ( $newer as $entry_id ) {
			wp_set_object_terms( $entry_id, [ $this->coverage_id ], Taxonomy::TAXONOMY_SLUG );
		}

		Post_Type::pin_entry( self::create_entry( $this->coverage_id, [ 'post_date' => '2026-01-03 10:00:00' ] ) );

		$control = $this->control( $this->render_with_shared( 'entry-6' ) );

		$this->assertSame( 101, Rolling_Coverage_Block::NEWER_COUNT_CAP );
		$this->assertSame( '101', $control['newer'], 'One past a hundred stands for "more than 100".' );
		$this->assertSame( '100+ Newer Entries', $control['text'] );
	}

	/**
	 * The label is exact up to ten; from there "N+" reads as more than N.
	 *
	 * @dataProvider newer_entries_labels
	 *
	 * @param int    $count How many entries are newer.
	 * @param string $label The label the control shows.
	 */
	public function test_newer_entries_label_is_bucketed( int $count, string $label ) {
		$this->assertSame( $label, Rolling_Coverage_Block::newer_entries_label( $count ) );
	}

	/**
	 * Counts and their labels; none means the control keeps its own text.
	 *
	 * @return array[]
	 */
	public static function newer_entries_labels(): array {
		return [
			[ 0, '' ],
			[ 1, '1 Newer Entry' ],
			[ 9, '9 Newer Entries' ],
			[ 10, '10 Newer Entries' ],
			[ 11, '10+ Newer Entries' ],
			[ 50, '10+ Newer Entries' ],
			[ 51, '50+ Newer Entries' ],
			[ 100, '50+ Newer Entries' ],
			[ 101, '100+ Newer Entries' ],
			[ 250, '100+ Newer Entries' ],
		];
	}

	/**
	 * Entry IDs the HTML marks as the linked entry.
	 *
	 * @param string $html Rendered HTML.
	 * @return int[]
	 */
	private function linked_ids( string $html ): array {
		preg_match_all( '/<article\b[^>]*\sdata-entry-id="(\d+)"[^>]*\sdata-linked[\s>]/', $html, $matches );

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * The entry a link names is marked, in the shared view and in the normal view, pinned or not; no other entry is.
	 */
	public function test_linked_entry_is_marked() {
		$this->assertSame( $this->ids( 'entry-3' ), $this->linked_ids( $this->render_with_shared( 'entry-3' ) ), 'Shared view.' );
		$this->assertSame( $this->ids( 'entry-5' ), $this->linked_ids( $this->render_with_shared( 'entry-5' ) ), 'Normal view.' );
		$this->assertSame( [], $this->linked_ids( $this->render_with_shared( '' ) ), 'No link.' );

		Post_Type::pin_entry( $this->entries['entry-2'] );

		$html = $this->render_with_shared( 'entry-2' );

		$this->assertStringNotContainsString( 'data-view=', $html );
		$this->assertSame( $this->ids( 'entry-2' ), $this->linked_ids( $html ), 'Normal view, pinned.' );
	}

	/**
	 * A capped feed ignores a link to an entry: it shows its newest entries,
	 * marks none as linked and offers no way back to the live feed.
	 */
	public function test_capped_feed_ignores_a_shared_entry() {
		$capped = [
			'latestOnly'  => true,
			'latestCount' => 2,
		];

		foreach ( [ 'entry-3', 'entry-5' ] as $shared ) {
			$html = $this->render_layout_with_shared( $shared, '', 0, $capped );

			$this->assertStringNotContainsString( 'data-view=', $html, $shared );
			$this->assertSame( $this->ids( 'entry-6', 'entry-5' ), $this->entry_ids_in( $html ), $shared );
			$this->assertSame( [], $this->linked_ids( $html ), $shared );
			$this->assertSame( 0, $this->control( $html )['count'], $shared );
		}
	}

	/**
	 * The entry the page's link names for a coverage, as the block resolves it.
	 *
	 * @param string|array $slug        Value of the entry query var.
	 * @param int          $coverage_id Coverage term ID.
	 * @return WP_Post|null
	 */
	private function linked_entry_for( $slug, int $coverage_id ): ?WP_Post {
		set_query_var( Social_Sharing::ENTRY_QUERY_VAR, $slug );

		$resolve = new ReflectionMethod( Rolling_Coverage_Block::class, 'get_linked_entry' );
		$resolve->setAccessible( true );

		return $resolve->invoke( null, $coverage_id );
	}

	/**
	 * A link names an entry for a coverage only when the entry is published and in that coverage.
	 */
	public function test_entries_outside_the_coverage_are_not_linked() {
		$other_coverage = self::create_coverage();
		$elsewhere      = self::create_entry(
			$other_coverage,
			[
				'post_date' => '2026-01-01 09:00:00',
				'post_name' => 'elsewhere',
			]
		);

		$this->assertSame( $elsewhere, $this->linked_entry_for( 'elsewhere', $other_coverage )->ID, 'The entry is linked for its own coverage.' );
		$this->assertNull( $this->linked_entry_for( 'elsewhere', $this->coverage_id ), 'An entry of another coverage.' );

		$this->assertSame( $this->entries['entry-5'], $this->linked_entry_for( 'entry-5', $this->coverage_id )->ID );
		$this->assertSame( [], $this->linked_ids( $this->render_with_shared( [ 'entry-5' ] ) ), 'A value that is not a string.' );

		wp_update_post(
			[
				'ID'          => $this->entries['entry-5'],
				'post_status' => 'draft',
			]
		);

		$this->assertNull( $this->linked_entry_for( 'entry-5', $this->coverage_id ), 'An unpublished entry.' );
	}

	/**
	 * A pinned entry in the shared view's date range shows once, at the top, and the page below it stays full.
	 */
	public function test_shared_view_shows_a_pinned_entry_once_at_the_top() {
		Post_Type::pin_entry( $this->entries['entry-2'] );

		$html = $this->render_with_shared( 'entry-3' );

		$this->assertSame( $this->ids( 'entry-2', 'entry-3', 'entry-1' ), $this->entry_ids_in( $html ) );
		$this->assertStringContainsString( 'data-has-more="0"', $html );
	}

	/**
	 * A pinned entry at the top does not hide the older entries that remain to load.
	 */
	public function test_shared_view_keeps_more_to_load_below_pinned_entries() {
		Post_Type::pin_entry( $this->entries['entry-3'] );

		$html = $this->render_with_shared( 'entry-4' );

		$this->assertSame( $this->ids( 'entry-3', 'entry-4', 'entry-2' ), $this->entry_ids_in( $html ) );
		$this->assertStringContainsString( 'data-has-more="1"', $html );
		$this->assertSame( get_post( $this->entries['entry-2'] )->post_date_gmt, $this->data_attribute( $html, 'before' ) );
	}

	/**
	 * The shared view opens below the coverage's pinned entries, in the live feed's order, and leaves out entries pinned in other coverages.
	 */
	public function test_shared_view_shows_pinned_entries_first() {
		$elsewhere = self::create_entry(
			self::create_coverage(),
			[
				'post_date' => '2026-01-01 11:00:00',
				'post_name' => 'elsewhere',
			]
		);

		Post_Type::pin_entry( $this->entries['entry-5'] );
		Post_Type::pin_entry( $elsewhere );
		Post_Type::pin_entry( $this->entries['entry-6'] );

		$html = $this->render_with_shared( 'entry-2' );

		$this->assertStringContainsString( 'data-view="entry"', $html );
		$this->assertSame( $this->ids( 'entry-5', 'entry-6', 'entry-2', 'entry-1' ), $this->entry_ids_in( $html ) );
		$this->assertSame( $this->ids( 'entry-5', 'entry-6' ), $this->entry_ids_in( $this->render_with_shared( '' ) ), 'The live feed.' );
		$this->assertSame( $this->ids( 'entry-2' ), $this->linked_ids( $html ) );
	}

	/**
	 * The oldest entry opens alone, with nothing more to load and no closing separator.
	 */
	public function test_oldest_shared_entry_is_shown_alone() {
		$html = $this->render_with_shared( 'entry-1' );

		$this->assertSame( $this->ids( 'entry-1' ), $this->entry_ids_in( $html ) );
		$this->assertStringContainsString( 'data-has-more="0"', $html );

		$entries_html = substr( $html, (int) strpos( $html, 'newspack-rolling-coverage-entries' ) );

		$this->assertStringNotContainsString( 'wp-block-separator', $entries_html );
	}

	/**
	 * A feed set not to load older entries opens at a shared entry with
	 * nothing more to load, and neither the sentinel nor the button.
	 */
	public function test_shared_view_of_a_feed_that_loads_no_older_entries_has_nothing_more_to_load() {
		$html = $this->render_layout_with_shared( 'entry-3', '', 0, [ 'olderEntries' => 'none' ] );

		$this->assertStringContainsString( 'data-view="entry"', $html );
		$this->assertStringContainsString( 'data-has-more="0"', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-sentinel', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-load-more', $html );
	}

	/**
	 * Links that cannot open the feed at an entry leave the normal feed.
	 *
	 * @dataProvider unusable_shared_entries
	 *
	 * @param string $case Fixture to set up.
	 */
	public function test_unusable_shared_entries_keep_the_normal_view( string $case ) {
		$shared = 'entry-3';

		switch ( $case ) {
			case 'unknown':
				$shared = 'no-such-entry';
				break;
			case 'draft':
				wp_update_post(
					[
						'ID'          => $this->entries['entry-3'],
						'post_status' => 'draft',
					]
				);
				break;
			case 'other coverage':
				$other = self::create_entry(
					self::create_coverage(),
					[
						'post_date' => '2026-01-01 09:00:00',
						'post_name' => 'elsewhere',
					]
				);
				$this->assertGreaterThan( 0, $other );
				$shared = 'elsewhere';
				break;
			case 'pinned':
				foreach ( [ 'entry-1', 'entry-2', 'entry-3' ] as $slug ) {
					Post_Type::pin_entry( $this->entries[ $slug ] );
				}
				$shared = 'entry-3';
				break;
			case 'array':
				$shared = [ 'entry-3' ];
				break;
		}

		$html = $this->render_with_shared( $shared );

		$this->assertStringNotContainsString( 'data-view=', $html );
		$this->assertTrue( $this->control( $html )['hidden'] );
	}

	/**
	 * Fixture names for the unusable shared entries.
	 *
	 * @return array[]
	 */
	public static function unusable_shared_entries(): array {
		return [
			'unknown slug'              => [ 'unknown' ],
			'draft entry'               => [ 'draft' ],
			'entry of another coverage' => [ 'other coverage' ],
			'pinned older entry'        => [ 'pinned' ],
			'array value'               => [ 'array' ],
		];
	}

	/**
	 * The shared view polls from the newest entry of the coverage, so entries older than the page's start are not reported as new.
	 */
	public function test_shared_view_polls_from_the_coverage_not_the_page() {
		$html   = $this->render_with_shared( 'entry-2' );
		$cursor = $this->data_attribute( $html, 'cursor' );
		$latest = get_post( $this->entries['entry-6'] );

		$this->assertSame( $latest->ID . ':' . $latest->post_modified_gmt, $cursor );

		$params = [
			'cursor'       => $cursor,
			'template_key' => $this->data_attribute( $html, 'template-key' ),
		];
		$path   = '/coverages/' . $this->coverage_id . '/entries';

		$this->assertSame( [], self::dispatch( 'GET', $path, $params )->get_data()['entries'] );

		self::create_entry(
			$this->coverage_id,
			[
				'post_date'  => current_time( 'mysql' ),
				'post_name'  => 'entry-7',
				'post_title' => 'Entry 7',
			]
		);

		$entries = self::dispatch( 'GET', $path, $params )->get_data()['entries'];

		$this->assertCount( 1, $entries );
		$this->assertSame( 'insert', $entries[0]['type'] );
	}

	/**
	 * Load more can leave pinned entries out, and keeps them by default.
	 */
	public function test_load_more_can_leave_pinned_entries_out() {
		Post_Type::pin_entry( $this->entries['entry-2'] );

		$html   = $this->render_with_shared( 'entry-3' );
		$params = [
			'before'       => get_post( $this->entries['entry-4'] )->post_date_gmt,
			'per_page'     => 2,
			'template_key' => $this->data_attribute( $html, 'template-key' ),
		];
		$path   = '/coverages/' . $this->coverage_id . '/entries';

		$data = self::dispatch( 'GET', $path, array_merge( $params, [ 'skip_pinned' => true ] ) )->get_data();

		$this->assertSame( $this->ids( 'entry-3', 'entry-1' ), $this->entry_ids_in( $data['html'] ) );
		$this->assertSame( 2, $data['count'] );
		$this->assertFalse( $data['hasMore'] );

		$data = self::dispatch( 'GET', $path, $params )->get_data();

		$this->assertSame( $this->ids( 'entry-3', 'entry-2' ), $this->entry_ids_in( $data['html'] ) );
	}

	/**
	 * The link back to the live feed is the current URL without the entry, when the block is not on its host post's own page.
	 */
	public function test_pill_link_falls_back_to_the_current_url_outside_a_singular_page() {
		$this->go_to( home_url( '/' ) );
		$this->use_page_as_current_post();

		$_SERVER['REQUEST_URI'] = '/some-archive/?rolling-coverage-entry=entry-3&x=1';

		$html = $this->render_with_shared( 'entry-3' );

		$this->assertStringContainsString( 'data-view="entry"', $html );
		$this->assertSame( '/some-archive/?x=1', $this->pill_href( $html ) );
	}

	/**
	 * The fallback link stays on this site even when the request path starts with two slashes.
	 */
	public function test_pill_link_fallback_is_never_protocol_relative() {
		$this->go_to( home_url( '/' ) );
		$this->use_page_as_current_post();

		$_SERVER['REQUEST_URI'] = '//evil.example/?rolling-coverage-entry=entry-3';

		$href = $this->pill_href( $this->render_with_shared( 'entry-3' ) );

		$this->assertMatchesRegularExpression( '#^/[^/]#', $href );

		$_SERVER['REQUEST_URI'] = '/%0a/evil.example/?rolling-coverage-entry=entry-3';

		$href = $this->pill_href( $this->render_with_shared( 'entry-3' ) );

		$this->assertMatchesRegularExpression( '#^/[^/]#', $href );
	}

	/**
	 * A load-more bound that is not a real datetime is handled like any other value, without a date query error.
	 *
	 * @dataProvider invalid_datetime_bounds
	 *
	 * @param string $before Client-supplied bound.
	 */
	public function test_load_more_tolerates_bounds_that_are_not_datetimes( string $before ) {
		$html     = $this->render_with_shared( 'entry-2' );
		$response = self::dispatch(
			'GET',
			'/coverages/' . $this->coverage_id . '/entries',
			[
				'before'       => $before,
				'per_page'     => 2,
				'template_key' => $this->data_attribute( $html, 'template-key' ),
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsString( $response->get_data()['html'] );
	}

	/**
	 * Bounds that look like a datetime but are not one.
	 *
	 * @return array[]
	 */
	public static function invalid_datetime_bounds(): array {
		return [
			'month, day, and time out of range' => [ '2026-13-45 99:99:99' ],
			'day past the end of February'      => [ '2026-02-30 10:00:00' ],
		];
	}

	/**
	 * The href of the control's link to the live feed.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	private function pill_href( string $html ): string {
		return (string) $this->control( $html )['href'];
	}

	/**
	 * Load more with skip_pinned sent as the script sends it, the string "1", leaves pinned entries out and reports what remains.
	 */
	public function test_load_more_accepts_skip_pinned_as_a_string() {
		Post_Type::pin_entry( $this->entries['entry-4'] );

		$html = $this->render_with_shared( 'entry-2' );
		$data = self::dispatch(
			'GET',
			'/coverages/' . $this->coverage_id . '/entries',
			[
				'before'       => get_post( $this->entries['entry-6'] )->post_date_gmt,
				'per_page'     => 2,
				'template_key' => $this->data_attribute( $html, 'template-key' ),
				'skip_pinned'  => '1',
			]
		)->get_data();

		$this->assertSame( $this->ids( 'entry-5', 'entry-3' ), $this->entry_ids_in( $data['html'] ) );
		$this->assertTrue( $data['hasMore'] );
	}

	/**
	 * An archived coverage opens at the shared entry like an active one.
	 */
	public function test_archived_coverage_opens_at_the_shared_entry() {
		update_term_meta( $this->coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$html = $this->render_with_shared( 'entry-3' );

		$this->assertStringContainsString( 'data-view="entry"', $html );
		$this->assertSame( $this->ids( 'entry-3', 'entry-2' ), $this->entry_ids_in( $html ) );
		$this->assertFalse( $this->control( $html )['hidden'] );
		$this->assertSame( get_permalink( $this->page_id ), $this->control( $html )['href'] );
	}

	/**
	 * GMT bounds are compared as they are, even when they fall in the site timezone's skipped spring-forward hour.
	 */
	public function test_gmt_bounds_ignore_the_site_timezone() {
		update_option( 'timezone_string', 'America/New_York' );

		$coverage_id = self::create_coverage();
		$dst         = [];

		foreach ( [ '02:10:00', '02:20:00', '02:30:00', '02:40:00', '02:50:00', '03:20:00' ] as $time ) {
			$gmt          = '2026-03-08 ' . $time;
			$dst[ $time ] = self::create_entry(
				$coverage_id,
				[
					'post_date_gmt' => $gmt,
					'post_date'     => get_date_from_gmt( $gmt ),
					'post_name'     => 'dst-' . $time,
				]
			);

			$this->assertSame( $gmt, get_post( $dst[ $time ] )->post_date_gmt );
		}

		$html = $this->render_with_shared( 'dst-02:30:00', $coverage_id );

		$this->assertSame( [ $dst['02:30:00'], $dst['02:20:00'] ], $this->entry_ids_in( $html ) );

		$data = self::dispatch(
			'GET',
			'/coverages/' . $coverage_id . '/entries',
			[
				'before'       => '2026-03-08 02:30:00',
				'per_page'     => 2,
				'template_key' => $this->data_attribute( $html, 'template-key' ),
			]
		)->get_data();

		$this->assertSame( [ $dst['02:20:00'], $dst['02:10:00'] ], $this->entry_ids_in( $data['html'] ) );
	}
}
