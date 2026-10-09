<?php
/**
 * Tests that the feed shows gated entries as their teasers where Newspack's
 * content gate stands aside.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * Newspack's content gate stands aside for a whole request on some pages,
 * such as WooCommerce's cart, and in syndication feeds, which it leaves to
 * its feed setting (see Rolling_Coverage_Block::withhold_gated_entry()).
 * The suite runs without Newspack, so these tests use stand-ins for the gate
 * and its feed setting. The gate stand-in stages nothing, as the real gate
 * does where it stands aside.
 */
class Test_Entry_Teasers extends Rolling_Coverage_TestCase {

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
	 * A coverage with a gated entry and an open one.
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
		$gated_id          = self::create_entry(
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
		$this->gate_entry( $gated_id, self::TEASER );
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
	 * Each layout that shows an entry's words (its content, and the excerpt
	 * core builds from it for Post Excerpt, which reads the entry by ID), on
	 * a page the gate stands aside on and in an RSS feed, under each feed
	 * setting, with whether the gated entry shows whole.
	 *
	 * @return array[]
	 */
	public function data_requests(): array {
		$layouts = [
			'content' => '<!-- wp:post-content /-->',
			'excerpt' => '<!-- wp:post-excerpt {"excerptLength":100} /-->',
		];
		$cases   = [];

		foreach ( $layouts as $layout_name => $layout ) {
			foreach ( [ 'truncate', 'exclude', 'off' ] as $mode ) {
				$cases[ "{$layout_name}, page, feed setting {$mode}" ] = [ $layout, false, $mode, false ];
				$cases[ "{$layout_name}, RSS, feed setting {$mode}" ]  = [ $layout, true, $mode, 'off' === $mode ];
			}
		}

		return $cases;
	}

	/**
	 * A gated entry shows its teaser where the gate stands aside, and an open
	 * entry its body. In an RSS feed the gate's feed setting decides, asked
	 * for the feed's own query, where a feed's own setting overrides the
	 * site's: only a feed that includes restricted articles in full shows the
	 * gated entry whole. Pages don't follow the feed setting.
	 *
	 * @dataProvider data_requests
	 *
	 * @param string $layout       Serialized blocks each entry renders.
	 * @param bool   $is_feed      Whether the request is an RSS feed.
	 * @param string $feed_mode    The gate's feed setting.
	 * @param bool   $gated_whole  Whether the gated entry should show whole.
	 */
	public function test_gated_entries_show_their_teaser_where_the_gate_stands_aside( string $layout, bool $is_feed, string $feed_mode, bool $gated_whole ) {
		\Newspack\Content_Gate_Advanced_Settings::$feed_mode = $feed_mode;
		$this->go_to( $is_feed ? get_feed_link() : home_url( '/' ) );

		$html = $this->render_feed( $layout );

		$this->assertSame( $is_feed, is_feed() );
		$this->assertStringContainsString( 'Open body', $html );
		$this->assertSame( $gated_whole, false !== strpos( $html, 'Gated body' ), 'The gated entry should show whole only where the feed setting includes it in full.' );
		$this->assertSame( ! $gated_whole, false !== strpos( $html, 'Teaser text' ), 'The gated entry should show its teaser everywhere else.' );

		if ( $is_feed ) {
			$this->assertSame( $GLOBALS['wp_query'], end( \Newspack\Content_Gate_Advanced_Settings::$contexts )['query'] );
		}
	}
}
