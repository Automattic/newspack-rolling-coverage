<?php
/**
 * Tests for the coverage taxonomy: term meta, lifecycle routes and what the
 * REST API exposes about a coverage.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Covers the coverage lifecycle (trash, restore, delete), the archive end-time
 * snapshot, and the boundaries on coverage meta: off-site canonical URLs are
 * refused and chat-source linkage stays out of public responses.
 */
class Test_Taxonomy extends Rolling_Coverage_TestCase {

	/**
	 * Fetch a coverage through the core terms route.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $context     REST context.
	 * @return array Response data.
	 */
	private static function get_coverage_via_rest( $coverage_id, $context ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Taxonomy::REST_BASE . '/' . $coverage_id );
		$request->set_param( 'context', $context );
		return rest_get_server()->dispatch( $request )->get_data();
	}

	/**
	 * Canonical URLs feed push-notification links, so only URLs on this site
	 * are stored.
	 */
	public function test_canonical_url_must_be_on_this_site() {
		$on_site_url = home_url( '/live/election-night/' );

		$this->assertSame( $on_site_url, Taxonomy::sanitize_canonical_url( $on_site_url ), 'A URL on this site should be kept.' );
		$this->assertSame( '', Taxonomy::sanitize_canonical_url( 'https://elsewhere.example.test/live/' ), 'A URL on another host should be refused.' );
		$this->assertSame( '', Taxonomy::sanitize_canonical_url( 'javascript:alert(1)' ), 'A non-http URL should be refused.' );
	}

	/**
	 * The host comparison ignores case, so a differently-cased copy of the
	 * site's own URL is still accepted.
	 */
	public function test_canonical_url_host_comparison_ignores_case() {
		$site_host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$uppercase_url = 'http://' . strtoupper( $site_host ) . '/live/';

		$this->assertSame( $uppercase_url, Taxonomy::sanitize_canonical_url( $uppercase_url ) );
	}

	/**
	 * The sanitizer is wired to the meta key, so the rule holds for every
	 * writer, not only for callers that remember to sanitize.
	 */
	public function test_off_site_canonical_url_is_not_stored() {
		$coverage_id = self::create_coverage();

		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, 'https://elsewhere.example.test/live/' );

		$this->assertSame( '', get_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, true ) );
	}

	/**
	 * Archiving a coverage snapshots its last activity as the end time, and
	 * re-archiving after more activity refreshes the snapshot.
	 */
	public function test_archiving_snapshots_the_last_activity_as_the_end_time() {
		$coverage_id = self::create_coverage();
		update_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, '2026-01-01 20:00:00' );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$this->assertSame( '2026-01-01 20:00:00', get_term_meta( $coverage_id, Taxonomy::END_TIME_META_KEY, true ), 'The first archive should snapshot the last activity.' );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ACTIVE );
		update_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, '2026-01-02 09:30:00' );
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$this->assertSame( '2026-01-02 09:30:00', get_term_meta( $coverage_id, Taxonomy::END_TIME_META_KEY, true ), 'Re-archiving should refresh the snapshot.' );
	}

	/**
	 * Pausing a coverage is not an ending, so no end time is recorded.
	 */
	public function test_pausing_does_not_record_an_end_time() {
		$coverage_id = self::create_coverage();
		update_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, '2026-01-01 20:00:00' );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_PAUSED );

		$this->assertSame( '', get_term_meta( $coverage_id, Taxonomy::END_TIME_META_KEY, true ) );
	}

	/**
	 * The entry count on a coverage includes unpublished entries, because the
	 * admin list shows them, but leaves out trashed ones.
	 */
	public function test_entry_count_includes_drafts_and_excludes_trash() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		self::create_entry( $coverage_id, [ 'post_status' => 'draft' ] );
		wp_trash_post( self::create_entry( $coverage_id ) );

		$this->assertSame( 2, get_term( $coverage_id )->count );
	}

	/**
	 * Trashing a coverage only flips its status. Its entries are left alone
	 * so the coverage can be restored as it was.
	 */
	public function test_trashing_a_coverage_leaves_its_entries_untouched() {
		self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );

		$response = self::dispatch( 'POST', "/coverages/{$coverage_id}/trash" );

		$this->assertSame( 200, $response->get_status(), 'The request should succeed.' );
		$this->assertSame( 'trash', get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true ), 'The coverage should be marked as trashed.' );
		$this->assertSame( 'publish', get_post_status( $entry_id ), 'The entry should keep its status.' );
	}

	/**
	 * Restore brings a trashed coverage back as active.
	 */
	public function test_restoring_a_trashed_coverage_makes_it_active() {
		self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage( 'trash' );

		$response = self::dispatch( 'POST', "/coverages/{$coverage_id}/restore" );

		$this->assertSame( 200, $response->get_status(), 'The request should succeed.' );
		$this->assertSame( Taxonomy::STATUS_ACTIVE, get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true ), 'The coverage should be active again.' );
	}

	/**
	 * Restore is not a back door out of Archive Mode: it only acts on
	 * coverages that are in the trash.
	 */
	public function test_restore_does_not_reopen_an_archived_coverage() {
		self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );

		$response = self::dispatch( 'POST', "/coverages/{$coverage_id}/restore" );

		$this->assertSame( 400, $response->get_status(), 'The request should be refused.' );
		$this->assertSame( Taxonomy::STATUS_ARCHIVED, get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true ), 'The coverage should stay archived.' );
	}

	/**
	 * Deleting a coverage removes the term right away and leaves its entries
	 * to a scheduled cleanup, so the request stays fast on large coverages.
	 */
	public function test_deleting_a_coverage_schedules_the_entry_cleanup() {
		self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );

		$response = self::dispatch( 'DELETE', "/coverages/{$coverage_id}" );

		$this->assertSame( 200, $response->get_status(), 'The request should succeed.' );
		$this->assertNull( get_term( $coverage_id ), 'The coverage should be gone.' );
		$this->assertNotNull( get_post( $entry_id ), 'The entry should still exist until the cleanup runs.' );
		$this->assertNotFalse( wp_next_scheduled( Post_Type::CLEANUP_CRON_HOOK ), 'The cleanup should be scheduled.' );
	}

	/**
	 * Lifecycle routes answer 404 for a term that is not a coverage.
	 */
	public function test_lifecycle_routes_only_act_on_coverages() {
		self::log_in_as( 'editor' );
		$category_id = self::factory()->category->create();

		$response = self::dispatch( 'DELETE', "/coverages/{$category_id}" );

		$this->assertSame( 404, $response->get_status(), 'The request should not find a coverage.' );
		$this->assertNotNull( get_term( $category_id ), 'The category should be untouched.' );
	}

	/**
	 * Authors write entries but do not manage coverages.
	 */
	public function test_authors_cannot_trash_a_coverage() {
		self::log_in_as( 'author' );
		$coverage_id = self::create_coverage();

		$response = self::dispatch( 'POST', "/coverages/{$coverage_id}/trash" );

		$this->assertSame( 403, $response->get_status(), 'The request should be refused.' );
		$this->assertSame( Taxonomy::STATUS_ACTIVE, get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true ), 'The coverage should stay active.' );
	}

	/**
	 * The terms route is public, so the chat-source linkage is removed from
	 * everything except edit-context responses.
	 */
	public function test_chat_source_linkage_is_only_exposed_in_edit_context() {
		self::log_in_as( 'administrator' );
		$coverage_id = self::create_coverage();
		update_term_meta( $coverage_id, Taxonomy::META_SLACK_CHANNEL_ID, 'C0TESTCHAN' );
		update_term_meta( $coverage_id, Taxonomy::META_SOURCE_REF, 'C0TESTCHAN' );

		$public_meta = self::get_coverage_via_rest( $coverage_id, 'view' )['meta'];
		$edit_meta   = self::get_coverage_via_rest( $coverage_id, 'edit' )['meta'];

		foreach ( Taxonomy::RESTRICTED_META as $restricted_key ) {
			$this->assertArrayNotHasKey( $restricted_key, $public_meta, "{$restricted_key} should be hidden from the public response." );
		}
		$this->assertArrayHasKey( Taxonomy::STATUS_META_KEY, $public_meta, 'Non-sensitive meta should stay public.' );
		$this->assertSame( 'C0TESTCHAN', $edit_meta[ Taxonomy::META_SLACK_CHANNEL_ID ], 'The edit context should include the linkage.' );
	}
}
