<?php
/**
 * Tests for the Split layout.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;

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

	const COLUMN_RULE = 'border-top-color:#111;border-top-width:3px;border-top-style:solid;';

	const ENTRY_RULE = 'border-top-color:#ddd;border-top-width:1px;border-top-style:solid;';

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
	 * @param array  $attributes  Further block attributes.
	 * @return string Rendered block.
	 */
	private static function render_split( int $coverage_id, string $feed = '', array $attributes = [] ): string {
		$attributes = array_merge(
			[
				'coverageId' => $coverage_id,
				'align'      => 'wide',
			],
			$attributes
		);
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
	 * The column markers each entry's article carries, by entry ID.
	 *
	 * @param string $html Rendered entries.
	 * @return array<int, string[]>
	 */
	private static function article_marks( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$marks     = [];

		while ( $processor->next_tag( 'article' ) ) {
			$marks[ (int) $processor->get_attribute( 'data-entry-id' ) ] = array_values(
				array_filter(
					[ 'data-leads-column', 'data-heads-column' ],
					static fn( $name ) => null !== $processor->get_attribute( $name )
				)
			);
		}

		return $marks;
	}

	/**
	 * The inline style of each entry's top-level entry group or pinned card,
	 * by entry ID.
	 *
	 * @param string $html Rendered entries.
	 * @return array<int, string>
	 */
	private static function entry_group_styles( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$styles    = [];
		$entry_id  = 0;

		while ( $processor->next_tag() ) {
			if ( 'ARTICLE' === $processor->get_tag() ) {
				$entry_id = (int) $processor->get_attribute( 'data-entry-id' );
			} elseif ( $entry_id && ! isset( $styles[ $entry_id ] ) && ( $processor->has_class( 'newspack-rolling-coverage-regular-entry' ) || $processor->has_class( 'newspack-rolling-coverage-pinned-card' ) ) ) {
				$styles[ $entry_id ] = (string) $processor->get_attribute( 'style' );
			}
		}

		return $styles;
	}

	/**
	 * The top border declarations of an inline style, sorted.
	 *
	 * @param string $style Inline style.
	 * @return string[]
	 */
	private static function rule_of( string $style ): array {
		$rule = array_values( array_filter( array_map( 'trim', explode( ';', $style ) ), static fn( $declaration ) => str_starts_with( $declaration, 'border-top' ) ) );
		sort( $rule );

		return $rule;
	}

	/**
	 * The classes of each ad's wrapper.
	 *
	 * @param string $html Rendered entries and ads.
	 * @return array<int, string[]>
	 */
	private static function ad_classes( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$classes   = [];

		while ( $processor->next_tag( [ 'class_name' => 'newspack_global_ad' ] ) ) {
			$classes[] = iterator_to_array( $processor->class_list(), false );
		}

		return $classes;
	}

	/**
	 * Poll a coverage from the start, as the page rendered with the template
	 * key would.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $html        The rendered page.
	 * @return array The poll response.
	 */
	private static function poll( int $coverage_id, string $html ): array {
		preg_match( '/data-template-key="([^"]+)"/', $html, $key );

		return self::dispatch(
			'GET',
			'/coverages/' . $coverage_id . '/entries',
			[
				'cursor'       => '0:2000-01-01 00:00:00',
				'template_key' => $key[1] ?? '',
			]
		)->get_data();
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
		[ $entry_id ] = self::create_entries( $coverage_id );

		$html = self::render_split( $coverage_id );
		$css  = self::stored_css();

		Post_Type::pin_entry( $entry_id );

		$polled = self::article_classes( implode( '', wp_list_pluck( self::poll( $coverage_id, $html )['entries'], 'html' ) ) );
		$card   = self::cell_class( $polled[ $entry_id ] );

		$this->assertNotSame( '', $card, 'The polled pinned entry should carry a child layout class.' );
		$this->assertStringContainsString( '.' . $card . '{grid-column:1 / span 1;grid-row:span 100;}', $css, "The pinned card's placement should be stored with no pinned entry shown." );
	}

	/**
	 * The lead pinned entry is the first of the coverage the feed shows: an
	 * entry filed under two coverages, pinned second in one but first in the
	 * other, heads the entries beside the card in the first.
	 */
	public function test_lead_pinned_entry_is_decided_per_coverage() {
		$coverage_id = self::create_coverage();
		$other_id    = self::create_coverage();
		[ $first_pin, $shared_pin ] = self::create_entries( $coverage_id );
		wp_set_object_terms( $shared_pin, [ $coverage_id, $other_id ], Taxonomy::TAXONOMY_SLUG );
		Post_Type::pin_entry( $first_pin );
		Post_Type::pin_entry( $shared_pin );

		$html         = self::render_split( $coverage_id );
		$classes      = self::article_classes( $html );
		$polled       = self::article_classes( implode( '', wp_list_pluck( self::poll( $coverage_id, $html )['entries'], 'html' ) ) );
		$other_html   = self::render_split( $other_id );
		$other        = self::article_classes( $other_html );
		$other_polled = self::article_classes( implode( '', wp_list_pluck( self::poll( $other_id, $other_html )['entries'], 'html' ) ) );
		$card         = self::cell_class( $classes[ $first_pin ] );

		$this->assertNotSame( $card, self::cell_class( $classes[ $shared_pin ] ), "The second pin should take the entry group's placement." );
		$this->assertContains( Rolling_Coverage_Block::BESIDE_PINNED_CLASS, $classes[ $shared_pin ] );
		$this->assertSame( self::cell_class( $classes[ $shared_pin ] ), self::cell_class( $polled[ $shared_pin ] ), 'A poll should place it the same way.' );
		$this->assertSame( $card, self::cell_class( $polled[ $first_pin ] ), 'A poll should keep the lead.' );
		$this->assertSame( $card, self::cell_class( $other[ $shared_pin ] ), 'It should lead the coverage it is pinned first in.' );
		$this->assertSame( $card, self::cell_class( $other_polled[ $shared_pin ] ), 'A poll of that coverage should keep it as the lead.' );
	}

	/**
	 * The entry heading the entries beside the pinned card carries the
	 * card's top border in place of its own, so the two rules line up; the
	 * entries below it keep their own. The lead and the head are marked, and
	 * the block carries both rules for the view script.
	 */
	public function test_first_entry_beside_the_card_carries_its_rule() {
		$coverage_id = self::create_coverage();
		[ $pinned_id, $middle_id, $newest_id ] = self::create_entries( $coverage_id );
		Post_Type::pin_entry( $pinned_id );

		$html   = self::render_split( $coverage_id );
		$styles = self::entry_group_styles( $html );
		$marks  = self::article_marks( $html );

		$this->assertSame( self::rule_of( self::COLUMN_RULE ), self::rule_of( $styles[ $newest_id ] ), 'The head should carry the card\'s rule in place of its own.' );
		$this->assertStringContainsString( 'padding-top:var(--wp--preset--spacing--40)', $styles[ $newest_id ], 'The head should keep its other styles.' );
		$this->assertSame( self::rule_of( self::ENTRY_RULE ), self::rule_of( $styles[ $middle_id ] ), 'The next entry should keep its own rule.' );
		$this->assertSame( [ 'data-leads-column' ], $marks[ $pinned_id ] );
		$this->assertSame( [ 'data-heads-column' ], $marks[ $newest_id ] );
		$this->assertSame( [], $marks[ $middle_id ] );

		$root = new WP_HTML_Tag_Processor( $html );
		$root->next_tag();

		$this->assertSame( self::COLUMN_RULE, $root->get_attribute( 'data-column-rule' ) );
		$this->assertSame( self::ENTRY_RULE, $root->get_attribute( 'data-entry-rule' ) );
	}

	/**
	 * Without a lead card, no pin or a capped feed, no entry takes the
	 * card's rule; a capped feed carries no rules for the view script.
	 */
	public function test_no_column_rule_without_a_lead_card() {
		$coverage_id = self::create_coverage();
		self::create_entries( $coverage_id );

		$capped = self::render_split(
			$coverage_id,
			'',
			[
				'latestOnly'  => true,
				'latestCount' => 3,
			]
		);

		foreach ( [ self::render_split( $coverage_id ), $capped ] as $html ) {
			foreach ( self::entry_group_styles( $html ) as $style ) {
				$this->assertSame( self::rule_of( self::ENTRY_RULE ), self::rule_of( $style ) );
			}

			$this->assertStringNotContainsString( 'data-heads-column', $html );
			$this->assertStringNotContainsString( 'data-leads-column', $html );
		}

		$this->assertStringNotContainsString( 'data-column-rule', $capped );
	}

	/**
	 * A second pin heading the entries beside the card already has the
	 * card's look, and is left as it is.
	 */
	public function test_second_pin_heading_the_column_is_untouched() {
		$coverage_id = self::create_coverage();
		[ $first_pin, $second_pin, $newest_id ] = self::create_entries( $coverage_id );
		Post_Type::pin_entry( $first_pin );
		Post_Type::pin_entry( $second_pin );

		$html   = self::render_split( $coverage_id );
		$styles = self::entry_group_styles( $html );
		$marks  = self::article_marks( $html );

		$this->assertSame( [], $marks[ $second_pin ] );
		$this->assertSame( [], $marks[ $newest_id ] );
		$this->assertSame( self::rule_of( self::ENTRY_RULE ), self::rule_of( $styles[ $newest_id ] ) );
		$this->assertStringNotContainsString( 'data-heads-column', $html );
	}

	/**
	 * Polls and load more mark the lead, so the view script can find the
	 * entry heading the column whichever way the entries arrived.
	 */
	public function test_lead_is_marked_on_polls_and_load_more() {
		$coverage_id = self::create_coverage();
		[ $pinned_id, $middle_id ] = self::create_entries( $coverage_id );
		Post_Type::pin_entry( $pinned_id );

		$html = self::render_split( $coverage_id );

		preg_match( '/data-template-key="([^"]+)"/', $html, $key );

		$polled = self::article_marks( implode( '', wp_list_pluck( self::poll( $coverage_id, $html )['entries'], 'html' ) ) );
		$loaded = self::article_marks(
			self::dispatch(
				'GET',
				'/coverages/' . $coverage_id . '/entries',
				[
					'before'       => gmdate( 'Y-m-d H:i:s', strtotime( '+1 hour' ) ),
					'per_page'     => 10,
					'template_key' => $key[1],
				]
			)->get_data()['html']
		);

		$this->assertSame( [ 'data-leads-column' ], $polled[ $pinned_id ] );
		$this->assertSame( [], $polled[ $middle_id ], 'A poll should leave the head to the view script.' );
		$this->assertSame( [ 'data-leads-column' ], $loaded[ $pinned_id ] );
	}

	/**
	 * An ad between entries is placed as the entries beside the pinned card
	 * are, on the page, in a poll and in load more, so it fills their
	 * columns; outside a grid Feed it renders as before.
	 */
	public function test_ads_take_the_entries_placement() {
		self::enable_ad_placement();

		$coverage_id = self::create_coverage();
		[ $pinned_id, , $newest_id ] = self::create_entries( $coverage_id );
		Post_Type::pin_entry( $pinned_id );

		$ads   = [
			'enableAds'   => true,
			'adsInterval' => 1,
		];
		$html  = self::render_split( $coverage_id, '', $ads );
		$entry = self::cell_class( self::article_classes( $html )[ $newest_id ] );

		preg_match( '/data-template-key="([^"]+)"/', $html, $key );

		$more = self::dispatch(
			'GET',
			'/coverages/' . $coverage_id . '/entries',
			[
				'before'       => gmdate( 'Y-m-d H:i:s', strtotime( '+1 hour' ) ),
				'per_page'     => 10,
				'template_key' => $key[1],
			]
		)->get_data();

		$rendered = [
			'page'      => $html,
			'poll'      => implode( '', wp_list_pluck( self::poll( $coverage_id, $html )['entries'], 'adHtml' ) ),
			'load more' => $more['html'],
		];

		$this->assertNotSame( '', $entry );

		foreach ( $rendered as $path => $ad_html ) {
			$classes = self::ad_classes( $ad_html );

			$this->assertNotEmpty( $classes, "The $path should hold ads." );

			foreach ( $classes as $ad ) {
				$this->assertContains( $entry, $ad, "An ad in the $path should take the entry group's placement." );
				$this->assertContains( Rolling_Coverage_Block::BESIDE_PINNED_CLASS, $ad );
			}
		}

		$flex = self::render_split( $coverage_id, self::feed_markup( '{"type":"flex","orientation":"vertical"}', '{}' ), $ads );

		$this->assertNotEmpty( self::ad_classes( $flex ) );

		foreach ( self::ad_classes( $flex ) as $ad ) {
			$this->assertSame( [ 'newspack_global_ad', 'rolling_coverage_entry' ], $ad, 'Outside a grid Feed the ad should render as before.' );
		}
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
		$this->assertStringContainsString( '.wp-block-newspack-rolling-coverage-rolling-coverage .' . $position[0] . '{top:calc(0px + var(--newspack-rolling-coverage-top-bars, var(--wp-admin--admin-bar--position-offset, 0px)));position:sticky;z-index:10;}', self::stored_css() );

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
