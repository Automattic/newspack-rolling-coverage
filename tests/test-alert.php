<?php
/**
 * Tests for the Alert layout.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * A row of the coverage's status, its name and a link to the coverage page,
 * with no entries.
 */
class Test_Alert extends Rolling_Coverage_TestCase {

	const FEED_MARKUP = '<!-- wp:group {"className":"newspack-rolling-coverage-feed","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"horizontal","flexWrap":"nowrap","justifyContent":"left","verticalAlignment":"center"}} --><div class="wp-block-group newspack-rolling-coverage-feed">'
		. '<!-- wp:newspack-rolling-coverage/coverage-status /-->'
		. '<!-- wp:heading {"level":3,"className":"newspack-rolling-coverage-name","metadata":{"bindings":{"content":{"source":"newspack-rolling-coverage/entry","args":{"key":"coverageName"}}}}} --><h3 class="wp-block-heading newspack-rolling-coverage-name">Live Coverage</h3><!-- /wp:heading -->'
		. '<!-- wp:paragraph {"className":"use-header-font newspack-rolling-coverage-all-updates"} --><p class="use-header-font newspack-rolling-coverage-all-updates"><a href="#">See all entries</a></p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->';

	const ENTRY_FEED_MARKUP = '<!-- wp:group {"className":"newspack-rolling-coverage-feed","layout":{"type":"flex","orientation":"vertical"}} --><div class="wp-block-group newspack-rolling-coverage-feed">'
		. '<!-- wp:post-title /-->'
		. '<!-- wp:paragraph {"className":"use-header-font newspack-rolling-coverage-all-updates"} --><p class="use-header-font newspack-rolling-coverage-all-updates"><a href="#">See all entries</a></p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->';

	/**
	 * Register the Coverage Status block the row holds.
	 */
	public function set_up() {
		parent::set_up();
		$this->register_status_block();
	}

	/**
	 * Render a coverage with the attributes the Alert layout sets when picked.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $feed        Feed markup, the Alert's by default.
	 * @return string Rendered block.
	 */
	private static function render_alert( int $coverage_id, string $feed = self::FEED_MARKUP ): string {
		$attributes = [
			'coverageId'    => $coverage_id,
			'latestOnly'    => true,
			'latestCount'   => 1,
			'hideWhenEnded' => true,
		];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $feed . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * The row shows the status, the coverage's name and the link, renders no
	 * entries, and tells the view script not to add any.
	 */
	public function test_renders_the_row_without_entries() {
		$coverage_id = self::create_coverage( '', [ 'name' => 'Downtown Water Main Break' ] );
		self::create_entry( $coverage_id, [ 'post_title' => 'Crews reach the break' ] );
		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/water-main/' ) );

		$html = self::render_alert( $coverage_id );

		$this->assertStringContainsString( 'wp-block-newspack-rolling-coverage-coverage-status', $html );
		$this->assertMatchesRegularExpression( '#<h3[^>]*newspack-rolling-coverage-name[^>]*>Downtown Water Main Break</h3>#', $html );
		$this->assertStringContainsString( 'href="' . home_url( '/water-main/' ) . '"', $html );
		$this->assertStringContainsString( '<div class="newspack-rolling-coverage-entries"></div>', $html );
		$this->assertStringNotContainsString( 'Crews reach the break', $html );
		$this->assertStringContainsString( 'data-entries="none"', $html );
	}

	/**
	 * A coverage without entries shows the row without the "no entries"
	 * message, which has no place in a layout that shows none.
	 */
	public function test_an_empty_coverage_shows_no_empty_message() {
		$coverage_id = self::create_coverage();
		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/water-main/' ) );

		$html = self::render_alert( $coverage_id );

		$this->assertStringNotContainsString( 'newspack-rolling-coverage-entries__empty', $html );
		$this->assertStringContainsString( 'newspack-rolling-coverage-all-updates', $html );
	}

	/**
	 * A layout with an entry template keeps the "no entries" message and
	 * lets the view script add entries.
	 */
	public function test_a_layout_with_entries_keeps_its_empty_message() {
		$html = self::render_alert( self::create_coverage(), self::ENTRY_FEED_MARKUP );

		$this->assertStringContainsString( 'newspack-rolling-coverage-entries__empty', $html );
		$this->assertStringNotContainsString( 'data-entries=', $html );
	}
}
