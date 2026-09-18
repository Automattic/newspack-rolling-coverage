<?php
/**
 * Tests for the public entries route behind the Rolling Coverage block.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * This route is open to anonymous readers. It polls for new and edited
 * entries from a cursor, and pages backwards for "load more". These tests
 * cover what it may serve and how the cursor moves.
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
	 * this time too. A draft's is the zero date: WordPress leaves the GMT date
	 * unset until an entry is published.
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

		// Created last so the coverage's last-modified is a real date. The draft
		// would leave the zero date, which the poll reads as "nothing changed".
		$published_entry_id = $this->create_entry_at( '2026-01-01 12:00:00' );

		$poll = $this->get_feed( [ 'cursor' => '0:2026-01-01 00:00:00' ] )->get_data();
		$page = $this->get_feed( [ 'before' => '2026-01-02 00:00:00' ] )->get_data();

		$this->assertSame( [ $published_entry_id ], wp_list_pluck( $poll['entries'], 'id' ), 'Polling should only return the published entry.' );
		$this->assertSame( 1, $page['count'], 'Paging should only return the published entry.' );
		$this->assertStringContainsString( 'data-entry-id="' . $published_entry_id . '"', $page['html'], 'The page should hold the published entry.' );
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
}
