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
		$coverage_id = self::create_coverage();
		$page_id     = $this->create_page( $coverage_id, '2026-09-01 10:00:00' );
		$entry_id    = $this->create_dated_entry( $coverage_id, '2026-09-01 11:00:00', 'draft' );
		$publish_at  = gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS );

		wp_update_post(
			[
				'ID'            => $entry_id,
				'post_status'   => 'future',
				'post_date'     => get_date_from_gmt( $publish_at ),
				'post_date_gmt' => $publish_at,
			]
		);

		$this->assertSame( 'future', get_post_status( $entry_id ) );
		$this->assertGreaterThan( get_post( $entry_id )->post_modified_gmt, $publish_at, 'Scheduling stamps the edit time, before the publish time.' );
		$this->assertSame( '2026-09-01 10:00:00', get_post( $page_id )->post_modified_gmt, 'Scheduling is not a change readers see.' );

		wp_publish_post( $entry_id );

		$this->assertSame( $publish_at, get_post( $page_id )->post_modified_gmt );
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
