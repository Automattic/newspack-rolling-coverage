<?php
/**
 * Tests for restoring trashed entries and cleaning up orphaned ones.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * An entry only makes sense inside a coverage, so restoring one also has to
 * find it a coverage: the one it still has, the one it remembers, or a
 * recovery coverage created for the purpose. Entries left with none are
 * deleted by the cleanup cron.
 */
class Test_Entry_Restore extends Rolling_Coverage_TestCase {

	/**
	 * Log in as someone who can restore any entry.
	 */
	public function set_up() {
		parent::set_up();
		self::log_in_as( 'editor' );
	}

	/**
	 * Create a trashed entry whose coverage has been permanently deleted.
	 *
	 * @param int $coverage_id Coverage the entry belonged to. Deleted unless it is already gone.
	 * @return int Entry post ID.
	 */
	private static function create_trashed_entry_of_deleted_coverage( $coverage_id ) {
		$entry_id = self::create_entry( $coverage_id );
		wp_trash_post( $entry_id );
		wp_delete_term( $coverage_id, Taxonomy::TAXONOMY_SLUG );
		return $entry_id;
	}

	/**
	 * The coverage an entry is currently assigned to.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return WP_Term|null
	 */
	private static function get_entry_coverage( $entry_id ) {
		$coverages = wp_get_post_terms( $entry_id, Taxonomy::TAXONOMY_SLUG );
		return $coverages ? $coverages[0] : null;
	}

	/**
	 * Restoring an entry brings a trashed coverage back with it, since a
	 * live entry in a trashed coverage would be unreachable.
	 */
	public function test_restoring_an_entry_reactivates_its_trashed_coverage() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id, [ 'post_status' => 'draft' ] );
		wp_trash_post( $entry_id );
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, 'trash' );

		$result = self::dispatch( 'POST', "/entries/{$entry_id}/restore" )->get_data();

		$this->assertSame( 'draft', get_post_status( $entry_id ), 'The entry should return to the status it had before the trash.' );
		$this->assertSame( Taxonomy::STATUS_ACTIVE, get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true ), 'The coverage should be active again.' );
		$this->assertSame( $coverage_id, $result['coverageId'], 'The response should name the coverage.' );
		$this->assertFalse( $result['coverageCreated'], 'No recovery coverage should be created.' );
	}

	/**
	 * When the coverage was deleted, the entry goes into a recovery coverage
	 * named after the original.
	 */
	public function test_entry_of_a_deleted_coverage_is_restored_into_a_recovery_coverage() {
		$coverage_id = self::create_coverage(
			'',
			[
				'name' => 'Election Night',
				'slug' => 'election-night',
			]
		);
		$entry_id    = self::create_trashed_entry_of_deleted_coverage( $coverage_id );

		$result            = self::dispatch( 'POST', "/entries/{$entry_id}/restore" )->get_data();
		$recovery_coverage = self::get_entry_coverage( $entry_id );

		$this->assertTrue( $result['coverageCreated'], 'The response should say a coverage was created.' );
		$this->assertSame( 'Election Night - recovery', $recovery_coverage->name, 'The recovery coverage should be named after the original.' );
		$this->assertSame( 'election-night-recovery', $recovery_coverage->slug, 'The recovery slug should be derived from the original slug.' );
		$this->assertSame( 'publish', get_post_status( $entry_id ), 'The entry should be restored.' );
	}

	/**
	 * Entries of the same deleted coverage end up together, whether they are
	 * restored in one bulk request or one at a time.
	 */
	public function test_entries_of_the_same_deleted_coverage_share_one_recovery_coverage() {
		$coverage_id     = self::create_coverage();
		$first_entry_id  = self::create_entry( $coverage_id );
		$second_entry_id = self::create_entry( $coverage_id );
		$third_entry_id  = self::create_trashed_entry_of_deleted_coverage( $coverage_id );
		wp_trash_post( $first_entry_id );
		wp_trash_post( $second_entry_id );

		self::dispatch( 'POST', '/entries/restore', [ 'entry_ids' => [ $first_entry_id, $second_entry_id ] ] );
		self::dispatch( 'POST', "/entries/{$third_entry_id}/restore" );

		$recovery_coverage_ids = array_unique(
			[
				self::get_entry_coverage( $first_entry_id )->term_id,
				self::get_entry_coverage( $second_entry_id )->term_id,
				self::get_entry_coverage( $third_entry_id )->term_id,
			]
		);

		$this->assertCount( 1, $recovery_coverage_ids );
	}

	/**
	 * With no coverage and no memory of one, the entry stays in the trash
	 * rather than being restored somewhere arbitrary.
	 */
	public function test_entry_with_no_coverage_context_stays_in_the_trash() {
		$entry_id = self::create_entry();
		wp_trash_post( $entry_id );

		$response = self::dispatch( 'POST', "/entries/{$entry_id}/restore" );

		$this->assertSame( 400, $response->get_status(), 'The request should be refused.' );
		$this->assertSame( 'rolling_coverage_no_recovery_context', $response->get_data()['code'], 'The refusal should explain the missing context.' );
		$this->assertSame( 'trash', get_post_status( $entry_id ), 'The entry should stay in the trash.' );
	}

	/**
	 * Restoring out of the trash would add an entry to the archived record.
	 */
	public function test_entry_of_an_archived_coverage_cannot_be_restored() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		wp_trash_post( $entry_id );
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$response = self::dispatch( 'POST', "/entries/{$entry_id}/restore" );

		$this->assertSame( 403, $response->get_status(), 'The request should be refused.' );
		$this->assertSame( 'trash', get_post_status( $entry_id ), 'The entry should stay in the trash.' );
	}

	/**
	 * A bulk restore checks permission per entry: entries the user cannot
	 * edit are reported as failures and left alone, without failing the rest.
	 */
	public function test_bulk_restore_skips_entries_the_user_cannot_edit() {
		$coverage_id     = self::create_coverage();
		$author_id       = self::log_in_as( 'author' );
		$own_entry_id    = self::create_entry( $coverage_id, [ 'post_author' => $author_id ] );
		$others_entry_id = self::create_entry( $coverage_id, [ 'post_author' => self::factory()->user->create( [ 'role' => 'editor' ] ) ] );
		wp_trash_post( $own_entry_id );
		wp_trash_post( $others_entry_id );

		$results = self::dispatch( 'POST', '/entries/restore', [ 'entry_ids' => [ $own_entry_id, $others_entry_id ] ] )->get_data()['results'];

		$this->assertSame(
			[
				$own_entry_id    => true,
				$others_entry_id => false,
			],
			wp_list_pluck( $results, 'restored', 'entryId' ),
			'Only the entry the author owns should be restored.'
		);
		$this->assertSame( 'trash', get_post_status( $others_entry_id ), "The other user's entry should stay in the trash." );
	}

	/**
	 * An entry remembers the first coverage it was assigned to, and keeps that
	 * memory when it is later moved, so recovery names the original.
	 */
	public function test_entry_remembers_its_first_coverage() {
		$first_coverage_id = self::create_coverage( '', [ 'slug' => 'first-coverage' ] );
		$entry_id          = self::create_entry( $first_coverage_id );

		wp_set_object_terms( $entry_id, [ self::create_coverage() ], Taxonomy::TAXONOMY_SLUG );

		$this->assertSame( $first_coverage_id, (int) get_post_meta( $entry_id, Post_Type::META_ORIGINAL_COVERAGE_ID, true ), 'The original coverage id should be kept.' );
		$this->assertSame( 'first-coverage', get_post_meta( $entry_id, Post_Type::META_ORIGINAL_COVERAGE_SLUG, true ), 'The original coverage slug should be kept.' );
	}

	/**
	 * The cleanup deletes entries whose coverage is gone and nothing else:
	 * not entries in a live coverage, and not entries that never had one.
	 */
	public function test_cleanup_deletes_only_entries_whose_coverage_was_deleted() {
		$deleted_coverage_id = self::create_coverage();
		$orphaned_entry_id   = self::create_entry( $deleted_coverage_id );
		$live_entry_id       = self::create_entry( self::create_coverage() );
		$unassigned_entry_id = self::create_entry();
		wp_delete_term( $deleted_coverage_id, Taxonomy::TAXONOMY_SLUG );

		Post_Type::cleanup_orphaned_entries();

		$this->assertNull( get_post( $orphaned_entry_id ), 'The orphaned entry should be deleted.' );
		$this->assertNotNull( get_post( $live_entry_id ), 'The entry in a live coverage should be kept.' );
		$this->assertNotNull( get_post( $unassigned_entry_id ), 'The entry that never had a coverage should be kept.' );
	}

	/**
	 * Trashed orphans are left for the user: they can still be restored into
	 * a recovery coverage.
	 */
	public function test_cleanup_leaves_trashed_orphans_restorable() {
		$trashed_orphan_id = self::create_trashed_entry_of_deleted_coverage( self::create_coverage() );

		Post_Type::cleanup_orphaned_entries();

		$this->assertNotNull( get_post( $trashed_orphan_id ) );
	}

	/**
	 * The cleanup works through large backlogs one batch per cron run.
	 */
	public function test_cleanup_reschedules_itself_while_orphans_remain() {
		$deleted_coverage_id = self::create_coverage();
		for ( $i = 0; $i <= Post_Type::CLEANUP_BATCH_SIZE; $i++ ) {
			self::create_entry( $deleted_coverage_id );
		}
		wp_delete_term( $deleted_coverage_id, Taxonomy::TAXONOMY_SLUG );

		Post_Type::cleanup_orphaned_entries();

		$this->assertNotFalse( wp_next_scheduled( Post_Type::CLEANUP_CRON_HOOK ), 'Another run should be scheduled for the remainder.' );

		wp_clear_scheduled_hook( Post_Type::CLEANUP_CRON_HOOK );
		Post_Type::cleanup_orphaned_entries();

		$this->assertFalse( wp_next_scheduled( Post_Type::CLEANUP_CRON_HOOK ), 'No further run is needed once the backlog is cleared.' );
	}
}
