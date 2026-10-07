<?php
/**
 * Tests for editing the categories and tags of entries from the entries list.
 *
 * @package Newspack_Rolling_Coverage
 */

/**
 * The entries list's Edit Details drawer sets the author, categories and
 * tags of one or more entries. With one entry the terms sent replace its
 * own; with several they are added to each. Only the details named change.
 * The author side is covered by Test_Entry_Author.
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
	 * Send an Edit Details request.
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
	 * sets them is refused.
	 */
	public function test_refuses_terms_the_user_cannot_assign() {
		$tag      = self::factory()->tag->create( [ 'name' => 'Election' ] );
		$entry_id = self::create_entry( self::create_coverage() );
		$deny     = function ( $caps, $cap ) {
			return 'assign_post_tags' === $cap ? [ 'do_not_allow' ] : $caps;
		};

		add_filter( 'map_meta_cap', $deny, 10, 2 );
		try {
			$response = self::edit_details( [ $entry_id ], [ 'tags' => [ 'ids' => [ $tag ] ] ] );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}

		$this->assertSame( 403, $response->get_status(), 'The request should be refused.' );
		$this->assertSame( [], self::term_ids( $entry_id, 'post_tag' ), 'The entry should get no tag.' );
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
}
