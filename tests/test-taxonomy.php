<?php
/**
 * Tests for the coverage taxonomy: term meta, lifecycle routes and what the
 * REST API exposes about a coverage.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Placements;
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
		$coverage_id = self::create_coverage();
		update_term_meta( $coverage_id, Taxonomy::META_SLACK_CHANNEL_ID, 'C0TESTCHAN' );
		update_term_meta( $coverage_id, Taxonomy::META_SLACK_CHANNEL_NAME, 'test-channel' );
		update_term_meta( $coverage_id, Taxonomy::META_SOURCE, 'slack' );
		update_term_meta( $coverage_id, Taxonomy::META_SOURCE_REF, 'C0TESTCHAN' );

		// Logged out, every linkage key is hidden.
		wp_set_current_user( 0 );
		$public_meta = self::get_coverage_via_rest( $coverage_id, 'view' )['meta'];

		// Named rather than read from RESTRICTED_META or VIEWABLE_META, so a key drop can't drop its check.
		$this->assertArrayNotHasKey( Taxonomy::META_SLACK_CHANNEL_ID, $public_meta, 'The Slack channel ID should be hidden from the public response.' );
		$this->assertArrayNotHasKey( Taxonomy::META_SLACK_CHANNEL_NAME, $public_meta, 'The Slack channel name should be hidden from the public response.' );
		$this->assertArrayNotHasKey( Taxonomy::META_SOURCE, $public_meta, 'The chat source should be hidden from the public response.' );
		$this->assertArrayNotHasKey( Taxonomy::META_SOURCE_REF, $public_meta, 'The chat source reference should be hidden from the public response.' );
		$this->assertArrayHasKey( Taxonomy::STATUS_META_KEY, $public_meta, 'Non-sensitive meta should stay public.' );

		// Contributors pass the edit_posts gate, but source keys stay manager-only.
		self::log_in_as( 'contributor' );
		$contributor_meta = self::get_coverage_via_rest( $coverage_id, 'view' )['meta'];

		$this->assertArrayHasKey( Taxonomy::META_SLACK_CHANNEL_ID, $contributor_meta, 'The Slack channel ID should be visible to contributors.' );
		$this->assertArrayHasKey( Taxonomy::META_SLACK_CHANNEL_NAME, $contributor_meta, 'The Slack channel name should be visible to contributors.' );
		$this->assertArrayNotHasKey( Taxonomy::META_SOURCE, $contributor_meta, 'The chat source should stay hidden from contributors.' );
		$this->assertArrayNotHasKey( Taxonomy::META_SOURCE_REF, $contributor_meta, 'The chat source reference should stay hidden from contributors.' );

		// The edit context still carries the linkage.
		self::log_in_as( 'editor' );
		$edit_meta = self::get_coverage_via_rest( $coverage_id, 'edit' )['meta'];

		$this->assertSame( 'C0TESTCHAN', $edit_meta[ Taxonomy::META_SLACK_CHANNEL_ID ], 'The edit context should include the linkage.' );
	}

	/**
	 * The coverage exposes the newest published page embedding it, found
	 * inside nested blocks, and the next one once that page is unpublished.
	 */
	public function test_page_url_points_to_the_newest_published_page_showing_the_coverage() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$coverage_id = self::create_coverage();
		$other_id    = self::create_coverage();
		$block       = '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} /-->';

		$this->assertSame( '', self::get_coverage_via_rest( $coverage_id, 'view' )[ Taxonomy::PAGE_URL_REST_FIELD ], 'No page embeds the coverage yet.' );

		$older_id  = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_date'    => '2026-01-01 10:00:00',
				'post_content' => $block,
			]
		);
		$newest_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_date'    => '2026-02-01 10:00:00',
				'post_content' => '<!-- wp:group --><div class="wp-block-group">' . $block . '</div><!-- /wp:group -->',
			]
		);
		self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_date'    => '2026-03-01 10:00:00',
				'post_content' => $block,
			]
		);

		$this->assertSame( get_permalink( $newest_id ), self::get_coverage_via_rest( $coverage_id, 'view' )[ Taxonomy::PAGE_URL_REST_FIELD ], 'The newest published page should win over older and draft ones.' );
		$this->assertSame( '', Taxonomy::get_coverage_page_url( $other_id ), 'A coverage no page embeds should have no URL.' );

		wp_update_post(
			[
				'ID'          => $newest_id,
				'post_status' => 'draft',
			]
		);

		$this->assertSame( get_permalink( $older_id ), Taxonomy::get_coverage_page_url( $coverage_id ), 'Unpublishing the newest page should hand over to the next one.' );
	}

	/**
	 * A post that only embeds a capped block is never the coverage page,
	 * while one that also embeds an uncapped block still can be.
	 */
	public function test_page_url_skips_posts_that_only_embed_a_capped_block() {
		$coverage_id = self::create_coverage();
		$full        = '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} /-->';
		$capped      = '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . ',"latestOnly":true} /-->';

		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_date'    => '2026-01-01 10:00:00',
				'post_content' => $full,
			]
		);
		$only_capped_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_date'    => '2026-02-01 10:00:00',
				'post_content' => $capped,
			]
		);

		$this->assertSame( get_permalink( $page_id ), Taxonomy::get_coverage_page_url( $coverage_id ), 'A capped-only post should be skipped.' );

		wp_update_post(
			[
				'ID'           => $page_id,
				'post_content' => $capped,
			]
		);

		$this->assertSame( '', Taxonomy::get_coverage_page_url( $coverage_id ), 'With only capped blocks there is no page.' );

		wp_update_post(
			[
				'ID'           => $only_capped_id,
				'post_content' => $capped . '<!-- wp:group --><div class="wp-block-group">' . $full . '</div><!-- /wp:group -->',
			]
		);

		$this->assertSame( get_permalink( $only_capped_id ), Taxonomy::get_coverage_page_url( $coverage_id ), 'A post with a capped and an uncapped block is eligible.' );
	}

	/**
	 * A canonical URL is where share links and notifications send readers,
	 * so it wins over the newest embedding page.
	 */
	public function test_page_url_prefers_the_canonical_url() {
		$coverage_id   = self::create_coverage();
		$canonical_url = home_url( '/live/election-night/' );

		self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} /-->',
			]
		);
		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, $canonical_url );

		$this->assertSame( $canonical_url, Taxonomy::get_coverage_page_url( $coverage_id ) );
	}

	/**
	 * The page lookup scans post content, so it only runs for users who can
	 * reach the admin that shows it.
	 */
	public function test_page_url_is_empty_for_users_who_cannot_edit_posts() {
		$coverage_id = self::create_coverage();

		self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} /-->',
			]
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( '', self::get_coverage_via_rest( $coverage_id, 'view' )[ Taxonomy::PAGE_URL_REST_FIELD ] );
	}

	/**
	 * Whether an action clears the coverage-page lookup.
	 *
	 * @param callable $action Action to run.
	 * @return bool
	 */
	private static function clears_page_lookup( callable $action ): bool {
		$sentinel = self::stored_map( [ 1 => 1 ] );

		Placements::rebuild();
		update_option( Placements::OPTION, $sentinel, false );

		$action();
		Placements::rebuild();

		return get_option( Placements::OPTION ) !== $sentinel;
	}

	/**
	 * A stored placements map holding only the given page lookup.
	 *
	 * @param array $pages Map of coverage ID => page ID.
	 * @return array
	 */
	private static function stored_map( array $pages ): array {
		return [
			'pages'    => $pages,
			'places'   => [],
			'breakout' => [],
			'patterns' => [],
		];
	}

	/**
	 * Capped feeds look the page up on every front-end render, so readers keep
	 * the stored map while a change is saved, and the request that made the
	 * change, which is sure to see it, stores the new one when it ends.
	 */
	public function test_a_change_rebuilds_the_stored_page_lookup_once_the_request_ends() {
		$coverage_id = self::create_coverage();
		Placements::rebuild();
		update_option( Placements::OPTION, self::stored_map( [] ), false );
		remove_all_actions( 'shutdown' );

		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} /-->',
			]
		);

		$this->assertSame( self::stored_map( [] ), get_option( Placements::OPTION ), 'Readers keep the stored map until the request ends.' );
		$this->assertSame( get_permalink( $page_id ), Taxonomy::get_coverage_page_url( $coverage_id ), 'The request that made the change sees it straight away.' );

		wp_update_post(
			[
				'ID'         => $page_id,
				'post_title' => 'Renamed',
			]
		);
		update_option( Placements::OPTION, self::stored_map( [] ), false );
		do_action( 'shutdown' );

		$this->assertSame( [ $coverage_id => $page_id ], get_option( Placements::OPTION )['pages'], 'The end of the request overwrites whatever a reader stored.' );
	}

	/**
	 * Entries and revisions change constantly during live coverage, so saving
	 * them keeps the page lookup cached; saving a page with the block clears it.
	 */
	public function test_only_pages_that_can_host_the_block_clear_the_page_lookup() {
		$block = '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":1} /-->';

		$this->assertFalse(
			self::clears_page_lookup(
				function () use ( $block ) {
					$entry_id = self::factory()->post->create(
						[
							'post_type'    => Post_Type::CPT_SLUG,
							'post_content' => $block,
						]
					);
					self::factory()->post->create(
						[
							'post_type'    => 'revision',
							'post_status'  => 'inherit',
							'post_parent'  => $entry_id,
							'post_content' => $block,
						]
					);
				}
			),
			'Entry and revision writes should keep the lookup cached.'
		);
		$this->assertTrue(
			self::clears_page_lookup(
				fn() => self::factory()->post->create(
					[
						'post_type'    => 'page',
						'post_content' => $block,
					]
				)
			),
			'Saving a page with the block should clear the lookup.'
		);
	}

	/**
	 * Only posts that hold the block, or held it before the change, can
	 * change which page shows a coverage, so other writes keep the lookup.
	 */
	public function test_only_changes_to_posts_holding_the_block_clear_the_page_lookup() {
		$block    = '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":1} /-->';
		$plain_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$host_id  = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => $block,
			]
		);

		$this->assertFalse( self::clears_page_lookup( fn() => self::factory()->post->create( [ 'post_type' => 'page' ] ) ), 'A new page without the block keeps the lookup.' );
		$this->assertFalse(
			self::clears_page_lookup(
				fn() => wp_update_post(
					[
						'ID'         => $plain_id,
						'post_title' => 'Renamed',
					]
				)
			),
			'Editing a page without the block keeps the lookup.'
		);
		$this->assertFalse(
			self::clears_page_lookup(
				fn() => self::factory()->comment->create(
					[
						'comment_post_ID'  => $host_id,
						'comment_approved' => 1,
					]
				)
			),
			'An approved comment on the page keeps the lookup.'
		);
		$this->assertTrue(
			self::clears_page_lookup(
				fn() => self::factory()->post->create(
					[
						'post_type'    => 'page',
						'post_content' => '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":1,"latestOnly":true} /-->',
					]
				)
			),
			'A capped block is a placement too, so a page holding one clears the lookup.'
		);
		$this->assertTrue(
			self::clears_page_lookup(
				fn() => wp_update_post(
					[
						'ID'           => $plain_id,
						'post_content' => $block,
					]
				)
			),
			'Adding the block clears the lookup.'
		);
		$this->assertTrue(
			self::clears_page_lookup(
				fn() => wp_update_post(
					[
						'ID'           => $plain_id,
						'post_content' => 'No block.',
					]
				)
			),
			'Removing the block clears the lookup.'
		);
		$this->assertTrue(
			self::clears_page_lookup(
				fn() => wp_update_post(
					[
						'ID'         => $host_id,
						'post_title' => 'Renamed',
					]
				)
			),
			'Editing a page with the block clears the lookup.'
		);
		$this->assertTrue( self::clears_page_lookup( fn() => wp_trash_post( $host_id ) ), 'Trashing a page with the block clears the lookup.' );
		$this->assertFalse( self::clears_page_lookup( fn() => wp_delete_post( $host_id, true ) ), 'Deleting a trashed page keeps the lookup.' );
		$other_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => $block,
			]
		);
		$this->assertTrue( self::clears_page_lookup( fn() => wp_delete_post( $other_id, true ) ), 'Deleting a published page with the block clears the lookup.' );
	}

	/**
	 * A scheduled page publishes without an update, so the status change
	 * itself clears the lookup.
	 */
	public function test_publishing_a_scheduled_page_with_the_block_clears_the_page_lookup() {
		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'future',
				'post_date'    => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) ),
				'post_content' => '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":1} /-->',
			]
		);

		$this->assertTrue( self::clears_page_lookup( fn() => wp_publish_post( $page_id ) ) );
	}
}
