<?php
/**
 * Shared polling: one poll URL for every reader of a page.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Poll_Cursor;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * Shared poll replies and the cursor rules pages apply to them.
 */
class Test_Shared_Poll extends Rolling_Coverage_TestCase {

	/**
	 * Coverage term ID.
	 *
	 * @var int
	 */
	private $coverage_id;

	/**
	 * Template key of the coverage's default config.
	 *
	 * @var string
	 */
	private $template_key;

	/**
	 * Store the default config so requests carry a key the coverage knows.
	 */
	public function set_up() {
		parent::set_up();
		$this->coverage_id = self::create_coverage();
		$load              = new ReflectionMethod( Rolling_Coverage_Block::class, 'load_block_config' );
		$persist           = new ReflectionMethod( Rolling_Coverage_Block::class, 'persist_block_config' );
		$load->setAccessible( true );
		$persist->setAccessible( true );
		$defaults           = $load->invoke( null, $this->coverage_id, '' );
		$this->template_key = $persist->invoke( null, $this->coverage_id, $defaults['template'], $defaults['adsEnabled'], $defaults['adsInterval'] );
	}

	/**
	 * A shared poll of the test coverage.
	 *
	 * @param array $params Extra request parameters.
	 * @return WP_REST_Response
	 */
	private function share( array $params = [] ) {
		return self::dispatch(
			'GET',
			"/coverages/{$this->coverage_id}/entries",
			array_merge(
				[
					'recent'       => 1,
					'template_key' => $this->template_key,
				],
				$params
			)
		);
	}

	/**
	 * Create a published entry in the test coverage at a GMT time.
	 *
	 * @param string $gmt  `Y-m-d H:i:s`; the test site runs on UTC.
	 * @param array  $args Post factory arguments.
	 * @return int Entry ID.
	 */
	private function entry_at( $gmt, array $args = [] ) {
		return self::create_entry( $this->coverage_id, array_merge( [ 'post_date' => $gmt ], $args ) );
	}

	/**
	 * A GMT time some minutes ago.
	 *
	 * @param int $minutes Minutes.
	 * @return string `Y-m-d H:i:s`.
	 */
	private function minutes_ago( $minutes ) {
		return gmdate( 'Y-m-d H:i:s', time() - 60 * $minutes );
	}

	/**
	 * Render the test coverage's feed block with its defaults.
	 *
	 * @return string
	 */
	private function render_feed() {
		$attributes = [ 'coverageId' => $this->coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * The cases both copies of the cursor rules are checked against.
	 *
	 * @return array[]
	 */
	public function cursor_case_provider() {
		$cases = json_decode( file_get_contents( __DIR__ . '/fixtures/poll-cursor-cases.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return array_combine( array_column( $cases, 'case' ), array_map( static fn( $c ) => [ $c ], $cases ) );
	}

	/**
	 * The server's rules for a change against a page's position, as a real
	 * cursor poll from that position applies them: it skips what changed
	 * before the position or that the position holds, sends a takedown only
	 * when it came in the position's second or later, and calls an entry
	 * new when it was first published after the position, or in its second
	 * without the position holding it. tests/js/shared-poll-check.ts checks
	 * the page's copy of the rules against the same file.
	 *
	 * @dataProvider cursor_case_provider
	 *
	 * @param array $case One case from tests/fixtures/poll-cursor-cases.json.
	 */
	public function test_cursor_rules_match_the_shared_cases( $case ) {
		global $wpdb;

		$id = $this->entry_at( '2025-12-31 00:00:00' );

		if ( 'remove' === $case['type'] ) {
			wp_update_post(
				[
					'ID'          => $id,
					'post_status' => 'draft',
				]
			);
			update_post_meta( $id, Post_Type::META_UNPUBLISHED_GMT, $case['unpublished'] );
		} else {
			update_post_meta( $id, Post_Type::META_PUBLISHED_GMT, $case['published'] );
		}

		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => $case['modified'] ], [ 'ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );

		// No `@marker`, so the poll always looks for changes; no `latest`, so
		// a takedown comes as a removal rather than a capped feed's burst.
		$poll    = self::dispatch(
			'GET',
			"/coverages/{$this->coverage_id}/entries",
			[
				'template_key' => $this->template_key,
				'cursor'       => ( $case['held'] ? $id : 0 ) . ':' . $case['cursorModified'],
			]
		)->get_data();
		$change  = wp_list_filter( $poll['entries'], [ 'id' => $id ] );
		$outcome = $change ? [
			'insert' => 'new',
			'update' => 'edit',
			'remove' => 'remove',
		][ reset( $change )['type'] ] : 'skip';

		$this->assertSame( $case['expected'], $outcome );
	}

	/**
	 * With shared polling on, which is the default, the page tells its
	 * script to poll the shared URL.
	 */
	public function test_page_polls_the_shared_url_by_default() {
		$this->entry_at( $this->minutes_ago( 1 ) );

		$this->assertStringContainsString( 'data-poll="shared"', $this->render_feed() );
	}

	/**
	 * Values that turn the switch off: false, and the string 'false' that
	 * `wp config set` writes for the constant without `--raw`. The constant
	 * can't change within a test run, so the filter carries them.
	 *
	 * @return array[]
	 */
	public function switch_off_provider() {
		return [
			'boolean' => [ false ],
			'string'  => [ 'false' ],
		];
	}

	/**
	 * Turned off, new pages poll their own cursor, and the reply tells pages
	 * already open to do the same.
	 *
	 * @dataProvider switch_off_provider
	 *
	 * @param mixed $value What the switch returns.
	 */
	public function test_switch_off_moves_pages_to_cursor_polling( $value ) {
		add_filter( 'newspack_rolling_coverage_shared_polling', static fn() => $value );
		$this->entry_at( $this->minutes_ago( 1 ) );

		$data = $this->share()->get_data();

		$this->assertStringNotContainsString( 'data-poll=', $this->render_feed() );
		$this->assertFalse( $data['sharedPolling'] );
		$this->assertSame( [], $data['changes'] );
	}

	/**
	 * The window reaches back to the newest change before the cutoff, so a
	 * page that saw that change can apply the reply whatever came since.
	 */
	public function test_window_starts_at_the_last_change_before_the_cutoff() {
		$this->entry_at( $this->minutes_ago( 90 ) );
		$last_before = $this->entry_at( $this->minutes_ago( 60 ) );
		$recent      = $this->entry_at( $this->minutes_ago( 2 ) );

		$data = $this->share()->get_data();

		$this->assertSame( get_post( $last_before )->post_modified_gmt, $data['since'] );
		$this->assertSame( [ $recent, $last_before ], array_column( $data['changes'], 'id' ) );
	}

	/**
	 * After a quiet stretch the reply still carries the last change, which
	 * current pages skip by their marker.
	 */
	public function test_quiet_coverage_answers_with_its_last_change() {
		$id   = $this->entry_at( $this->minutes_ago( 60 ) );
		$data = $this->share()->get_data();

		$this->assertSame( [ $id ], array_column( $data['changes'], 'id' ) );
		$this->assertSame( (string) new Poll_Cursor( get_post( $id )->post_modified_gmt, [ $id ], Poll_Cursor::get_marker( $this->coverage_id ) ), $data['cursor'] );
	}

	/**
	 * A coverage with no entries answers with no changes and a cursor
	 * carrying its marker.
	 */
	public function test_coverage_without_changes_answers_with_none() {
		$data = $this->share()->get_data();

		$this->assertSame( '', $data['since'] );
		$this->assertSame( [], $data['changes'] );
		$this->assertSame( Poll_Cursor::get_marker( $this->coverage_id ), Poll_Cursor::parse( $data['cursor'] )->marker );
	}

	/**
	 * Entries taken down in the window come back as removals, with the time
	 * they were taken down; an entry never published is never named.
	 */
	public function test_entries_taken_down_in_the_window_come_back_as_removals() {
		$this->entry_at( $this->minutes_ago( 60 ) );
		$taken_down = $this->entry_at( $this->minutes_ago( 3 ) );
		$draft      = $this->entry_at( $this->minutes_ago( 2 ), [ 'post_status' => 'draft' ] );

		wp_trash_post( $taken_down );

		$changes = array_column( $this->share()->get_data()['changes'], null, 'id' );

		$this->assertSame( 'remove', $changes[ $taken_down ]['type'] );
		$this->assertSame( get_post_meta( $taken_down, Post_Type::META_UNPUBLISHED_GMT, true ), $changes[ $taken_down ]['unpublished'] );
		$this->assertArrayNotHasKey( $draft, $changes );
	}

	/**
	 * An entry's `published` is when it first went live, so a draft written
	 * long ago and published now arrives as new on every page.
	 */
	public function test_entries_carry_their_first_publish_time() {
		$id = $this->entry_at( $this->minutes_ago( 30 ), [ 'post_status' => 'draft' ] );
		wp_publish_post( $id );

		$changes = array_column( $this->share()->get_data()['changes'], null, 'id' );

		$this->assertSame( Post_Type::get_entry_published_gmt( get_post( $id ) ), $changes[ $id ]['published'] );
		$this->assertNotSame( get_post( $id )->post_date_gmt, $changes[ $id ]['published'] );
	}

	/**
	 * A page that takes the reply's cursor holds exactly what the reply
	 * carried: a cursor poll from it repeats nothing, and gets the next
	 * entry.
	 */
	public function test_page_adopting_the_cursor_neither_skips_nor_repeats() {
		$this->entry_at( $this->minutes_ago( 60 ) );
		$this->entry_at( $this->minutes_ago( 2 ) );
		$cursor_poll = [
			'recent' => 0,
			'cursor' => $this->share()->get_data()['cursor'],
		];

		$this->assertSame( [], $this->share( $cursor_poll )->get_data()['entries'] );

		// Now, not ahead: a future date would schedule the entry instead.
		$next    = $this->entry_at( gmdate( 'Y-m-d H:i:s' ) );
		$entries = $this->share( $cursor_poll )->get_data()['entries'];

		$this->assertSame( [ [ $next, 'insert' ] ], array_map( null, array_column( $entries, 'id' ), array_column( $entries, 'type' ) ) );
	}

	/**
	 * More changes in the window than a poll sends: the page takes its own
	 * cursor path.
	 */
	public function test_window_over_the_cap_overflows() {
		for ( $i = 0; $i <= Rolling_Coverage_Block::POLL_CAP; $i++ ) {
			$this->entry_at( $this->minutes_ago( 1 ) );
		}

		$data = $this->share()->get_data();

		$this->assertTrue( $data['overflow'] );
		$this->assertSame( [], $data['changes'] );
	}

	/**
	 * A key the coverage no longer stores asks the page to reload, as a
	 * cursor poll does.
	 */
	public function test_unknown_template_key_asks_the_page_to_reload() {
		$this->entry_at( $this->minutes_ago( 1 ) );

		$data = $this->share( [ 'template_key' => 'pruned' ] )->get_data();

		$this->assertTrue( $data['staleTemplate'] );
		$this->assertSame( [], $data['changes'] );
	}

	/**
	 * Shared replies are cached like any poll, and carry what polls carry.
	 */
	public function test_shared_reply_is_cached_like_a_poll() {
		$this->entry_at( $this->minutes_ago( 1 ) );
		$response = $this->share();

		$this->assertSame( 'public, max-age=' . Rolling_Coverage_Block::POLL_MAX_AGE, $response->get_headers()['Cache-Control'] );
		$this->assertSame( 'active', $response->get_data()['status'] );
		$this->assertArrayHasKey( 'minPollInterval', $response->get_data() );
	}

	/**
	 * Entries come rendered for the page to flag as new itself.
	 */
	public function test_entries_arrive_without_an_arrival() {
		$this->entry_at( $this->minutes_ago( 1 ) );

		$this->assertStringContainsString( 'data-arrival=""', $this->share()->get_data()['changes'][0]['html'] );
	}

	/**
	 * Store a config with ads on or off, and use its key.
	 *
	 * @param int  $interval    Entries between ads.
	 * @param bool $ads_enabled Whether the block shows ads.
	 */
	private function use_ads( $interval, $ads_enabled = true ) {
		self::enable_ad_placement();
		$load    = new ReflectionMethod( Rolling_Coverage_Block::class, 'load_block_config' );
		$persist = new ReflectionMethod( Rolling_Coverage_Block::class, 'persist_block_config' );
		$load->setAccessible( true );
		$persist->setAccessible( true );
		$defaults           = $load->invoke( null, $this->coverage_id, '' );
		$this->template_key = $persist->invoke( null, $this->coverage_id, $defaults['template'], $ads_enabled, $interval );
	}

	/**
	 * Feeds whose polls never carry ads: a block with ads off, even where
	 * ads could run, a lite page and a capped feed.
	 *
	 * @return array[]
	 */
	public function feeds_without_ads_provider() {
		return [
			'ads off' => [ false, [] ],
			'lite'    => [ true, [ 'lite' => 1 ] ],
			'capped'  => [ true, [ 'latest' => 2 ] ],
		];
	}

	/**
	 * Those feeds carry no placements in shared replies either.
	 *
	 * @dataProvider feeds_without_ads_provider
	 *
	 * @param bool  $ads_enabled The block's ads setting.
	 * @param array $params      Extra request parameters.
	 */
	public function test_feeds_without_ads_carry_no_ad_placements( $ads_enabled, $params ) {
		if ( isset( $params['lite'] ) ) {
			require_once __DIR__ . '/mocks/class-lite-site.php';
		}

		$this->use_ads( 1, $ads_enabled );
		$this->entry_at( $this->minutes_ago( 1 ) );

		$data = $this->share( $params )->get_data();

		$this->assertArrayNotHasKey( 'adsInterval', $data );
		$this->assertArrayNotHasKey( 'adHtml', $data['changes'][0] );
	}

	/**
	 * With ads on, every entry carries a placement of its own, and the reply
	 * the interval, for the page to place one after every Nth entry new to it.
	 */
	public function test_each_entry_carries_its_own_ad_placement() {
		$this->use_ads( 2 );
		$this->entry_at( $this->minutes_ago( 2 ) );
		$this->entry_at( $this->minutes_ago( 1 ) );

		$data = $this->share()->get_data();

		$this->assertSame( 2, $data['adsInterval'] );
		$this->assertCount( 2, array_filter( array_column( $data['changes'], 'adHtml' ) ) );
		$this->assertNotSame( $data['changes'][0]['adHtml'], $data['changes'][1]['adHtml'], 'Each placement needs its own container id.' );
	}

	/**
	 * Only an entry first published in the window can be new to a page that
	 * applies the reply, whose position is at or after the window's start,
	 * so only those carry a placement. An edit to an older entry would carry
	 * one no page uses.
	 */
	public function test_only_entries_published_in_the_window_carry_ad_placements() {
		$this->use_ads( 1 );
		$edited = $this->entry_at( $this->minutes_ago( 20 ) );
		$this->entry_at( $this->minutes_ago( 10 ) );
		$new = $this->entry_at( $this->minutes_ago( 1 ) );
		wp_update_post(
			[
				'ID'           => $edited,
				'post_content' => 'Edited.',
			]
		);

		$changes = array_column( $this->share()->get_data()['changes'], null, 'id' );

		$this->assertArrayHasKey( $edited, $changes );
		$this->assertArrayNotHasKey( 'adHtml', $changes[ $edited ] );
		$this->assertNotEmpty( $changes[ $new ]['adHtml'] );
	}

	/**
	 * Makes a change the way a request already under way sees it. The
	 * request read the posts and terms last-changed times at an earlier
	 * query, before the change, so its queries find their cached results
	 * under those times; it reads the coverage's marker later, after the
	 * change moved it.
	 *
	 * @param callable $change Makes the change.
	 * @return mixed What the change returns.
	 */
	private function change_mid_request( callable $change ) {
		$posts  = wp_cache_get_last_changed( 'posts' );
		$terms  = wp_cache_get_last_changed( 'terms' );
		$result = $change();

		wp_cache_set( 'last_changed', $posts, 'posts' );
		wp_cache_set( 'last_changed', $terms, 'terms' );

		return $result;
	}

	/**
	 * A reply carrying the marker a publish moved carries the entry it
	 * published. Pages adopt the reply's marker, so a reply without the
	 * entry would have them take every later reply as unchanged and miss
	 * the entry until the coverage changes again.
	 */
	public function test_shared_reply_with_a_new_marker_carries_its_change() {
		$this->entry_at( $this->minutes_ago( 10 ) );
		$this->share();

		$new  = $this->change_mid_request( fn() => $this->entry_at( gmdate( 'Y-m-d H:i:s' ) ) );
		$data = $this->share()->get_data();

		$this->assertStringEndsWith( '@' . Poll_Cursor::get_marker( $this->coverage_id ), $data['cursor'] );
		$this->assertContains( $new, array_column( $data['changes'], 'id' ) );
	}

	/**
	 * The same holds for a page's own cursor poll, which answers a cursor
	 * carrying the current marker without looking at the entries.
	 */
	public function test_cursor_reply_with_a_new_marker_carries_its_change() {
		$this->entry_at( $this->minutes_ago( 10 ) );
		$cursor = $this->share()->get_data()['cursor'];
		$poll   = fn() => self::dispatch(
			'GET',
			"/coverages/{$this->coverage_id}/entries",
			[
				'cursor'       => $cursor,
				'template_key' => $this->template_key,
			]
		)->get_data();

		// Past the page's marker, so its poll looks at the entries and caches what it finds.
		Poll_Cursor::mark_changed( $this->coverage_id );
		$poll();

		$new  = $this->change_mid_request( fn() => $this->entry_at( gmdate( 'Y-m-d H:i:s' ) ) );
		$data = $poll();

		$this->assertStringEndsWith( '@' . Poll_Cursor::get_marker( $this->coverage_id ), $data['cursor'] );
		$this->assertContains( $new, array_column( $data['entries'], 'id' ) );
	}

	/**
	 * Caches outside WordPress's own key query results on their SQL alone,
	 * such as the Advanced Post Cache that WordPress.com and Atomic sites
	 * run. The marker is in the SQL, so they keep a result per marker too.
	 */
	public function test_entry_queries_carry_the_marker_in_their_sql() {
		$this->entry_at( $this->minutes_ago( 10 ) );
		Poll_Cursor::mark_changed( $this->coverage_id );
		$marker  = Poll_Cursor::get_marker( $this->coverage_id );
		$queries = [];
		$capture = static function ( $sql, $query ) use ( &$queries ) {
			if ( Post_Type::CPT_SLUG === $query->get( 'post_type' ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};

		add_filter( 'posts_request', $capture, 10, 2 );
		$this->share();
		$this->render_feed();
		remove_filter( 'posts_request', $capture, 10 );

		$this->assertNotEmpty( $queries );
		foreach ( $queries as $sql ) {
			$this->assertStringContainsString( $marker, $sql );
		}
	}

	/**
	 * And for the page itself, whose cursor starts its polls: a page given
	 * the marker a publish moved lists the entry it published.
	 */
	public function test_render_with_a_new_marker_lists_its_change() {
		$this->entry_at( $this->minutes_ago( 10 ) );

		// The first render stores the block's config, which moves the terms
		// last-changed time; the second queries as every later page does.
		$this->render_feed();
		$this->render_feed();

		$new  = $this->change_mid_request( fn() => $this->entry_at( gmdate( 'Y-m-d H:i:s' ) ) );
		$html = $this->render_feed();

		$this->assertStringContainsString( '@' . Poll_Cursor::get_marker( $this->coverage_id ) . '"', $html );
		$this->assertStringContainsString( 'data-entry-id="' . $new . '"', $html );
	}
}
