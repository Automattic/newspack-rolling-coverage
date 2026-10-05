<?php
/**
 * Tests for the public entries route behind the Rolling Coverage block.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * This route is open to anonymous readers. It polls for new and edited
 * entries from a cursor, and pages backwards for "load more". These tests
 * cover what it may serve, how the cursor moves, and how long caches may
 * keep its responses.
 */
class Test_Reader_Feed extends Rolling_Coverage_TestCase {

	/**
	 * Coverage the entries belong to.
	 *
	 * @var int
	 */
	private $coverage_id;

	/**
	 * Create the coverage. Requests are anonymous throughout.
	 */
	public function set_up() {
		parent::set_up();
		$this->coverage_id = self::create_coverage();
		wp_set_current_user( 0 );
	}

	/**
	 * Request the feed for the test coverage.
	 *
	 * @param array $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function get_feed( array $params ) {
		return self::dispatch( 'GET', "/coverages/{$this->coverage_id}/entries", array_merge( [ 'template_key' => 'test' ], $params ) );
	}

	/**
	 * Create an entry in the test coverage at a fixed time.
	 *
	 * On insert WordPress copies the date into the modified columns, so a
	 * published entry's `post_modified_gmt`, which cursors are built from, is
	 * this time too. Drafts get a concrete GMT date too: the plugin's
	 * `normalize_entry_gmt_dates()` derives it from the local date rather than
	 * leaving WordPress's zero date in place.
	 *
	 * @param string $post_date Entry date, `Y-m-d H:i:s`. The test site runs on UTC.
	 * @param array  $args      Post factory arguments.
	 * @return int Entry post ID.
	 */
	private function create_entry_at( $post_date, array $args = [] ) {
		return self::create_entry( $this->coverage_id, array_merge( [ 'post_date' => $post_date ], $args ) );
	}

	/**
	 * A poll returns entries published after the cursor as inserts and
	 * entries edited after it as updates, and moves the cursor to the most
	 * recent change. The entry the cursor points at is not sent again.
	 */
	public function test_poll_separates_new_entries_from_edited_ones() {
		$earlier_entry_id = $this->create_entry_at( '2026-01-01 11:00:00' );
		$cursor_entry_id  = $this->create_entry_at( '2026-01-01 12:00:00' );
		$cursor           = "{$cursor_entry_id}:2026-01-01 12:00:00";

		$later_entry_id = $this->create_entry_at( '2026-01-01 12:05:00' );
		wp_update_post(
			[
				'ID'           => $earlier_entry_id,
				'post_content' => 'Corrected.',
			]
		);

		$poll = $this->get_feed( [ 'cursor' => $cursor ] )->get_data();

		$this->assertSame(
			[
				$earlier_entry_id => 'update',
				$later_entry_id   => 'insert',
			],
			wp_list_pluck( $poll['entries'], 'type', 'id' ),
			'The edited entry should be an update and the later one an insert.'
		);
		$this->assertSame( $earlier_entry_id . ':' . get_post( $earlier_entry_id )->post_modified_gmt, $poll['cursor'], 'The cursor should move to the most recent change.' );
	}

	/**
	 * Drafts are never served, by polling or by paging.
	 */
	public function test_unpublished_entries_are_never_served() {
		$this->create_entry_at( '2026-01-01 12:05:00', [ 'post_status' => 'draft' ] );
		$this->create_entry_at( '2026-01-01 12:06:00', [ 'post_status' => 'private' ] );

		// Created last so the coverage's last-modified ends at this entry's date.
		$published_entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );

		$poll = $this->get_feed( [ 'cursor' => '0:2026-01-01 00:00:00' ] )->get_data();
		$page = $this->get_feed( [ 'before' => '2026-01-02 00:00:00' ] )->get_data();

		$this->assertSame( [ $published_entry_id ], wp_list_pluck( $poll['entries'], 'id' ), 'Polling should only return the published entry.' );
		$this->assertSame( 1, $page['count'], 'Paging should only return the published entry.' );
		$this->assertStringContainsString( 'data-entry-id="' . $published_entry_id . '"', $page['html'], 'The page should hold the published entry.' );
	}

	/**
	 * Trashing a published entry tells open pages to drop it: the next poll
	 * names it as removed, with no markup, and moves the cursor past it so
	 * later polls stay idle.
	 */
	public function test_poll_tells_pages_to_drop_a_trashed_entry() {
		$trashed_entry_id = $this->create_entry_at( '2026-01-01 11:00:00' );
		$cursor_entry_id  = $this->create_entry_at( '2026-01-01 12:00:00' );

		wp_trash_post( $trashed_entry_id );

		$poll = $this->get_feed( [ 'cursor' => "{$cursor_entry_id}:2026-01-01 12:00:00" ] )->get_data();

		$this->assertSame( [ $trashed_entry_id => 'remove' ], wp_list_pluck( $poll['entries'], 'type', 'id' ) );
		$this->assertSame( '', $poll['entries'][0]['html'], 'A removal carries no markup.' );
		$this->assertSame( $trashed_entry_id . ':' . get_post( $trashed_entry_id )->post_modified_gmt, $poll['cursor'], 'The cursor should move past the removal.' );
		$this->assertArrayNotHasKey( 'replace', $poll, 'An uncapped feed is never sent whole.' );
		$this->assertSame( [], $this->get_feed( [ 'cursor' => $poll['cursor'] ] )->get_data()['entries'], 'The removal should be sent once.' );
	}

	/**
	 * The other ways an editor takes an entry down.
	 *
	 * @return array[]
	 */
	public function unpublish_changes() {
		return [
			'back to draft'  => [ [ 'post_status' => 'draft' ] ],
			'pending review' => [ [ 'post_status' => 'pending' ] ],
			'private'        => [ [ 'post_status' => 'private' ] ],
			'scheduled anew' => [
				[
					'post_date'     => '2099-01-01 12:00:00',
					'post_date_gmt' => '2099-01-01 12:00:00',
				],
			],
		];
	}

	/**
	 * Taking a published entry down any other way removes it from open pages
	 * too.
	 *
	 * @dataProvider unpublish_changes
	 * @param array $change Post fields the editor changes.
	 */
	public function test_poll_tells_pages_to_drop_an_unpublished_entry( $change ) {
		$entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );

		wp_update_post( array_merge( [ 'ID' => $entry_id ], $change ) );

		$poll = $this->get_feed( [ 'cursor' => "{$entry_id}:2026-01-01 12:00:00" ] )->get_data();

		$this->assertNotSame( 'publish', get_post_status( $entry_id ) );
		$this->assertSame( [ $entry_id => 'remove' ], wp_list_pluck( $poll['entries'], 'type', 'id' ) );
	}

	/**
	 * An entry readers never saw is never named, even once it's trashed, and
	 * the cursor stays clear of it.
	 */
	public function test_poll_never_names_an_entry_that_was_never_published() {
		$draft_id = $this->create_entry_at( '2026-01-01 12:05:00', [ 'post_status' => 'draft' ] );

		wp_trash_post( $draft_id );

		$poll = $this->get_feed( [ 'cursor' => '0:2026-01-01 00:00:00' ] )->get_data();

		$this->assertSame( [], $poll['entries'] );
		$this->assertSame( '0:2026-01-01 00:00:00', $poll['cursor'] );
	}

	/**
	 * An entry published again after being taken down polls as an entry,
	 * not as a removal.
	 */
	public function test_poll_sends_a_republished_entry_as_an_entry() {
		$entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );

		wp_update_post(
			[
				'ID'          => $entry_id,
				'post_status' => 'draft',
			]
		);
		wp_update_post(
			[
				'ID'          => $entry_id,
				'post_status' => 'publish',
			]
		);

		$poll = $this->get_feed( [ 'cursor' => "{$entry_id}:2026-01-01 12:00:00" ] )->get_data();

		$this->assertSame( [ $entry_id ], wp_list_pluck( $poll['entries'], 'id' ) );
		$this->assertStringContainsString( 'data-entry-id="' . $entry_id . '"', $poll['entries'][0]['html'], 'The entry should come with its markup.' );
	}

	/**
	 * A trashed coverage answers as if it did not exist, even though its
	 * entries are still published.
	 */
	public function test_trashed_coverage_is_not_served() {
		$this->create_entry_at( '2026-01-01 12:00:00' );
		update_term_meta( $this->coverage_id, 'rolling_coverage_status', 'trash' );

		$this->assertSame( 404, $this->get_feed( [ 'cursor' => '0:2026-01-01 00:00:00' ] )->get_status() );
	}

	/**
	 * A burst larger than the poll cap is not sent piecemeal. The reader is
	 * told to reload and keeps its cursor.
	 */
	public function test_poll_asks_the_reader_to_reload_when_the_burst_exceeds_the_cap() {
		$cursor = '0:2026-01-01 00:00:00';

		for ( $i = 0; $i <= Rolling_Coverage_Block::POLL_CAP; $i++ ) {
			$this->create_entry_at( '2026-01-01 12:00:00' );
		}

		$poll = $this->get_feed( [ 'cursor' => $cursor ] )->get_data();

		$this->assertTrue( $poll['overflow'], 'The response should signal an overflow.' );
		$this->assertSame( [], $poll['entries'], 'No partial burst should be sent.' );
		$this->assertSame( $cursor, $poll['cursor'], 'The cursor should not move.' );
	}

	/**
	 * "Load more" returns the entries before a date, newest first, and the
	 * date to continue from.
	 */
	public function test_load_more_pages_backwards_from_a_date() {
		$oldest_entry_id = $this->create_entry_at( '2026-01-01 10:00:00' );
		$middle_entry_id = $this->create_entry_at( '2026-01-01 11:00:00' );
		$this->create_entry_at( '2026-01-01 12:00:00' );

		$first_page = $this->get_feed(
			[
				'before'   => '2026-01-01 12:00:00',
				'per_page' => 1,
			]
		)->get_data();

		$this->assertStringContainsString( 'data-entry-id="' . $middle_entry_id . '"', $first_page['html'], 'The newest entry before the date should come first.' );
		$this->assertStringNotContainsString( 'data-entry-id="' . $oldest_entry_id . '"', $first_page['html'], 'The page size should be respected.' );
		$this->assertTrue( $first_page['hasMore'], 'A full page should report that more may follow.' );
		$this->assertSame( '2026-01-01 11:00:00', $first_page['before'], 'The next page should continue from the last entry served.' );
	}

	/**
	 * The route needs a direction: a cursor to poll from or a date to page from.
	 */
	public function test_request_without_a_cursor_or_a_date_is_refused() {
		$this->assertSame( 400, $this->get_feed( [] )->get_status() );
	}

	/**
	 * A draft keeps the date it was created with when it is published, and is
	 * stamped with a distinct publish moment.
	 */
	public function test_publishing_a_draft_keeps_the_date_it_was_created_with() {
		$draft_id = $this->create_entry_at( '2026-01-01 12:00:00', [ 'post_status' => 'draft' ] );

		$this->assertSame(
			'2026-01-01 12:00:00',
			get_post( $draft_id )->post_date_gmt,
			'normalize_entry_gmt_dates() should give the draft a concrete GMT date.'
		);

		wp_update_post(
			[
				'ID'          => $draft_id,
				'post_status' => 'publish',
			]
		);

		$published = get_post( $draft_id );

		$this->assertSame( '2026-01-01 12:00:00', $published->post_date, 'Publishing should keep the created local date.' );
		$this->assertSame( '2026-01-01 12:00:00', $published->post_date_gmt, 'Publishing should keep the created GMT date.' );
		$this->assertNotSame(
			'2026-01-01 12:00:00',
			Post_Type::get_entry_published_gmt( $published ),
			'The recorded publish moment should differ from the created date.'
		);
	}

	/**
	 * A draft first published after the poll cursor is served as an insert,
	 * even though its created date precedes the cursor.
	 */
	public function test_draft_published_after_the_cursor_polls_as_an_insert() {
		$draft_id = $this->create_entry_at( '2026-01-01 12:00:00', [ 'post_status' => 'draft' ] );

		// Save first so the coverage's last-modified moves past the cursor.
		wp_update_post( [ 'ID' => $draft_id ] );

		$cursor = '0:2026-01-01 12:30:00';

		wp_publish_post( $draft_id );

		$poll = $this->get_feed( [ 'cursor' => $cursor ] )->get_data();

		$this->assertSame(
			[ $draft_id => 'insert' ],
			wp_list_pluck( $poll['entries'], 'type', 'id' ),
			'A draft first published after the cursor polls as an insert; this fails if META_PUBLISHED_GMT is dropped.'
		);
	}

	/**
	 * Entries published after the poll cursor, one count for each kind of
	 * poll response: nothing new, new entries, and a burst over the cap.
	 *
	 * @return array[]
	 */
	public function new_entry_count_provider() {
		return [
			'nothing new'          => [ 0 ],
			'a new entry'          => [ 1 ],
			'a burst over the cap' => [ Rolling_Coverage_Block::POLL_CAP + 1 ],
		];
	}

	/**
	 * Shared caches may keep a poll, so readers polling at the same moment
	 * share one request to the site, but for at most half the block's default
	 * poll interval: stacked caches can serve a response for up to twice its
	 * max-age, and a new entry should still reach open pages within about one
	 * poll.
	 *
	 * @dataProvider new_entry_count_provider
	 *
	 * @param int $new_entry_count Entries published after the cursor.
	 */
	public function test_poll_is_shared_for_at_most_half_the_poll_interval( $new_entry_count ) {
		$cursor_entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );

		for ( $i = 0; $i < $new_entry_count; $i++ ) {
			$this->create_entry_at( '2026-01-01 12:05:00' );
		}

		$headers               = $this->get_feed( [ 'cursor' => "{$cursor_entry_id}:2026-01-01 12:00:00" ] )->get_headers();
		$block_json            = wp_json_file_decode( dirname( __DIR__ ) . '/src/blocks/rolling-coverage/block.json', [ 'associative' => true ] );
		$default_poll_interval = $block_json['attributes']['pollInterval']['default'];
		$cache_control         = $headers['Cache-Control'] ?? '';

		preg_match( '/\bmax-age=(\d+)\b/', $cache_control, $max_age_match );

		$this->assertNotEmpty( $max_age_match, 'A poll should say how long it may be cached.' );
		$this->assertDoesNotMatchRegularExpression( '/\b(private|no-store|no-cache|s-maxage)\b/i', $cache_control, 'Shared caches should keep a poll, and for no longer than its max-age.' );

		$max_age = (int) $max_age_match[1];

		$this->assertGreaterThan( 0, $max_age, 'Readers polling at the same moment should share a cached response.' );
		$this->assertLessThanOrEqual( $default_poll_interval, 2 * $max_age, 'A poll served twice over by stacked caches should still expire before the next one is due.' );
	}

	/**
	 * "Load more" sets no lifetime of its own and is cached like the page it
	 * extends: a page of entries costs more to render than an idle poll.
	 */
	public function test_load_more_is_cached_like_the_page_it_extends() {
		$this->create_entry_at( '2026-01-01 10:00:00' );

		$headers = $this->get_feed( [ 'before' => '2026-01-01 12:00:00' ] )->get_headers();

		$this->assertArrayNotHasKey( 'Cache-Control', $headers );
	}

	/**
	 * Set the site's minimum poll interval for the rest of the test.
	 *
	 * @param mixed $value Value to answer the filter with.
	 */
	private static function set_minimum_poll_interval( $value ) {
		add_filter(
			'newspack_rolling_coverage_min_poll_interval',
			function () use ( $value ) {
				return $value;
			}
		);
	}

	/**
	 * Render the block for the test coverage, as a story page would.
	 *
	 * @return string
	 */
	private function render_block() {
		$attributes = [ 'coverageId' => $this->coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * With no minimum set, a poll tells open pages to keep polling at the
	 * block's own interval.
	 */
	public function test_poll_reports_no_minimum_interval_by_default() {
		$poll = $this->get_feed( [ 'cursor' => '0:2026-01-01 00:00:00' ] )->get_data();

		$this->assertSame( 0, $poll['minPollInterval'] );
	}

	/**
	 * Every poll carries the minimum interval, so pages that are already
	 * open slow down without a reload, whatever the poll has to report.
	 *
	 * @dataProvider new_entry_count_provider
	 *
	 * @param int $new_entry_count Entries published after the cursor.
	 */
	public function test_minimum_poll_interval_reaches_open_pages_through_the_poll( $new_entry_count ) {
		self::set_minimum_poll_interval( 60 );
		$cursor_entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );

		for ( $i = 0; $i < $new_entry_count; $i++ ) {
			$this->create_entry_at( '2026-01-01 12:05:00' );
		}

		$poll = $this->get_feed( [ 'cursor' => "{$cursor_entry_id}:2026-01-01 12:00:00" ] )->get_data();

		$this->assertSame( 60, $poll['minPollInterval'] );
	}

	/**
	 * A slower poll is also shared for longer: half the minimum interval,
	 * the same share of the interval as the default lifetime.
	 */
	public function test_minimum_poll_interval_lengthens_the_poll_cache_to_half_of_it() {
		self::set_minimum_poll_interval( 60 );

		$headers = $this->get_feed( [ 'cursor' => '0:2026-01-01 00:00:00' ] )->get_headers();

		$this->assertSame( 'public, max-age=30', $headers['Cache-Control'] );
	}

	/**
	 * A minimum too low to matter leaves the poll's default lifetime alone
	 * rather than shortening it.
	 */
	public function test_low_minimum_poll_interval_does_not_shorten_the_poll_cache() {
		self::set_minimum_poll_interval( Rolling_Coverage_Block::POLL_MAX_AGE );

		$headers = $this->get_feed( [ 'cursor' => '0:2026-01-01 00:00:00' ] )->get_headers();

		$this->assertSame( 'public, max-age=' . Rolling_Coverage_Block::POLL_MAX_AGE, $headers['Cache-Control'] );
	}

	/**
	 * Values a site might set the minimum to, with the whole seconds each
	 * should count as.
	 *
	 * @return array[]
	 */
	public function minimum_poll_interval_value_provider() {
		return [
			'a number of seconds' => [ 60, 60 ],
			'a numeric string'    => [ '60', 60 ],
			'a fraction'          => [ 45.7, 45 ],
			'zero'                => [ 0, 0 ],
			'a negative number'   => [ -30, 0 ],
			'a word'              => [ 'slow', 0 ],
			'true'                => [ true, 0 ],
			'null'                => [ null, 0 ],
		];
	}

	/**
	 * Only a positive number of seconds sets a minimum. Anything else is
	 * ignored, so a mistyped value can't stop or speed up polling.
	 *
	 * @dataProvider minimum_poll_interval_value_provider
	 *
	 * @param mixed $value    Value the minimum is set to.
	 * @param int   $expected Seconds it should count as.
	 */
	public function test_only_a_positive_number_sets_a_minimum_poll_interval( $value, $expected ) {
		self::set_minimum_poll_interval( $value );

		$this->assertSame( $expected, Rolling_Coverage_Block::get_min_poll_interval() );
	}

	/**
	 * The page carries the minimum next to the block's own interval, so a
	 * fresh page starts slow even when its first poll fails, and can go back
	 * to its own interval once the minimum is lifted.
	 */
	public function test_page_carries_the_minimum_poll_interval() {
		self::set_minimum_poll_interval( 60 );
		$this->create_entry_at( '2026-01-01 12:00:00' );

		$html = $this->render_block();

		$this->assertStringContainsString( 'data-min-poll-interval="60"', $html );
		$this->assertStringContainsString( 'data-poll-interval="10"', $html, 'The block should keep its own interval.' );
	}

	/**
	 * With no minimum set, the page says nothing about one.
	 */
	public function test_page_carries_no_minimum_poll_interval_by_default() {
		$this->create_entry_at( '2026-01-01 12:00:00' );

		$this->assertStringNotContainsString( 'data-min-poll-interval', $this->render_block() );
	}

	/**
	 * The minimum is about polling: "load more" keeps the page cache's
	 * lifetime either way.
	 */
	public function test_minimum_poll_interval_leaves_load_more_alone() {
		self::set_minimum_poll_interval( 60 );
		$this->create_entry_at( '2026-01-01 10:00:00' );

		$response = $this->get_feed( [ 'before' => '2026-01-01 12:00:00' ] );

		$this->assertArrayNotHasKey( 'Cache-Control', $response->get_headers() );
		$this->assertArrayNotHasKey( 'minPollInterval', $response->get_data() );
	}

	/**
	 * Every poll carries the coverage's status and its newest entry's date,
	 * so a status block on the page can follow along.
	 */
	public function test_poll_reports_the_status_and_newest_entry() {
		$entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );

		$poll = $this->get_feed( [ 'cursor' => '0:2026-01-01 00:00:00' ] )->get_data();

		$this->assertSame( 'active', $poll['status'] );
		$this->assertSame( '2026-01-01T12:00:00+00:00', $poll['newestEntry'] );

		update_term_meta( $this->coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$modified = get_post( $entry_id )->post_modified_gmt;
		update_term_meta( $this->coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, $modified );

		$idle = $this->get_feed( [ 'cursor' => $entry_id . ':' . $modified ] )->get_data();

		$this->assertSame( [], $idle['entries'], 'Nothing changed since the cursor.' );
		$this->assertSame( 'archived', $idle['status'], 'An idle poll still reports a status change.' );
		$this->assertSame( '2026-01-01T12:00:00+00:00', $idle['newestEntry'] );
	}

	/**
	 * A status the plugin doesn't know reads as live, and a coverage without
	 * entries has no newest entry.
	 */
	public function test_poll_reports_unknown_status_as_live_and_no_entries_as_null() {
		update_term_meta( $this->coverage_id, Taxonomy::STATUS_META_KEY, 'unknown' );

		$poll = $this->get_feed( [ 'cursor' => '0:2026-01-01 00:00:00' ] )->get_data();

		$this->assertSame( 'active', $poll['status'] );
		$this->assertNull( $poll['newestEntry'] );
	}

	/**
	 * An overflowing poll carries them too; "load more" does not.
	 */
	public function test_overflow_carries_the_status_and_load_more_does_not() {
		for ( $i = 0; $i <= Rolling_Coverage_Block::POLL_CAP; $i++ ) {
			$this->create_entry_at( '2026-01-01 12:00:00' );
		}

		$overflow = $this->get_feed( [ 'cursor' => '0:2026-01-01 00:00:00' ] )->get_data();
		$page     = $this->get_feed( [ 'before' => '2026-01-02 00:00:00' ] )->get_data();

		$this->assertTrue( $overflow['overflow'] );
		$this->assertSame( 'active', $overflow['status'] );
		$this->assertArrayNotHasKey( 'status', $page );
		$this->assertArrayNotHasKey( 'newestEntry', $page );
	}

	/**
	 * Polls for a feed rendered with these attributes, from the start of the
	 * coverage.
	 *
	 * @param array  $attributes Block attributes besides the coverage.
	 * @param string $items      The layout items' markup, or none for the default layout.
	 * @return array Poll response data.
	 */
	private function poll_with_attributes( array $attributes, string $items = '' ) {
		$attributes = array_merge( [ 'coverageId' => $this->coverage_id ], $attributes );
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ( '' === $items ? ' /-->' : ' -->' . $items . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' ) )[0];
		$html       = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		preg_match( '/data-template-key="([^"]+)"/', $html, $matches );

		return $this->get_feed(
			[
				'cursor'       => '0:2026-01-01 00:00:00',
				'template_key' => $matches[1],
			]
		)->get_data();
	}

	/**
	 * Polled entries carry only the entry template, never the blocks the
	 * coverage renders once around them.
	 */
	public function test_polled_entries_hold_no_coverage_level_blocks() {
		$this->create_entry_at( '2026-01-01 12:00:00' );

		$header = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Coverage header</p><!-- /wp:paragraph -->'
			. '<!-- wp:newspack-rolling-coverage/coverage-follow --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"tagName":"button","metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"followTag"}}}}} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Follow</button></div><!-- /wp:button --></div><!-- /wp:buttons --><!-- /wp:newspack-rolling-coverage/coverage-follow -->'
			. '</div><!-- /wp:group -->';
		$entries = $this->poll_with_attributes( [], $header . '<!-- wp:paragraph --><p>Entry text</p><!-- /wp:paragraph -->' )['entries'];

		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( 'Entry text', $entries[0]['html'] );
		$this->assertStringNotContainsString( 'Coverage header', $entries[0]['html'] );
		$this->assertStringNotContainsString( 'Follow', $entries[0]['html'] );
	}

	/**
	 * A capped feed's polls bring a pinned entry in as any other, with no
	 * ad; uncapped, the same poll brings the pinned card and an ad.
	 */
	public function test_capped_feed_polls_entries_unpinned_and_without_ads() {
		self::enable_ad_placement();

		$entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );
		Post_Type::pin_entry( $entry_id );

		$attributes = [
			'enableAds'   => true,
			'adsInterval' => 1,
			'latestCount' => 3,
		];
		$uncapped   = $this->poll_with_attributes( $attributes )['entries'];
		$capped     = $this->poll_with_attributes( array_merge( $attributes, [ 'latestOnly' => true ] ) )['entries'];

		$this->assertSame( [ $entry_id ], wp_list_pluck( $uncapped, 'id' ) );
		$this->assertStringContainsString( 'data-pinned', $uncapped[0]['html'] );
		$this->assertStringContainsString( 'newspack-rolling-coverage-pinned-card', $uncapped[0]['html'] );
		$this->assertStringContainsString( 'test-ad-code', (string) $uncapped[0]['adHtml'] );

		$this->assertSame( [ $entry_id ], wp_list_pluck( $capped, 'id' ) );
		$this->assertStringNotContainsString( 'data-pinned', $capped[0]['html'] );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-card', $capped[0]['html'] );
		$this->assertStringNotContainsString( 'Pinned', $capped[0]['html'] );
		$this->assertNull( $capped[0]['adHtml'] );
	}

	/**
	 * Polled entries of a capped feed carry no anchor id, like its first
	 * render, so links to an entry never land on the capped feed.
	 */
	public function test_capped_feed_polls_entries_without_an_anchor_id() {
		$entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );

		$uncapped = $this->poll_with_attributes( [ 'latestCount' => 3 ] )['entries'];
		$capped   = $this->poll_with_attributes(
			[
				'latestOnly'  => true,
				'latestCount' => 3,
			]
		)['entries'];

		$this->assertStringContainsString( 'id="newspack-rolling-coverage-entry-' . $entry_id . '"', $uncapped[0]['html'] );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-entry-' . $entry_id . '"', $capped[0]['html'] );
	}

	/**
	 * A capped feed can't load an entry to take a removed one's place, so a
	 * removal brings its newest entries whole, for the page to swap in for
	 * its own, with the cursor past the removal. Polls without a removal
	 * send changes as usual.
	 */
	public function test_capped_poll_after_a_removal_sends_the_newest_entries_whole() {
		$oldest_entry_id = $this->create_entry_at( '2026-01-01 11:00:00' );
		$older_entry_id  = $this->create_entry_at( '2026-01-01 11:30:00' );
		$newest_entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );

		$capped = [
			'template_key' => 'pruned',
			'latest'       => 2,
		];

		$this->assertArrayNotHasKey( 'replace', $this->get_feed( array_merge( $capped, [ 'cursor' => '0:2026-01-01 00:00:00' ] ) )->get_data() );

		wp_trash_post( $newest_entry_id );

		$poll = $this->get_feed( array_merge( $capped, [ 'cursor' => "{$newest_entry_id}:2026-01-01 12:00:00" ] ) )->get_data();

		$this->assertTrue( $poll['replace'] );
		$this->assertSame( [ $older_entry_id, $oldest_entry_id ], wp_list_pluck( $poll['entries'], 'id' ) );
		$this->assertSame( $newest_entry_id . ':' . get_post( $newest_entry_id )->post_modified_gmt, $poll['cursor'] );
	}

	/**
	 * A capped feed never reloads its host page on a burst: the poll sends
	 * its newest entries by date whole, for the page to swap in for its own,
	 * and moves the cursor to the most recent change.
	 */
	public function test_capped_poll_over_the_cap_sends_the_newest_entries_instead_of_overflowing() {
		$entry_ids = [];

		for ( $i = 0; $i <= Rolling_Coverage_Block::POLL_CAP; $i++ ) {
			$entry_ids[] = $this->create_entry_at( gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 12:00:00' ) + $i * 60 ) );
		}

		wp_update_post(
			[
				'ID'           => $entry_ids[0],
				'post_content' => 'Corrected.',
			]
		);
		$edited = get_post( $entry_ids[0] );

		$poll = [
			'cursor'       => '0:2026-01-01 00:00:00',
			'template_key' => 'pruned',
		];

		$this->assertTrue( $this->get_feed( $poll )->get_data()['overflow'], 'Uncapped, the burst should overflow.' );

		$capped = $this->get_feed( array_merge( $poll, [ 'latest' => 3 ] ) )->get_data();

		$this->assertFalse( $capped['overflow'] );
		$this->assertTrue( $capped['replace'] );
		$this->assertSame( array_slice( array_reverse( $entry_ids ), 0, 3 ), wp_list_pluck( $capped['entries'], 'id' ) );
		$this->assertSame( [ 'insert', 'insert', 'insert' ], wp_list_pluck( $capped['entries'], 'type' ) );
		$this->assertSame( $edited->ID . ':' . $edited->post_modified_gmt, $capped['cursor'] );
	}

	/**
	 * A page whose stored config is gone, pruned after newer layouts, still
	 * polls capped when it sends how many entries it shows: no pinned card,
	 * no ad. Without the count, the same poll falls back to the defaults.
	 */
	public function test_capped_poll_without_a_stored_config_stays_capped() {
		self::enable_ad_placement();

		$entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );
		Post_Type::pin_entry( $entry_id );

		$poll     = [
			'cursor'       => '0:2026-01-01 00:00:00',
			'template_key' => 'pruned',
			'polled_count' => 3,
		];
		$uncapped = $this->get_feed( $poll )->get_data()['entries'];
		$capped   = $this->get_feed( array_merge( $poll, [ 'latest' => 3 ] ) )->get_data()['entries'];

		$this->assertStringContainsString( 'data-pinned', $uncapped[0]['html'] );
		$this->assertStringContainsString( 'newspack-rolling-coverage-pinned-card', $uncapped[0]['html'] );
		$this->assertStringContainsString( 'test-ad-code', (string) $uncapped[0]['adHtml'] );

		$this->assertSame( [ $entry_id ], wp_list_pluck( $capped, 'id' ) );
		$this->assertStringNotContainsString( 'data-pinned', $capped[0]['html'] );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-card', $capped[0]['html'] );
		$this->assertNull( $capped[0]['adHtml'] );
	}

	/**
	 * Load more without a stored config brings nothing further when the
	 * request says the feed is capped.
	 */
	public function test_capped_load_more_without_a_stored_config_returns_nothing() {
		$this->create_entry_at( '2026-01-01 11:00:00' );

		$page = [
			'before'       => '2026-01-01 12:00:00',
			'template_key' => 'pruned',
		];

		$this->assertSame( 1, $this->get_feed( $page )->get_data()['count'] );

		$capped = $this->get_feed( array_merge( $page, [ 'latest' => 3 ] ) )->get_data();

		$this->assertSame( '', $capped['html'] );
		$this->assertSame( 0, $capped['count'] );
		$this->assertFalse( $capped['hasMore'] );
	}

	/**
	 * A count that is not a positive number is refused.
	 */
	public function test_entries_refuse_a_count_below_one() {
		$this->create_entry_at( '2026-01-01 11:00:00' );

		$response = $this->get_feed(
			[
				'before'       => '2026-01-01 12:00:00',
				'template_key' => 'pruned',
				'latest'       => 0,
			]
		);

		$this->assertSame( 400, $response->get_status() );
	}
}
