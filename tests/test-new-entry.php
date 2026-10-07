<?php
/**
 * Tests for starting a new entry from the entries list.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Add Entry opens Quick Edit on an auto-draft the plugin's route creates in
 * the coverage. These tests pin down what that route makes, who may call it,
 * and that an auto-draft stays invisible until its first save, which dates it.
 */
class Test_New_Entry extends Rolling_Coverage_TestCase {

	/**
	 * Start a new entry in a coverage through the route.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return WP_REST_Response
	 */
	private static function start_entry( $coverage_id ) {
		return self::dispatch( 'POST', "/coverages/{$coverage_id}/entries" );
	}

	/**
	 * Save an entry through the core entries route, as Quick Edit does.
	 *
	 * @param int   $entry_id Entry post ID.
	 * @param array $fields   Fields to save.
	 * @return WP_REST_Response
	 */
	private static function save_entry( $entry_id, array $fields ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Post_Type::REST_BASE . "/{$entry_id}" );

		foreach ( $fields as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The route makes an empty auto-draft, credited to the caller and
	 * assigned to the coverage, so the editor has a post to open before
	 * anything is saved. Contributors can start one.
	 */
	public function test_route_starts_an_empty_auto_draft_in_the_coverage() {
		$user_id     = self::log_in_as( 'contributor' );
		$coverage_id = self::create_coverage();

		$response = self::start_entry( $coverage_id );

		$this->assertSame( 201, $response->get_status(), 'The entry should be created.' );
		$entry = get_post( $response->get_data()['id'] );
		$this->assertInstanceOf( WP_Post::class, $entry, 'The response should name the new entry.' );
		$this->assertSame( Post_Type::CPT_SLUG, $entry->post_type );
		$this->assertSame( 'auto-draft', $entry->post_status, 'The entry should not exist as a draft until it is saved.' );
		$this->assertSame( '', $entry->post_title, 'The title should be empty, not "Auto Draft".' );
		$this->assertSame( '', $entry->post_content );
		$this->assertSame( $user_id, (int) $entry->post_author, 'The entry should be credited to the person adding it.' );
		$this->assertSame( [ $coverage_id ], wp_get_post_terms( $entry->ID, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] ), 'The entry should be in the coverage.' );
	}

	/**
	 * Quick Edit loads the auto-draft through the core route in the edit
	 * context, which the person who started it can do, however low their role.
	 */
	public function test_the_person_who_started_the_entry_can_open_it_through_the_core_route() {
		self::log_in_as( 'contributor' );
		$entry_id = self::start_entry( self::create_coverage() )->get_data()['id'];

		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::REST_BASE . "/{$entry_id}" );
		$request->set_param( 'context', 'edit' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'The auto-draft should be readable by its author.' );
		$this->assertSame( 'auto-draft', $response->get_data()['status'] );
		$this->assertSame( '', $response->get_data()['title']['raw'] );
	}

	/**
	 * Starting an entry needs the capability to create entries.
	 */
	public function test_route_is_closed_to_users_who_cannot_create_entries() {
		$coverage_id = self::create_coverage();

		$this->assertSame( 401, self::start_entry( $coverage_id )->get_status(), 'Visitors should be refused.' );

		self::log_in_as( 'subscriber' );

		$this->assertSame( 403, self::start_entry( $coverage_id )->get_status(), 'Subscribers should be refused.' );
	}

	/**
	 * An ended coverage takes no new entries, with Archive Mode's error.
	 */
	public function test_route_refuses_an_ended_coverage() {
		self::log_in_as( 'editor' );

		$response = self::start_entry( self::create_coverage( Taxonomy::STATUS_ARCHIVED ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rolling_coverage_entry_locked', $response->get_data()['code'] );
		$this->assertStringContainsString( '“Ended”', $response->get_data()['message'], "The refusal should name the site's label for the status." );
	}

	/**
	 * A trashed coverage takes no new entries either.
	 */
	public function test_route_refuses_a_trashed_coverage() {
		self::log_in_as( 'editor' );

		$response = self::start_entry( self::create_coverage( 'trash' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rolling_coverage_coverage_trashed', $response->get_data()['code'] );
	}

	/**
	 * A coverage that doesn't exist is a 404.
	 */
	public function test_route_is_a_404_for_an_unknown_coverage() {
		self::log_in_as( 'editor' );

		$this->assertSame( 404, self::start_entry( 999999 )->get_status() );
	}

	/**
	 * An auto-draft is not an entry yet: the list doesn't show it, the
	 * coverage's last-modified time ignores it, and the coverage's count
	 * leaves it out.
	 */
	public function test_an_auto_draft_is_not_entry_activity() {
		self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		$last_modified = '2026-01-01 12:00:00';
		update_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, $last_modified );

		$entry_id = self::start_entry( $coverage_id )->get_data()['id'];
		$listed   = self::dispatch( 'GET', "/coverages/{$coverage_id}/entries-view", [ 'status' => implode( ',', Post_Type::ALLOWED_STATUSES ) ] )->get_data()['entries'];

		$this->assertNotContains( $entry_id, wp_list_pluck( $listed, 'id' ), 'The list should not show the auto-draft.' );
		$this->assertSame( $last_modified, get_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, true ), 'Starting an entry should not count as activity in the coverage.' );
		$this->assertSame( 1, (int) get_term( $coverage_id, Taxonomy::TAXONOMY_SLUG )->count, 'The coverage should count only its saved entries.' );
	}

	/**
	 * The route schedules core's auto-draft cleanup, as post-new.php does, so
	 * an entry that is never saved is deleted after a week.
	 */
	public function test_route_schedules_the_auto_draft_cleanup() {
		self::log_in_as( 'editor' );
		wp_clear_scheduled_hook( 'wp_scheduled_auto_draft_delete' );

		self::start_entry( self::create_coverage() );

		$this->assertNotFalse( wp_next_scheduled( 'wp_scheduled_auto_draft_delete' ), 'The cleanup event should be scheduled.' );
	}

	/**
	 * The auto-draft's date floats until it is first saved, so the entry is
	 * dated when it becomes one, not when the editor opened. The first save
	 * through the core route turns it into a draft with concrete GMT dates,
	 * and marks the coverage as changed so open lists pick it up.
	 */
	public function test_first_save_turns_the_auto_draft_into_a_dated_draft() {
		self::log_in_as( 'contributor' );
		$coverage_id = self::create_coverage();
		$entry_id    = self::start_entry( $coverage_id )->get_data()['id'];
		$zero        = '0000-00-00 00:00:00';
		$opened      = '2020-01-01 00:00:00';

		$this->assertSame( $zero, get_post( $entry_id )->post_date_gmt, 'An auto-draft should keep a floating date.' );

		// Pretend the editor was opened long ago. Only the local date is set,
		// which wp_update_post() cannot do without also setting the GMT one.
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_date' => $opened ], [ 'ID' => $entry_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $entry_id );
		update_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, '2026-01-01 12:00:00' );

		$response = self::save_entry(
			$entry_id,
			[
				'status' => 'draft',
				'title'  => 'Saved at last',
			]
		);
		$saved    = get_post( $entry_id );

		$this->assertSame( 200, $response->get_status(), 'The author should be able to save the entry.' );
		$this->assertSame( 'draft', $saved->post_status );
		$this->assertNotSame( $opened, $saved->post_date, 'The entry should be dated when it was saved, not when it was started.' );
		$this->assertNotSame( $zero, $saved->post_date_gmt, 'A draft should carry a concrete GMT date.' );
		$this->assertSame( get_gmt_from_date( $saved->post_date ), $saved->post_date_gmt );
		$this->assertSame( $saved->post_modified_gmt, get_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, true ), 'Saving the entry should count as activity in the coverage.' );
	}

	/**
	 * Publishing straight from the auto-draft publishes it, dated then.
	 */
	public function test_first_save_can_publish_the_auto_draft() {
		self::log_in_as( 'editor' );
		$entry_id = self::start_entry( self::create_coverage() )->get_data()['id'];

		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_date' => '2020-01-01 00:00:00' ], [ 'ID' => $entry_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $entry_id );

		$response = self::save_entry(
			$entry_id,
			[
				'status'  => 'publish',
				'content' => '<!-- wp:paragraph --><p>Live now.</p><!-- /wp:paragraph -->',
			]
		);
		$saved    = get_post( $entry_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'publish', $saved->post_status );
		$this->assertStringStartsWith( gmdate( 'Y' ), $saved->post_date_gmt, 'The entry should be dated when it was published.' );
	}

	/**
	 * A coverage that ends while the modal is open takes no new entries
	 * either: the first save is refused as the route refuses a start, and the
	 * auto-draft stays one.
	 */
	public function test_first_save_is_refused_once_the_coverage_has_ended() {
		self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage();
		$entry_id    = self::start_entry( $coverage_id )->get_data()['id'];
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$response = self::save_entry(
			$entry_id,
			[
				'status' => 'publish',
				'title'  => 'Too late',
			]
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rolling_coverage_entry_locked', $response->get_data()['code'] );
		$this->assertSame( 'auto-draft', get_post( $entry_id )->post_status, 'The refused save should leave the auto-draft as it was.' );
	}

	/**
	 * Quick Edit deletes the auto-draft when a new entry is cancelled before
	 * its first save, through the core route, which the person who started
	 * it can do, however low their role.
	 */
	public function test_the_person_who_started_the_entry_can_delete_it_before_saving() {
		self::log_in_as( 'contributor' );
		$entry_id = self::start_entry( self::create_coverage() )->get_data()['id'];

		$request = new WP_REST_Request( 'DELETE', '/wp/v2/' . Post_Type::REST_BASE . "/{$entry_id}" );
		$request->set_param( 'force', true );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'The author should be able to delete the auto-draft.' );
		$this->assertNull( get_post( $entry_id ), 'The auto-draft should be gone, not trashed.' );
	}

	/**
	 * Deleting an auto-draft, as Cancel does and as core's weekly cleanup does
	 * for one that was never saved, is not activity in its coverage either.
	 */
	public function test_deleting_an_abandoned_auto_draft_is_not_entry_activity() {
		self::log_in_as( 'editor' );
		$coverage_id   = self::create_coverage();
		$entry_id      = self::start_entry( $coverage_id )->get_data()['id'];
		$last_modified = '2026-01-01 12:00:00';
		update_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, $last_modified );

		wp_delete_post( $entry_id, true );

		$this->assertNull( get_post( $entry_id ) );
		$this->assertSame( $last_modified, get_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, true ), 'Deleting an auto-draft should not count as activity in the coverage.' );
	}
}
