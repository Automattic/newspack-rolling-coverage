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
	 * Load the Lite Site stand-in and create the coverage. Requests are anonymous.
	 */
	public function set_up() {
		parent::set_up();
		require_once __DIR__ . '/mocks/class-lite-site.php';
		$this->coverage_id = self::create_coverage();
		wp_set_current_user( 0 );
	}

	/**
	 * Forget any lite feed the test served. It would otherwise last for the
	 * rest of the run, as it lasts for the rest of a request.
	 */
	public function tear_down() {
		$has_feed = new ReflectionProperty( Lite_Feed::class, 'has_feed' );
		$has_feed->setAccessible( true );
		$has_feed->setValue( null, false );
		set_query_var( Social_Sharing::ENTRY_QUERY_VAR, '' );

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
	 * A block without a coverage shows its message and serves no feed.
	 */
	public function test_a_missing_coverage_is_not_a_lite_feed() {
		$html    = $this->render_lite_page( [ 'coverageId' => 0 ] );
		$allowed = [ 'div' => [ 'class' => true ] ];

		$this->assertStringContainsString( 'Select a coverage to display its entries.', $html );
		$this->assertSame( $allowed, apply_filters( 'newspack_lite_site_allowed_html', $allowed ), 'The message adds nothing to the allowlist.' );
	}
}
