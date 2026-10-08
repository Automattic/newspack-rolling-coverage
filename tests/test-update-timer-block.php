<?php
/**
 * Tests for the Update Timer block.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Entry_Bindings;
use Newspack_Rolling_Coverage\Lite_Feed;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;
use Newspack_Rolling_Coverage\Update_Timer_Block;

/**
 * The block renders hidden with the coverage it follows, for the feed's
 * script to show, and nothing where it could never count down.
 */
class Test_Update_Timer_Block extends Rolling_Coverage_TestCase {

	/**
	 * Whether this test registered the Rolling Coverage block itself.
	 *
	 * @var bool
	 */
	private $registered_feed = false;

	/**
	 * Register the block from its metadata when the build isn't there, and
	 * the Rolling Coverage block, so the feeds the tests use render.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Rolling_Coverage_Block::BLOCK_NAME ) ) {
			register_block_type( Rolling_Coverage_Block::BLOCK_NAME, Rolling_Coverage_Block::block_type_args() );
			$this->registered_feed = true;
		}

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Update_Timer_Block::BLOCK_NAME ) ) {
			$metadata = wp_json_file_decode( NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'src/blocks/update-timer/block.json', [ 'associative' => true ] );

			register_block_type(
				Update_Timer_Block::BLOCK_NAME,
				[
					'attributes'      => $metadata['attributes'],
					'supports'        => $metadata['supports'],
					'uses_context'    => $metadata['usesContext'],
					'render_callback' => [ Update_Timer_Block::class, 'render_block' ],
				]
			);
		}
	}

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
	 * A Rolling Coverage block's markup.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string
	 */
	private static function feed( int $coverage_id ): string {
		return '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} /-->';
	}

	/**
	 * The block's markup.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	private static function timer( array $attributes = [] ): string {
		return '<!-- wp:newspack-rolling-coverage/update-timer ' . wp_json_encode( (object) $attributes ) . ' /-->';
	}

	/**
	 * Create a page holding the given block markup.
	 *
	 * @param string $content Post content.
	 * @return int Page ID.
	 */
	private static function page( string $content ): int {
		return self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => $content,
			]
		);
	}

	/**
	 * Render the block on the given post's page.
	 *
	 * @param array $attributes Block attributes.
	 * @param int   $post_id    Post being viewed; 0 to keep the current view.
	 * @return string
	 */
	private function render( array $attributes = [], int $post_id = 0 ): string {
		if ( $post_id ) {
			$this->go_to( get_permalink( $post_id ) );
		}

		return do_blocks( self::timer( $attributes ) );
	}

	/**
	 * It renders hidden, with the ring and an empty text, for the script to
	 * show once a feed is counting down.
	 */
	public function test_renders_hidden_with_the_ring() {
		$coverage_id = self::create_coverage();
		$html        = $this->render( [], self::page( self::feed( $coverage_id ) ) );

		$this->assertMatchesRegularExpression( '/<div [^>]*data-coverage-id="' . $coverage_id . '"[^>]* hidden>/', $html );
		$this->assertStringContainsString( 'class="newspack-rolling-coverage-update-timer__ring"', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		$this->assertStringContainsString( '<circle', $html );
		$this->assertStringContainsString( '<span class="newspack-rolling-coverage-update-timer__text"></span>', $html );
	}

	/**
	 * With no choice made, it follows the first feed on the page.
	 */
	public function test_follows_the_first_feed() {
		$first  = self::create_coverage();
		$second = self::create_coverage();

		$this->assertStringContainsString( 'data-coverage-id="' . $first . '"', $this->render( [], self::page( self::feed( $first ) . self::feed( $second ) ) ) );
	}

	/**
	 * A chosen coverage wins over the page's, and a gone one falls back to it.
	 */
	public function test_custom_coverage_and_fallback() {
		$feed_id   = self::create_coverage();
		$chosen_id = self::create_coverage();
		$page_id   = self::page( self::feed( $feed_id ) );

		$this->assertStringContainsString( 'data-coverage-id="' . $chosen_id . '"', $this->render( [ 'coverageId' => $chosen_id ], $page_id ) );
		$this->assertStringContainsString( 'data-coverage-id="' . $feed_id . '"', $this->render( [ 'coverageId' => 999999 ], $page_id ) );
	}

	/**
	 * Inside a feed it follows that feed's coverage, whatever it chose.
	 */
	public function test_follows_the_feed_around_it() {
		$feed_id   = self::create_coverage();
		$chosen_id = self::create_coverage();
		$parsed    = parse_blocks( self::timer( [ 'coverageId' => $chosen_id ] ) )[0];
		$html      = ( new WP_Block( $parsed, [ Entry_Bindings::COVERAGE_ID_CONTEXT => $feed_id ] ) )->render();

		$this->assertStringContainsString( 'data-coverage-id="' . $feed_id . '"', $html );
	}

	/**
	 * Nothing without a coverage, or once it has ended; a paused coverage
	 * keeps it, since the feed counts down again when it resumes.
	 */
	public function test_renders_nothing_without_a_live_or_paused_coverage() {
		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', $this->render() );

		$this->assertSame( '', $this->render( [ 'coverageId' => self::create_coverage( Taxonomy::STATUS_ARCHIVED ) ] ) );
		$this->assertStringContainsString( ' hidden>', $this->render( [ 'coverageId' => self::create_coverage( Taxonomy::STATUS_PAUSED ) ] ) );
	}

	/**
	 * A lite page strips the markup the timer needs and runs no script for it.
	 */
	public function test_renders_nothing_on_a_lite_page() {
		require_once __DIR__ . '/mocks/class-lite-site.php';

		$coverage_id = self::create_coverage();

		add_filter( Lite_Feed::CONTENT_FILTER, 'do_blocks', 9 );

		try {
			$lite_render = apply_filters( Lite_Feed::CONTENT_FILTER, self::timer( [ 'coverageId' => $coverage_id ] ) );
		} finally {
			remove_filter( Lite_Feed::CONTENT_FILTER, 'do_blocks', 9 );
		}

		$this->assertSame( '', trim( $lite_render ) );
	}

	/**
	 * Inside a feed it renders once for the coverage, not in every entry.
	 */
	public function test_is_a_coverage_item() {
		$this->assertTrue( Entry_Bindings::is_coverage_item( parse_blocks( self::timer() )[0] ) );
	}
}
