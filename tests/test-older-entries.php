<?php
/**
 * Tests for how the feed loads entries older than its first page.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * The Older entries setting: the sentinel for loading on scroll, the Load
 * More button, or neither.
 */
class Test_Older_Entries extends Rolling_Coverage_TestCase {

	/**
	 * An entry's text, then the separator that closes it.
	 */
	const TEMPLATE_MARKUP = '<!-- wp:paragraph --><p>Entry text</p><!-- /wp:paragraph -->'
		. '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->';

	/**
	 * A group below the entries, held there by the Follow Coverage block.
	 */
	const FOOTER_MARKUP = '<!-- wp:group --><div class="wp-block-group">'
		. '<!-- wp:paragraph --><p>Coverage footer</p><!-- /wp:paragraph -->'
		. '<!-- wp:newspack-rolling-coverage/coverage-follow --><!-- /wp:newspack-rolling-coverage/coverage-follow -->'
		. '</div><!-- /wp:group -->';

	/**
	 * Render the block holding a layout.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $markup     The block's inner blocks, as the editor saves them.
	 * @return string
	 */
	private static function render_block( array $attributes, string $markup = self::TEMPLATE_MARKUP ): string {
		$block = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $markup . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * A coverage with three entries, an hour apart.
	 *
	 * @return int Coverage term ID.
	 */
	private static function create_coverage_with_entries(): int {
		$coverage_id = self::create_coverage();

		foreach ( [ '10:00:00', '11:00:00', '12:00:00' ] as $time ) {
			self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 ' . $time ] );
		}

		return $coverage_id;
	}

	/**
	 * With the setting unset or unknown, more entries load on scroll.
	 */
	public function test_entries_load_on_scroll_by_default() {
		$coverage_id = self::create_coverage_with_entries();

		foreach ( [ [], [ 'olderEntries' => 'scroll' ], [ 'olderEntries' => 'unknown' ] ] as $setting ) {
			$html = self::render_block(
				array_merge(
					[
						'coverageId'     => $coverage_id,
						'entriesPerPage' => 2,
					],
					$setting
				)
			);

			$this->assertStringContainsString( 'newspack-rolling-coverage-sentinel', $html );
			$this->assertStringContainsString( 'data-has-more="1"', $html );
			$this->assertStringNotContainsString( 'newspack-rolling-coverage-load-more', $html );
		}
	}

	/**
	 * The Load More button takes the sentinel's place, styled as the theme
	 * styles buttons, right after the entries and above the blocks below
	 * them. It renders hidden, for the view script to show while more
	 * entries remain, so a page without the script never offers it.
	 */
	public function test_load_more_button_follows_the_entries() {
		$coverage_id = self::create_coverage_with_entries();
		$html        = self::render_block(
			[
				'coverageId'     => $coverage_id,
				'entriesPerPage' => 2,
				'olderEntries'   => 'button',
			],
			self::TEMPLATE_MARKUP . self::FOOTER_MARKUP
		);

		$this->assertStringNotContainsString( 'newspack-rolling-coverage-sentinel', $html );
		$this->assertStringContainsString( 'data-has-more="1"', $html );
		$this->assertStringContainsString( '<div class="newspack-rolling-coverage-load-more" hidden><button type="button" class="wp-element-button wp-block-button__link">Load More</button></div>', $html );

		$entries   = strpos( $html, 'class="newspack-rolling-coverage-entries"' );
		$load_more = strpos( $html, 'newspack-rolling-coverage-load-more' );
		$footer    = strpos( $html, 'Coverage footer' );

		$this->assertNotFalse( $footer );
		$this->assertGreaterThan( $entries, $load_more, 'The button should come after the entries.' );
		$this->assertLessThan( $footer, $load_more, 'The button should come before the blocks below the entries.' );
	}

	/**
	 * A coverage with exactly one page of entries has nothing more to load,
	 * so the button is never offered for a page that would load nothing.
	 */
	public function test_exactly_one_page_of_entries_leaves_nothing_more_to_load() {
		$coverage_id = self::create_coverage_with_entries();
		$html        = self::render_block(
			[
				'coverageId'     => $coverage_id,
				'entriesPerPage' => 3,
				'olderEntries'   => 'button',
			]
		);

		preg_match_all( '/data-entry-id="(\d+)"/', $html, $matches );

		$this->assertCount( 3, $matches[1] );
		$this->assertStringContainsString( 'data-has-more="0"', $html );
	}

	/**
	 * A page of older entries that ends at the coverage's oldest entry
	 * reports nothing more to load.
	 */
	public function test_older_page_ending_at_the_oldest_entry_leaves_nothing_more_to_load() {
		$coverage_id = self::create_coverage_with_entries();
		$html        = self::render_block(
			[
				'coverageId'     => $coverage_id,
				'entriesPerPage' => 1,
				'olderEntries'   => 'button',
			]
		);

		preg_match( '/data-before="([^"]*)"/', $html, $before );
		preg_match( '/data-template-key="([^"]*)"/', $html, $template_key );

		$data = self::dispatch(
			'GET',
			'/coverages/' . $coverage_id . '/entries',
			[
				'before'       => html_entity_decode( $before[1] ),
				'per_page'     => 2,
				'template_key' => $template_key[1],
			]
		)->get_data();

		$this->assertSame( 2, $data['count'] );
		$this->assertFalse( $data['hasMore'] );
	}

	/**
	 * Don't load shows the first page alone: no sentinel or button, nothing
	 * more to load, and the last entry drops its closing separator as the
	 * last entry of a feed does.
	 */
	public function test_dont_load_shows_the_first_page_alone() {
		$coverage_id = self::create_coverage_with_entries();
		$html        = self::render_block(
			[
				'coverageId'     => $coverage_id,
				'entriesPerPage' => 2,
				'olderEntries'   => 'none',
			]
		);

		preg_match_all( '/data-entry-id="(\d+)"/', $html, $matches );

		$this->assertCount( 2, $matches[1] );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-sentinel', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-load-more', $html );
		$this->assertStringContainsString( 'data-has-more="0"', $html );
		$this->assertSame( 1, substr_count( $html, 'wp-block-separator' ), 'Only the first entry should keep its separator.' );
	}

	/**
	 * A capped feed loads no older entries, whatever the setting.
	 */
	public function test_capped_feed_ignores_the_setting() {
		$coverage_id = self::create_coverage_with_entries();
		$html        = self::render_block(
			[
				'coverageId'   => $coverage_id,
				'latestOnly'   => true,
				'latestCount'  => 2,
				'olderEntries' => 'button',
			]
		);

		$this->assertStringNotContainsString( 'newspack-rolling-coverage-load-more', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-sentinel', $html );
		$this->assertStringContainsString( 'data-has-more="0"', $html );
	}

	/**
	 * A syndication feed never shows the button, which does nothing there.
	 */
	public function test_load_more_button_is_left_out_of_syndication_feeds() {
		$coverage_id = self::create_coverage_with_entries();

		$this->go_to( get_feed_link() );

		$html = self::render_block(
			[
				'coverageId'     => $coverage_id,
				'entriesPerPage' => 2,
				'olderEntries'   => 'button',
			]
		);

		$this->assertTrue( is_feed() );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-load-more', $html );
	}
}
