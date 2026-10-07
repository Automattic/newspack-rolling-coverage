<?php
/**
 * Tests for reassigning entries from the entries list.
 *
 * @package Newspack_Rolling_Coverage
 */

/**
 * The entries list's Reassign drawer sets the author, categories and tags
 * of one or more entries, and the slug and date of a single entry. With one
 * entry the terms sent replace its own; with several they are added to
 * each. Only the details named change. The author side is covered by
 * Test_Entry_Author.
 */
class Test_Entry_Details extends Rolling_Coverage_TestCase {

	/**
	 * Log in as someone who can edit others' entries.
	 */
	public function set_up() {
		parent::set_up();
		self::log_in_as( 'editor' );
	}

	/**
	 * Send a Reassign request.
	 *
	 * @param int[] $entry_ids Entry post IDs.
	 * @param array $details   Other request parameters.
	 * @return WP_REST_Response
	 */
	private static function edit_details( array $entry_ids, array $details ) {
		return self::dispatch( 'POST', '/entries/details', [ 'entry_ids' => $entry_ids ] + $details );
	}

	/**
	 * An entry's term IDs in a taxonomy.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return int[]
	 */
	private static function term_ids( $entry_id, $taxonomy ) {
		return array_map( 'intval', wp_get_post_terms( $entry_id, $taxonomy, [ 'fields' => 'ids' ] ) );
	}

	/**
	 * Without `append`, the terms sent become the entry's terms, so a term
	 * left out is removed and an empty list clears the taxonomy.
	 */
	public function test_replaces_the_terms_of_an_entry() {
		$news     = self::factory()->category->create( [ 'name' => 'News' ] );
		$sport    = self::factory()->category->create( [ 'name' => 'Sport' ] );
		$tag      = self::factory()->tag->create( [ 'name' => 'Election' ] );
		$entry_id = self::create_entry( self::create_coverage() );
		wp_set_post_terms( $entry_id, [ $news ], 'category' );
		wp_set_post_terms( $entry_id, [ $tag ], 'post_tag' );

		$response = self::edit_details(
			[ $entry_id ],
			[
				'categories' => [ 'ids' => [ $sport ] ],
				'tags'       => [ 'ids' => [] ],
			]
		);

		$this->assertSame( 200, $response->get_status(), 'The request should succeed.' );
		$this->assertTrue( $response->get_data()['results'][0]['updated'], 'The entry should be reported as updated.' );
		$this->assertSame( [ $sport ], self::term_ids( $entry_id, 'category' ), 'The categories sent should replace the old ones.' );
		$this->assertSame( [], self::term_ids( $entry_id, 'post_tag' ), 'An empty list should remove every tag.' );
	}

	/**
	 * With `append`, the terms sent are added to each entry's own, and
	 * nothing is removed.
	 */
	public function test_adds_terms_to_every_entry_when_appending() {
		$news        = self::factory()->category->create( [ 'name' => 'News' ] );
		$sport       = self::factory()->category->create( [ 'name' => 'Sport' ] );
		$live        = self::factory()->category->create( [ 'name' => 'Live' ] );
		$coverage_id = self::create_coverage();
		$first       = self::create_entry( $coverage_id );
		$second      = self::create_entry( $coverage_id );
		wp_set_post_terms( $first, [ $news ], 'category' );
		wp_set_post_terms( $second, [ $sport ], 'category' );

		$results = self::edit_details(
			[ $first, $second ],
			[
				'categories' => [ 'ids' => [ $live ] ],
				'append'     => true,
			]
		)->get_data()['results'];

		$this->assertSame( [ true, true ], wp_list_pluck( $results, 'updated' ), 'Both entries should be reported as updated.' );
		$this->assertEqualSets( [ $news, $live ], self::term_ids( $first, 'category' ), 'The first entry should keep its category and gain the new one.' );
		$this->assertEqualSets( [ $sport, $live ], self::term_ids( $second, 'category' ), 'The second entry should keep its category and gain the new one.' );
	}

	/**
	 * Details the request leaves out stay as they were.
	 */
	public function test_only_changes_the_details_sent() {
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$news      = self::factory()->category->create( [ 'name' => 'News' ] );
		$tag       = self::factory()->tag->create( [ 'name' => 'Election' ] );
		$entry_id  = self::create_entry( self::create_coverage(), [ 'post_author' => $author_id ] );
		wp_set_post_terms( $entry_id, [ $news ], 'category' );

		self::edit_details( [ $entry_id ], [ 'tags' => [ 'ids' => [ $tag ] ] ] );

		$this->assertSame( [ $tag ], self::term_ids( $entry_id, 'post_tag' ), 'The tag should be set.' );
		$this->assertSame( [ $news ], self::term_ids( $entry_id, 'category' ), 'The categories should be left alone.' );
		$this->assertSame( $author_id, (int) get_post_field( 'post_author', $entry_id ), 'The author should be left alone.' );
	}

	/**
	 * A request that names nothing to change is rejected.
	 */
	public function test_rejects_a_request_with_nothing_to_change() {
		$entry_id = self::create_entry( self::create_coverage() );

		$response = self::edit_details( [ $entry_id ], [] );

		$this->assertSame( 400, $response->get_status(), 'An empty change should be rejected.' );
	}

	/**
	 * A named term that doesn't exist yet is created; one that does is used,
	 * not duplicated.
	 */
	public function test_creates_named_terms_that_do_not_exist() {
		$existing = self::factory()->tag->create( [ 'name' => 'Election' ] );
		$entry_id = self::create_entry( self::create_coverage() );

		$response = self::edit_details(
			[ $entry_id ],
			[ 'tags' => [ 'names' => [ 'Election', 'Recount' ] ] ]
		);

		$created = get_term_by( 'name', 'Recount', 'post_tag' );

		$this->assertSame( 200, $response->get_status(), 'The request should succeed.' );
		$this->assertInstanceOf( WP_Term::class, $created, 'The new tag should be created.' );
		$this->assertEqualSets( [ $existing, $created->term_id ], self::term_ids( $entry_id, 'post_tag' ), 'The entry should get the existing tag and the new one.' );
		$this->assertCount(
			1,
			get_terms(
				[
					'taxonomy'   => 'post_tag',
					'name'       => 'Election',
					'hide_empty' => false,
				]
			),
			'The existing tag should not be duplicated.'
		);
	}

	/**
	 * Creating terms follows core's rule: anyone who can assign tags can
	 * create them, but categories need `manage_categories`. An author can
	 * still pick existing categories.
	 */
	public function test_authors_can_create_tags_but_not_categories() {
		$author_id = self::log_in_as( 'author' );
		$news      = self::factory()->category->create( [ 'name' => 'News' ] );
		$entry_id  = self::create_entry( self::create_coverage(), [ 'post_author' => $author_id ] );

		$refused = self::edit_details( [ $entry_id ], [ 'categories' => [ 'names' => [ 'Opinion' ] ] ] );
		$picked  = self::edit_details( [ $entry_id ], [ 'categories' => [ 'ids' => [ $news ] ] ] );
		$tagged  = self::edit_details( [ $entry_id ], [ 'tags' => [ 'names' => [ 'Recount' ] ] ] );

		$this->assertSame( 403, $refused->get_status(), 'An author should not create a category.' );
		$this->assertFalse( get_term_by( 'name', 'Opinion', 'category' ), 'No category should be created.' );
		$this->assertSame( 200, $picked->get_status(), 'An author should be able to pick an existing category.' );
		$this->assertSame( [ $news ], self::term_ids( $entry_id, 'category' ), 'The existing category should be set.' );
		$this->assertSame( 200, $tagged->get_status(), 'An author should be able to create a tag.' );
		$this->assertInstanceOf( WP_Term::class, get_term_by( 'name', 'Recount', 'post_tag' ), 'The tag should be created.' );
	}

	/**
	 * Setting terms only needs the right to assign them, so an author can
	 * set the tags of their own entry even though they can't change authors.
	 */
	public function test_terms_alone_do_not_need_the_author_permission() {
		$author_id = self::log_in_as( 'author' );
		$tag       = self::factory()->tag->create( [ 'name' => 'Election' ] );
		$entry_id  = self::create_entry( self::create_coverage(), [ 'post_author' => $author_id ] );

		$response = self::edit_details( [ $entry_id ], [ 'tags' => [ 'ids' => [ $tag ] ] ] );

		$this->assertSame( 200, $response->get_status(), 'The request should succeed.' );
		$this->assertSame( [ $tag ], self::term_ids( $entry_id, 'post_tag' ), 'The tag should be set.' );
	}

	/**
	 * Without the capability to assign a taxonomy's terms, a request that
	 * sets them is refused before anything changes: the entry keeps its
	 * tags, and a tag named in the request isn't created.
	 */
	public function test_refuses_terms_the_user_cannot_assign() {
		$kept     = self::factory()->tag->create( [ 'name' => 'Polls' ] );
		$tag      = self::factory()->tag->create( [ 'name' => 'Election' ] );
		$entry_id = self::create_entry( self::create_coverage() );
		wp_set_post_terms( $entry_id, [ $kept ], 'post_tag' );
		$deny = function ( $caps, $cap ) {
			return 'assign_post_tags' === $cap ? [ 'do_not_allow' ] : $caps;
		};

		add_filter( 'map_meta_cap', $deny, 10, 2 );
		try {
			$response = self::edit_details(
				[ $entry_id ],
				[
					'tags' => [
						'ids'   => [ $tag ],
						'names' => [ 'Recount' ],
					],
				]
			);
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}

		$this->assertSame( 403, $response->get_status(), 'The request should be refused.' );
		$this->assertSame( [ $kept ], self::term_ids( $entry_id, 'post_tag' ), 'The entry should keep its tags.' );
		$this->assertFalse( get_term_by( 'name', 'Recount', 'post_tag' ), 'No tag should be created.' );
	}

	/**
	 * Each term is checked with `assign_term`, as core's REST API checks it,
	 * so a site can keep one term from being assigned.
	 */
	public function test_refuses_a_term_the_user_cannot_assign() {
		$allowed  = self::factory()->category->create( [ 'name' => 'News' ] );
		$guarded  = self::factory()->category->create( [ 'name' => 'Sponsored' ] );
		$entry_id = self::create_entry( self::create_coverage() );
		$deny     = function ( $caps, $cap, $user_id, $args ) use ( $guarded ) {
			return 'assign_term' === $cap && (int) ( $args[0] ?? 0 ) === $guarded ? [ 'do_not_allow' ] : $caps;
		};

		add_filter( 'map_meta_cap', $deny, 10, 4 );
		try {
			$refused = self::edit_details( [ $entry_id ], [ 'categories' => [ 'ids' => [ $allowed, $guarded ] ] ] );
			$allowed_only = self::edit_details( [ $entry_id ], [ 'categories' => [ 'ids' => [ $allowed ] ] ] );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}

		$this->assertSame( 403, $refused->get_status(), 'A request with the guarded category should be refused.' );
		$this->assertSame( 200, $allowed_only->get_status(), 'A request without it should succeed.' );
		$this->assertSame( [ $allowed ], self::term_ids( $entry_id, 'category' ), 'Only the allowed category should be set.' );
	}

	/**
	 * Categories with the same name under different parents are told apart
	 * by ID, and a name typed as a new category only matches a top-level
	 * one, so it never lands on a same-named child.
	 */
	public function test_tells_same_name_categories_apart() {
		$sport       = self::factory()->category->create( [ 'name' => 'Sport' ] );
		$news        = self::factory()->category->create( [ 'name' => 'News' ] );
		$sport_local = self::factory()->category->create(
			[
				'name'   => 'Local',
				'parent' => $sport,
			]
		);
		$news_local  = self::factory()->category->create(
			[
				'name'   => 'Local',
				'parent' => $news,
			]
		);
		$entry_id    = self::create_entry( self::create_coverage() );
		wp_set_post_terms( $entry_id, [ $sport_local ], 'category' );

		self::edit_details( [ $entry_id ], [ 'categories' => [ 'ids' => [ $news_local ] ] ] );

		$this->assertSame( [ $news_local ], self::term_ids( $entry_id, 'category' ), 'The category picked by ID should replace the other "Local".' );

		self::edit_details( [ $entry_id ], [ 'categories' => [ 'names' => [ 'Local' ] ] ] );

		$named = get_term( self::term_ids( $entry_id, 'category' )[0], 'category' );

		$this->assertNotContains( $named->term_id, [ $sport_local, $news_local ], 'A typed name should not pick a child category.' );
		$this->assertSame( 'Local', $named->name, 'A new top-level "Local" should be created.' );
		$this->assertSame( 0, (int) $named->parent, 'The new category should be top-level.' );
	}

	/**
	 * A typed name matches a term by its name only, never by a slug that
	 * happens to match, so "Apple" doesn't pick up "Apple Inc.".
	 */
	public function test_matches_typed_names_by_name_not_slug() {
		$apple_inc = self::factory()->tag->create(
			[
				'name' => 'Apple Inc.',
				'slug' => 'apple',
			]
		);
		$entry_id  = self::create_entry( self::create_coverage() );

		self::edit_details( [ $entry_id ], [ 'tags' => [ 'names' => [ 'Apple' ] ] ] );

		$tags = wp_get_post_terms( $entry_id, 'post_tag' );

		$this->assertCount( 1, $tags, 'The entry should get one tag.' );
		$this->assertNotSame( $apple_inc, $tags[0]->term_id, 'The entry should not get "Apple Inc.".' );
		$this->assertSame( 'Apple', $tags[0]->name, 'A tag named "Apple" should be created.' );
	}

	/**
	 * An ID that isn't a term of the taxonomy is rejected before anything
	 * changes, and a term named in the same request isn't created.
	 */
	public function test_rejects_ids_from_another_taxonomy_without_creating_terms() {
		$tag      = self::factory()->tag->create( [ 'name' => 'Election' ] );
		$entry_id = self::create_entry( self::create_coverage() );

		$response = self::edit_details(
			[ $entry_id ],
			[
				'categories' => [ 'ids' => [ $tag ] ],
				'tags'       => [ 'names' => [ 'Recount' ] ],
			]
		);

		$this->assertSame( 400, $response->get_status(), 'A tag sent as a category should be rejected.' );
		$this->assertSame( [], self::term_ids( $entry_id, 'category' ), 'The entry should get no category.' );
		$this->assertFalse( get_term_by( 'name', 'Recount', 'post_tag' ), 'No tag should be created.' );
	}

	/**
	 * Names are sanitized like any term name, and blank ones are ignored.
	 */
	public function test_sanitizes_names() {
		$entry_id = self::create_entry( self::create_coverage() );

		self::edit_details( [ $entry_id ], [ 'tags' => [ 'names' => [ '  <b>Recount</b>  ', '   ' ] ] ] );

		$tags = wp_get_post_terms( $entry_id, 'post_tag' );

		$this->assertCount( 1, $tags, 'Only the non-blank name should become a tag.' );
		$this->assertSame( 'Recount', $tags[0]->name, 'The tag name should be stripped of markup and spaces.' );
	}

	/**
	 * An archived entry is locked, so its terms stay as they were.
	 */
	public function test_skips_archived_entries() {
		$news     = self::factory()->category->create( [ 'name' => 'News' ] );
		$sport    = self::factory()->category->create( [ 'name' => 'Sport' ] );
		$entry_id = self::create_entry( self::create_coverage() );
		wp_set_post_terms( $entry_id, [ $news ], 'category' );
		update_post_meta( $entry_id, Newspack_Rolling_Coverage\Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );

		$results = self::edit_details( [ $entry_id ], [ 'categories' => [ 'ids' => [ $sport ] ] ] )->get_data()['results'];

		$this->assertFalse( $results[0]['updated'], 'The entry should be reported as failed.' );
		$this->assertSame( [ $news ], self::term_ids( $entry_id, 'category' ), 'The entry should keep its category.' );
	}

	/**
	 * Permission is checked per entry: an author can set the terms of their
	 * own entry but not of someone else's in the same request.
	 */
	public function test_skips_entries_the_user_cannot_edit() {
		$author_id   = self::log_in_as( 'author' );
		$other_id    = self::factory()->user->create( [ 'role' => 'author' ] );
		$tag         = self::factory()->tag->create( [ 'name' => 'Election' ] );
		$coverage_id = self::create_coverage();
		$own         = self::create_entry( $coverage_id, [ 'post_author' => $author_id ] );
		$others      = self::create_entry( $coverage_id, [ 'post_author' => $other_id ] );

		$results = self::edit_details(
			[ $own, $others ],
			[
				'tags'   => [ 'ids' => [ $tag ] ],
				'append' => true,
			]
		)->get_data()['results'];

		$this->assertSame(
			[
				$own    => true,
				$others => false,
			],
			wp_list_pluck( $results, 'updated', 'entryId' ),
			'Only the author\'s own entry should be updated.'
		);
		$this->assertSame( [], self::term_ids( $others, 'post_tag' ), 'The other author\'s entry should get no tag.' );
	}

	/**
	 * The slug is sanitized like any post slug, and the result reports the
	 * slug the entry ended up with.
	 */
	public function test_sanitizes_the_slug() {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_title' => 'Polls close' ] );

		$results = self::edit_details( [ $entry_id ], [ 'slug' => 'Polls Close <b>Now</b>!' ] )->get_data()['results'];

		$this->assertTrue( $results[0]['updated'], 'The entry should be reported as updated.' );
		$this->assertSame( 'polls-close-now', get_post_field( 'post_name', $entry_id ), 'The slug should be sanitized.' );
		$this->assertSame( 'polls-close-now', $results[0]['slug'], 'The result should report the stored slug.' );
	}

	/**
	 * A slug another entry already has is made unique, and the result
	 * reports the slug the entry got instead of the one sent.
	 */
	public function test_makes_the_slug_unique() {
		$coverage_id = self::create_coverage();
		$first       = self::create_entry( $coverage_id, [ 'post_name' => 'polls-close' ] );
		$second      = self::create_entry( $coverage_id, [ 'post_name' => 'results' ] );

		$results = self::edit_details( [ $second ], [ 'slug' => 'polls-close' ] )->get_data()['results'];

		$this->assertSame( 'polls-close', get_post_field( 'post_name', $first ), 'The first entry should keep its slug.' );
		$this->assertNotSame( 'polls-close', get_post_field( 'post_name', $second ), 'The second entry should not share the slug.' );
		$this->assertSame( get_post_field( 'post_name', $second ), $results[0]['slug'], 'The result should report the unique slug.' );
	}

	/**
	 * The date sets the entry's local publish date, and its GMT date with it.
	 */
	public function test_sets_the_date() {
		update_option( 'timezone_string', 'Europe/London' );
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_date' => '2026-03-01 09:00:00' ] );

		$results = self::edit_details( [ $entry_id ], [ 'date' => '2026-07-01T08:30:00' ] )->get_data()['results'];

		$this->assertTrue( $results[0]['updated'], 'The entry should be reported as updated.' );
		$this->assertSame( '2026-07-01 08:30:00', get_post_field( 'post_date', $entry_id ), 'The local date should be the one sent.' );
		$this->assertSame( '2026-07-01 07:30:00', get_post_field( 'post_date_gmt', $entry_id ), 'The GMT date should follow the site time zone.' );
		$this->assertSame( 'publish', get_post_status( $entry_id ), 'The entry should stay published.' );
	}

	/**
	 * Moving a published entry into the future would quietly schedule it,
	 * so it is refused, along with the rest of the changes to that entry.
	 */
	public function test_refuses_a_future_date_for_a_published_entry() {
		$tag      = self::factory()->tag->create( [ 'name' => 'Election' ] );
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_date' => '2026-03-01 09:00:00' ] );

		$results = self::edit_details(
			[ $entry_id ],
			[
				'date' => wp_date( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ),
				'tags' => [ 'ids' => [ $tag ] ],
			]
		)->get_data()['results'];

		$this->assertFalse( $results[0]['updated'], 'The entry should be reported as failed.' );
		$this->assertNotEmpty( $results[0]['error'], 'The failure should say why.' );
		$this->assertSame( 'publish', get_post_status( $entry_id ), 'The entry should stay published.' );
		$this->assertSame( '2026-03-01 09:00:00', get_post_field( 'post_date', $entry_id ), 'The entry should keep its date.' );
		$this->assertSame( [], self::term_ids( $entry_id, 'post_tag' ), 'The entry should get no tag.' );
	}

	/**
	 * A draft can take a future date, and stays a draft.
	 */
	public function test_a_draft_can_take_a_future_date() {
		$future   = wp_date( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS );
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_status' => 'draft' ] );

		$results = self::edit_details( [ $entry_id ], [ 'date' => $future ] )->get_data()['results'];

		$this->assertTrue( $results[0]['updated'], 'The entry should be reported as updated.' );
		$this->assertSame( str_replace( 'T', ' ', $future ), get_post_field( 'post_date', $entry_id ), 'The draft should take the date.' );
		$this->assertSame( 'draft', get_post_status( $entry_id ), 'The entry should stay a draft.' );
	}

	/**
	 * The slug and date belong to one entry, so a request that sets them on
	 * several is rejected before anything changes.
	 */
	public function test_rejects_a_slug_or_date_for_several_entries() {
		$coverage_id = self::create_coverage();
		$entry_ids   = [ self::create_entry( $coverage_id ), self::create_entry( $coverage_id ) ];

		$with_slug = self::edit_details( $entry_ids, [ 'slug' => 'polls-close' ] );
		$with_date = self::edit_details( $entry_ids, [ 'date' => '2020-01-01T00:00:00' ] );

		$this->assertSame( 400, $with_slug->get_status(), 'A slug for several entries should be rejected.' );
		$this->assertSame( 400, $with_date->get_status(), 'A date for several entries should be rejected.' );
		foreach ( $entry_ids as $entry_id ) {
			$this->assertNotSame( 'polls-close', get_post_field( 'post_name', $entry_id ), 'No entry should take the slug.' );
			$this->assertNotSame( '2020-01-01 00:00:00', get_post_field( 'post_date', $entry_id ), 'No entry should take the date.' );
		}
	}

	/**
	 * Without `append`, the terms sent replace each selected entry's own.
	 */
	public function test_replaces_the_terms_of_every_entry_without_append() {
		$news        = self::factory()->category->create( [ 'name' => 'News' ] );
		$sport       = self::factory()->category->create( [ 'name' => 'Sport' ] );
		$live        = self::factory()->category->create( [ 'name' => 'Live' ] );
		$coverage_id = self::create_coverage();
		$first       = self::create_entry( $coverage_id );
		$second      = self::create_entry( $coverage_id );
		wp_set_post_terms( $first, [ $news ], 'category' );
		wp_set_post_terms( $second, [ $sport ], 'category' );

		self::edit_details( [ $first, $second ], [ 'categories' => [ 'ids' => [ $live ] ] ] );

		$this->assertSame( [ $live ], self::term_ids( $first, 'category' ), 'The first entry should have only the new category.' );
		$this->assertSame( [ $live ], self::term_ids( $second, 'category' ), 'The second entry should have only the new category.' );
	}

	/**
	 * The result only carries a slug when the request set one.
	 */
	public function test_leaves_the_slug_out_of_the_result_when_not_sent() {
		$tag      = self::factory()->tag->create( [ 'name' => 'Election' ] );
		$entry_id = self::create_entry( self::create_coverage() );

		$results = self::edit_details( [ $entry_id ], [ 'tags' => [ 'ids' => [ $tag ] ] ] )->get_data()['results'];

		$this->assertTrue( $results[0]['updated'], 'The entry should be reported as updated.' );
		$this->assertArrayNotHasKey( 'slug', $results[0], 'The result should carry no slug.' );
	}

	/**
	 * A date that can't be read is rejected.
	 */
	public function test_rejects_an_invalid_date() {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_date' => '2026-03-01 09:00:00' ] );

		$response = self::edit_details( [ $entry_id ], [ 'date' => 'next Tuesday' ] );

		$this->assertSame( 400, $response->get_status(), 'The date should be rejected.' );
		$this->assertSame( '2026-03-01 09:00:00', get_post_field( 'post_date', $entry_id ), 'The entry should keep its date.' );
	}

	/**
	 * WordPress only schedules a post dated a minute or more ahead, so a
	 * published entry may be dated less than that ahead and stays published.
	 */
	public function test_accepts_a_date_less_than_a_minute_ahead_for_a_published_entry() {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_date' => '2026-03-01 09:00:00' ] );
		$soon     = wp_date( 'Y-m-d\TH:i:s', time() + MINUTE_IN_SECONDS - 1 );

		$results = self::edit_details( [ $entry_id ], [ 'date' => $soon ] )->get_data()['results'];

		$this->assertTrue( $results[0]['updated'], 'The entry should be reported as updated.' );
		$this->assertSame( str_replace( 'T', ' ', $soon ), get_post_field( 'post_date', $entry_id ), 'The entry should take the date.' );
		$this->assertSame( 'publish', get_post_status( $entry_id ), 'The entry should stay published.' );
	}

	/**
	 * Moving a scheduled entry into the past publishes it, so it needs the
	 * right to publish that entry.
	 */
	public function test_publishes_a_scheduled_entry_moved_into_the_past_only_with_publish_rights() {
		$coverage_id = self::create_coverage();
		$future_date = wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$allowed     = self::create_entry(
			$coverage_id,
			[
				'post_status' => 'future',
				'post_date'   => $future_date,
			]
		);
		$denied      = self::create_entry(
			$coverage_id,
			[
				'post_status' => 'future',
				'post_date'   => $future_date,
			]
		);
		$deny        = function ( $caps, $cap, $user_id, $args ) use ( $denied ) {
			return 'publish_post' === $cap && (int) ( $args[0] ?? 0 ) === $denied ? [ 'do_not_allow' ] : $caps;
		};

		$published = self::edit_details( [ $allowed ], [ 'date' => '2026-03-01T09:00:00' ] )->get_data()['results'];

		add_filter( 'map_meta_cap', $deny, 10, 4 );
		try {
			$refused = self::edit_details( [ $denied ], [ 'date' => '2026-03-01T09:00:00' ] )->get_data()['results'];
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}

		$this->assertTrue( $published[0]['updated'], 'The entry the editor can publish should be updated.' );
		$this->assertSame( 'publish', get_post_status( $allowed ), 'It should be published.' );
		$this->assertFalse( $refused[0]['updated'], 'The entry the editor can\'t publish should be refused.' );
		$this->assertSame( 'future', get_post_status( $denied ), 'It should stay scheduled.' );
		$this->assertSame( $future_date, get_post_field( 'post_date', $denied ), 'It should keep its date.' );
	}

	/**
	 * Terms named in a request are only created once an entry is going to
	 * get them: not when the only entry is refused for its date.
	 */
	public function test_creates_no_terms_when_the_only_entry_is_refused() {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_date' => '2026-03-01 09:00:00' ] );

		$results = self::edit_details(
			[ $entry_id ],
			[
				'date' => wp_date( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ),
				'tags' => [ 'names' => [ 'Recount' ] ],
			]
		)->get_data()['results'];

		$this->assertFalse( $results[0]['updated'], 'The entry should be refused.' );
		$this->assertFalse( get_term_by( 'name', 'Recount', 'post_tag' ), 'No tag should be created.' );
	}

	/**
	 * Nor are they created when every selected entry is archived.
	 */
	public function test_creates_no_terms_when_every_entry_is_archived() {
		$coverage_id = self::create_coverage();
		$entry_ids   = [ self::create_entry( $coverage_id ), self::create_entry( $coverage_id ) ];
		foreach ( $entry_ids as $entry_id ) {
			update_post_meta( $entry_id, Newspack_Rolling_Coverage\Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );
		}

		$results = self::edit_details(
			$entry_ids,
			[
				'categories' => [ 'names' => [ 'Opinion' ] ],
				'append'     => true,
			]
		)->get_data()['results'];

		$this->assertSame( [ false, false ], wp_list_pluck( $results, 'updated' ), 'Both entries should be refused.' );
		$this->assertFalse( get_term_by( 'name', 'Opinion', 'category' ), 'No category should be created.' );
	}

	/**
	 * An entry locked because its coverage ended says so, rather than
	 * calling the entry archived.
	 */
	public function test_explains_a_lock_from_an_ended_coverage() {
		$tag      = self::factory()->tag->create( [ 'name' => 'Election' ] );
		$entry_id = self::create_entry( self::create_coverage( Newspack_Rolling_Coverage\Taxonomy::STATUS_ARCHIVED ) );

		$results = self::edit_details( [ $entry_id ], [ 'tags' => [ 'ids' => [ $tag ] ] ] )->get_data()['results'];

		$this->assertFalse( $results[0]['updated'], 'The entry should be refused.' );
		$this->assertSame(
			Newspack_Rolling_Coverage\Archive_Mode::coverage_ended_error( 'rolling_coverage_entry_locked' )->get_error_message(),
			$results[0]['error'],
			'The error should say the coverage ended.'
		);
	}
}
