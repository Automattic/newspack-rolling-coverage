<?php
/**
 * Tests for what the core entries REST route exposes, and for pinning.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Entries are a public post type, so their core REST route answers anonymous
 * requests. These tests pin down what that route keeps back: chat-source
 * provenance, and entries whose coverage is trashed or gone.
 */
class Test_Entries_REST extends Rolling_Coverage_TestCase {

	/**
	 * Create a coverage the way the admin screen does, with its status stored.
	 *
	 * The public listing finds visible coverages with a meta query, which only
	 * sees stored rows, not the registered default.
	 *
	 * @return int Coverage term ID.
	 */
	private static function create_active_coverage() {
		return self::create_coverage( Taxonomy::STATUS_ACTIVE );
	}

	/**
	 * Request the core entries collection.
	 *
	 * @param string $context REST context.
	 * @return array[] Response data.
	 */
	private static function list_entries_via_rest( $context = 'view' ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::REST_BASE );
		$request->set_param( 'context', $context );
		return rest_get_server()->dispatch( $request )->get_data();
	}

	/**
	 * Slack provenance identifies people and channels in the newsroom's
	 * workspace, so anonymous responses leave it out.
	 */
	public function test_chat_source_provenance_is_hidden_from_public_responses() {
		$entry_id = self::create_entry( self::create_active_coverage() );
		update_post_meta( $entry_id, Post_Type::META_ENTRY_SOURCE, 'slack' );
		update_post_meta( $entry_id, Post_Type::META_SLACK_USER_ID, 'U0REPORTER' );
		update_post_meta( $entry_id, Post_Type::META_SLACK_AUTHOR_NAME, 'Riley Sample' );

		$public_meta = self::list_entries_via_rest()[0]['meta'];

		// Named rather than read from RESTRICTED_META, so dropping a key from
		// that list cannot drop its check too.
		$this->assertArrayNotHasKey( Post_Type::META_SLACK_USER_ID, $public_meta, 'The Slack user ID should be hidden from the public response.' );
		$this->assertArrayNotHasKey( Post_Type::META_SLACK_AUTHOR_NAME, $public_meta, 'The Slack author name should be hidden from the public response.' );
		foreach ( Post_Type::RESTRICTED_META as $restricted_key ) {
			$this->assertArrayNotHasKey( $restricted_key, $public_meta, "{$restricted_key} should be hidden from the public response." );
		}
		$this->assertSame( 'slack', $public_meta[ Post_Type::META_ENTRY_SOURCE ], 'The source slug is not sensitive and should stay public.' );
	}

	/**
	 * Editors see the provenance.
	 */
	public function test_chat_source_provenance_is_available_in_edit_context() {
		self::log_in_as( 'editor' );
		$entry_id = self::create_entry( self::create_coverage() );
		update_post_meta( $entry_id, Post_Type::META_SLACK_AUTHOR_NAME, 'Riley Sample' );

		$edit_meta = self::list_entries_via_rest( 'edit' )[0]['meta'];

		$this->assertSame( 'Riley Sample', $edit_meta[ Post_Type::META_SLACK_AUTHOR_NAME ] );
	}

	/**
	 * Trashing a coverage leaves its entries published, so the public listing
	 * has to hide them itself. The same goes for entries whose coverage was
	 * deleted and that the cleanup has not reached yet.
	 */
	public function test_public_listing_hides_entries_of_trashed_and_deleted_coverages() {
		$deleted_coverage_id = self::create_coverage();
		$visible_entry_id    = self::create_entry( self::create_active_coverage() );
		self::create_entry( self::create_coverage( 'trash' ) );
		self::create_entry( $deleted_coverage_id );
		wp_delete_term( $deleted_coverage_id, Taxonomy::TAXONOMY_SLUG );

		$this->assertSame( [ $visible_entry_id ], wp_list_pluck( self::list_entries_via_rest(), 'id' ) );
	}

	/**
	 * The admin list still needs those entries, for the trash view.
	 */
	public function test_edit_context_listing_includes_entries_of_trashed_coverages() {
		self::log_in_as( 'editor' );
		$entry_id = self::create_entry( self::create_coverage( 'trash' ) );

		$this->assertSame( [ $entry_id ], wp_list_pluck( self::list_entries_via_rest( 'edit' ), 'id' ) );
	}

	/**
	 * A coverage with no stored status reads as active.
	 */
	public function test_coverage_status_field_defaults_to_active() {
		$entry_id          = self::create_entry( self::create_coverage() );
		$paused_entry_id   = self::create_entry( self::create_coverage( Taxonomy::STATUS_PAUSED ) );
		$unassigned_entry  = self::create_entry();

		$this->assertSame( Taxonomy::STATUS_ACTIVE, Post_Type::get_coverage_status_rest_field( [ 'id' => $entry_id ] ), 'No stored status should read as active.' );
		$this->assertSame( Taxonomy::STATUS_PAUSED, Post_Type::get_coverage_status_rest_field( [ 'id' => $paused_entry_id ] ), 'A stored status should be returned.' );
		$this->assertSame( '', Post_Type::get_coverage_status_rest_field( [ 'id' => $unassigned_entry ] ), 'An entry with no coverage has no coverage status.' );
	}

	/**
	 * The pin route flips the pinned state and reports the new one.
	 */
	public function test_pin_route_toggles_the_pinned_state() {
		self::log_in_as( 'editor' );
		$entry_id = self::create_entry( self::create_coverage() );

		$pin_response = self::dispatch( 'POST', "/entries/{$entry_id}/pin" );

		$this->assertSame( [ 'pinned' => true ], $pin_response->get_data(), 'The first call should pin the entry.' );
		$this->assertTrue( Post_Type::is_pinned( $entry_id ), 'The entry should be pinned.' );

		$unpin_response = self::dispatch( 'POST', "/entries/{$entry_id}/pin" );

		$this->assertSame( [ 'pinned' => false ], $unpin_response->get_data(), 'The second call should unpin it.' );
		$this->assertFalse( Post_Type::is_pinned( $entry_id ), 'The entry should no longer be pinned.' );
	}

	/**
	 * Pinning marks the entry as modified, so pages already open pick up its
	 * pinned row on their next poll.
	 */
	public function test_pin_route_marks_the_entry_modified() {
		self::log_in_as( 'editor' );
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry(
			$coverage_id,
			[
				'post_date'     => '2020-01-01 00:00:00',
				'post_date_gmt' => '2020-01-01 00:00:00',
			]
		);
		$before      = get_post_field( 'post_modified_gmt', $entry_id );
		update_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, $before );

		self::dispatch( 'POST', "/entries/{$entry_id}/pin" );
		clean_post_cache( $entry_id );

		$this->assertNotSame( $before, get_post_field( 'post_modified_gmt', $entry_id ), 'The entry should be marked modified.' );
		$this->assertNotSame( $before, get_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, true ), 'The coverage should be too, so polls look for changes.' );
	}

	/**
	 * Pinning leaves the entry's content alone, even when the person pinning
	 * can't post the HTML or block CSS it holds.
	 */
	public function test_pin_route_keeps_html_the_pinner_cannot_post() {
		self::log_in_as( 'administrator' );
		kses_init();
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_content' => '<!-- wp:html --><iframe src="https://example.org/embed"></iframe><!-- /wp:html --><!-- wp:paragraph {"style":{"css":"color:red"}} --><p>Styled</p><!-- /wp:paragraph -->' ] );
		$content  = get_post_field( 'post_content', $entry_id );
		$deny     = function ( $caps, $cap ) {
			return in_array( $cap, [ 'unfiltered_html', 'edit_css' ], true ) ? [ 'do_not_allow' ] : $caps;
		};

		self::log_in_as( 'editor' );
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		kses_init();

		try {
			self::dispatch( 'POST', "/entries/{$entry_id}/pin" );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
			kses_init();
		}

		clean_post_cache( $entry_id );
		$this->assertStringContainsString( '<iframe', $content, 'The author should be able to post the iframe.' );
		$this->assertStringContainsString( '"css":"color:red"', $content, 'The author should be able to post block CSS.' );
		$this->assertSame( $content, get_post_field( 'post_content', $entry_id ), 'Pinning should keep it.' );
	}

	/**
	 * Only entries can be pinned, so a regular post id is a 404 and the
	 * pinned list stays clean.
	 */
	public function test_pin_route_refuses_posts_that_are_not_entries() {
		self::log_in_as( 'editor' );
		$post_id = self::factory()->post->create();

		$response = self::dispatch( 'POST', "/entries/{$post_id}/pin" );

		$this->assertSame( 404, $response->get_status(), 'The request should not find an entry.' );
		$this->assertSame( [], Post_Type::get_pinned_ids(), 'Nothing should be pinned.' );
	}

	/**
	 * Authors can pin their own entries but not other people's.
	 */
	public function test_authors_cannot_pin_entries_they_cannot_edit() {
		$others_entry_id = self::create_entry( self::create_coverage(), [ 'post_author' => self::factory()->user->create( [ 'role' => 'editor' ] ) ] );
		self::log_in_as( 'author' );

		$response = self::dispatch( 'POST', "/entries/{$others_entry_id}/pin" );

		$this->assertSame( 403, $response->get_status(), 'The request should be refused.' );
		$this->assertFalse( Post_Type::is_pinned( $others_entry_id ), 'The entry should not be pinned.' );
	}

	/**
	 * The pinned ordering is hooked into every query on the site, so it has
	 * to leave queries for other post types exactly as they were.
	 */
	public function test_pinned_ordering_leaves_other_post_type_queries_alone() {
		Post_Type::pin_entry( self::create_entry( self::create_coverage() ) );
		$posts_query = new WP_Query();
		$posts_query->set( 'post_type', 'post' );
		$skipped_query = new WP_Query();
		$skipped_query->set( 'post_type', Post_Type::CPT_SLUG );
		$skipped_query->set( Post_Type::SKIP_PIN_ORDER_VAR, true );

		$this->assertSame( 'wp_posts.post_date DESC', Post_Type::orderby_pinned_first( 'wp_posts.post_date DESC', $posts_query ), 'A query for posts should be untouched.' );
		$this->assertSame( 'wp_posts.post_date DESC', Post_Type::orderby_pinned_first( 'wp_posts.post_date DESC', $skipped_query ), 'An entries query that opts out should be untouched.' );
	}

	/**
	 * When other users' entries are trashed, an author's edit-context trash
	 * collection must return a body whose length matches its X-WP-Total
	 * header. Core computes the header from an unfiltered query and then drops
	 * uneditable entries from the body; that mismatch makes core-data treat
	 * the collection as failed (the trashed-entries view shows "Failed to
	 * load trashed entries"). Author-scoping the query keeps them in sync.
	 */
	public function test_edit_context_trash_total_matches_body_for_author() {
		$author_id = self::log_in_as( 'author' );
		$coverage  = self::create_coverage();
		$other_id  = self::factory()->user->create( [ 'role' => 'author' ] );

		$others_trashed = self::create_entry( $coverage, [ 'post_author' => $other_id ] );
		$own_trashed    = self::create_entry( $coverage, [ 'post_author' => $author_id ] );
		wp_trash_post( $others_trashed );
		wp_trash_post( $own_trashed );

		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::REST_BASE );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'status', 'trash' );
		$request->set_param( 'per_page', 100 );

		$response = rest_get_server()->dispatch( $request );
		$body     = $response->get_data();
		$headers  = $response->get_headers();

		$this->assertSame( 200, $response->get_status(), 'The collection should resolve.' );
		$this->assertSame( [ $own_trashed ], wp_list_pluck( $body, 'id' ), "The author should only get their own trashed entry, not another user's." );
		$this->assertSame( count( $body ), (int) $headers['X-WP-Total'], 'X-WP-Total must match the returned body so core-data does not treat it as a failed resolution.' );
	}

	/**
	 * A contributor cannot edit their own published (or published-then-trashed)
	 * entries, so the edit-context collection must exclude them — otherwise
	 * core counts them in X-WP-Total while dropping them from the body and
	 * core-data fails with "Failed to load trashed entries".
	 */
	public function test_edit_context_trash_total_matches_body_for_contributor() {
		$contributor_id = self::log_in_as( 'contributor' );
		$coverage       = self::create_coverage();

		// A draft the contributor can edit, and a published entry an editor
		// later trashed, which the contributor cannot edit.
		$own_draft = self::create_entry(
			$coverage,
			[
				'post_author' => $contributor_id,
				'post_status' => 'draft',
			] 
		);
		$published = self::create_entry(
			$coverage,
			[
				'post_author' => $contributor_id,
				'post_status' => 'publish',
			] 
		);
		wp_trash_post( $own_draft );
		wp_trash_post( $published );

		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::REST_BASE );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'status', 'trash' );
		$request->set_param( 'per_page', 100 );

		$response = rest_get_server()->dispatch( $request );
		$body     = $response->get_data();
		$headers  = $response->get_headers();

		$this->assertSame( 200, $response->get_status(), 'The collection should resolve.' );
		$this->assertSame( [ $own_draft ], wp_list_pluck( $body, 'id' ), 'The contributor should only get the trashed entry they can edit.' );
		$this->assertSame( count( $body ), (int) $headers['X-WP-Total'], 'X-WP-Total must match the returned body so core-data does not treat it as a failed resolution.' );
	}

	/**
	 * Words 1 to N of a sentence, for content longer than any excerpt length used here.
	 *
	 * @param int $count Number of words.
	 * @return string
	 */
	private static function words( $count ) {
		return implode( ' ', array_map( fn( $n ) => "word{$n}", range( 1, $count ) ) );
	}

	/**
	 * Entries have no excerpt of their own, but the editor's Post Excerpt
	 * block reads one from the REST record, so it is generated from the content.
	 */
	public function test_entry_rest_record_has_an_excerpt_generated_from_content() {
		self::log_in_as( 'editor' );
		$entry_id = self::create_entry(
			self::create_coverage(),
			[
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>' . self::words( 80 ) . '</p><!-- /wp:paragraph -->',
			]
		);

		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::REST_BASE . '/' . $entry_id );
		$request->set_param( 'context', 'edit' );
		$excerpt = rest_get_server()->dispatch( $request )->get_data()['excerpt'];

		$this->assertSame( self::words( 80 ), $excerpt['raw'], 'The raw excerpt should be the whole content as plain text.' );
		$this->assertStringContainsString( 'word1 word2', $excerpt['rendered'] );
		$this->assertStringNotContainsString( 'word80', $excerpt['rendered'], 'The rendered excerpt should be trimmed like core.' );
		$this->assertFalse( $excerpt['protected'] );
	}

	/**
	 * The public view carries the rendered excerpt only.
	 */
	public function test_entry_excerpt_is_rendered_in_the_public_view() {
		self::create_entry(
			self::create_active_coverage(),
			[
				'post_excerpt' => '',
				'post_content' => '<p>Short update.</p>',
			] 
		);

		$excerpt = self::list_entries_via_rest()[0]['excerpt'];

		$this->assertStringContainsString( 'Short update.', $excerpt['rendered'] );
		$this->assertArrayNotHasKey( 'raw', $excerpt );
	}

	/**
	 * Entries do not take manual excerpts, so the type must not gain support.
	 */
	public function test_entries_do_not_support_excerpts() {
		$this->assertFalse( post_type_supports( Post_Type::CPT_SLUG, 'excerpt' ) );
	}

	/**
	 * The Post Excerpt block trims an entry to the requested words with an
	 * ellipsis and no more link, whatever the theme adds to excerpts.
	 */
	public function test_post_excerpt_block_trims_an_entry_without_a_more_link() {
		$entry_id = self::create_entry(
			self::create_active_coverage(),
			[
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>' . self::words( 40 ) . '</p><!-- /wp:paragraph -->',
			]
		);
		add_filter( 'excerpt_more', fn() => ' <a class="more-link" href="#">Read more</a>' );
		$GLOBALS['post'] = get_post( $entry_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$html = do_blocks( '<!-- wp:post-excerpt {"excerptLength":15,"moreText":""} /-->' );
		$text = trim( html_entity_decode( wp_strip_all_tags( $html ) ) );

		$this->assertSame( self::words( 15 ) . '…', $text );
		$this->assertStringNotContainsString( 'more-link', $html );
	}
}
