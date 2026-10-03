<?php
/**
 * Tests for the Coverage Status block.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Coverage_Status_Block;
use Newspack_Rolling_Coverage\Newest_Entry;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Status_Labels;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * The block shows the status of the Rolling Coverage block on its page, and
 * nothing on a page without one.
 */
class Test_Coverage_Status_Block extends Rolling_Coverage_TestCase {

	/**
	 * Whether this test registered the Rolling Coverage block itself.
	 *
	 * @var bool
	 */
	private $registered_feed = false;

	/**
	 * Register the block from its metadata when the build isn't there, so its
	 * `postId` context reaches the render callback, and the Rolling Coverage
	 * block, so the feeds the tests nest it in render.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Rolling_Coverage_Block::BLOCK_NAME ) ) {
			register_block_type( Rolling_Coverage_Block::BLOCK_NAME, Rolling_Coverage_Block::block_type_args() );
			$this->registered_feed = true;
		}

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Coverage_Status_Block::BLOCK_NAME ) ) {
			$metadata = json_decode( file_get_contents( NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'src/blocks/coverage-status/block.json' ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown

			register_block_type(
				Coverage_Status_Block::BLOCK_NAME,
				[
					'supports'        => $metadata['supports'],
					'uses_context'    => $metadata['usesContext'],
					'render_callback' => [ Coverage_Status_Block::class, 'render_block' ],
				]
			);
		}
	}

	/**
	 * Forget the theme.json data a test switched to, and the Rolling Coverage
	 * block if the test registered it.
	 */
	public function tear_down() {
		if ( $this->registered_feed ) {
			unregister_block_type( Rolling_Coverage_Block::BLOCK_NAME );
			$this->registered_feed = false;
		}

		parent::tear_down();
		wp_clean_theme_json_cache();
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
	 * A capped feed only previews a coverage, so the status follows the
	 * page's full feed even when the capped one comes first, and an article
	 * holding only a capped feed shows no status.
	 */
	public function test_does_not_follow_capped_feeds() {
		$related = self::create_coverage();
		$own     = self::create_coverage();
		$capped  = '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $related . ',"latestOnly":true} /-->';

		$this->assertStringContainsString( 'data-coverage-id="' . $own . '"', $this->render( [], self::page( $capped . self::feed( $own ) ) ) );
		$this->assertSame( '', $this->render( [], self::page( $capped ) ) );
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
	 * Hidden once ended when asked to; still shown while paused, and shown
	 * when ended without the option.
	 */
	public function test_hide_when_ended() {
		$coverage_id = self::create_coverage();
		$page_id     = self::page( self::feed( $coverage_id ) );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );
		$this->assertSame( '', $this->render( [ 'hideWhenEnded' => true ], $page_id ) );
		$this->assertStringContainsString( 'data-status="archived"', $this->render( [], $page_id ) );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_PAUSED );
		$html = $this->render( [ 'hideWhenEnded' => true ], $page_id );
		$this->assertStringContainsString( 'data-status="paused"', $html );
		$this->assertStringContainsString( 'data-hide-when-ended="true"', $html );
		$this->assertStringNotContainsString( 'data-hide-when-ended', $this->render( [], $page_id ) );
	}

	/**
	 * Turning the dot off drops its classes from the live badge only.
	 */
	public function test_dot_can_be_turned_off() {
		$coverage_id = self::create_coverage();
		$page_id     = self::page( self::feed( $coverage_id ) );

		$html = $this->render( [ 'showDot' => false ], $page_id );
		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--success">Live</span>', $html );
		$this->assertStringContainsString( 'data-hide-dot="true"', $html );

		$html = $this->render( [ 'showDot' => true ], $page_id );
		$this->assertStringContainsString( 'newspack-ui__badge--dot newspack-ui__badge--pulse', $html );
		$this->assertStringNotContainsString( 'data-hide-dot', $html );
		$this->assertStringNotContainsString( 'data-hide-dot', $this->render( [], $page_id ) );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_PAUSED );
		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--secondary">Paused</span>', $this->render( [ 'showDot' => false ], $page_id ) );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );
		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--error">Ended</span>', $this->render( [ 'showDot' => false ], $page_id ) );
	}

	/**
	 * A custom color styles the current badge, and each custom status style
	 * rides on the wrapper; an invalid color adds nothing.
	 */
	public function test_custom_background_colors() {
		$coverage_id = self::create_coverage();
		$page_id     = self::page( self::feed( $coverage_id ) );

		$html = $this->render(
			[
				'backgroundColors' => [
					'active'   => '#FFD700',
					'paused'   => 'red',
					'archived' => '#2271b1',
				],
			],
			$page_id
		);

		$this->assertStringContainsString( 'style="background:#ffd700;color:#000000;--newspack-ui-badge-dot-color:color-mix(in srgb, #000000 60%, #ffd700)">Live</span>', $html );
		$this->assertStringContainsString( 'data-style-active="background:#ffd700;color:#000000;--newspack-ui-badge-dot-color:color-mix(in srgb, #000000 60%, #ffd700)"', $html );
		$this->assertStringNotContainsString( 'data-style-paused', $html );
		$this->assertStringContainsString( 'data-style-archived="background:#2271b1;color:#ffffff;', $html );

		$html = $this->render( [ 'backgroundColors' => [ 'active' => 'url(x)' ] ], $page_id );
		$this->assertStringNotContainsString( 'style="background', $html );
		$this->assertStringNotContainsString( 'data-style-', $html );
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

		$this->assertDoesNotMatchRegularExpression( '#^<div class="(?:[^"]* )?newspack-ui(?: [^"]*)?"#', $html );
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

	/**
	 * The time takes the newspack-ui size from the block's own stylesheet,
	 * and keeps the theme's font.
	 */
	public function test_updated_time_styles_ship_with_the_block() {
		$dir = NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'src/blocks/coverage-status/';

		$this->assertStringContainsString( '"style": "file:./view.css"', file_get_contents( $dir . 'block.json' ) ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown

		$scss = file_get_contents( $dir . 'style.scss' ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown

		$this->assertMatchesRegularExpression( '#font-size:\s*var\(--newspack-ui-font-size-xs\)#', $scss );
		$this->assertMatchesRegularExpression( '#line-height:\s*var\(--newspack-ui-line-height-xs\)#', $scss );
		$this->assertStringNotContainsString( 'font-family', $scss );

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( Coverage_Status_Block::BLOCK_NAME );

		if ( ! $block_type->style_handles ) {
			$this->markTestSkipped( 'The block is registered without a build, so it has no style handles.' );
		}

		$this->assertContains( 'newspack-rolling-coverage-coverage-status-style', $block_type->style_handles );
	}

	/**
	 * The block declares a flex layout and block gap support, and core renders
	 * the wrapper as a flex layout with the chosen gap on themes that support it.
	 */
	public function test_wrapper_gets_a_flex_layout_and_the_chosen_gap_from_core() {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( Coverage_Status_Block::BLOCK_NAME );

		$this->assertTrue( block_has_support( $block_type, [ 'spacing', 'blockGap' ], false ) );
		$this->assertSame( 'flex', $block_type->supports['layout']['default']['type'] ?? null );

		add_filter(
			'wp_theme_json_data_theme',
			static function ( $theme_json ) {
				return $theme_json->update_with(
					[
						'version'  => WP_Theme_JSON::LATEST_SCHEMA,
						'settings' => [ 'spacing' => [ 'blockGap' => true ] ],
					]
				);
			}
		);
		wp_clean_theme_json_cache();

		$coverage_id = self::create_coverage();
		$html        = $this->render(
			[
				'showLastUpdated' => true,
				'style'           => [ 'spacing' => [ 'blockGap' => 'var:preset|spacing|30' ] ],
			],
			self::page( self::feed( $coverage_id ) )
		);

		$this->assertMatchesRegularExpression( '#^<div class="(?:[^"]* )?is-layout-flex(?: [^"]*)?"#', $html );
		$this->assertStringContainsString( 'gap:var(--wp--preset--spacing--30)', wp_style_engine_get_stylesheet_from_context( 'block-supports', [] ) );
	}

	/**
	 * A capped Rolling Coverage block shaped like Flash: a status block, then
	 * the entry, among the coverage-level blocks of its Feed.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string
	 */
	private static function flash_feed( int $coverage_id ): string {
		$attributes = [
			'coverageId'  => $coverage_id,
			'latestOnly'  => true,
			'latestCount' => 2,
		];

		return '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->'
			. '<!-- wp:group {"className":"newspack-rolling-coverage-feed","layout":{"type":"flex"}} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. '<!-- wp:newspack-rolling-coverage/coverage-status /-->'
			. '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry"><!-- wp:post-title /--></div><!-- /wp:group -->'
			. '</div><!-- /wp:group -->'
			. '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->';
	}

	/**
	 * The coverage each status block in the HTML follows, in order.
	 *
	 * @param string $html Rendered HTML.
	 * @return int[]
	 */
	private static function followed_coverages( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$followed  = [];

		while ( $processor->next_tag( [ 'class_name' => 'wp-block-newspack-rolling-coverage-coverage-status' ] ) ) {
			$followed[] = (int) $processor->get_attribute( 'data-coverage-id' );
		}

		return $followed;
	}

	/**
	 * Inside a Rolling Coverage block, the status block follows that block's
	 * coverage on any page, the home page included, and renders once above
	 * the entries rather than in each one.
	 */
	public function test_status_inside_a_feed_follows_that_feed_on_any_page() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_PAUSED );
		self::create_entry( $coverage_id );
		self::create_entry( $coverage_id );
		self::factory()->post->create();
		$this->go_to( home_url( '/' ) );

		$html = do_blocks( self::flash_feed( $coverage_id ) );

		$this->assertSame( [ $coverage_id ], self::followed_coverages( $html ) );
		$this->assertSame( 1, substr_count( $html, '<span class="newspack-ui__badge newspack-ui__badge--secondary">Paused</span>' ) );
		$this->assertLessThan( strpos( $html, '<article' ), strpos( $html, 'newspack-ui__badge' ) );
	}

	/**
	 * A standalone status block still follows the page's first feed, not the
	 * coverage of a status block nested in a later feed.
	 */
	public function test_standalone_status_ignores_a_status_nested_in_a_feed() {
		$first   = self::create_coverage();
		$capped  = self::create_coverage();
		$page_id = self::page( self::feed( $first ) . self::flash_feed( $capped ) );
		$this->go_to( get_permalink( $page_id ) );

		$html = do_blocks( '<!-- wp:newspack-rolling-coverage/coverage-status /-->' . get_post( $page_id )->post_content );

		$this->assertSame( [ $first, $capped ], self::followed_coverages( $html ) );
	}
}
