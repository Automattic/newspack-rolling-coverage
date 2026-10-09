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
use Newspack_Rolling_Coverage\Taxonomy;

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
	 * The Follow Coverage block, as the editor saves it.
	 */
	const FOLLOW_MARKUP = '<!-- wp:newspack-rolling-coverage/coverage-follow --><!-- wp:buttons --><div class="wp-block-buttons">'
		. '<!-- wp:button {"tagName":"button","metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"followTag"}}}}} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Follow</button></div><!-- /wp:button -->'
		. '</div><!-- /wp:buttons --><!-- /wp:newspack-rolling-coverage/coverage-follow -->';

	/**
	 * A feed layout holding the Check for Updates block above a plain entry
	 * template.
	 */
	const CHECK_UPDATES_LAYOUT = '<!-- wp:group {"className":"newspack-rolling-coverage-feed"} --><div class="wp-block-group newspack-rolling-coverage-feed">'
		. '<!-- wp:newspack-rolling-coverage/check-updates --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"tagName":"button"} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Check for Updates</button></div><!-- /wp:button --></div><!-- /wp:buttons --><!-- /wp:newspack-rolling-coverage/check-updates -->'
		. '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry"><!-- wp:post-title /--></div><!-- /wp:group -->'
		. '</div><!-- /wp:group -->';

	/**
	 * What a lite feed shows in place of a protected entry's body.
	 */
	const PROTECTED_NOTICE = '<p class="newspack-rolling-coverage-entry-protected">This content is password protected.</p>';

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
	 * Whether the test registered the block itself.
	 *
	 * @var bool
	 */
	private $registered_block = false;

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
	 * Restore the request the test changed, and forget the block if the test
	 * registered it.
	 */
	public function tear_down() {
		set_query_var( Social_Sharing::ENTRY_QUERY_VAR, '' );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Forgetting the test's postpass cookie.

		if ( $this->registered_block ) {
			unregister_block_type( Rolling_Coverage_Block::BLOCK_NAME );
			$this->registered_block = false;
		}

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

		$this->assertStringContainsString( '<script>alert(1)</script><iframe', get_post_field( 'post_content', $entry_id ), 'Precondition: the script and the embed were saved.' );

		$html = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertStringContainsString( '<p>Before</p>', $html );
		$this->assertStringNotContainsString( 'alert(1)', $html, 'Scripts are dropped with their contents.' );
		$this->assertStringNotContainsString( '<iframe', $html, 'Embeds are stripped.' );
	}

	/**
	 * The allowlist grows only once the request serves a lite feed, and keeps
	 * what it already allowed. The page tests check that what the view script
	 * reads survives.
	 */
	public function test_allowlist_grows_only_once_a_feed_is_served() {
		$allowed = [
			'div' => [ 'class' => true ],
			'a'   => [ 'href' => true ],
		];

		$this->assertSame( $allowed, apply_filters( 'newspack_lite_site_allowed_html', $allowed ), 'A lite page without a feed keeps the list it had.' );

		Lite_Feed::add_feed();
		$grown = apply_filters( 'newspack_lite_site_allowed_html', $allowed );

		$this->assertTrue( $grown['div']['data-*'], 'The feed settings survive.' );
		$this->assertTrue( $grown['div']['class'], 'Attributes already on the list stay.' );
		$this->assertTrue( $grown['a']['href'], 'Links keep their targets.' );
	}

	/**
	 * A plugin can widen Lite Site's list past what its single template
	 * prints, which bounds the page with wp_kses_post(). Entry bodies keep
	 * within that bound, so a poll still sends only what the page shows.
	 */
	public function test_entry_body_keeps_within_what_a_lite_page_prints() {
		// Saved as an administrator, so core keeps the iframe.
		self::log_in_as( 'administrator' );
		$entry_id = self::create_entry( $this->coverage_id, [ 'post_content' => '<p>Before</p><iframe src="https://example.test/embed"></iframe>' ] );
		wp_set_current_user( 0 );

		add_filter(
			'newspack_lite_site_allowed_html',
			static function ( $allowed_html ) {
				$allowed_html['iframe'] = [ 'src' => true ];
				return $allowed_html;
			},
			20
		);

		$this->assertStringContainsString( '<iframe', \Newspack_Lite_Site\Lite_Site::clean_content( get_post_field( 'post_content', $entry_id ) ), 'Precondition: the widened list keeps the embed.' );

		$html = Lite_Feed::render_entry( get_post( $entry_id ), 'poll' );

		$this->assertStringContainsString( '<p>Before</p>', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	/**
	 * Lite Site caches a page for every reader and lite polls are public, so
	 * an entry's body leaves out the blocks Newspack shows only to signed-in
	 * readers, even when a signed-in reader asks.
	 */
	public function test_entry_body_leaves_out_members_only_blocks_whoever_asks() {
		$this->use_block_visibility_stub();
		$entry_id = self::create_entry( $this->coverage_id, [ 'post_content' => '<!-- wp:paragraph --><p>Everyone reads this.</p><!-- /wp:paragraph -->' . self::members_only_paragraph( 'Members read this.' ) ] );
		self::log_in_as( 'administrator' );

		$html = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertStringContainsString( 'Everyone reads this.', $html );
		$this->assertStringNotContainsString( 'Members read this.', $html );
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
	 * An archived entry's notice is filterable, so Lite Site cleans it like
	 * the body: a poll sends the notice the page shows, without markup the
	 * page strips.
	 */
	public function test_archived_notice_is_cleaned_like_the_rest_of_a_lite_page() {
		$entry_id = self::create_entry(
			$this->coverage_id,
			[
				'post_title' => 'Road closed',
				'post_date'  => '2026-01-01 08:00:00',
			]
		);
		update_post_meta( $entry_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );
		add_filter(
			'newspack_rolling_coverage_entry_archived_notice',
			function () {
				return 'Out of date. <img src="https://example.test/map.png" alt="Map">';
			}
		);

		$entry = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertStringContainsString( 'Out of date.', $entry );
		$this->assertStringNotContainsString( '<img', $entry, 'The notice loses what a lite page strips.' );
		$this->assertStringContainsString( $entry, $this->render_lite_page(), 'A poll sends the notice the page shows.' );
	}

	/**
	 * A lite page of a live coverage holds no hidden ended notice, as a full
	 * page does: a Lite Site that can't keep the feed's markup strips its
	 * `hidden` attribute and would show it. Once ended, the notice renders.
	 */
	public function test_lite_page_holds_no_ended_notice_until_the_coverage_ends() {
		self::create_entry( $this->coverage_id );

		$this->assertStringContainsString( 'archived-notice', $this->render_block_html(), 'A full page holds it.' );
		$this->assertStringNotContainsString( 'archived-notice', $this->render_lite_page(), 'A lite page does not.' );

		update_term_meta( $this->coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$this->assertStringContainsString( 'archived-notice', $this->render_lite_page(), 'Ended, a lite page shows it.' );
	}

	/**
	 * The Follow button needs its own script and a push provider, neither of
	 * which a lite page has.
	 */
	public function test_lite_page_leaves_out_the_follow_button() {
		self::create_entry( $this->coverage_id );
		self::configure_onesignal();

		$this->assertStringContainsString( 'Follow</button>', $this->render_block_html( [], self::FOLLOW_MARKUP ), 'A full page shows the Follow button.' );
		$this->assertStringNotContainsString( 'Follow</button>', $this->render_lite_page( [], self::FOLLOW_MARKUP ), 'A lite page does not.' );
	}

	/**
	 * A feed whose layout holds the Check for Updates block checks for new
	 * entries only when a reader presses its button, which suits a lite page.
	 * The lite page keeps the switch, the block hidden for the view script to
	 * show, and its button.
	 */
	public function test_lite_page_keeps_the_check_for_updates_block() {
		$this->register_check_updates_block();
		self::create_entry( $this->coverage_id );

		$html = $this->render_lite_page( [], self::CHECK_UPDATES_LAYOUT );

		$this->assertStringContainsString( 'data-new-entries="button"', $html );
		$this->assertMatchesRegularExpression( '/<div class="[^"]*newspack-rolling-coverage-check-updates[^"]*" hidden>/', $html );
		$this->assertMatchesRegularExpression( '/<button type="button" class="[^"]+">Check for Updates<\/button>/', $html );
	}

	/**
	 * The layout's other coverage-level blocks read as text on a lite page: a
	 * capped feed keeps the coverage's name and its "See all updates" link,
	 * while the Follow button sharing their group drops out.
	 */
	public function test_capped_lite_page_keeps_the_name_and_link_but_drops_follow() {
		self::configure_onesignal();
		wp_update_term( $this->coverage_id, Taxonomy::TAXONOMY_SLUG, [ 'name' => 'Storm coverage' ] );

		// The coverage page lives on this site; an address elsewhere isn't kept.
		$coverage_page = home_url( '/storm-coverage/' );
		update_term_meta( $this->coverage_id, Taxonomy::CANONICAL_URL_META_KEY, $coverage_page );

		$attributes = [
			'latestOnly'  => true,
			'latestCount' => 2,
		];
		$footer     = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:heading {"metadata":{"bindings":{"content":{"source":"newspack-rolling-coverage/entry","args":{"key":"coverageName"}}}}} --><h2 class="wp-block-heading">Saved title</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph {"className":"newspack-rolling-coverage-all-updates"} --><p class="newspack-rolling-coverage-all-updates"><a href="#">See all updates</a></p><!-- /wp:paragraph -->'
			. self::FOLLOW_MARKUP
			. '</div><!-- /wp:group -->';
		$lite_page  = $this->render_lite_page( $attributes, $footer );

		$this->assertStringContainsString( 'Follow</button>', $this->render_block_html( $attributes, $footer ), 'A full page shows the Follow button.' );
		$this->assertStringContainsString( '<h2>Storm coverage</h2>', $lite_page );
		$this->assertStringContainsString( '<a href="' . $coverage_page . '">See all updates</a>', $lite_page );
		$this->assertStringNotContainsString( 'Follow</button>', $lite_page );
	}

	/**
	 * Lite Site keeps a page for every reader, so a signed-in reader's lite
	 * page renders the feed as a signed-out reader gets it: an entry's own
	 * blocks, those of a synced pattern in it, and the layout's blocks around
	 * the entries. The reader is signed back in once the feed has rendered.
	 */
	public function test_lite_page_renders_the_feed_for_a_signed_out_reader() {
		$pattern_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Pattern text.</p><!-- /wp:paragraph -->',
			]
		);
		self::create_entry( $this->coverage_id, [ 'post_content' => '<!-- wp:paragraph --><p>Entry text.</p><!-- /wp:paragraph --><!-- wp:block {"ref":' . $pattern_id . '} /-->' ] );

		// The layout's "See all updates" link shows only with a coverage page.
		update_term_meta( $this->coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/storm-coverage/' ) );

		$attributes = [
			'latestOnly'  => true,
			'latestCount' => 2,
		];
		$layout     = '<!-- wp:post-title /-->'
			. '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph {"className":"newspack-rolling-coverage-all-updates"} --><p class="newspack-rolling-coverage-all-updates"><a href="#">See all updates</a></p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';

		// Paragraphs read differently for a signed-in reader, as blocks
		// Newspack shows or hides by reader do.
		add_filter(
			'render_block',
			static fn( $html, $block ) => 'core/paragraph' === $block['blockName'] && is_user_logged_in() ? str_replace( '</p>', ' Signed in.</p>', $html ) : $html,
			10,
			2
		);
		$reader_id = self::log_in_as( 'subscriber' );
		$lite_page = $this->render_lite_page( $attributes, $layout );

		$this->assertStringContainsString( 'Signed in.', $this->render_block_html( $attributes, $layout ), 'A full page renders for the reader.' );
		$this->assertStringContainsString( 'Entry text.', $lite_page );
		$this->assertStringContainsString( 'Pattern text.', $lite_page );
		$this->assertStringContainsString( 'See all updates', $lite_page );
		$this->assertStringNotContainsString( 'Signed in.', $lite_page );
		$this->assertSame( $reader_id, get_current_user_id(), 'The reader is signed back in.' );
	}

	/**
	 * A full page wraps the feed's items in the layout's Feed group, or in a
	 * plain container standing in for it. A lite page leaves out the layout's
	 * Feed group and the groups around it, so its items sit right inside the
	 * block.
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
	 * with bodies keeping the markup the page keeps, so the poll serves its
	 * feed before it renders them.
	 */
	public function test_lite_poll_returns_entries_as_text() {
		$entry_id = self::create_entry(
			$this->coverage_id,
			[
				'post_title'   => 'Bridge reopens',
				'post_content' => '<!-- wp:paragraph --><p>Open since <time datetime="2026-01-01T12:00:00+00:00">noon</time>.</p><!-- /wp:paragraph -->',
				'post_date'    => '2026-01-01 12:00:00',
			]
		);

		$poll         = $this->get_lite_feed( [ 'cursor' => '0:2025-12-31 00:00:00' ] )->get_data();
		$entries      = $poll['entries'];
		$allowed_html = apply_filters( 'newspack_lite_site_allowed_html', [ 'div' => [ 'class' => true ] ] );

		$this->assertArrayNotHasKey( 'staleTemplate', $poll, 'Lite entries need no stored template.' );

		$this->assertCount( 1, $entries );
		$this->assertSame( $entry_id, $entries[0]['id'] );
		$this->assertStringContainsString( 'Open since <time datetime="2026-01-01T12:00:00+00:00">noon</time>.', $entries[0]['html'], 'Only a served lite feed keeps the time.' );
		$this->assertSame( Lite_Feed::render_entry( get_post( $entry_id ), 'poll' ), $entries[0]['html'] );
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

		$this->assertArrayNotHasKey( 'staleTemplate', $page, 'Lite entries need no stored template.' );
		$this->assertSame( 1, $page['count'] );
		$this->assertSame( Lite_Feed::render_entry( get_post( $entry_id ), 'load_more' ), $page['html'] );
	}

	/**
	 * Create a password-protected entry, as seen by a reader who entered its
	 * password or by one who didn't.
	 *
	 * @param bool $knows_password Whether the reader holds a valid postpass cookie.
	 * @return int Entry ID.
	 */
	private function create_protected_entry( bool $knows_password ): int {
		$entry_id = self::create_entry(
			$this->coverage_id,
			[
				'post_title'    => 'Locked',
				'post_content'  => '<!-- wp:paragraph --><p>Only for subscribers.</p><!-- /wp:paragraph -->',
				'post_password' => 'secret',
				'post_date'     => '2026-01-01 12:00:00',
			]
		);

		if ( $knows_password ) {
			require_once ABSPATH . WPINC . '/class-phpass.php';
			$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( 'secret' ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- The cookie core sets for a reader who entered the password.
		}

		$this->assertSame( ! $knows_password, post_password_required( $entry_id ), 'Precondition: only the password lets the reader in.' );

		return $entry_id;
	}

	/**
	 * A reader who didn't enter a protected entry's password, and one who did.
	 *
	 * @return array[]
	 */
	public function data_protected_entry_readers(): array {
		return [
			'without the password' => [ false ],
			'with the password'    => [ true ],
		];
	}

	/**
	 * Lite Site caches a page for every reader, so a protected entry shows a
	 * notice in place of its body, even to a reader who entered the password
	 * and would fill the cache with it.
	 *
	 * @dataProvider data_protected_entry_readers
	 *
	 * @param bool $knows_password Whether the reader holds a valid postpass cookie.
	 */
	public function test_lite_page_shows_a_notice_in_place_of_a_protected_body( bool $knows_password ) {
		$entry_id = $this->create_protected_entry( $knows_password );

		$html = $this->render_lite_page();

		$this->assertStringContainsString( 'data-entry-id="' . $entry_id . '"', $html );
		$this->assertStringNotContainsString( 'Only for subscribers.', $html );
		$this->assertStringContainsString( self::PROTECTED_NOTICE, $html );
	}

	/**
	 * Lite polls and load more are public, so they send a protected entry
	 * with the same notice in place of its body.
	 *
	 * @dataProvider data_protected_entry_readers
	 *
	 * @param bool $knows_password Whether the reader holds a valid postpass cookie.
	 */
	public function test_lite_requests_send_a_protected_entry_without_its_body( bool $knows_password ) {
		$entry_id = $this->create_protected_entry( $knows_password );

		$poll = $this->get_lite_feed( [ 'cursor' => '0:2025-12-31 00:00:00' ] )->get_data()['entries'];
		$more = $this->get_lite_feed( [ 'before' => '2026-01-02 00:00:00' ] )->get_data()['html'];

		$this->assertSame( $entry_id, $poll[0]['id'] );
		$this->assertStringNotContainsString( 'Only for subscribers.', $poll[0]['html'], 'A poll sends no body.' );
		$this->assertStringContainsString( self::PROTECTED_NOTICE, $poll[0]['html'] );
		$this->assertStringContainsString( 'data-entry-id="' . $entry_id . '"', $more );
		$this->assertStringNotContainsString( 'Only for subscribers.', $more, 'Nor does load more.' );
		$this->assertStringContainsString( self::PROTECTED_NOTICE, $more );
	}

	/**
	 * Register the block for the rest of the test when the build isn't there,
	 * so a feed in an entry's content renders.
	 */
	private function register_block() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( Rolling_Coverage_Block::BLOCK_NAME ) ) {
			return;
		}

		register_block_type( Rolling_Coverage_Block::BLOCK_NAME, Rolling_Coverage_Block::block_type_args() );
		$this->registered_block = true;
	}

	/**
	 * Stop a render that nests Lite Site's content filter deeper than a page
	 * and an entry in it need. Left alone, it would go on until PHP runs out
	 * of stack, taking the test run with it.
	 *
	 * @param string $content Content being filtered.
	 * @return string
	 * @throws RuntimeException When the filter nests too deep.
	 */
	public static function stop_runaway_nesting( $content ) {
		$depth = count( array_keys( $GLOBALS['wp_current_filter'], Lite_Feed::CONTENT_FILTER, true ) );

		if ( $depth > 4 ) {
			throw new RuntimeException( sprintf( 'Lite Site\'s content filter nested %d deep.', (int) $depth ) );
		}

		return $content;
	}

	/**
	 * An entry can hold a feed of its own coverage, which lists the entry
	 * again. As on a full page, the lite page and its polls show the entry's
	 * body only once, and the feed inside it has no new-posts control.
	 */
	public function test_a_feed_inside_its_own_entry_shows_the_entry_body_once() {
		$this->register_block();
		$entry_id = self::create_entry(
			$this->coverage_id,
			[
				'post_title'   => 'Key updates',
				'post_content' => '<!-- wp:paragraph --><p>Catch up below.</p><!-- /wp:paragraph -->'
					. '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $this->coverage_id . '} /-->',
				'post_date'    => '2026-01-01 12:00:00',
			]
		);
		add_filter( Lite_Feed::CONTENT_FILTER, [ __CLASS__, 'stop_runaway_nesting' ], 1 );

		$page = $this->render_lite_page();
		$poll = $this->get_lite_feed( [ 'cursor' => '0:2025-12-31 00:00:00' ] )->get_data()['entries'];

		$this->assertSame( 2, substr_count( $page, 'data-coverage-id="' . $this->coverage_id . '"' ), 'The page shows its feed and the one inside the entry.' );
		$this->assertSame( 1, substr_count( $page, 'Catch up below.' ), 'The entry\'s body shows once.' );
		$this->assertSame( 1, substr_count( $page, 'newspack-rolling-coverage-new-entries' ), 'Only the page\'s own feed has a new-posts control.' );
		$this->assertSame( $entry_id, $poll[0]['id'] );
		$this->assertSame( 1, substr_count( $poll[0]['html'], 'Catch up below.' ), 'A poll sends the body once.' );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-new-entries', $poll[0]['html'], 'The feed inside a polled entry has no new-posts control.' );
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
				'template_key' => '',
				'cursor'       => '0:2025-12-31 00:00:00',
			]
		)->get_data()['entries'];
		$allowed = [ 'div' => [ 'class' => true ] ];

		$this->assertStringContainsString( 'wp-block-post', $entries[0]['html'] );
		$this->assertSame( $allowed, apply_filters( 'newspack_lite_site_allowed_html', $allowed ) );
	}

	/**
	 * A capped feed shows every entry as unpinned, so on a lite page a pinned
	 * entry carries no pin, on the page or in its polls, and the view script
	 * places it like any other. Uncapped, the same entry shows as pinned.
	 */
	public function test_capped_lite_feed_shows_no_entry_as_pinned() {
		$entry_id = self::create_entry( $this->coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );
		Post_Type::pin_entry( $entry_id );

		$this->assertStringContainsString( 'data-pinned', $this->render_lite_page(), 'Uncapped, the entry shows as pinned.' );

		$page = $this->render_lite_page(
			[
				'latestOnly'  => true,
				'latestCount' => 2,
			]
		);
		$poll = $this->get_lite_feed(
			[
				'cursor' => '0:2025-12-31 00:00:00',
				'latest' => 2,
			]
		)->get_data()['entries'];

		$this->assertStringContainsString( 'data-entry-id="' . $entry_id . '"', $page );
		$this->assertStringNotContainsString( 'data-pinned', $page );
		$this->assertStringNotContainsString( 'Pinned', $page );
		$this->assertStringNotContainsString( 'data-pinned', $poll[0]['html'] );
		$this->assertStringNotContainsString( 'Pinned', $poll[0]['html'] );
	}

	/**
	 * A burst too large to send piecemeal brings a capped feed twice its count
	 * of newest entries in place of a reload. A lite page gets them as text,
	 * like its other polls, and without a pin, like the rest of a capped feed.
	 */
	public function test_capped_lite_burst_sends_the_newest_entries_as_text() {
		$entry_ids = [];

		for ( $i = 0; $i <= Rolling_Coverage_Block::POLL_CAP; $i++ ) {
			$entry_ids[] = self::create_entry( $this->coverage_id, [ 'post_date' => gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 12:00:00' ) + $i * 60 ) ] );
		}

		$newest_ids = array_slice( array_reverse( $entry_ids ), 0, 4 );
		Post_Type::pin_entry( $newest_ids[0] );

		$entries = $this->get_lite_feed(
			[
				'cursor' => '0:2025-12-31 00:00:00',
				'latest' => 2,
			]
		)->get_data()['entries'];

		$this->assertSame(
			array_map( static fn( $entry_id ) => Lite_Feed::render_entry( get_post( $entry_id ), 'poll', true ), $newest_ids ),
			wp_list_pluck( $entries, 'html' )
		);
	}

	/**
	 * When an entry is taken down, a capped feed gets the removal and twice
	 * its count of newest entries to swap in for its own. A lite page gets
	 * them as text, like its other polls.
	 */
	public function test_capped_lite_poll_after_a_removal_sends_the_newest_entries_as_text() {
		$oldest_entry_id = self::create_dated_entry( $this->coverage_id, '2026-01-01 11:00:00' );
		$older_entry_id  = self::create_dated_entry( $this->coverage_id, '2026-01-01 11:30:00' );
		$newest_entry_id = self::create_dated_entry( $this->coverage_id, '2026-01-01 12:00:00' );

		self::create_dated_entry( $this->coverage_id, '2026-01-01 10:00:00' );
		wp_trash_post( $newest_entry_id );

		$poll    = $this->get_lite_feed(
			[
				'cursor' => "{$newest_entry_id}:2026-01-01 12:00:00",
				'latest' => 1,
			]
		)->get_data();
		$inserts = wp_list_filter( $poll['entries'], [ 'type' => 'insert' ] );

		$this->assertTrue( $poll['replace'] );
		$this->assertSame(
			array_map( static fn( $entry_id ) => Lite_Feed::render_entry( get_post( $entry_id ), 'poll', true ), [ $older_entry_id, $oldest_entry_id ] ),
			array_values( wp_list_pluck( $inserts, 'html' ) )
		);
	}

	/**
	 * Lite pages carry no ads. A feed that shows them on a full page and in
	 * that page's polls shows none on a lite page, and its lite polls and load
	 * more bring none.
	 */
	public function test_lite_page_and_its_requests_carry_no_ads() {
		self::enable_ad_placement();
		self::create_entry( $this->coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );

		$attributes   = [
			'enableAds'   => true,
			'adsInterval' => 1,
		];
		$full_page    = $this->render_block_html( $attributes );
		$template_key = preg_match( '/data-template-key="([^"]+)"/', $full_page, $matches ) ? $matches[1] : '';
		$poll         = [
			'cursor'       => '0:2025-12-31 00:00:00',
			'template_key' => $template_key,
		];
		$full_poll    = self::dispatch( 'GET', "/coverages/{$this->coverage_id}/entries", $poll )->get_data()['entries'];

		$this->assertStringContainsString( 'test-ad-code', $full_page, 'A full page shows an ad.' );
		$this->assertStringContainsString( 'test-ad-code', (string) $full_poll[0]['adHtml'], 'So do its polls.' );

		$this->assertStringNotContainsString( 'test-ad-code', $this->render_lite_page( $attributes ), 'A lite page shows none.' );
		$this->assertNull( $this->get_lite_feed( $poll )->get_data()['entries'][0]['adHtml'], 'Nor do its polls.' );
		$this->assertStringNotContainsString(
			'test-ad-code',
			$this->get_lite_feed(
				[
					'before'       => '2026-01-02 00:00:00',
					'template_key' => $template_key,
				]
			)->get_data()['html'],
			'Nor does its load more.'
		);
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
	 * not have, so a bare block type stands in when it's missing. Afterwards
	 * the handle is forgotten, printed or not, so the next test prints it too.
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
			wp_scripts()->done = array_values( array_diff( wp_scripts()->done, [ 'rolling-coverage-test-view' ] ) );

			if ( ! $registered ) {
				unregister_block_type( Rolling_Coverage_Block::BLOCK_NAME );
			}
		}
	}

	/**
	 * Serve a feed on a lite page as Lite Site does: the feed renders, then
	 * Lite Site applies its allowlist filter as it cleans the page.
	 */
	private static function serve_lite_feed() {
		Lite_Feed::add_feed();
		apply_filters( 'newspack_lite_site_allowed_html', [] );
	}

	/**
	 * Lite pages print no enqueued scripts, so a page with a feed prints the
	 * view script itself, and a page without one prints nothing.
	 */
	public function test_view_script_prints_only_on_a_lite_page_with_a_feed() {
		$this->with_view_script(
			function () {
				$this->assertSame( '', $this->print_after_footer(), 'A lite page without a feed prints no script.' );

				self::serve_lite_feed();

				$this->assertStringContainsString( 'https://example.test/view.js', $this->print_after_footer() );
			}
		);
	}

	/**
	 * Lite Site caches the page for every reader, so the view script prints
	 * without the `wp_print_scripts` action, whose callbacks could print a
	 * reader's own settings into it.
	 */
	public function test_view_script_prints_without_the_script_printing_action() {
		$this->with_view_script(
			function () {
				add_action(
					'wp_print_scripts',
					static function () {
						echo '<script>var reader = "reader@example.test";</script>';
					}
				);
				self::serve_lite_feed();

				$footer = $this->print_after_footer();

				$this->assertStringContainsString( 'https://example.test/view.js', $footer );
				$this->assertStringNotContainsString( 'reader@example.test', $footer );
			}
		);
	}

	/**
	 * A Lite Site without the allowlist filter strips the markup the feed's
	 * styles and view script rely on: the styles would fix a new-posts
	 * control that lost its hidden attribute to the screen for good, and the
	 * script would find no settings to poll with. A page whose feed was
	 * cleaned that way gets neither, only a rule hiding the Load More and
	 * Check for Updates controls, which lost their hidden attribute too and
	 * have nothing to run them.
	 */
	public function test_only_the_controls_nothing_runs_are_hidden_when_lite_site_cannot_keep_the_feed_markup() {
		$this->with_view_script(
			function () {
				// The feed rendered, but Lite Site never applied the filter.
				Lite_Feed::add_feed();
				$styles = $this->print_lite_styles();

				$this->assertMatchesRegularExpression( '/^\.newspack-rolling-coverage-load-more,\s*\.newspack-rolling-coverage-check-updates\s*\{\s*display:\s*none;\s*\}$/', $styles, 'Only the Load More and Check for Updates controls are hidden.' );
				$this->assertSame( '', $this->print_after_footer(), 'No script.' );
			}
		);
	}

	/**
	 * A page with a feed gets the few styles the feed needs, which print
	 * inside Lite Site's style element.
	 */
	public function test_styles_print_only_on_a_lite_page_with_a_feed() {
		$this->assertSame( '', $this->print_lite_styles(), 'A lite page without a feed adds no styles.' );

		self::serve_lite_feed();
		$styles = $this->print_lite_styles();

		$this->assertStringContainsString( '.newspack-rolling-coverage-new-entries[hidden]', $styles );
		$this->assertStringContainsString( '.newspack-rolling-coverage-status', $styles );
		$this->assertStringNotContainsString( '.newspack-rolling-coverage-load-more', $styles, 'The view script shows and hides the Load More control.' );
		$this->assertStringNotContainsString( '.newspack-rolling-coverage-check-updates', $styles, 'And the Check for Updates control.' );
		$this->assertStringNotContainsString( '<', $styles, 'Nothing can close the style element early.' );
	}
}
