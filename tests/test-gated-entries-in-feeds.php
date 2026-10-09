<?php
/**
 * Tests that syndication feeds show gated entries as their teasers.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * Newspack's content gate stands aside in syndication feeds and leaves them
 * to its feed setting, which judges the host post rather than the entries
 * its feed lists (see Rolling_Coverage_Block::withhold_gated_entry_in_feed()).
 * The suite runs without Newspack, so these tests use stand-ins for the gate
 * and its feed setting.
 */
class Test_Gated_Entries_In_Feeds extends Rolling_Coverage_TestCase {

	/**
	 * What the gate shows of the gated entry.
	 */
	const TEASER = '<p>Teaser text</p>';

	/**
	 * Coverage under test.
	 *
	 * @var int
	 */
	private $coverage_id;

	/**
	 * Its entry behind the gate.
	 *
	 * @var int
	 */
	private $gated_id;

	/**
	 * A coverage with a gated entry and an open one, and a syndication feed
	 * as the request.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( '\\Newspack\\Content_Gate_Advanced_Settings' ) ) {
			require_once __DIR__ . '/stubs/class-content-gate-advanced-settings.php';
		}

		if ( ! defined( '\\Newspack\\Content_Gate_Advanced_Settings::IS_TEST_STUB' ) ) {
			$this->markTestSkipped( 'Newspack is loaded; its feed setting is tested there.' );
		}

		$this->coverage_id = self::create_coverage();
		$this->gated_id    = self::create_entry(
			$this->coverage_id,
			[
				'post_date'    => '2026-01-01 12:00:00',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>Gated body</p><!-- /wp:paragraph -->',
			]
		);
		self::create_entry(
			$this->coverage_id,
			[
				'post_date'    => '2026-01-01 11:00:00',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>Open body</p><!-- /wp:paragraph -->',
			]
		);
		$this->gate_entry( $this->gated_id, self::TEASER );

		$this->go_to( get_feed_link() );
	}

	/**
	 * Put the stand-in's feed setting back.
	 */
	public function tear_down() {
		if ( defined( '\\Newspack\\Content_Gate_Advanced_Settings::IS_TEST_STUB' ) ) {
			\Newspack\Content_Gate_Advanced_Settings::$feed_mode = 'truncate';
			\Newspack\Content_Gate_Advanced_Settings::$contexts  = [];
		}

		parent::tear_down();
	}

	/**
	 * Render the coverage's feed with a layout.
	 *
	 * @param string $layout Serialized blocks each entry renders.
	 * @return string
	 */
	private function render_feed( string $layout ): string {
		$attributes = [ 'coverageId' => $this->coverage_id ];
		$markup     = '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $layout . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->';

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( parse_blocks( $markup )[0] ) );
	}

	/**
	 * Layouts that show an entry's words: its content, and the excerpt core
	 * builds from it for the Post Excerpt block, which reads the entry by ID.
	 *
	 * @return array[]
	 */
	public function data_layouts(): array {
		return [
			'content' => [ '<!-- wp:post-content /-->' ],
			'excerpt' => [ '<!-- wp:post-excerpt {"excerptLength":100} /-->' ],
		];
	}

	/**
	 * A feed that withholds restricted articles shows a gated entry's teaser,
	 * and an entry no gate covers in full.
	 *
	 * @dataProvider data_layouts
	 *
	 * @param string $layout Serialized blocks each entry renders.
	 */
	public function test_gated_entries_show_their_teaser_in_a_feed( string $layout ) {
		$html = $this->render_feed( $layout );

		$this->assertTrue( is_feed() );
		$this->assertStringContainsString( 'Teaser text', $html );
		$this->assertStringNotContainsString( 'Gated body', $html );
		$this->assertStringContainsString( 'Open body', $html );
	}

	/**
	 * A feed set to include restricted articles in full keeps gated entries
	 * whole. The setting is asked for this feed's query, where a feed's own
	 * setting overrides the site's.
	 */
	public function test_a_feed_that_includes_restricted_articles_in_full_keeps_gated_entries_whole() {
		\Newspack\Content_Gate_Advanced_Settings::$feed_mode = 'off';

		$html = $this->render_feed( '<!-- wp:post-content /-->' );

		$this->assertStringContainsString( 'Gated body', $html );
		$this->assertStringNotContainsString( 'Teaser text', $html );
		$this->assertSame( $GLOBALS['wp_query'], end( \Newspack\Content_Gate_Advanced_Settings::$contexts )['query'] );
	}
}
