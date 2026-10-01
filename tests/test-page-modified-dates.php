<?php
/**
 * Tests for dating the pages that show a coverage.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Schema;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * A page showing a coverage changes whenever readers can see an entry change,
 * so its own modified date has to follow, or the byline, the SEO plugin and
 * the sitemap report it as unchanged.
 */
class Test_Page_Modified_Dates extends Rolling_Coverage_TestCase {

	/**
	 * Publishing an entry moves every page showing its coverage up to the
	 * entry's date. Pages that don't show the coverage, or that changed later,
	 * keep their date, and the coverage-to-page map isn't rebuilt.
	 */
	public function test_publishing_an_entry_dates_the_pages_showing_its_coverage() {
		$coverage_id  = self::create_coverage();
		$older_id     = $this->create_page( $coverage_id, '2026-09-01 10:00:00' );
		$newer_id     = $this->create_page( $coverage_id, '2026-09-02 10:00:00' );
		$later_id     = $this->create_page( $coverage_id, '2026-09-20 10:00:00' );
		$unrelated_id = $this->create_page( self::create_coverage(), '2026-09-01 10:00:00' );

		Taxonomy::get_coverage_page_url( $coverage_id );
		$map_last_changed = wp_cache_get_last_changed( Taxonomy::PAGE_IDS_CACHE_GROUP );

		$this->create_dated_entry( $coverage_id, '2026-09-10 10:00:00' );

		$this->assertSame( '2026-09-10 10:00:00', get_post( $older_id )->post_modified_gmt );
		$this->assertSame( '2026-09-10 10:00:00', get_post( $newer_id )->post_modified_gmt );
		$this->assertSame( '2026-09-10 10:00:00', get_post( $newer_id )->post_modified, 'The local date should move too.' );
		$this->assertSame( '2026-09-20 10:00:00', get_post( $later_id )->post_modified_gmt, 'A page is never moved back in time.' );
		$this->assertSame( '2026-09-01 10:00:00', get_post( $unrelated_id )->post_modified_gmt );
		$this->assertSame( $map_last_changed, wp_cache_get_last_changed( Taxonomy::PAGE_IDS_CACHE_GROUP ), 'Only the date changed, so the map should stay cached.' );
	}

	/**
	 * Taking a published entry down is a change readers see too.
	 */
	public function test_trashing_a_published_entry_dates_the_page() {
		$coverage_id = self::create_coverage();
		$page_id     = $this->create_page( $coverage_id, '2026-09-01 10:00:00' );
		$entry_id    = $this->create_dated_entry( $coverage_id, '2026-09-10 10:00:00' );

		wp_trash_post( $entry_id );

		$trashed_gmt = get_post( $entry_id )->post_modified_gmt;
		$this->assertGreaterThan( '2026-09-10 10:00:00', $trashed_gmt );
		$this->assertSame( $trashed_gmt, get_post( $page_id )->post_modified_gmt );
	}

	/**
	 * An entry scheduled through the editor keeps the modified date of its last
	 * edit, so when it goes live the page is dated to its publish time, which is
	 * when readers first saw it.
	 */
	public function test_a_scheduled_entry_going_live_dates_the_page_to_its_publish_time() {
		global $wpdb;

		$coverage_id = self::create_coverage();
		$page_id     = $this->create_page( $coverage_id, '2026-09-01 10:00:00' );
		$entry_id    = $this->create_dated_entry( $coverage_id, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), 'future' );

		$this->assertSame( 'future', get_post_status( $entry_id ) );
		$this->assertSame( '2026-09-01 10:00:00', get_post( $page_id )->post_modified_gmt, 'Scheduling is not a change readers see.' );

		// The scheduled time has come: last edited at 09:00, set to go live at 10:00.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->posts,
			[
				'post_modified'     => '2026-09-02 09:00:00',
				'post_modified_gmt' => '2026-09-02 09:00:00',
				'post_date'         => '2026-09-02 10:00:00',
				'post_date_gmt'     => '2026-09-02 10:00:00',
			],
			[ 'ID' => $entry_id ]
		);
		clean_post_cache( $entry_id );

		wp_publish_post( $entry_id );

		$this->assertSame( '2026-09-02 10:00:00', get_post( $page_id )->post_modified_gmt );
	}

	/**
	 * Moving a live entry's date ahead still dates the page, but never ahead
	 * of now: the page's date reaches the site's feeds, and a feed reader that
	 * saw a future one would be told nothing changed until that time passed.
	 *
	 * @dataProvider data_seconds_a_live_entry_is_moved_ahead
	 *
	 * @param int    $seconds_ahead   How far ahead of now the entry is re-dated.
	 * @param string $expected_status The status core leaves the entry in.
	 */
	public function test_moving_a_live_entry_ahead_never_dates_the_page_into_the_future( int $seconds_ahead, string $expected_status ) {
		$coverage_id = self::create_coverage();
		$page_id     = $this->create_page( $coverage_id, '2026-09-01 10:00:00' );
		$entry_id    = $this->create_dated_entry( $coverage_id, '2026-09-10 10:00:00' );
		$new_date    = gmdate( 'Y-m-d H:i:s', time() + $seconds_ahead );

		wp_update_post(
			[
				'ID'            => $entry_id,
				'post_date'     => get_date_from_gmt( $new_date ),
				'post_date_gmt' => $new_date,
			]
		);

		$this->assertSame( $expected_status, get_post_status( $entry_id ) );

		$page_date = get_post( $page_id )->post_modified_gmt;

		$this->assertGreaterThan( '2026-09-10 10:00:00', $page_date, 'The edit should still date the page.' );
		$this->assertLessThanOrEqual( gmdate( 'Y-m-d H:i:s' ), $page_date );
	}

	/**
	 * Core turns a published post dated a minute or more ahead into a
	 * scheduled one, and leaves it published below that.
	 *
	 * @return array[]
	 */
	public function data_seconds_a_live_entry_is_moved_ahead(): array {
		return [
			'far enough to become scheduled' => [ 10 * MINUTE_IN_SECONDS, 'future' ],
			'close enough to stay published' => [ 30, 'publish' ],
		];
	}

	/**
	 * Saving an entry readers can't see leaves the page alone.
	 */
	public function test_draft_entry_saves_leave_the_page_alone() {
		$coverage_id = self::create_coverage();
		$page_id     = $this->create_page( $coverage_id, '2026-09-01 10:00:00' );
		$draft_id    = $this->create_dated_entry( $coverage_id, '2026-09-10 10:00:00', 'draft' );

		wp_update_post(
			[
				'ID'           => $draft_id,
				'post_content' => 'Revised draft',
			]
		);

		$this->assertSame( '2026-09-01 10:00:00', get_post( $page_id )->post_modified_gmt );
	}

	/**
	 * Dating the page doesn't re-save it, so an entry published by someone
	 * who can't post unfiltered HTML leaves the page's content intact.
	 */
	public function test_an_author_publishing_an_entry_leaves_the_page_content_intact() {
		self::log_in_as( 'administrator' );
		$coverage_id = self::create_coverage();
		$page_id     = $this->create_page( $coverage_id, '2026-09-01 10:00:00', '<iframe src="https://example.test/embed"></iframe>' );
		$content     = get_post( $page_id )->post_content;

		self::log_in_as( 'author' );
		$this->create_dated_entry( $coverage_id, '2026-09-10 10:00:00' );

		$this->assertSame( $content, get_post( $page_id )->post_content );
		$this->assertSame( '2026-09-10 10:00:00', get_post( $page_id )->post_modified_gmt );
	}

	/**
	 * Create a published page embedding a coverage.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $date        Publish date, which is also its modified date.
	 * @param string $extra       Markup placed after the block.
	 * @return int Page ID.
	 */
	private function create_page( int $coverage_id, string $date, string $extra = '' ): int {
		return self::factory()->post->create(
			[
				'post_type'     => 'page',
				'post_content'  => sprintf( '<!-- wp:%s {"coverageId":%d} /-->', Schema::BLOCK_NAME, $coverage_id ) . $extra,
				'post_date'     => $date,
				'post_date_gmt' => $date,
			]
		);
	}
}
