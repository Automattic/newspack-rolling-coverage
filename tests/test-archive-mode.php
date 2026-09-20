<?php
/**
 * Tests for Archive Mode restrictions.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Archive_Mode;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Archiving freezes the published record of an event. These tests pin down
 * what a locked entry refuses (deletion, new assignments, pinning) and what
 * it still allows (content edits), for both ways an entry becomes locked.
 */
class Test_Archive_Mode extends Rolling_Coverage_TestCase {

	/**
	 * Archive or unarchive an entry through the REST route.
	 *
	 * @param int  $entry_id Entry post ID.
	 * @param bool $archived Target state.
	 * @return WP_REST_Response
	 */
	private static function set_entry_archived( $entry_id, $archived ) {
		return self::dispatch( 'POST', "/entries/{$entry_id}/archive", [ 'archived' => $archived ] );
	}

	/**
	 * Build the REST request for saving an entry with the given coverages.
	 *
	 * @param int[] $coverage_ids Coverage term IDs sent with the save.
	 * @param int   $entry_id     Entry to update, or 0 for a new entry.
	 * @return WP_REST_Request
	 */
	private static function entry_save_request( array $coverage_ids, $entry_id = 0 ) {
		$route   = '/wp/v2/' . Post_Type::REST_BASE . ( $entry_id ? "/{$entry_id}" : '' );
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( Taxonomy::REST_BASE, $coverage_ids );
		return $request;
	}

	/**
	 * An entry is locked by its own archive flag or by its coverage's status.
	 */
	public function test_entry_is_locked_by_its_own_flag_or_by_its_coverage() {
		$open_entry_id            = self::create_entry( self::create_coverage() );
		$archived_entry_id        = self::create_entry( self::create_coverage() );
		$archived_coverage_member = self::create_entry( self::create_coverage( Taxonomy::STATUS_ARCHIVED ) );
		update_post_meta( $archived_entry_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );

		$this->assertFalse( Archive_Mode::is_entry_locked( $open_entry_id ), 'An entry in an active coverage should be open.' );
		$this->assertTrue( Archive_Mode::is_entry_locked( $archived_entry_id ), 'An individually archived entry should be locked.' );
		$this->assertTrue( Archive_Mode::is_entry_locked( $archived_coverage_member ), 'An entry in an archived coverage should be locked.' );
	}

	/**
	 * Not even an administrator can trash a locked entry, while editing it
	 * stays possible.
	 */
	public function test_locked_entry_cannot_be_trashed_but_can_still_be_edited() {
		self::log_in_as( 'administrator' );
		$locked_entry_id = self::create_entry( self::create_coverage( Taxonomy::STATUS_ARCHIVED ) );
		$open_entry_id   = self::create_entry( self::create_coverage() );

		$this->assertFalse( current_user_can( 'delete_post', $locked_entry_id ), 'A locked entry should not be deletable.' );
		$this->assertTrue( current_user_can( 'edit_post', $locked_entry_id ), 'A locked entry should remain editable.' );
		$this->assertTrue( current_user_can( 'delete_post', $open_entry_id ), 'An open entry should be deletable.' );
	}

	/**
	 * An entry that was already in the trash when its coverage was archived
	 * can still be deleted for good, so the trash can be emptied.
	 */
	public function test_trashed_entry_in_an_archived_coverage_can_still_be_deleted() {
		self::log_in_as( 'administrator' );
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		wp_trash_post( $entry_id );
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$this->assertTrue( current_user_can( 'delete_post', $entry_id ) );
	}

	/**
	 * The delete restriction is limited to entries.
	 */
	public function test_delete_restriction_does_not_reach_other_post_types() {
		self::log_in_as( 'administrator' );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );

		$this->assertTrue( current_user_can( 'delete_post', $post_id ) );
	}

	/**
	 * A REST save cannot move an entry into an archived coverage.
	 */
	public function test_rest_save_cannot_assign_an_entry_to_an_archived_coverage() {
		self::log_in_as( 'editor' );
		$open_coverage_id     = self::create_coverage();
		$archived_coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		$entry_id             = self::create_entry( $open_coverage_id );

		$response = rest_get_server()->dispatch( self::entry_save_request( [ $archived_coverage_id ], $entry_id ) );

		$this->assertSame( 'rolling_coverage_entry_locked', $response->get_data()['code'] ?? null, 'The save should be refused with the entry-locked error.' );
		$this->assertSame( 403, $response->get_status(), 'The refusal should be a 403.' );
		$this->assertSame( [ $open_coverage_id ], wp_get_post_terms( $entry_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] ), 'The entry should stay in its coverage.' );
	}

	/**
	 * A brand-new entry cannot be created inside an archived coverage either.
	 */
	public function test_rest_save_cannot_create_an_entry_in_an_archived_coverage() {
		$archived_coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );

		$result = Archive_Mode::block_rest_writes( new stdClass(), self::entry_save_request( [ $archived_coverage_id ] ) );

		$this->assertWPError( $result );
	}

	/**
	 * Saving an entry that already sits in an archived coverage resends that
	 * coverage id. That is not a new assignment, so content edits go through.
	 */
	public function test_rest_save_of_an_entry_already_in_an_archived_coverage_is_allowed() {
		$archived_coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		$prepared_post        = (object) [ 'ID' => self::create_entry( $archived_coverage_id ) ];

		$result = Archive_Mode::block_rest_writes( $prepared_post, self::entry_save_request( [ $archived_coverage_id ] ) );

		$this->assertSame( $prepared_post, $result );
	}

	/**
	 * Archiving keeps the entry published and records when it happened;
	 * unarchiving clears the record.
	 */
	public function test_archiving_an_entry_flags_it_without_unpublishing_it() {
		self::log_in_as( 'editor' );
		$entry_id = self::create_entry( self::create_coverage() );

		$archive_response = self::set_entry_archived( $entry_id, true );

		$this->assertSame( [ 'archived' => true ], $archive_response->get_data(), 'The route should confirm the archived state.' );
		$this->assertSame( 'publish', get_post_status( $entry_id ), 'The entry should stay published.' );
		$this->assertEqualsWithDelta( time(), Archive_Mode::get_entry_archived_at( $entry_id ), 5, 'The archive time should be recorded.' );

		$unarchive_response = self::set_entry_archived( $entry_id, false );

		$this->assertSame( [ 'archived' => false ], $unarchive_response->get_data(), 'The route should confirm the unarchived state.' );
		$this->assertFalse( Archive_Mode::is_entry_archived( $entry_id ), 'The archive flag should be cleared.' );
	}

	/**
	 * A draft has not been part of the public record, so it cannot be archived.
	 */
	public function test_unpublished_entry_cannot_be_archived() {
		self::log_in_as( 'editor' );
		$draft_entry_id = self::create_entry( self::create_coverage(), [ 'post_status' => 'draft' ] );

		$response = self::set_entry_archived( $draft_entry_id, true );

		$this->assertSame( 400, $response->get_status(), 'The request should be refused.' );
		$this->assertFalse( Archive_Mode::is_entry_archived( $draft_entry_id ), 'The entry should not be flagged.' );
	}

	/**
	 * While the coverage is archived its entries cannot be unarchived one by
	 * one; the coverage has to be reopened first.
	 */
	public function test_entry_archive_state_is_frozen_while_its_coverage_is_archived() {
		self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		update_post_meta( $entry_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$response = self::set_entry_archived( $entry_id, false );

		$this->assertSame( 403, $response->get_status(), 'The request should be refused.' );
		$this->assertTrue( Archive_Mode::is_entry_archived( $entry_id ), 'The entry should stay archived.' );
	}

	/**
	 * Archiving changes the public record, so it needs publish rights, not
	 * just the ability to edit one's own entry.
	 */
	public function test_archiving_requires_publish_rights() {
		$contributor_id = self::log_in_as( 'contributor' );
		$own_draft_id   = self::create_entry(
			self::create_coverage(),
			[
				'post_status' => 'draft',
				'post_author' => $contributor_id,
			]
		);

		$this->assertTrue( current_user_can( 'edit_post', $own_draft_id ), 'Precondition: the contributor can edit the entry.' );
		$this->assertSame( 403, self::set_entry_archived( $own_draft_id, true )->get_status(), 'A contributor should not be able to archive.' );
	}

	/**
	 * A locked entry cannot be pinned or unpinned.
	 */
	public function test_locked_entry_cannot_be_pinned() {
		self::log_in_as( 'editor' );
		$locked_entry_id = self::create_entry( self::create_coverage( Taxonomy::STATUS_ARCHIVED ) );

		$response = self::dispatch( 'POST', "/entries/{$locked_entry_id}/pin" );

		$this->assertSame( 403, $response->get_status(), 'The request should be refused.' );
		$this->assertFalse( Post_Type::is_pinned( $locked_entry_id ), 'The entry should not be pinned.' );
	}
}
