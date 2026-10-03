<?php
/**
 * Tests for the Follow Coverage block.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Coverage_Follow_Block;
use Newspack_Rolling_Coverage\Push_Notifications;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * The block's core "Follow" button follows the coverage around it, else the
 * chosen one, else the page's first feed, and renders nothing without one.
 */
class Test_Coverage_Follow_Block extends Rolling_Coverage_TestCase {

	/**
	 * The follow button, as the block holds it.
	 */
	const BUTTONS_MARKUP = '<!-- wp:buttons --><div class="wp-block-buttons">'
		. '<!-- wp:button {"tagName":"button","metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"followTag"}}}}} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Follow</button></div><!-- /wp:button -->'
		. '</div><!-- /wp:buttons -->';

	/**
	 * Whether this test registered the Rolling Coverage block itself.
	 *
	 * @var bool
	 */
	private $registered_feed = false;

	/**
	 * Register the blocks when the build isn't there, and stand in a
	 * configured OneSignal.
	 */
	public function set_up() {
		parent::set_up();
		$this->register_follow_block();

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Rolling_Coverage_Block::BLOCK_NAME ) ) {
			register_block_type( Rolling_Coverage_Block::BLOCK_NAME, Rolling_Coverage_Block::block_type_args() );
			$this->registered_feed = true;
		}

		self::configure_onesignal();
	}

	/**
	 * Unregister the Rolling Coverage block if the test registered it, and
	 * restore the request.
	 */
	public function tear_down() {
		if ( $this->registered_feed ) {
			unregister_block_type( Rolling_Coverage_Block::BLOCK_NAME );
			$this->registered_feed = false;
		}

		$this->go_to( home_url( '/' ) );
		parent::tear_down();
	}

	/**
	 * The block's markup, as the editor saves it.
	 *
	 * @param int $coverage_id Chosen coverage; 0 for Automatic.
	 * @return string
	 */
	private static function follow( int $coverage_id = 0 ): string {
		$attributes = $coverage_id ? ' ' . wp_json_encode( [ 'coverageId' => $coverage_id ] ) : '';

		return '<!-- wp:newspack-rolling-coverage/coverage-follow' . $attributes . ' -->' . self::BUTTONS_MARKUP . '<!-- /wp:newspack-rolling-coverage/coverage-follow -->';
	}

	/**
	 * A Rolling Coverage block's markup, holding the given coverage-level
	 * blocks above an entry group.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $header      Blocks above the entries.
	 * @return string
	 */
	private static function feed( int $coverage_id, string $header = '' ): string {
		$attributes = wp_json_encode( [ 'coverageId' => $coverage_id ] );

		if ( '' === $header ) {
			return '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . $attributes . ' /-->';
		}

		return '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . $attributes . ' -->'
			. $header
			. '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry"><!-- wp:post-title /--></div><!-- /wp:group -->'
			. '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->';
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
	 * Render markup the way core does while viewing a post.
	 *
	 * @param string $markup  Block markup.
	 * @param int    $post_id Post being viewed; 0 for the home page.
	 * @return string
	 */
	private function render( string $markup, int $post_id = 0 ): string {
		$this->go_to( $post_id ? get_permalink( $post_id ) : home_url( '/' ) );

		return do_blocks( $markup );
	}

	/**
	 * The notification tag of each follow button in the HTML, in order.
	 *
	 * @param string $html Rendered HTML.
	 * @return string[]
	 */
	private static function tags( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$tags      = [];

		while ( $processor->next_tag( 'button' ) ) {
			if ( null !== $processor->get_attribute( 'data-rc-follow' ) ) {
				$tags[] = (string) $processor->get_attribute( 'data-tag' );
			}
		}

		return $tags;
	}

	/**
	 * Inside a Rolling Coverage block it follows that block's coverage on any
	 * page, whatever coverage it was set to, and renders once above the
	 * entries.
	 */
	public function test_inside_a_feed_follows_that_feed_once_above_the_entries() {
		$coverage_id = self::create_coverage();
		$other_id    = self::create_coverage();
		self::create_entry( $coverage_id );
		self::create_entry( $coverage_id );

		$html = $this->render( self::feed( $coverage_id, self::follow( $other_id ) ) );

		$this->assertSame( [ Push_Notifications::follow_tag( $coverage_id ) ], self::tags( $html ) );
		$this->assertLessThan( strpos( $html, '<article' ), strpos( $html, 'data-rc-follow' ) );
	}

	/**
	 * The script that follows and unfollows is enqueued for a block inside a
	 * Rolling Coverage block.
	 */
	public function test_inside_a_feed_enqueues_the_follow_script() {
		$handles = WP_Block_Type_Registry::get_instance()->get_registered( Coverage_Follow_Block::BLOCK_NAME )->view_script_handles;

		if ( ! $handles ) {
			$this->markTestSkipped( 'The block is registered without a build, so it has no view script.' );
		}

		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$this->render( self::feed( $coverage_id, self::follow() ) );

		foreach ( $handles as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ), $handle . ' should be enqueued.' );
		}
	}

	/**
	 * A chosen coverage is followed on a page with no feed.
	 */
	public function test_follows_the_chosen_coverage_on_a_page_without_a_feed() {
		$coverage_id = self::create_coverage();
		$page_id     = self::page( self::follow( $coverage_id ) );

		$this->assertSame( [ Push_Notifications::follow_tag( $coverage_id ) ], self::tags( $this->render( self::follow( $coverage_id ), $page_id ) ) );
	}

	/**
	 * A chosen coverage wins over the page's feeds.
	 */
	public function test_chosen_coverage_wins_over_the_pages_feed() {
		$feed_id   = self::create_coverage();
		$chosen_id = self::create_coverage();
		$page_id   = self::page( self::feed( $feed_id ) );

		$this->assertSame( [ Push_Notifications::follow_tag( $chosen_id ) ], self::tags( $this->render( self::follow( $chosen_id ), $page_id ) ) );
	}

	/**
	 * Automatic follows the page's feed.
	 */
	public function test_automatic_follows_the_pages_feed() {
		$coverage_id = self::create_coverage();
		$page_id     = self::page( self::feed( $coverage_id ) );

		$this->assertSame( [ Push_Notifications::follow_tag( $coverage_id ) ], self::tags( $this->render( self::follow(), $page_id ) ) );
	}

	/**
	 * Automatic follows the first of the page's feeds.
	 */
	public function test_automatic_follows_the_first_of_two_feeds() {
		$first   = self::create_coverage();
		$second  = self::create_coverage();
		$page_id = self::page( self::feed( $first ) . self::feed( $second ) );

		$this->assertSame( [ Push_Notifications::follow_tag( $first ) ], self::tags( $this->render( self::follow(), $page_id ) ) );
	}

	/**
	 * Automatic renders nothing on a page with no feed, or away from a single
	 * view.
	 */
	public function test_automatic_without_a_feed_renders_nothing() {
		$page_id = self::page( '<!-- wp:paragraph --><p>No feed here</p><!-- /wp:paragraph -->' );

		$this->assertSame( '', $this->render( self::follow(), $page_id ) );
		$this->assertSame( '', $this->render( self::follow() ) );
	}

	/**
	 * A chosen coverage that can't be followed, gone or trashed, leaves the
	 * block on Automatic.
	 */
	public function test_chosen_coverage_that_cannot_be_followed_falls_back_to_the_page() {
		$feed_id    = self::create_coverage();
		$trashed_id = self::create_coverage( 'trash' );
		$page_id    = self::page( self::feed( $feed_id ) );
		$expected   = [ Push_Notifications::follow_tag( $feed_id ) ];

		$this->assertSame( $expected, self::tags( $this->render( self::follow( $trashed_id ), $page_id ) ) );
		$this->assertSame( $expected, self::tags( $this->render( self::follow( 999999 ), $page_id ) ) );
	}

	/**
	 * An archived coverage can't be followed, so nothing renders.
	 */
	public function test_archived_coverage_renders_nothing() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		$page_id     = self::page( self::feed( $coverage_id ) );

		$this->assertSame( '', $this->render( self::follow( $coverage_id ) ) );
		$this->assertSame( '', $this->render( self::follow(), $page_id ) );
	}

	/**
	 * Nothing renders while OneSignal isn't set up.
	 */
	public function test_renders_nothing_without_onesignal() {
		delete_option( 'OneSignalWPSetting' );

		$this->assertSame( '', $this->render( self::follow( self::create_coverage() ) ) );
	}

	/**
	 * Two blocks on one page each follow their own coverage.
	 */
	public function test_two_blocks_follow_their_own_coverages() {
		$first  = self::create_coverage();
		$second = self::create_coverage();

		$this->assertSame(
			[ Push_Notifications::follow_tag( $first ), Push_Notifications::follow_tag( $second ) ],
			self::tags( $this->render( self::follow( $first ) . self::follow( $second ) ) )
		);
	}

	/**
	 * The block adds no markup around the core Buttons block it holds.
	 */
	public function test_adds_no_markup_of_its_own() {
		$html = trim( $this->render( self::follow( self::create_coverage() ) ) );

		$this->assertStringStartsWith( '<div class="wp-block-buttons', $html );
		$this->assertStringEndsWith( '</div>', $html );
		$this->assertSame( 2, substr_count( $html, '<div' ), 'Only the Buttons and Button wrappers should render.' );
		$this->assertStringNotContainsString( 'coverage-follow', $html );
	}

	/**
	 * A follow button outside the block and any feed has no coverage, even
	 * right after a block that has one, so it renders nothing.
	 */
	public function test_coverage_does_not_reach_a_later_bound_button() {
		$coverage_id = self::create_coverage();

		$html = $this->render( self::follow( $coverage_id ) . self::BUTTONS_MARKUP );

		$this->assertSame( [ Push_Notifications::follow_tag( $coverage_id ) ], self::tags( $html ) );
		$this->assertSame( 1, substr_count( $html, '>Follow</button>' ) );
	}
}
