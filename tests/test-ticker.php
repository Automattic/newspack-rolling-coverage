<?php
/**
 * Tests for the Ticker layout.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Social_Sharing;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * A capped grid of the latest headlines under the coverage's status and
 * name, each headline linking to its entry.
 */
class Test_Ticker extends Rolling_Coverage_TestCase {

	const HEADER_MARKUP = '<!-- wp:group {"style":{"layout":{"columnSpan":4},"@tablet":{"layout":{"columnSpan":2}},"@mobile":{"layout":{"columnSpan":1}},"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} --><div class="wp-block-group">'
		. '<!-- wp:newspack-rolling-coverage/coverage-status /-->'
		. '<!-- wp:heading {"level":3,"metadata":{"bindings":{"content":{"source":"newspack-rolling-coverage/entry","args":{"key":"coverageName"}}}}} --><h3 class="wp-block-heading">Live Coverage</h3><!-- /wp:heading -->'
		. '</div><!-- /wp:group -->';

	const ENTRY_BLOCKS = '<!-- wp:post-date {"format":"human-diff","metadata":{"bindings":{"datetime":{"source":"core/post-data","args":{"field":"date"}}}}} /-->'
		. self::TITLE_MARKUP;

	const TITLE_MARKUP = '<!-- wp:post-title {"level":4,"isLink":true,"className":"newspack-rolling-coverage-entry-link"} /-->';

	const FOOTER_MARKUP = '<!-- wp:paragraph {"className":"use-header-font newspack-rolling-coverage-all-updates","style":{"layout":{"columnSpan":4},"@tablet":{"layout":{"columnSpan":2}},"@mobile":{"layout":{"columnSpan":1}}}} --><p class="use-header-font newspack-rolling-coverage-all-updates"><a href="#">See all updates</a></p><!-- /wp:paragraph -->';

	/**
	 * Register the Coverage Status block the header holds.
	 */
	public function set_up() {
		parent::set_up();
		$this->register_status_block();
	}

	/**
	 * Restore the request the tests change.
	 */
	public function tear_down() {
		$this->go_to( home_url( '/' ) );

		parent::tear_down();
	}

	/**
	 * The Ticker layout's Feed, as the editor saves it.
	 *
	 * @return string
	 */
	private static function feed_markup(): string {
		$entry = static fn( string $class_name ) => '<!-- wp:group {"className":"' . $class_name . '","style":{"spacing":{"blockGap":"var:preset|spacing|10"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} --><div class="wp-block-group ' . $class_name . '">'
			. self::ENTRY_BLOCKS
			. '</div><!-- /wp:group -->';

		return '<!-- wp:group {"className":"newspack-rolling-coverage-feed","style":{"@tablet":{"layout":{"columnCount":2}},"@mobile":{"layout":{"columnCount":1}},"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"grid","columnCount":4}} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. self::HEADER_MARKUP
			. $entry( 'newspack-rolling-coverage-pinned-card' )
			. $entry( 'newspack-rolling-coverage-regular-entry' )
			. self::FOOTER_MARKUP
			. '</div><!-- /wp:group -->';
	}

	/**
	 * Render a coverage in the Ticker layout, with the attributes the layout
	 * sets when picked.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string Rendered block.
	 */
	private static function render_ticker( int $coverage_id ): string {
		$attributes = [
			'coverageId'    => $coverage_id,
			'latestOnly'    => true,
			'latestCount'   => 4,
			'hideWhenEnded' => true,
			'align'         => 'wide',
		];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . self::feed_markup() . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * Render an entry through the Ticker's title, set as given.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param string $title    Post Title block markup.
	 * @return string Rendered entry.
	 */
	private static function render_title( int $entry_id, string $title ): string {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( $title ) );
	}

	/**
	 * Create five entries an hour apart.
	 *
	 * @param int $coverage_id Coverage term ID.
	 */
	private static function create_entries( int $coverage_id ): void {
		foreach ( range( 1, 5 ) as $number ) {
			self::create_entry(
				$coverage_id,
				[
					'post_title' => 'Update ' . $number,
					'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( 6 - $number ) . ' hours' ) ),
				]
			);
		}
	}

	/**
	 * The header and footer render outside the Feed group, yet span its grid
	 * as its own children would: by the Feed's column count at each
	 * viewport, without the container query core adds to reset the span of a
	 * child whose grid it can't see.
	 */
	public function test_header_and_footer_span_the_grid_without_a_container_reset() {
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();
		$coverage_id = self::create_coverage();
		self::create_entries( $coverage_id );
		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/storm-coverage/' ) );

		$html = self::render_ticker( $coverage_id );
		$css  = wp_style_engine_get_stylesheet_from_context( 'block-supports', [ 'prettify' => false ] );

		$this->assertMatchesRegularExpression( '#<div class="wp-block-group [^"]*wp-container-content-[^"]*"[^>]*>\s*<div class="wp-block-newspack-rolling-coverage-coverage-status#', $html, 'The header should carry its child layout class.' );
		$this->assertMatchesRegularExpression( '#<p class="[^"]*newspack-rolling-coverage-all-updates[^"]*wp-container-content-#', $html, 'The footer should carry its child layout class.' );
		$this->assertStringContainsString( 'grid-column:span 4', $css );
		$this->assertMatchesRegularExpression( '#@media \(480px < width <= 782px\)\{[^}]*grid-column:span 2#', $css );
		$this->assertStringNotContainsString( '@container', $css );
	}

	/**
	 * Create a titled entry in a new coverage.
	 *
	 * @return int[] The coverage term ID and the entry ID.
	 */
	private static function create_titled_entry(): array {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry(
			$coverage_id,
			[
				'post_title' => 'Bridge closed',
				'post_name'  => 'bridge-closed',
			]
		);

		return [ $coverage_id, $entry_id ];
	}

	/**
	 * The link to an entry on a coverage page.
	 *
	 * @param string $page_url Coverage page URL.
	 * @param int    $entry_id Entry post ID.
	 * @return string
	 */
	private static function deep_link( string $page_url, int $entry_id ): string {
		return esc_url( $page_url . '?rolling-coverage-entry=bridge-closed#newspack-rolling-coverage-entry-' . $entry_id );
	}

	/**
	 * A title carrying the entry link class links to the published breakout
	 * post, else to the entry on the coverage page, else to its share link.
	 */
	public function test_entry_link_title_links_to_the_breakout_then_the_coverage_page() {
		$title                       = '<!-- wp:post-title {"level":4,"className":"newspack-rolling-coverage-entry-link"} /-->';
		[ $coverage_id, $entry_id ] = self::create_titled_entry();
		$page_url                    = home_url( '/storm-coverage/' );

		$this->assertStringContainsString(
			'<a href="' . esc_url( Social_Sharing::get_entry_share_url( $entry_id ) ) . '">Bridge closed</a></h4>',
			self::render_title( $entry_id, $title ),
			'Without a coverage page: the share link.'
		);

		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, $page_url );

		$this->assertStringContainsString(
			'<a href="' . self::deep_link( $page_url, $entry_id ) . '">Bridge closed</a></h4>',
			self::render_title( $entry_id, $title ),
			'The entry on the coverage page.'
		);

		$breakout_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );

		$this->assertStringContainsString(
			'<a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Bridge closed</a></h4>',
			self::render_title( $entry_id, $title ),
			'A published breakout wins.'
		);
	}

	/**
	 * A title set to link to the entry, as the layout ships it, points at the
	 * entry on the coverage page rather than the entry's own permalink.
	 */
	public function test_linked_entry_link_title_points_at_the_coverage_page() {
		[ $coverage_id, $entry_id ] = self::create_titled_entry();
		$page_url                    = home_url( '/storm-coverage/' );
		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, $page_url );

		$html = self::render_title( $entry_id, '<!-- wp:post-title {"level":4,"isLink":true,"className":"newspack-rolling-coverage-entry-link"} /-->' );

		$this->assertStringContainsString( 'href="' . self::deep_link( $page_url, $entry_id ) . '"', $html );
		$this->assertSame( 1, substr_count( $html, '<a ' ) );
	}

	/**
	 * Create an untitled entry with an excerpt in a coverage shown on a page.
	 *
	 * @return int[] The coverage term ID and the entry ID.
	 */
	private static function create_untitled_entry(): array {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry(
			$coverage_id,
			[
				'post_title'   => '',
				'post_name'    => 'bridge-closed',
				'post_excerpt' => 'Traffic is being diverted while engineers inspect the bridge after the storm, with the council promising an update by noon.',
			]
		);
		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/storm-coverage/' ) );

		return [ $coverage_id, $entry_id ];
	}

	/**
	 * An untitled entry's marked title shows its excerpt's first fifteen
	 * words, linked as a title would be: to the entry on the coverage page,
	 * then to the breakout once it's published.
	 */
	public function test_untitled_entry_link_title_shows_its_opening_words_linked() {
		[ , $entry_id ] = self::create_untitled_entry();
		$words          = 'Traffic is being diverted while engineers inspect the bridge after the storm, with the council…';

		$this->assertStringContainsString(
			'<h4 class="newspack-rolling-coverage-entry-link wp-block-post-title"><a href="' . self::deep_link( home_url( '/storm-coverage/' ), $entry_id ) . '" target="_self" >' . $words . '</a></h4>',
			self::render_title( $entry_id, self::TITLE_MARKUP )
		);

		$breakout_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );

		$this->assertStringContainsString(
			'<a href="' . esc_url( get_permalink( $breakout_id ) ) . '" target="_self" >' . $words . '</a></h4>',
			self::render_title( $entry_id, self::TITLE_MARKUP )
		);
	}

	/**
	 * Without an excerpt, the opening words come from the entry's text, as
	 * plain escaped text.
	 */
	public function test_untitled_entry_link_title_falls_back_to_the_text() {
		$entry_id = self::create_entry(
			self::create_coverage(),
			[
				'post_title'   => '',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>Roads <b>closed</b> &amp; diverted</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertStringContainsString( '>Roads closed &amp; diverted</a></h4>', self::render_title( $entry_id, self::TITLE_MARKUP ) );
	}

	/**
	 * A titled entry keeps its own title.
	 */
	public function test_titled_entry_keeps_its_title() {
		[ , $entry_id ] = self::create_titled_entry();
		wp_update_post(
			[
				'ID'           => $entry_id,
				'post_excerpt' => 'Traffic is being diverted.',
			]
		);

		$html = self::render_title( $entry_id, self::TITLE_MARKUP );

		$this->assertStringContainsString( '>Bridge closed</a></h4>', $html );
		$this->assertStringNotContainsString( 'Traffic is being diverted', $html );
	}

	/**
	 * A title without the class renders nothing for an untitled entry, and
	 * the title the marked block borrowed doesn't carry over to later ones.
	 */
	public function test_unmarked_title_of_an_untitled_entry_stays_empty() {
		[ , $entry_id ] = self::create_untitled_entry();

		$this->assertStringNotContainsString( 'wp-block-post-title', self::render_title( $entry_id, '<!-- wp:post-title {"level":4} /-->' ) );
		$this->assertSame( 1, substr_count( self::render_title( $entry_id, self::TITLE_MARKUP . '<!-- wp:post-title {"level":5} /-->' ), 'wp-block-post-title' ), 'Only the marked title should show.' );
	}

	/**
	 * A marked title rendering outside an entry, with the untitled entry as
	 * the global post, stays empty.
	 */
	public function test_marked_title_outside_an_entry_render_stays_empty() {
		[ , $entry_id ] = self::create_untitled_entry();
		$GLOBALS['post'] = get_post( $entry_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The title reads the global post, as on the entry's own page.
		setup_postdata( $GLOBALS['post'] );

		$this->assertSame( '', render_block( parse_blocks( self::TITLE_MARKUP )[0] ) );

		wp_reset_postdata();
	}

	/**
	 * A title without the class still links only to a published breakout.
	 */
	public function test_titles_without_the_class_stay_unlinked_without_a_breakout() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id, [ 'post_title' => 'Bridge closed' ] );
		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/storm-coverage/' ) );

		$this->assertStringNotContainsString( '<a ', self::render_title( $entry_id, '<!-- wp:post-title {"level":4} /-->' ) );
	}

	/**
	 * On a theme without block spacing support, the grid's gap follows the
	 * Feed's Block spacing rather than core's 0.5em, and its tablet and
	 * mobile columns still apply over it.
	 */
	public function test_grid_feed_follows_its_spacing_without_block_spacing_support() {
		$this->assertNull( wp_get_global_settings( [ 'spacing', 'blockGap' ] ), 'The test theme has no block spacing support.' );

		$coverage_id = self::create_coverage();
		self::create_entries( $coverage_id );

		$html = self::render_ticker( $coverage_id );
		$css  = wp_style_engine_get_stylesheet_from_context( 'block-supports', [ 'prettify' => false ] );

		$this->assertMatchesRegularExpression( '#<div class="wp-block-group newspack-rolling-coverage-feed[^"]*newspack-rolling-coverage-feed-layout-[0-9a-f]{8}#', $html );
		preg_match( '#newspack-rolling-coverage-feed-layout-[0-9a-f]{8}#', $html, $class_name );
		$selector = preg_quote( '.newspack-rolling-coverage-feed.' . $class_name[0], '#' );

		$this->assertMatchesRegularExpression( '#(?<!\{)' . $selector . '\{grid-template-columns:repeat\(4, minmax\(0, 1fr\)\);gap:var\(--wp--preset--spacing--40\);\}#', $css );
		$this->assertMatchesRegularExpression( '#@media \(480px < width <= 782px\)\{' . $selector . '\{grid-template-columns:repeat\(2, minmax\(0, 1fr\)\);\}\}#', $css );
		$this->assertMatchesRegularExpression( '#@media \(width <= 480px\)\{' . $selector . '\{grid-template-columns:repeat\(1, minmax\(0, 1fr\)\);\}\}#', $css );
		$this->assertGreaterThan( strpos( $css, $class_name[0] . '{' ), strpos( $css, '@media (480px < width <= 782px){.newspack-rolling-coverage-feed.' . $class_name[0] ), 'The overrides should follow the base rule they override.' );
	}
}
