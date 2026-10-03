<?php
/**
 * Tests for the Split layout.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * The full feed in a grid, the first pinned entry's summary down the first
 * column and the other entries across the next two, each entry's article
 * placed where its group says.
 */
class Test_Split extends Rolling_Coverage_TestCase {

	const STACKED_PLACEMENT = '{"layout":{"columnStart":1,"columnSpan":1}}';

	const CARD_PLACEMENT = '"layout":{"columnStart":1,"columnSpan":1,"rowSpan":100},"@tablet":{"layout":{"columnStart":1,"columnSpan":1,"rowSpan":1}},"@mobile":{"layout":{"columnStart":1,"columnSpan":1,"rowSpan":1}},';

	const ENTRY_PLACEMENT = '"layout":{"columnStart":2,"columnSpan":2},"@tablet":' . self::STACKED_PLACEMENT . ',"@mobile":' . self::STACKED_PLACEMENT . ',';

	const CARD_MARKUP = '<!-- wp:group {"className":"newspack-rolling-coverage-pinned-card","style":{' . self::CARD_PLACEMENT . '"border":{"top":{"color":"#111","width":"3px","style":"solid"}},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|50"}},"position":{"type":"sticky","top":"0px"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->'
		. '<div class="wp-block-group newspack-rolling-coverage-pinned-card" style="border-top-color:#111;border-top-style:solid;border-top-width:3px;padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--50)">'
		. '<!-- wp:post-title {"level":4} /-->'
		. '</div><!-- /wp:group -->';

	const ENTRY_MARKUP = '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry","style":{' . self::ENTRY_PLACEMENT . '"border":{"top":{"color":"#ddd","width":"1px","style":"solid"}},"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40"}}}} -->'
		. '<div class="wp-block-group newspack-rolling-coverage-regular-entry" style="border-top-color:#ddd;border-top-style:solid;border-top-width:1px;padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40)">'
		. '<!-- wp:post-title {"level":4} /-->'
		. '</div><!-- /wp:group -->';

	/**
	 * Forget the theme.json data a test changed.
	 */
	public function tear_down() {
		remove_filter( 'wp_theme_json_data_theme', [ $this, 'with_sticky_support' ] );
		wp_clean_theme_json_cache();

		parent::tear_down();
	}

	/**
	 * The Split layout's Feed, as the editor saves it, or with the given
	 * layout and style in place of its grid.
	 *
	 * @param string $layout Feed layout attribute, as JSON.
	 * @param string $style  Feed style attribute, as JSON.
	 * @return string
	 */
	private static function feed_markup(
		string $layout = '{"type":"grid","columnCount":3}',
		string $style = '{"@tablet":{"layout":{"columnCount":1}},"@mobile":{"layout":{"columnCount":1}},"spacing":{"blockGap":{"top":"0","left":"var:preset|spacing|50"}}}'
	): string {
		return '<!-- wp:group {"className":"newspack-rolling-coverage-feed","style":' . $style . ',"layout":' . $layout . '} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. self::CARD_MARKUP
			. self::ENTRY_MARKUP
			. '</div><!-- /wp:group -->';
	}

	/**
	 * Render a coverage in the Split layout, or in the given Feed.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $feed        Feed markup.
	 * @return string Rendered block.
	 */
	private static function render_split( int $coverage_id, string $feed = '' ): string {
		$attributes = [
			'coverageId' => $coverage_id,
			'align'      => 'wide',
		];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . ( '' !== $feed ? $feed : self::feed_markup() ) . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * Create entries an hour apart, oldest first.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @param int $count       How many.
	 * @return int[] Entry IDs, oldest first.
	 */
	private static function create_entries( int $coverage_id, int $count = 3 ): array {
		return array_map(
			static fn( int $number ) => self::create_entry(
				$coverage_id,
				[
					'post_title' => 'Update ' . $number,
					'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $count + 1 - $number ) . ' hours' ) ),
				]
			),
			range( 1, $count )
		);
	}

	/**
	 * The classes of each entry's article, by entry ID.
	 *
	 * @param string $html Rendered entries.
	 * @return array<int, string[]>
	 */
	private static function article_classes( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$classes   = [];

		while ( $processor->next_tag( 'article' ) ) {
			$classes[ (int) $processor->get_attribute( 'data-entry-id' ) ] = iterator_to_array( $processor->class_list(), false );
		}

		return $classes;
	}

	/**
	 * The class core's child layout support gave an article.
	 *
	 * @param string[] $classes The article's classes.
	 * @return string The class, or an empty string.
	 */
	private static function cell_class( array $classes ): string {
		foreach ( $classes as $class ) {
			if ( str_starts_with( $class, 'wp-container-content-' ) ) {
				return $class;
			}
		}

		return '';
	}

	/**
	 * The block support styles stored so far.
	 *
	 * @return string
	 */
	private static function stored_css(): string {
		return wp_style_engine_get_stylesheet_from_context( 'block-supports', [ 'prettify' => false ] );
	}

	/**
	 * Theme.json data supporting sticky blocks, as the Newspack Block Theme's.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme.json data.
	 * @return WP_Theme_JSON_Data
	 */
	public function with_sticky_support( $theme_json ) {
		return $theme_json->update_with(
			[
				'version'  => WP_Theme_JSON::LATEST_SCHEMA,
				'settings' => [ 'position' => [ 'sticky' => true ] ],
			]
		);
	}

	/**
	 * The pinned entry's article takes the pinned card's placement and every
	 * other entry's the entry group's, at each viewport, with no container
	 * query resetting them, and the groups inside don't place themselves.
	 */
	public function test_articles_take_their_groups_placement() {
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();
		$coverage_id = self::create_coverage();
		[ $pinned_id, $middle_id, $newest_id ] = self::create_entries( $coverage_id );
		Post_Type::pin_entry( $pinned_id );

		$html    = self::render_split( $coverage_id );
		$css     = self::stored_css();
		$classes = self::article_classes( $html );
		$card    = self::cell_class( $classes[ $pinned_id ] );
		$entry   = self::cell_class( $classes[ $newest_id ] );

		$this->assertSame( [ $pinned_id, $newest_id, $middle_id ], array_keys( $classes ), 'The pinned entry should come first.' );
		$this->assertNotSame( '', $card, "The pinned entry's article should carry a child layout class." );
		$this->assertNotSame( '', $entry, "An entry's article should carry a child layout class." );
		$this->assertSame( $entry, self::cell_class( $classes[ $middle_id ] ), 'Every entry should share the class.' );
		$this->assertStringContainsString( '.' . $card . '{grid-column:1 / span 1;grid-row:span 100;}', $css );
		$this->assertMatchesRegularExpression( '#@media \(480px < width <= 782px\)\{\.' . $card . '\{grid-column:1 / span 1;grid-row:span 1;\}\}#', $css );
		$this->assertMatchesRegularExpression( '#@media \(width <= 480px\)\{\.' . $card . '\{grid-column:1 / span 1;grid-row:span 1;\}\}#', $css );
		$this->assertStringContainsString( '.' . $entry . '{grid-column:2 / span 2;}', $css );
		$this->assertMatchesRegularExpression( '#@media \(480px < width <= 782px\)\{\.' . $entry . '\{grid-column:1 / span 1;\}\}#', $css );
		$this->assertNotContains( Rolling_Coverage_Block::BESIDE_PINNED_CLASS, $classes[ $pinned_id ] );
		$this->assertContains( Rolling_Coverage_Block::BESIDE_PINNED_CLASS, $classes[ $newest_id ], 'An entry beside the pinned card should be marked.' );
		$this->assertStringNotContainsString( '@container', $css, 'The Feed has fixed columns, so nothing should reset the placements.' );
		$this->assertDoesNotMatchRegularExpression( '#<div class="[^"]*newspack-rolling-coverage-(?:pinned-card|regular-entry)[^"]*wp-container-content-#', $html, 'The groups should not place themselves inside the article.' );
		$this->assertDoesNotMatchRegularExpression( '#<div class="[^"]*wp-container-content-[^"]*newspack-rolling-coverage-(?:pinned-card|regular-entry)#', $html, 'The groups should not place themselves inside the article.' );
	}

	/**
	 * The Feed's rows have no gap, set where its gap goes into calc() with a
	 * unit.
	 */
	public function test_feed_rows_have_no_gap() {
		$coverage_id = self::create_coverage();
		self::create_entries( $coverage_id, 1 );

		$this->assertStringContainsString( '--newspack-rolling-coverage-gap:0px', self::render_split( $coverage_id ) );
	}

	/**
	 * Only the first pinned entry takes the pinned card's placement: a later
	 * one heads the entries beside it, keeping the card's look.
	 */
	public function test_later_pinned_entry_takes_the_entry_groups_placement() {
		$coverage_id = self::create_coverage();
		[ $first_pin, $second_pin, $newest_id ] = self::create_entries( $coverage_id );
		Post_Type::pin_entry( $first_pin );
		Post_Type::pin_entry( $second_pin );

		$html    = self::render_split( $coverage_id );
		$classes = self::article_classes( $html );
		$entry   = self::cell_class( $classes[ $newest_id ] );

		$this->assertSame( [ $first_pin, $second_pin, $newest_id ], array_keys( $classes ), 'Pinned entries should come first, in the order they were pinned.' );
		$this->assertNotSame( $entry, self::cell_class( $classes[ $first_pin ] ), 'The first pin should take the card placement.' );
		$this->assertSame( $entry, self::cell_class( $classes[ $second_pin ] ), "The second pin should take the entry group's placement." );
		$this->assertContains( Rolling_Coverage_Block::BESIDE_PINNED_CLASS, $classes[ $second_pin ] );
		$this->assertSame( 2, substr_count( $html, 'class="wp-block-group newspack-rolling-coverage-pinned-card' ), 'Both pins should keep the pinned card.' );
	}

	/**
	 * Entries the poll and load more bring carry the classes the page
	 * rendered them with.
	 */
	public function test_polled_and_loaded_entries_keep_their_placement() {
		$coverage_id = self::create_coverage();
		$entry_ids   = self::create_entries( $coverage_id );
		Post_Type::pin_entry( $entry_ids[0] );

		$html = self::render_split( $coverage_id );

		preg_match( '/data-template-key="([^"]+)"/', $html, $key );

		$poll = self::dispatch(
			'GET',
			'/coverages/' . $coverage_id . '/entries',
			[
				'cursor'       => '0:2000-01-01 00:00:00',
				'template_key' => $key[1],
			]
		)->get_data();
		$more = self::dispatch(
			'GET',
			'/coverages/' . $coverage_id . '/entries',
			[
				'before'       => gmdate( 'Y-m-d H:i:s', strtotime( '+1 hour' ) ),
				'per_page'     => 10,
				'template_key' => $key[1],
			]
		)->get_data();

		$initial = self::article_classes( $html );
		$polled  = self::article_classes( implode( '', wp_list_pluck( $poll['entries'], 'html' ) ) );
		$loaded  = self::article_classes( $more['html'] );

		foreach ( $entry_ids as $entry_id ) {
			$this->assertSame( self::cell_class( $initial[ $entry_id ] ), self::cell_class( $polled[ $entry_id ] ), 'A polled entry should keep its placement.' );
			$this->assertSame( in_array( Rolling_Coverage_Block::BESIDE_PINNED_CLASS, $initial[ $entry_id ], true ), in_array( Rolling_Coverage_Block::BESIDE_PINNED_CLASS, $polled[ $entry_id ], true ) );
		}

		$this->assertSame( self::cell_class( $initial[ $entry_ids[2] ] ), self::cell_class( $loaded[ $entry_ids[2] ] ), 'A loaded entry should keep its placement.' );
	}

	/**
	 * Both groups' placements are stored when the page renders, so an entry
	 * of a kind that wasn't on the page is placed when the poll brings it.
	 */
	public function test_placements_are_stored_for_kinds_not_on_the_page() {
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();
		$coverage_id = self::create_coverage();
		self::create_entries( $coverage_id );

		self::render_split( $coverage_id );

		$this->assertStringContainsString( '{grid-column:1 / span 1;grid-row:span 100;}', self::stored_css(), "The pinned card's placement should be stored with no pinned entry shown." );
	}

	/**
	 * Outside a grid Feed, and in a grid Feed whose groups set no placement,
	 * the articles carry no placement and the groups keep their settings.
	 */
	public function test_entries_outside_a_placing_grid_render_as_before() {
		$coverage_id = self::create_coverage();
		[ $pinned_id ] = self::create_entries( $coverage_id );
		Post_Type::pin_entry( $pinned_id );

		$flex = self::render_split( $coverage_id, self::feed_markup( '{"type":"flex","orientation":"vertical"}', '{}' ) );
		$grid = self::render_split( $coverage_id, str_replace( [ self::CARD_PLACEMENT, self::ENTRY_PLACEMENT ], '', self::feed_markup() ) );

		foreach ( [ $flex, $grid ] as $html ) {
			foreach ( self::article_classes( $html ) as $classes ) {
				$this->assertSame( '', self::cell_class( $classes ) );
				$this->assertNotContains( Rolling_Coverage_Block::BESIDE_PINNED_CLASS, $classes );
			}
		}

		$this->assertMatchesRegularExpression( '#<div class="[^"]*newspack-rolling-coverage-regular-entry[^"]*wp-container-content-#', $flex, 'Outside a grid Feed the group keeps its own child layout.' );
	}

	/**
	 * On a theme supporting sticky blocks the pinned card sticks, under a
	 * class that stays the same when the poll renders it again; elsewhere it
	 * doesn't.
	 */
	public function test_pinned_card_sticks_where_the_theme_supports_it() {
		$coverage_id = self::create_coverage();
		[ $pinned_id ] = self::create_entries( $coverage_id );
		Post_Type::pin_entry( $pinned_id );

		$this->assertStringNotContainsString( 'is-position-sticky', self::render_split( $coverage_id ), 'The classic theme has no sticky support.' );

		add_filter( 'wp_theme_json_data_theme', [ $this, 'with_sticky_support' ] );
		wp_clean_theme_json_cache();
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();

		$html = self::render_split( $coverage_id );

		preg_match( '#<div class="[^"]*newspack-rolling-coverage-pinned-card[^"]*"#', $html, $card );
		preg_match( '/newspack-rolling-coverage-position-[0-9a-f]+/', $card[0] ?? '', $position );
		preg_match( '/data-template-key="([^"]+)"/', $html, $key );

		$this->assertStringContainsString( 'is-position-sticky', $card[0] ?? '' );
		$this->assertNotEmpty( $position, 'The card should carry a stable position class.' );
		$this->assertDoesNotMatchRegularExpression( '/wp-container-\d/', $card[0], "Core's class unique to the render should be gone." );
		$this->assertStringContainsString( '.wp-block-newspack-rolling-coverage-rolling-coverage .' . $position[0] . '{top:calc(0px + var(--wp-admin--admin-bar--position-offset, 0px));position:sticky;z-index:10;}', self::stored_css() );

		$poll = self::dispatch(
			'GET',
			'/coverages/' . $coverage_id . '/entries',
			[
				'cursor'       => '0:2000-01-01 00:00:00',
				'template_key' => $key[1],
			]
		)->get_data();

		$this->assertStringContainsString( $position[0] . ' is-position-sticky', implode( '', wp_list_pluck( $poll['entries'], 'html' ) ), 'A polled card should keep the class.' );
	}
}
