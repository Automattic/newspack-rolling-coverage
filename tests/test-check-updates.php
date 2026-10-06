<?php
/**
 * Tests for feeds that check for new entries only when the reader asks.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * A feed set to "Check for Updates button" renders the button and tells the
 * view script not to poll; a capped feed always polls.
 */
class Test_Check_Updates extends Rolling_Coverage_TestCase {

	/**
	 * Whether this test registered the Rolling Coverage block.
	 *
	 * @var bool
	 */
	private $registered_feed = false;

	/**
	 * Forget the Rolling Coverage block if the test registered it.
	 */
	public function tear_down() {
		if ( $this->registered_feed ) {
			unregister_block_type( Rolling_Coverage_Block::BLOCK_NAME );
			$this->registered_feed = false;
		}

		parent::tear_down();
	}

	/**
	 * A feed of one coverage with a plain entry template. Registers the block
	 * when the build isn't there.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	private function render_feed( array $attributes ): string {
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Rolling_Coverage_Block::BLOCK_NAME ) ) {
			register_block_type( Rolling_Coverage_Block::BLOCK_NAME, Rolling_Coverage_Block::block_type_args() );
			$this->registered_feed = true;
		}

		return do_blocks(
			'<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->'
			. '<!-- wp:group {"className":"newspack-rolling-coverage-feed"} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry"><!-- wp:post-title /--></div><!-- /wp:group -->'
			. '</div><!-- /wp:group -->'
			. '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->'
		);
	}

	/**
	 * By default the feed polls and has no Check for Updates button.
	 */
	public function test_feed_polls_by_default() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$html = $this->render_feed( [ 'coverageId' => $coverage_id ] );

		$this->assertStringNotContainsString( 'data-new-entries=', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-check-updates', $html );
	}

	/**
	 * In button mode the feed renders the button hidden, above the entries,
	 * for the view script to show, and tells the script not to poll.
	 */
	public function test_button_mode_renders_a_hidden_button_above_the_entries() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$html = $this->render_feed(
			[
				'coverageId' => $coverage_id,
				'newEntries' => 'button',
			]
		);

		$this->assertStringContainsString( 'data-new-entries="button"', $html );
		$this->assertMatchesRegularExpression( '/<div class="newspack-rolling-coverage-check-updates" hidden><button type="button" class="wp-element-button wp-block-button__link">Check for Updates<\/button><\/div>/', $html );
		$this->assertLessThan(
			strpos( $html, 'class="newspack-rolling-coverage-entries"' ),
			strpos( $html, 'newspack-rolling-coverage-check-updates' )
		);
	}

	/**
	 * A capped feed always polls, whatever the setting says.
	 */
	public function test_capped_feed_ignores_button_mode() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$html = $this->render_feed(
			[
				'coverageId' => $coverage_id,
				'newEntries' => 'button',
				'latestOnly' => true,
			]
		);

		$this->assertStringNotContainsString( 'data-new-entries=', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-check-updates', $html );
	}
}
