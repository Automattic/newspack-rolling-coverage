<?php
/**
 * Tests for the entries-view route that feeds the admin entries list.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;

/**
 * The route has two modes. Page mode answers the list's filters, several of
 * which treat "no meta row" as a value. Sync mode answers the list's polling
 * with the entries that changed since a cursor.
 */
class Test_Entries_View extends Rolling_Coverage_TestCase {

	/**
	 * Coverage the entries belong to.
	 *
	 * @var int
	 */
	private $coverage_id;

	/**
	 * Create the coverage and log in as someone who can use the list.
	 */
	public function set_up() {
		parent::set_up();
		$this->coverage_id = self::create_coverage();
		self::log_in_as( 'editor' );
	}

	/**
	 * Request the entries view for the test coverage.
	 *
	 * @param array $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function get_entries_view( array $params = [] ) {
		return self::dispatch( 'GET', "/coverages/{$this->coverage_id}/entries-view", $params );
	}

	/**
	 * IDs of the entries a page-mode request returns, in order.
	 *
	 * @param array $params Query parameters.
	 * @return int[]
	 */
	private function get_listed_entry_ids( array $params = [] ) {
		return wp_list_pluck( $this->get_entries_view( $params )->get_data()['entries'], 'id' );
	}

	/**
	 * Create an entry in the test coverage at a fixed time.
	 *
	 * On insert WordPress copies the date into the modified columns, so a
	 * published entry's `post_modified_gmt`, which cursors are built from, is
	 * this time too. A draft's is a concrete GMT date derived from its local
	 * date by normalize_entry_gmt_dates(), not the zero date.
	 *
	 * @param string $post_date Entry date, `Y-m-d H:i:s`. The test site runs on UTC.
	 * @param array  $args      Post factory arguments.
	 * @return int Entry post ID.
	 */
	private function create_entry_at( $post_date, array $args = [] ) {
		return self::create_entry( $this->coverage_id, array_merge( [ 'post_date' => $post_date ], $args ) );
	}

	/**
	 * The list shows drafts, so it is limited to users who can edit posts.
	 */
	public function test_entries_view_is_closed_to_users_who_cannot_edit_posts() {
		self::log_in_as( 'subscriber' );

		$this->assertSame( 403, $this->get_entries_view()->get_status() );
	}

	/**
	 * An unknown coverage is a 404, not an empty list.
	 */
	public function test_unknown_coverage_is_a_404() {
		$category_id = self::factory()->category->create();

		$response = self::dispatch( 'GET', "/coverages/{$category_id}/entries-view" );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Pinned entries lead the list regardless of their date.
	 */
	public function test_pinned_entries_are_listed_first() {
		$older_pinned_id = $this->create_entry_at( '2026-01-01 10:00:00' );
		$newer_entry_id  = $this->create_entry_at( '2026-01-01 11:00:00' );
		Post_Type::pin_entry( $older_pinned_id );

		$this->assertSame( [ $older_pinned_id, $newer_entry_id ], $this->get_listed_entry_ids() );
	}

	/**
	 * Entries written in the editor before the source meta existed have no
	 * meta row. The source filters count them as editor entries.
	 */
	public function test_source_filters_treat_entries_without_source_meta_as_editor_entries() {
		$legacy_entry_id = $this->create_entry_at( '2026-01-01 10:00:00' );
		$slack_entry_id  = $this->create_entry_at( '2026-01-01 11:00:00' );
		update_post_meta( $slack_entry_id, Post_Type::META_ENTRY_SOURCE, 'slack' );

		$this->assertSame( [ $legacy_entry_id ], $this->get_listed_entry_ids( [ 'source' => 'wordpress' ] ), 'Filtering for editor entries should include the legacy entry.' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Machine value.
		$this->assertSame( [ $legacy_entry_id ], $this->get_listed_entry_ids( [ 'source_exclude' => 'slack' ] ), 'Excluding Slack should keep the legacy entry.' );
		$this->assertSame( [ $slack_entry_id ], $this->get_listed_entry_ids( [ 'source_exclude' => 'wordpress' ] ), 'Excluding editor entries should drop the legacy entry.' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Machine value.
	}

	/**
	 * A filter value that matches nothing returns nothing. It must not fall
	 * back to the unfiltered list.
	 *
	 * @dataProvider unmatched_filter_provider
	 *
	 * @param array $filter_params Query parameters for a filter that matches nothing.
	 */
	public function test_filter_that_matches_nothing_returns_an_empty_list( array $filter_params ) {
		$this->create_entry_at( '2026-01-01 10:00:00' );

		$this->assertSame( [], $this->get_listed_entry_ids( $filter_params ) );
	}

	/**
	 * Filters whose value resolves to no term, no user or no valid date.
	 *
	 * @return array[]
	 */
	public function unmatched_filter_provider() {
		return [
			'category name nobody uses' => [ [ 'category_search' => 'no-such-category' ] ],
			'tag name nobody uses'      => [ [ 'tag_search' => 'no-such-tag' ] ],
			'author name nobody has'    => [ [ 'author' => 'no-such-author' ] ],
			'unparseable date filter'   => [ [ 'date_filter' => 'not json' ] ],
			'unknown date operator'     => [
				[
					'date_filter' => wp_json_encode(
						[
							'operator' => 'sometime',
							'value'    => '2026-01-01',
						]
					),
				],
			],
		];
	}

	/**
	 * The title filter matches the title only, unlike the general search.
	 */
	public function test_title_filter_does_not_match_entry_content() {
		$titled_entry_id = $this->create_entry_at( '2026-01-01 10:00:00', [ 'post_title' => 'Recount ordered' ] );
		$this->create_entry_at(
			'2026-01-01 11:00:00',
			[
				'post_title'   => 'Polls close',
				'post_content' => 'A recount is possible.',
			]
		);

		$this->assertSame( [ $titled_entry_id ], $this->get_listed_entry_ids( [ 'title' => 'recount' ] ) );
	}

	/**
	 * The "on" date filter covers the whole calendar day in the site's
	 * timezone, not the UTC day.
	 */
	public function test_on_date_filter_uses_the_site_timezone_day() {
		update_option( 'gmt_offset', -5 );
		// 03:30 UTC on Jan 2 is 22:30 on Jan 1 at UTC-5.
		$late_evening_entry_id = $this->create_entry_at(
			'2026-01-01 22:30:00',
			[ 'post_date_gmt' => '2026-01-02 03:30:00' ]
		);

		$on_january_first = wp_json_encode(
			[
				'operator' => 'on',
				'value'    => '2026-01-01T12:00:00-05:00',
			]
		);

		$this->assertSame( [ $late_evening_entry_id ], $this->get_listed_entry_ids( [ 'date_filter' => $on_january_first ] ) );
	}

	/**
	 * Cursor values the sync mode accepts and refuses.
	 *
	 * @return array[]
	 */
	public function since_cursor_provider() {
		return [
			'id and timestamp'          => [ '42:2026-08-28 12:00:00', true ],
			'bare timestamp'            => [ '2026-08-28 12:00:00', true ],
			'relative time'             => [ 'yesterday', false ],
			'iso 8601 timestamp'        => [ '2026-08-28T12:00:00Z', false ],
			'month that does not exist' => [ '2026-13-01 00:00:00', false ],
			'day that does not exist'   => [ '42:2026-02-30 00:00:00', false ],
			'hour that does not exist'  => [ '2026-08-28 25:00:00', false ],
			'id with no timestamp'      => [ '42:', false ],
			'empty string'              => [ '', false ],
			'not a string'              => [ 1767225600, false ],
		];
	}

	/**
	 * The cursor goes straight into a date query, so only an exact GMT
	 * timestamp is accepted. PHP would otherwise roll "Feb 30" over to March.
	 *
	 * @dataProvider since_cursor_provider
	 *
	 * @param mixed $since    Cursor value.
	 * @param bool  $is_valid Whether it should be accepted.
	 */
	public function test_since_cursor_must_be_an_exact_gmt_timestamp( $since, $is_valid ) {
		$this->assertSame( $is_valid, Post_Type::validate_since( $since ) );
	}

	/**
	 * Entries published after the cursor are new to the client; entries that
	 * existed before it and changed since are updates. The returned cursor
	 * moves to the most recently modified entry.
	 */
	public function test_sync_separates_new_entries_from_updated_ones() {
		$earlier_entry_id = $this->create_entry_at( '2026-01-01 11:00:00' );
		$cursor_entry_id  = $this->create_entry_at( '2026-01-01 12:00:00' );
		$cursor           = "{$cursor_entry_id}:2026-01-01 12:00:00";

		$later_entry_id = $this->create_entry_at( '2026-01-01 12:05:00' );
		wp_update_post(
			[
				'ID'         => $earlier_entry_id,
				'post_title' => 'Corrected',
			]
		);

		$sync = $this->get_entries_view( [ 'since' => $cursor ] )->get_data();

		$change_types = wp_list_pluck( $sync['changed'], 'change_type', 'id' );

		$this->assertSame(
			[
				$earlier_entry_id => 'update',
				$later_entry_id   => 'new',
			],
			$change_types,
			'The edited entry should be an update and the later one new; the cursor entry should not be reported.'
		);
		$this->assertSame( $earlier_entry_id . ':' . get_post( $earlier_entry_id )->post_modified_gmt, $sync['cursor'], 'The cursor should move to the most recently modified entry.' );
		$this->assertFalse( $sync['overflow'], 'A small delta should not overflow.' );
	}

	/**
	 * A trashed entry is reported with its trash status, which is how the
	 * list learns to remove it.
	 *
	 * The entry is a draft because trashing a published one also goes through
	 * the block's publish-status hook, which would report it even without the
	 * trash hook this covers.
	 */
	public function test_sync_reports_trashed_entries() {
		$cursor_entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );
		$doomed_entry_id = $this->create_entry_at( '2026-01-01 11:00:00', [ 'post_status' => 'draft' ] );
		$cursor          = "{$cursor_entry_id}:2026-01-01 12:00:00";

		wp_trash_post( $doomed_entry_id );

		$changed = $this->get_entries_view( [ 'since' => $cursor ] )->get_data()['changed'];

		$this->assertSame( [ $doomed_entry_id => 'trash' ], wp_list_pluck( $changed, 'status', 'id' ) );
	}

	/**
	 * When nothing changed the client keeps its cursor.
	 */
	public function test_sync_returns_the_same_cursor_when_nothing_changed() {
		$cursor_entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );
		$cursor          = "{$cursor_entry_id}:2026-01-01 12:00:00";

		$sync = $this->get_entries_view( [ 'since' => $cursor ] )->get_data();

		$this->assertSame( [], $sync['changed'], 'Nothing should be reported.' );
		$this->assertSame( $cursor, $sync['cursor'], 'The cursor should not move.' );
	}

	/**
	 * A delta larger than one page is not sent. The client is told to reload
	 * and keeps its cursor, so nothing is skipped.
	 */
	public function test_sync_asks_the_client_to_reload_when_the_delta_exceeds_one_page() {
		$cursor = '0:2026-01-01 00:00:00';

		for ( $i = 0; $i <= Post_Type::PER_PAGE_MAX; $i++ ) {
			$this->create_entry_at( '2026-01-01 12:00:00' );
		}

		$sync = $this->get_entries_view( [ 'since' => $cursor ] )->get_data();

		$this->assertTrue( $sync['overflow'], 'The response should signal an overflow.' );
		$this->assertSame( [], $sync['changed'], 'No partial delta should be sent.' );
		$this->assertSame( $cursor, $sync['cursor'], 'The cursor should not move.' );
	}

	/**
	 * Contributors cannot publish, so they only ever see their own entries.
	 */
	public function test_contributor_only_sees_their_own_entries() {
		self::log_in_as( 'contributor' );
		$other_id = self::factory()->user->create( [ 'role' => 'author' ] );

		$own_draft_id     = $this->create_entry_at( '2026-01-01 10:00:00', [ 'post_status' => 'draft' ] );
		$own_published_id = $this->create_entry_at( '2026-01-01 11:00:00' );
		$this->create_entry_at(
			'2026-01-01 12:00:00',
			[
				'post_status' => 'draft',
				'post_author' => $other_id,
			]
		);
		$this->create_entry_at( '2026-01-01 13:00:00', [ 'post_author' => $other_id ] );

		$listed = $this->get_listed_entry_ids();

		$this->assertEqualsCanonicalizing(
			[ $own_published_id, $own_draft_id ],
			$listed,
			'A contributor should see only their own entries.'
		);
	}

	/**
	 * Authors can publish, so they also see other authors' published entries,
	 * but never another author's drafts.
	 */
	public function test_author_sees_others_published_but_not_their_drafts() {
		self::log_in_as( 'author' );
		$other_id = self::factory()->user->create( [ 'role' => 'author' ] );

		$own_draft_id     = $this->create_entry_at( '2026-01-01 10:00:00', [ 'post_status' => 'draft' ] );
		$own_published_id = $this->create_entry_at( '2026-01-01 11:00:00' );
		$this->create_entry_at(
			'2026-01-01 12:00:00',
			[
				'post_status' => 'draft',
				'post_author' => $other_id,
			]
		);
		$other_published_id = $this->create_entry_at( '2026-01-01 13:00:00', [ 'post_author' => $other_id ] );

		$listed = $this->get_listed_entry_ids();

		$this->assertEqualsCanonicalizing(
			[ $own_draft_id, $own_published_id, $other_published_id ],
			$listed,
			"An author should see their own entries and others' published entries, but not another author's draft."
		);
	}

	/**
	 * Sync mode applies the same author scoping as page mode.
	 */
	public function test_author_scope_applies_in_sync_mode() {
		$since = '0:2026-01-01 00:00:00';

		self::log_in_as( 'contributor' );
		$other_id = self::factory()->user->create( [ 'role' => 'author' ] );

		$contributor_published_id = $this->create_entry_at( '2026-01-01 11:00:00' );
		$contributor_draft_id     = $this->create_entry_at( '2026-01-01 10:00:00', [ 'post_status' => 'draft' ] );
		$other_published_id       = $this->create_entry_at( '2026-01-01 13:00:00', [ 'post_author' => $other_id ] );
		$other_draft_id           = $this->create_entry_at(
			'2026-01-01 12:00:00',
			[
				'post_status' => 'draft',
				'post_author' => $other_id,
			]
		);

		$changed = $this->get_entries_view( [ 'since' => $since ] )->get_data()['changed'];
		$listed  = wp_list_pluck( $changed, 'id' );

		$this->assertContains( $contributor_published_id, $listed, 'A contributor should receive their own published entry in sync mode.' );
		$this->assertContains( $contributor_draft_id, $listed, 'A contributor should receive their own draft in sync mode.' );
		$this->assertNotContains( $other_published_id, $listed, 'A contributor should not receive another user\'s published entry in sync mode.' );
		$this->assertNotContains( $other_draft_id, $listed, 'A contributor should not receive another user\'s draft in sync mode.' );

		self::log_in_as( 'author' );

		$author_published_id = $this->create_entry_at( '2026-01-01 14:00:00' );
		$author_draft_id     = $this->create_entry_at( '2026-01-01 15:00:00', [ 'post_status' => 'draft' ] );

		$changed = $this->get_entries_view( [ 'since' => $since ] )->get_data()['changed'];
		$listed  = wp_list_pluck( $changed, 'id' );

		$this->assertContains( $author_published_id, $listed, 'An author should receive their own published entry in sync mode.' );
		$this->assertContains( $author_draft_id, $listed, 'An author should receive their own draft in sync mode.' );
		$this->assertContains( $other_published_id, $listed, 'An author should receive another author\'s published entry in sync mode.' );
		$this->assertNotContains( $other_draft_id, $listed, 'An author should not receive another author\'s draft in sync mode.' );
	}

	/**
	 * Excluding every requested status is an empty result, not the default
	 * published statuses WP_Query falls back to when post_status is empty.
	 */
	public function test_excluding_every_requested_status_returns_an_empty_list() {
		$this->create_entry_at( '2026-01-01 10:00:00' );

		$this->assertSame(
			[],
			$this->get_listed_entry_ids(
				[
					'status'         => 'draft',
					'status_exclude' => 'draft',
				]
			),
			'Excluding every requested status should yield nothing, not WP_Query\'s default published entries.'
		);
	}

	/**
	 * An author finds their own trashed entry in the entries view, so it can
	 * be restored, but never sees another author's trashed entry.
	 */
	public function test_author_sees_their_own_trashed_entry_but_not_others() {
		self::log_in_as( 'author' );
		$other_id = self::factory()->user->create( [ 'role' => 'author' ] );

		$own_trashed_id   = $this->create_entry_at( '2026-01-01 10:00:00', [ 'post_status' => 'draft' ] );
		$other_trashed_id = $this->create_entry_at(
			'2026-01-01 11:00:00',
			[
				'post_status' => 'draft',
				'post_author' => $other_id,
			]
		);
		wp_trash_post( $own_trashed_id );
		wp_trash_post( $other_trashed_id );

		$listed = $this->get_listed_entry_ids( [ 'status' => 'trash' ] );

		$this->assertContains( $own_trashed_id, $listed, 'An author should see their own trashed entry.' );
		$this->assertNotContains( $other_trashed_id, $listed, 'An author should not see another author\'s trashed entry.' );
	}

	/**
	 * A contributor cannot publish, and trash is scoped to the user's own
	 * entries, so a contributor only sees their own trashed draft.
	 */
	public function test_contributor_sees_only_their_own_trashed_entry() {
		self::log_in_as( 'contributor' );
		$other_id = self::factory()->user->create( [ 'role' => 'author' ] );

		$own_trashed_id   = $this->create_entry_at( '2026-01-01 10:00:00', [ 'post_status' => 'draft' ] );
		$other_trashed_id = $this->create_entry_at(
			'2026-01-01 11:00:00',
			[
				'post_status' => 'draft',
				'post_author' => $other_id,
			]
		);
		wp_trash_post( $own_trashed_id );
		wp_trash_post( $other_trashed_id );

		$listed = $this->get_listed_entry_ids( [ 'status' => 'trash' ] );

		$this->assertContains( $own_trashed_id, $listed, 'A contributor should see their own trashed entry.' );
		$this->assertNotContains( $other_trashed_id, $listed, 'A contributor should not see another user\'s trashed entry.' );
	}
}
