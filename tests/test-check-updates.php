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

		if ( ! $registry->is_registered( Rolling_Coverage_Block::BLOCK_NAME ) ) {
			register_block_type( Rolling_Coverage_Block::BLOCK_NAME, Rolling_Coverage_Block::block_type_args() );
			$this->registered[] = Rolling_Coverage_Block::BLOCK_NAME;
		}

		if ( ! $registry->is_registered( Check_Updates_Block::BLOCK_NAME ) ) {
			$metadata = json_decode( file_get_contents( NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'src/blocks/check-updates/block.json' ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown

			register_block_type(
				Check_Updates_Block::BLOCK_NAME,
				array_merge( Check_Updates_Block::block_type_args(), [ 'uses_context' => $metadata['usesContext'] ] )
			);
			$this->registered[] = Check_Updates_Block::BLOCK_NAME;
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
	 * A feed layout: a plain entry template, with blocks above and below it.
	 *
	 * @param string $header Markup above the entries.
	 * @param string $footer Markup below the entries.
	 * @return string The Feed group's markup.
	 */
	private static function feed_markup( string $header, string $footer = '' ): string {
		return '<!-- wp:group {"className":"newspack-rolling-coverage-feed"} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. $header
			. '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry"><!-- wp:post-title /--></div><!-- /wp:group -->'
			. $footer
			. '</div><!-- /wp:group -->';
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
		return self::render_layout( $attributes, self::feed_markup( $with_the_button ? self::CHECK_UPDATES_MARKUP : '' ) );
	}

	/**
	 * A Rolling Coverage block holding a layout.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $layout     The block's inner markup; none for a synced layout.
	 * @return string Rendered HTML.
	 */
	private static function render_layout( array $attributes, string $layout ): string {
		$name = 'newspack-rolling-coverage/rolling-coverage';

		return do_blocks(
			'' === $layout
				? '<!-- wp:' . $name . ' ' . wp_json_encode( $attributes ) . ' /-->'
				: '<!-- wp:' . $name . ' ' . wp_json_encode( $attributes ) . ' -->' . $layout . '<!-- /wp:' . $name . ' -->'
		);
	}

	/**
	 * How many Check for Updates blocks the HTML shows the view script.
	 *
	 * @param string $html Rendered HTML.
	 * @return int
	 */
	private static function check_buttons_in( string $html ): int {
		return preg_match_all( '/<div class="[^"]*\bnewspack-rolling-coverage-check-updates\b[^"]*" hidden>.*?<button[^>]*>Check for Updates<\/button>/s', $html );
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
		$this->assertSame( 1, self::check_buttons_in( $html ) );
		$this->assertLessThan(
			strpos( $html, 'class="newspack-rolling-coverage-entries"' ),
			strpos( $html, 'newspack-rolling-coverage-check-updates' )
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
		$this->assertSame( 0, self::check_buttons_in( $html ) );
	}

	/**
	 * An ended coverage gets no new entries, so the block drops out.
	 */
	public function test_ended_coverage_leaves_the_block_out() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		self::create_entry( $coverage_id );

		$html = self::render_feed( [ 'coverageId' => $coverage_id ], true );

		$this->assertStringNotContainsString( 'data-new-entries=', $html );
		$this->assertSame( 0, self::check_buttons_in( $html ) );
	}

	/**
	 * A paused coverage may resume, so the feed keeps the button.
	 */
	public function test_paused_coverage_keeps_the_block() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_PAUSED );
		self::create_entry( $coverage_id );

		$html = self::render_feed( [ 'coverageId' => $coverage_id ], true );

		$this->assertStringContainsString( 'data-new-entries="button"', $html );
		$this->assertSame( 1, self::check_buttons_in( $html ) );
	}

	/**
	 * The block switches the feed from a footer group too, and every copy
	 * renders for the view script.
	 */
	public function test_block_in_a_footer_group_switches_the_feed_and_every_copy_renders() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$html = self::render_layout(
			[ 'coverageId' => $coverage_id ],
			self::feed_markup(
				self::CHECK_UPDATES_MARKUP,
				'<!-- wp:group --><div class="wp-block-group">' . self::CHECK_UPDATES_MARKUP . '</div><!-- /wp:group -->'
			)
		);

		$this->assertStringContainsString( 'data-new-entries="button"', $html );
		$this->assertSame( 2, self::check_buttons_in( $html ) );
	}

	/**
	 * A synced layout holding the block switches the feed.
	 */
	public function test_synced_layout_with_the_block_switches_the_feed() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		$layout_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-rolling-coverage/rolling-coverage -->' . self::feed_markup( self::CHECK_UPDATES_MARKUP ) . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->',
			]
		);

		$html = self::render_layout(
			[
				'coverageId' => $coverage_id,
				'layoutId'   => $layout_id,
			],
			''
		);

		$this->assertStringContainsString( 'data-new-entries="button"', $html );
		$this->assertSame( 1, self::check_buttons_in( $html ) );
	}

	/**
	 * Outside a Rolling Coverage block, the block renders nothing.
	 */
	public function test_block_outside_a_feed_renders_nothing() {
		$this->assertSame( '', trim( do_blocks( self::CHECK_UPDATES_MARKUP ) ) );
	}
}
