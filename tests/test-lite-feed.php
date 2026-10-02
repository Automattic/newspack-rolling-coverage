<?php
/**
 * Tests for Rolling Coverage feeds on Lite Site pages.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Archive_Mode;
use Newspack_Rolling_Coverage\Lite_Feed;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Social_Sharing;

/**
 * Lite Site renders a post's blocks through its own content filter, then
 * strips scripts and every attribute outside its allowlist. A feed on such a
 * page renders its entries as text and keeps what the view script polls
 * with, and polls from it get entries in the same form.
 */
class Test_Lite_Feed extends Rolling_Coverage_TestCase {

	/**
	 * What the test's content filter turns into the block. Anything else that
	 * passes through the filter, like the entry bodies Lite Site cleans, is
	 * left alone.
	 */
	const FEED_PLACEHOLDER = '<!-- rolling-coverage-test-feed -->';

	/**
	 * The Follow button, as the editor saves it.
	 */
	const FOLLOW_MARKUP = '<!-- wp:buttons --><div class="wp-block-buttons">'
		. '<!-- wp:button {"tagName":"button","metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"followTag"}}}}} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Follow</button></div><!-- /wp:button -->'
		. '</div><!-- /wp:buttons -->';

	/**
	 * Coverage the entries belong to.
	 *
	 * @var int
	 */
	private $coverage_id;

	/**
	 * Request URI to restore after the test.
	 *
	 * @var string|null
	 */
	private $request_uri;

	/**
	 * Load the Lite Site stand-in, create the coverage and keep the request
	 * URI to restore. Requests are anonymous.
	 */
	public function set_up() {
		parent::set_up();
		require_once __DIR__ . '/mocks/class-lite-site.php';
		$this->coverage_id = self::create_coverage();
		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stored only to be restored.
		wp_set_current_user( 0 );
	}

	/**
	 * Forget any lite feed the test served. It would otherwise last for the
	 * rest of the run, as it lasts for the rest of a request. Restore the
	 * request the test changed.
	 */
	public function tear_down() {
		$has_feed = new ReflectionProperty( Lite_Feed::class, 'has_feed' );
		$has_feed->setAccessible( true );
		$has_feed->setValue( null, false );
		set_query_var( Social_Sharing::ENTRY_QUERY_VAR, '' );

		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}

		parent::tear_down();
	}

	/**
	 * An entry renders as its time, title and body, without the layout.
	 */
	public function test_entry_renders_as_text_with_its_time_title_and_body() {
		$entry_id = self::create_entry(
			$this->coverage_id,
			[
				'post_title'   => 'Bridge reopens',
				'post_content' => '<!-- wp:paragraph --><p>Traffic is <strong>moving</strong>.</p><!-- /wp:paragraph -->',
				'post_date'    => '2026-01-01 12:00:00',
			]
		);

		$this->assertSame(
			'<article class="newspack-rolling-coverage-entry" data-entry-id="' . $entry_id . '" data-arrival="initial"><p class="newspack-rolling-coverage-entry-meta"><time datetime="2026-01-01T12:00:00+00:00">12:00 pm</time></p><h3>Bridge reopens</h3><p class="wp-block-paragraph">Traffic is <strong>moving</strong>.</p></article>',
			Lite_Feed::render_entry( get_post( $entry_id ), 'initial' )
		);
	}

	/**
	 * Pinned entries say so and carry the attribute the view script places
	 * them by, and an individually archived entry keeps its notice.
	 */
	public function test_pinned_and_archived_entries_say_so() {
		$entry_id = self::create_entry(
			$this->coverage_id,
			[
				'post_title' => 'Road closed',
				'post_date'  => '2026-01-01 08:00:00',
			]
		);
		Post_Type::pin_entry( $entry_id );
		update_post_meta( $entry_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );

		$html = Lite_Feed::render_entry( get_post( $entry_id ), 'poll' );

		$this->assertStringStartsWith( '<article class="newspack-rolling-coverage-entry" data-entry-id="' . $entry_id . '" data-arrival="poll" data-pinned>', $html );
		$this->assertStringContainsString( '8:00 am</time> &middot; Pinned</p>', $html );
		$this->assertStringContainsString( '<p class="newspack-rolling-coverage-entry-archived-notice">', $html );
	}

	/**
	 * Entry bodies go through Lite Site's cleaner, like the rest of the page,
	 * so polls never deliver what the page itself would have stripped.
	 */
	public function test_entry_body_is_cleaned_like_the_rest_of_a_lite_page() {
		// Saved as an administrator, so core keeps the script and the iframe.
		self::log_in_as( 'administrator' );
		$entry_id = self::create_entry( $this->coverage_id, [ 'post_content' => '<p>Before</p><script>alert(1)</script><iframe src="https://example.test/embed"></iframe>' ] );
		wp_set_current_user( 0 );

		$html = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertStringContainsString( '<p>Before</p>', $html );
		$this->assertStringNotContainsString( 'alert(1)', $html, 'Scripts are dropped with their contents.' );
		$this->assertStringNotContainsString( '<iframe', $html, 'Embeds are stripped.' );
	}

	/**
	 * The allowlist only grows once the request serves a lite feed, and then
	 * keeps what the view script reads.
	 */
	public function test_allowlist_grows_only_once_a_feed_is_served() {
		$allowed = [
			'div' => [ 'class' => true ],
			'a'   => [ 'href' => true ],
		];

		$this->assertSame( $allowed, apply_filters( 'newspack_lite_site_allowed_html', $allowed ), 'A lite page without a feed keeps the list it had.' );

		Lite_Feed::add_feed();
		$grown = apply_filters( 'newspack_lite_site_allowed_html', $allowed );

		$this->assertTrue( $grown['div']['class'], 'Attributes already on the list stay.' );
		$this->assertTrue( $grown['div']['data-*'], 'The feed settings survive.' );
		$this->assertTrue( $grown['div']['hidden'], 'The new-posts control stays hidden.' );
		$this->assertTrue( $grown['div']['role'], 'The status region keeps its role.' );
		$this->assertTrue( $grown['div']['aria-live'], 'The status region keeps announcing.' );
		$this->assertTrue( $grown['div']['aria-hidden'], 'The sentinel stays hidden from screen readers.' );
		$this->assertTrue( $grown['article']['class'], 'Entries keep their class.' );
		$this->assertTrue( $grown['article']['data-*'], 'Entries keep their IDs.' );
		$this->assertTrue( $grown['time']['datetime'], 'Entry times keep their machine-readable date.' );
		$this->assertTrue( $grown['a']['href'], 'Links keep their targets.' );
		$this->assertTrue( $grown['a']['data-*'], 'The new-posts link keeps its marker.' );
	}

	/**
	 * Render the block for the test coverage, as a full page does.
	 *
	 * @param array  $attributes   Block attributes, on top of the test coverage.
	 * @param string $inner_blocks Inner block markup.
	 * @return string Rendered HTML.
	 */
	private function render_block_html( array $attributes = [], string $inner_blocks = '' ): string {
		$attributes = array_merge( [ 'coverageId' => $this->coverage_id ], $attributes );
		$opening    = '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes );
		$markup     = '' === $inner_blocks
			? $opening . ' /-->'
			: $opening . ' -->' . $inner_blocks . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->';

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( parse_blocks( $markup )[0] ) );
	}

	/**
	 * Render the block as a lite page does: inside Lite Site's content filter,
	 * then cleaned with its allowlist.
	 *
	 * @param array  $attributes   Block attributes, on top of the test coverage.
	 * @param string $inner_blocks Inner block markup.
	 * @return string Cleaned HTML.
	 */
	private function render_lite_page( array $attributes = [], string $inner_blocks = '' ): string {
		$render = function ( $content ) use ( $attributes, $inner_blocks ) {
			return self::FEED_PLACEHOLDER === $content ? $this->render_block_html( $attributes, $inner_blocks ) : $content;
		};

		add_filter( Lite_Feed::CONTENT_FILTER, $render );

		try {
			return \Newspack_Lite_Site\Lite_Site::clean_content( self::FEED_PLACEHOLDER );
		} finally {
			remove_filter( Lite_Feed::CONTENT_FILTER, $render );
		}
	}

	/**
	 * A lite page keeps everything the view script needs to poll and to place
	 * new entries, and nothing that names a host it can't know.
	 */
	public function test_lite_page_keeps_what_the_view_script_polls_with() {
		$entry_id = self::create_entry(
			$this->coverage_id,
			[
				'post_title' => 'Bridge reopens',
				'post_date'  => '2026-01-01 12:00:00',
			]
		);

		$html = $this->render_lite_page();

		$this->assertStringContainsString( 'data-lite="1"', $html );
		$this->assertStringContainsString( 'data-coverage-id="' . $this->coverage_id . '"', $html );
		$this->assertStringContainsString( 'data-cursor="' . $entry_id . ':2026-01-01 12:00:00"', $html );
		$this->assertStringContainsString( 'data-status="active"', $html );
		$this->assertMatchesRegularExpression( '#data-rest-url="[^"]*coverages/' . $this->coverage_id . '/entries"#', $html );
		$this->assertStringContainsString( '<div class="newspack-rolling-coverage-status" role="status" aria-live="polite"></div>', $html );
		$this->assertMatchesRegularExpression( '/<div (?=[^>]*class="[^"]*newspack-rolling-coverage-new-entries[^"]*")[^>]* hidden[\s>]/', $html, 'The new-posts control stays hidden until entries wait behind it.' );
		$this->assertMatchesRegularExpression( '/<a [^>]*data-rc-latest/', $html );
		$this->assertStringContainsString( '<div class="newspack-rolling-coverage-sentinel" aria-hidden="true"></div>', $html );
		$this->assertStringNotContainsString( 'data-host-post-id', $html, 'A lite request\'s global post is not the host.' );
	}

	/**
	 * Entries on a lite page are the text-only ones, newest first.
	 */
	public function test_lite_page_lists_entries_as_text() {
		$first  = self::create_entry(
			$this->coverage_id,
			[
				'post_title' => 'Gates open',
				'post_date'  => '2026-01-01 08:00:00',
			]
		);
		$second = self::create_entry(
			$this->coverage_id,
			[
				'post_title' => 'Bridge reopens',
				'post_date'  => '2026-01-01 12:00:00',
			]
		);

		$html = $this->render_lite_page();

		$this->assertStringContainsString( Lite_Feed::render_entry( get_post( $second ), 'initial' ) . Lite_Feed::render_entry( get_post( $first ), 'initial' ), $html );
		$this->assertStringNotContainsString( 'wp-block-post', $html, 'No layout markup reaches a lite page.' );
	}

	/**
	 * The Follow button needs its own script and a push provider, neither of
	 * which a lite page has.
	 */
	public function test_lite_page_leaves_out_the_follow_button() {
		self::create_entry( $this->coverage_id );
		require_once __DIR__ . '/mocks/onesignal.php';
		update_option(
			'OneSignalWPSetting',
			[
				'app_id'           => 'test-app-id',
				'app_rest_api_key' => 'test-rest-api-key',
			]
		);

		$this->assertStringContainsString( 'Follow</button>', $this->render_block_html( [], self::FOLLOW_MARKUP ), 'A full page shows the Follow button.' );
		$this->assertStringNotContainsString( 'Follow</button>', $this->render_lite_page( [], self::FOLLOW_MARKUP ), 'A lite page does not.' );
	}

	/**
	 * A full page wraps the feed's items in the layout's Feed group, or in a
	 * plain container standing in for it. A lite page has no layout to
	 * apply, so its items sit right inside the block.
	 */
	public function test_lite_page_leaves_out_the_feed_group() {
		self::create_entry( $this->coverage_id );

		$this->assertStringContainsString( '<div class="newspack-rolling-coverage-feed">', $this->render_block_html(), 'A full page wraps its items.' );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-feed', $this->render_lite_page(), 'A lite page does not.' );
	}

	/**
	 * Lite Site caches a page by its path alone, so a lite page keeps the
	 * normal view instead of opening at a shared entry.
	 */
	public function test_lite_page_ignores_links_to_a_shared_entry() {
		self::create_entry( $this->coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );
		self::create_entry(
			$this->coverage_id,
			[
				'post_name' => 'older-entry',
				'post_date' => '2026-01-01 08:00:00',
			]
		);
		set_query_var( Social_Sharing::ENTRY_QUERY_VAR, 'older-entry' );

		$this->assertStringContainsString( 'data-view="entry"', $this->render_block_html( [ 'entriesPerPage' => 1 ] ), 'A full page opens at the shared entry.' );
		$this->assertStringNotContainsString( 'data-view="entry"', $this->render_lite_page( [ 'entriesPerPage' => 1 ] ), 'A lite page keeps the normal view.' );
	}

	/**
	 * Lite Site caches a page by its path alone, so the new-posts control
	 * links to that path, not to the URL of whoever filled the cache.
	 */
	public function test_lite_page_links_to_its_path_without_the_query_string() {
		self::create_entry( $this->coverage_id );

		// A page request, after the main query ran.
		$_SERVER['REQUEST_URI']      = '/lite/live-story/?mc_eid=abc123';
		$GLOBALS['wp_actions']['wp'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The core test case restores it.

		$this->assertStringContainsString( 'data-live-url="/lite/live-story/?mc_eid=abc123"', $this->render_block_html(), 'A full page links to the URL it was requested at.' );

		$html = $this->render_lite_page();

		$this->assertStringContainsString( 'data-live-url="/lite/live-story/"', $html );
		$this->assertStringContainsString( 'href="/lite/live-story/"', $html );
		$this->assertStringNotContainsString( 'mc_eid', $html, 'Nothing on a lite page carries the query string of whoever filled the cache.' );
	}

	/**
	 * The link stays on this site even when the request path starts with two
	 * slashes, which would make the path alone protocol-relative.
	 */
	public function test_lite_page_links_stay_on_this_site() {
		self::create_entry( $this->coverage_id );

		// A page request, after the main query ran.
		$_SERVER['REQUEST_URI']      = '//lite//evil.example/live-story/';
		$GLOBALS['wp_actions']['wp'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The core test case restores it.

		$html = $this->render_lite_page();

		$this->assertMatchesRegularExpression( '#data-live-url="/[^/]#', $html );
		$this->assertMatchesRegularExpression( '#href="/[^/]#', $html );
	}

	/**
	 * A block without a coverage shows its message and serves no feed.
	 */
	public function test_a_missing_coverage_is_not_a_lite_feed() {
		$html    = $this->render_lite_page( [ 'coverageId' => 0 ] );
		$allowed = [ 'div' => [ 'class' => true ] ];

		$this->assertStringContainsString( 'Select a coverage to display its entries.', $html );
		$this->assertSame( $allowed, apply_filters( 'newspack_lite_site_allowed_html', $allowed ), 'The message adds nothing to the allowlist.' );
	}

	/**
	 * Request entries the way a lite page's view script does.
	 *
	 * @param array $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function get_lite_feed( array $params ): WP_REST_Response {
		return self::dispatch(
			'GET',
			"/coverages/{$this->coverage_id}/entries",
			array_merge(
				[
					'template_key' => 'test',
					'lite'         => true,
				],
				$params
			)
		);
	}

	/**
	 * A poll from a lite page gets entries in the form the page renders them,
	 * without ads.
	 */
	public function test_lite_poll_returns_entries_as_text() {
		$entry_id = self::create_entry(
			$this->coverage_id,
			[
				'post_title' => 'Bridge reopens',
				'post_date'  => '2026-01-01 12:00:00',
			]
		);

		$entries      = $this->get_lite_feed( [ 'cursor' => '0:2025-12-31 00:00:00' ] )->get_data()['entries'];
		$allowed_html = apply_filters( 'newspack_lite_site_allowed_html', [ 'div' => [ 'class' => true ] ] );

		$this->assertCount( 1, $entries );
		$this->assertSame( $entry_id, $entries[0]['id'] );
		$this->assertSame( Lite_Feed::render_entry( get_post( $entry_id ), 'poll' ), $entries[0]['html'] );
		$this->assertNull( $entries[0]['adHtml'] );
		$this->assertTrue( $allowed_html['div']['data-*'] ?? false, 'A lite request serves a lite feed.' );
	}

	/**
	 * Load more from a lite page gets older entries in the same form.
	 */
	public function test_lite_load_more_returns_entries_as_text() {
		$entry_id = self::create_entry(
			$this->coverage_id,
			[
				'post_title' => 'Gates open',
				'post_date'  => '2026-01-01 08:00:00',
			]
		);

		$page = $this->get_lite_feed( [ 'before' => '2026-01-02 00:00:00' ] )->get_data();

		$this->assertSame( 1, $page['count'] );
		$this->assertSame( Lite_Feed::render_entry( get_post( $entry_id ), 'load_more' ), $page['html'] );
		$this->assertSame( [], $page['adSlots'] );
	}

	/**
	 * Without the flag, full pages keep getting entries in their layout, and
	 * the request serves no lite feed.
	 */
	public function test_full_pages_still_get_layout_entries() {
		self::create_entry( $this->coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );

		$entries = self::dispatch(
			'GET',
			"/coverages/{$this->coverage_id}/entries",
			[
				'template_key' => 'test',
				'cursor'       => '0:2025-12-31 00:00:00',
			]
		)->get_data()['entries'];
		$allowed = [ 'div' => [ 'class' => true ] ];

		$this->assertStringContainsString( 'wp-block-post', $entries[0]['html'] );
		$this->assertSame( $allowed, apply_filters( 'newspack_lite_site_allowed_html', $allowed ) );
	}

	/**
	 * What Lite Site prints after a single page's footer.
	 *
	 * @return string
	 */
	private function print_after_footer(): string {
		ob_start();
		do_action( 'newspack_lite_site_single_after_footer', get_post( self::factory()->post->create() ) );
		return ob_get_clean();
	}

	/**
	 * What plugins add inside Lite Site's style element.
	 *
	 * @return string
	 */
	private function print_lite_styles(): string {
		ob_start();
		do_action( 'newspack_lite_site_styles' );
		return ob_get_clean();
	}

	/**
	 * Run a test with the block's view script registered under a test handle.
	 *
	 * The block only registers from its built `dist/`, which the test run may
	 * not have, so a bare block type stands in when it's missing.
	 *
	 * @param callable $test Test body.
	 */
	private function with_view_script( callable $test ) {
		$registry   = WP_Block_Type_Registry::get_instance();
		$registered = $registry->is_registered( Rolling_Coverage_Block::BLOCK_NAME );
		$block_type = $registered ? $registry->get_registered( Rolling_Coverage_Block::BLOCK_NAME ) : register_block_type( Rolling_Coverage_Block::BLOCK_NAME );
		$handles    = $block_type->view_script_handles;

		wp_register_script( 'rolling-coverage-test-view', 'https://example.test/view.js', [], '1', true );
		$block_type->view_script_handles = [ 'rolling-coverage-test-view' ];

		try {
			$test();
		} finally {
			$block_type->view_script_handles = $handles;
			wp_deregister_script( 'rolling-coverage-test-view' );

			if ( ! $registered ) {
				unregister_block_type( Rolling_Coverage_Block::BLOCK_NAME );
			}
		}
	}

	/**
	 * Lite pages print no enqueued scripts, so a page with a feed prints the
	 * view script itself, and a page without one prints nothing.
	 */
	public function test_view_script_prints_only_on_a_lite_page_with_a_feed() {
		$this->with_view_script(
			function () {
				$this->assertSame( '', $this->print_after_footer(), 'A lite page without a feed prints no script.' );

				Lite_Feed::add_feed();

				$this->assertStringContainsString( 'https://example.test/view.js', $this->print_after_footer() );
			}
		);
	}

	/**
	 * A page with a feed gets the few styles the feed needs, which print
	 * inside Lite Site's style element.
	 */
	public function test_styles_print_only_on_a_lite_page_with_a_feed() {
		$this->assertSame( '', $this->print_lite_styles(), 'A lite page without a feed adds no styles.' );

		Lite_Feed::add_feed();
		$styles = $this->print_lite_styles();

		$this->assertStringContainsString( '.newspack-rolling-coverage-new-entries[hidden]', $styles );
		$this->assertStringContainsString( '.newspack-rolling-coverage-status', $styles );
		$this->assertStringNotContainsString( '<', $styles, 'Nothing can close the style element early.' );
	}
}
