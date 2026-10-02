<?php
/**
 * Tests for the Coverage Status block.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Coverage_Status_Block;
use Newspack_Rolling_Coverage\Newest_Entry;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Status_Labels;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * The block shows the status of the Rolling Coverage block on its page, and
 * nothing on a page without one.
 */
class Test_Coverage_Status_Block extends Rolling_Coverage_TestCase {

	/**
	 * Register the block from its metadata when the build isn't there, so its
	 * `postId` context reaches the render callback.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Coverage_Status_Block::BLOCK_NAME ) ) {
			register_block_type(
				Coverage_Status_Block::BLOCK_NAME,
				[
					'uses_context'    => [ 'postId' ],
					'render_callback' => [ Coverage_Status_Block::class, 'render_block' ],
				]
			);
		}
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
	 * Render the block the way core does on the given post's page: the post is
	 * the queried one and core supplies the context.
	 *
	 * @param array $attributes Block attributes.
	 * @param int   $post_id    Post being viewed; 0 to keep the current view.
	 * @return string
	 */
	private function render( array $attributes = [], int $post_id = 0 ): string {
		if ( $post_id ) {
			$this->go_to( get_permalink( $post_id ) );
		}

		return do_blocks( '<!-- wp:newspack-rolling-coverage/coverage-status ' . wp_json_encode( (object) $attributes ) . ' /-->' );
	}

	/**
	 * With no choice made, it follows the first feed on the page.
	 */
	public function test_follows_the_first_feed() {
		$first   = self::create_coverage();
		$second  = self::create_coverage();
		$page_id = self::page( self::feed( $first ) . self::feed( $second ) );

		$this->assertStringContainsString( 'data-coverage-id="' . $first . '"', $this->render( [], $page_id ) );
	}

	/**
	 * A chosen feed wins; a chosen feed no longer on the page falls back to
	 * the first.
	 */
	public function test_follows_the_chosen_feed_while_it_is_on_the_page() {
		$first   = self::create_coverage();
		$second  = self::create_coverage();
		$gone    = self::create_coverage();
		$page_id = self::page( self::feed( $first ) . self::feed( $second ) );

		$this->assertStringContainsString( 'data-coverage-id="' . $second . '"', $this->render( [ 'coverageId' => $second ], $page_id ) );
		$this->assertStringContainsString( 'data-coverage-id="' . $first . '"', $this->render( [ 'coverageId' => $gone ], $page_id ) );
	}

	/**
	 * Feeds nested in groups and synced patterns count; a feed with no
	 * coverage chosen yet does not.
	 */
	public function test_finds_nested_feeds_and_skips_unset_ones() {
		$coverage_id = self::create_coverage();
		$pattern_id  = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:group --><div class="wp-block-group">' . self::feed( $coverage_id ) . '</div><!-- /wp:group -->',
			]
		);
		$page_id     = self::page( self::feed( 0 ) . '<!-- wp:block {"ref":' . $pattern_id . '} /-->' );

		$this->assertStringContainsString( 'data-coverage-id="' . $coverage_id . '"', $this->render( [], $page_id ) );
	}

	/**
	 * A synced pattern that includes itself is read once.
	 */
	public function test_survives_a_synced_pattern_that_includes_itself() {
		$pattern_id = self::factory()->post->create( [ 'post_type' => 'wp_block' ] );
		wp_update_post(
			[
				'ID'           => $pattern_id,
				'post_content' => '<!-- wp:block {"ref":' . $pattern_id . '} /-->',
			]
		);
		$page_id = self::page( '<!-- wp:block {"ref":' . $pattern_id . '} /-->' );

		$this->assertSame( '', $this->render( [], $page_id ) );
	}

	/**
	 * No feed, or only feeds of missing or trashed coverages: nothing.
	 */
	public function test_renders_nothing_without_a_feed() {
		$trashed = self::create_coverage( 'trash' );
		$live    = self::create_coverage();

		$this->assertSame( '', $this->render( [], self::page( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ) ) );
		$this->assertSame( '', $this->render( [], self::page( self::feed( $trashed ) . self::feed( 999999 ) ) ) );
		$this->assertStringContainsString( 'data-coverage-id="' . $live . '"', $this->render( [], self::page( self::feed( $trashed ) . self::feed( $live ) ) ), 'A trashed coverage is skipped.' );
	}

	/**
	 * It follows the page being viewed, and renders nothing on views that
	 * aren't a single post or page, even though core hands it the first listed
	 * post as context there.
	 */
	public function test_renders_on_single_views_only() {
		$coverage_id = self::create_coverage();
		$page_id     = self::page( self::feed( $coverage_id ) );

		$this->go_to( get_permalink( $page_id ) );
		$this->assertStringContainsString( 'data-coverage-id="' . $coverage_id . '"', $this->render() );

		$post_id = self::factory()->post->create( [ 'post_content' => self::feed( $coverage_id ) ] );
		$this->go_to( home_url( '/' ) );
		$this->assertSame( $post_id, $GLOBALS['post']->ID, 'The post is the first one listed, so core passes it as context.' );
		$this->assertSame( '', $this->render() );
	}

	/**
	 * A feed a reader can't see isn't followed: a password-protected page, or
	 * a synced pattern that isn't published.
	 */
	public function test_does_not_follow_feeds_the_reader_cannot_see() {
		$coverage_id = self::create_coverage();
		$protected   = self::factory()->post->create(
			[
				'post_type'     => 'page',
				'post_password' => 'secret',
				'post_content'  => self::feed( $coverage_id ),
			]
		);
		$this->assertSame( '', $this->render( [], $protected ) );

		$draft_pattern = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'draft',
				'post_content' => self::feed( $coverage_id ),
			]
		);
		$this->assertSame( '', $this->render( [], self::page( '<!-- wp:block {"ref":' . $draft_pattern . '} /-->' ) ) );

		$protected_pattern = self::factory()->post->create(
			[
				'post_type'     => 'wp_block',
				'post_password' => 'secret',
				'post_content'  => self::feed( $coverage_id ),
			]
		);
		$this->assertSame( '', $this->render( [], self::page( '<!-- wp:block {"ref":' . $protected_pattern . '} /-->' ) ) );
	}

	/**
	 * Each status has its badge; a status the plugin doesn't know is live.
	 */
	public function test_badge_follows_the_status() {
		$coverage_id = self::create_coverage();
		$page_id     = self::page( self::feed( $coverage_id ) );

		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--success newspack-ui__badge--dot newspack-ui__badge--pulse">Live</span>', $this->render( [], $page_id ) );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_PAUSED );
		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--secondary">Paused</span>', $this->render( [], $page_id ) );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );
		$html = $this->render( [], $page_id );
		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--error">Ended</span>', $html );
		$this->assertStringContainsString( 'data-status="archived"', $html );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, 'unknown' );
		$this->assertStringContainsString( 'data-status="active"', $this->render( [], $page_id ) );
	}

	/**
	 * The block's label wins, escaped; a blank or non-text one falls back to
	 * the site's, then the built-in one. Every status's label rides on the
	 * wrapper for the view script.
	 */
	public function test_labels() {
		$coverage_id = self::create_coverage();
		$page_id     = self::page( self::feed( $coverage_id ) );
		update_option( Status_Labels::OPTION_KEY, [ 'paused' => 'On hold' ] );

		$html = $this->render(
			[
				'labels' => [
					'active'   => 'On <b>air</b>',
					'paused'   => ' ',
					'archived' => [ 'Over' ],
				],
			],
			$page_id
		);

		$this->assertStringContainsString( '>On &lt;b&gt;air&lt;/b&gt;</span>', $html );
		$this->assertStringContainsString( 'data-label-active="On &lt;b&gt;air&lt;/b&gt;"', $html );
		$this->assertStringContainsString( 'data-label-paused="On hold"', $html );
		$this->assertStringContainsString( 'data-label-archived="Ended"', $html );
	}

	/**
	 * "Updated" is off by default.
	 */
	public function test_last_updated_is_off_by_default() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );

		$this->assertStringNotContainsString( 'newspack-rolling-coverage-updated', $this->render( [], self::page( self::feed( $coverage_id ) ) ) );
	}

	/**
	 * While live, it shows when the newest entry went out.
	 */
	public function test_last_updated_shows_the_newest_entry_while_live() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );

		$html = $this->render( [ 'showLastUpdated' => true ], self::page( self::feed( $coverage_id ) ) );

		$this->assertMatchesRegularExpression( '#<span class="newspack-rolling-coverage-updated">Updated <time datetime="2026-01-01T12:00:00\+00:00" data-rc-relative>[^<]+ ago</time></span>#', $html );
	}

	/**
	 * Not live, or no entries yet: the text is there for the view script but
	 * hidden.
	 */
	public function test_last_updated_is_hidden_when_not_live_or_empty() {
		$empty_id = self::create_coverage();
		$ended_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		self::create_entry( $ended_id, [ 'post_date' => '2026-01-01 12:00:00' ] );

		$this->assertStringContainsString( '<span class="newspack-rolling-coverage-updated" hidden>Updated <time datetime="" data-rc-relative></time></span>', $this->render( [ 'showLastUpdated' => true ], self::page( self::feed( $empty_id ) ) ) );
		$this->assertStringContainsString( '<span class="newspack-rolling-coverage-updated" hidden>', $this->render( [ 'showLastUpdated' => true ], self::page( self::feed( $ended_id ) ) ) );
	}

	/**
	 * GET a coverage term through the REST API as an editor.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return array Response data.
	 */
	private static function rest_coverage( int $coverage_id ): array {
		self::log_in_as( 'editor' );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/' . Taxonomy::REST_BASE . '/' . $coverage_id ) );

		return $response->get_data();
	}

	/**
	 * The coverage's REST record carries the same publish moment the front end
	 * shows, however the entries are pinned.
	 */
	public function test_rest_exposes_the_newest_entry_like_the_front_end() {
		$coverage_id = self::create_coverage();
		$older_id    = self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 08:00:00' ] );
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );
		Post_Type::pin_entry( $older_id );

		$data = self::rest_coverage( $coverage_id );

		$this->assertSame( '2026-01-01T12:00:00+00:00', $data['newestEntry'] );
		$this->assertSame( Newest_Entry::get_iso( $coverage_id ), $data['newestEntry'] );
	}

	/**
	 * No published entries: null.
	 */
	public function test_rest_newest_entry_is_null_without_entries() {
		$data = self::rest_coverage( self::create_coverage() );

		$this->assertArrayHasKey( 'newestEntry', $data );
		$this->assertNull( $data['newestEntry'] );
	}

	/**
	 * Only people who can edit see the newest entry through REST.
	 */
	public function test_rest_newest_entry_is_hidden_from_anonymous_requests() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );

		wp_set_current_user( 0 );

		$this->assertNull( Coverage_Status_Block::get_newest_entry_rest_field( [ 'id' => $coverage_id ] ) );
		$this->assertNull( Coverage_Status_Block::get_newest_entry_rest_field( [] ) );
	}

	/**
	 * Asking for the field alone raises no warning.
	 */
	public function test_rest_newest_entry_alone_raises_no_warning() {
		$coverage_id = self::create_coverage();

		self::log_in_as( 'editor' );

		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Taxonomy::REST_BASE . '/' . $coverage_id );
		$request->set_param( '_fields', Coverage_Status_Block::NEWEST_ENTRY_REST_FIELD );

		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertArrayHasKey( 'newestEntry', $data );
	}
}
