<?php
/**
 * Tests for the ads a Rolling Coverage feed places between its entries.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * The block places an ad after every few entries, only where a page can
 * fill it.
 */
class Test_Ads extends Rolling_Coverage_TestCase {

	/**
	 * Render the block with the default entry template.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	private static function render_block( array $attributes ): string {
		$block = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * A syndication feed carries no ads. Feed readers run no ad code, so the
	 * slot would stay empty, and apps that read the feed place their own. The
	 * same block on a page shows them.
	 */
	public function test_syndication_feeds_carry_no_ads() {
		self::enable_ad_placement();

		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$attributes = [
			'coverageId'  => $coverage_id,
			'enableAds'   => true,
			'adsInterval' => 1,
		];

		$this->assertStringContainsString( 'test-ad-code', self::render_block( $attributes ), 'A page shows an ad.' );

		$this->go_to( get_feed_link() );

		$feed = self::render_block( $attributes );

		$this->assertTrue( is_feed() );
		$this->assertStringNotContainsString( 'test-ad-code', $feed, 'A feed shows none.' );
		$this->assertStringNotContainsString( 'data-ads=', $feed, 'Nor does it tell the view script to expect any.' );
	}
}
