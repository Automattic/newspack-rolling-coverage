<?php
/**
 * Tests for the Check for Updates block and the feeds it switches to checking
 * only when readers ask.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Check_Updates_Block;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * A feed whose layout holds a Check for Updates block renders the button and
 * tells the view script not to poll; without one, or capped, it polls.
 */
class Test_Check_Updates extends Rolling_Coverage_TestCase {

	/**
	 * The block's markup, as the editor saves it.
	 */
	const CHECK_UPDATES_MARKUP = '<!-- wp:newspack-rolling-coverage/check-updates --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"tagName":"button"} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Check for Updates</button></div><!-- /wp:button --></div><!-- /wp:buttons --><!-- /wp:newspack-rolling-coverage/check-updates -->';

	/**
	 * Block types this test registered.
	 *
	 * @var string[]
	 */
	private $registered = [];

	/**
	 * Register the blocks when the build isn't there.
	 */
	public function set_up() {
		parent::set_up();

		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( [ Rolling_Coverage_Block::class, Check_Updates_Block::class ] as $block_class ) {
			if ( ! $registry->is_registered( $block_class::BLOCK_NAME ) ) {
				register_block_type( $block_class::BLOCK_NAME, $block_class::block_type_args() );
				$this->registered[] = $block_class::BLOCK_NAME;
			}
		}
	}

	/**
	 * Forget the blocks this test registered.
	 */
	public function tear_down() {
		foreach ( $this->registered as $name ) {
			unregister_block_type( $name );
		}

		$this->registered = [];

		parent::tear_down();
	}

	/**
	 * A feed of one coverage with a plain entry template, and optionally a
	 * Check for Updates block above the entries.
	 *
	 * @param array $attributes      Block attributes.
	 * @param bool  $with_the_button Whether the layout holds the block.
	 * @return string Rendered HTML.
	 */
	private static function render_feed( array $attributes, bool $with_the_button ): string {
		return do_blocks(
			'<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->'
			. '<!-- wp:group {"className":"newspack-rolling-coverage-feed"} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. ( $with_the_button ? self::CHECK_UPDATES_MARKUP : '' )
			. '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry"><!-- wp:post-title /--></div><!-- /wp:group -->'
			. '</div><!-- /wp:group -->'
			. '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->'
		);
	}

	/**
	 * Without the block, the feed polls.
	 */
	public function test_feed_without_the_block_polls() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$html = self::render_feed( [ 'coverageId' => $coverage_id ], false );

		$this->assertStringNotContainsString( 'data-new-entries=', $html );
	}

	/**
	 * With the block, the feed tells the view script not to poll, and the
	 * button renders hidden, before the entries, for the script to show.
	 */
	public function test_block_switches_the_feed_to_checking_on_request() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$html = self::render_feed( [ 'coverageId' => $coverage_id ], true );

		$this->assertStringContainsString( 'data-new-entries="button"', $html );
		$this->assertSame( 1, preg_match( '/<div class="wp-block-newspack-rolling-coverage-check-updates" hidden>.*?<button[^>]*>Check for Updates<\/button>/s', $html ) );
		$this->assertLessThan(
			strpos( $html, 'class="newspack-rolling-coverage-entries"' ),
			strpos( $html, 'wp-block-newspack-rolling-coverage-check-updates' )
		);
	}

	/**
	 * A capped feed always polls, and leaves the block out.
	 */
	public function test_capped_feed_ignores_the_block() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$html = self::render_feed(
			[
				'coverageId' => $coverage_id,
				'latestOnly' => true,
			],
			true
		);

		$this->assertStringNotContainsString( 'data-new-entries=', $html );
		$this->assertStringNotContainsString( 'wp-block-newspack-rolling-coverage-check-updates', $html );
	}

	/**
	 * An ended coverage gets no new entries, so the block drops out.
	 */
	public function test_ended_coverage_leaves_the_block_out() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		self::create_entry( $coverage_id );

		$html = self::render_feed( [ 'coverageId' => $coverage_id ], true );

		$this->assertStringNotContainsString( 'data-new-entries=', $html );
		$this->assertStringNotContainsString( 'wp-block-newspack-rolling-coverage-check-updates', $html );
	}

	/**
	 * Outside a Rolling Coverage block, the block renders nothing.
	 */
	public function test_block_outside_a_feed_renders_nothing() {
		$this->assertSame( '', trim( do_blocks( self::CHECK_UPDATES_MARKUP ) ) );
	}
}
