<?php
/**
 * Tests for changing the author of several entries at once.
 *
 * @package Newspack_Rolling_Coverage
 */

/**
 * The entries list's Reassign drawer can credit one person with every
 * selected entry. It is an editor's job, checked per entry, and keeps
 * Co-Authors Plus in step so the byline and the list agree.
 */
class Test_Entry_Author extends Rolling_Coverage_TestCase {

	/**
	 * The Co-Authors Plus global before a test replaced it.
	 *
	 * @var mixed
	 */
	private $original_coauthors_plus;

	/**
	 * Log in as someone who can edit others' entries.
	 */
	public function set_up() {
		parent::set_up();
		$this->original_coauthors_plus = $GLOBALS['coauthors_plus'] ?? null;
		self::log_in_as( 'editor' );
	}

	/**
	 * Put the Co-Authors Plus global back.
	 */
	public function tear_down() {
		$GLOBALS['coauthors_plus'] = $this->original_coauthors_plus;
		parent::tear_down();
	}

	/**
	 * Every selected entry gets the new author.
	 */
	public function test_changes_the_author_of_every_entry() {
		$coverage_id = self::create_coverage();
		$old_author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$new_author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_ids   = [
			self::create_entry( $coverage_id, [ 'post_author' => $old_author ] ),
			self::create_entry( $coverage_id, [ 'post_author' => $old_author ] ),
		];

		$response = self::dispatch(
			'POST',
			'/entries/details',
			[
				'entry_ids' => $entry_ids,
				'author_id' => $new_author,
			]
		);

		$this->assertSame( 200, $response->get_status(), 'The request should succeed.' );
		$this->assertSame( [ true, true ], wp_list_pluck( $response->get_data()['results'], 'updated' ), 'Both entries should be reported as updated.' );
		foreach ( $entry_ids as $entry_id ) {
			$this->assertSame( $new_author, (int) get_post_field( 'post_author', $entry_id ), 'The entry should have the new author.' );
		}
	}

	/**
	 * Crediting entries to someone else is an editor's job, so an author
	 * can't do it, even to their own entries.
	 */
	public function test_authors_cannot_change_authors() {
		$coverage_id = self::create_coverage();
		$author_id   = self::log_in_as( 'author' );
		$entry_id    = self::create_entry( $coverage_id, [ 'post_author' => $author_id ] );
		$other_id    = self::factory()->user->create( [ 'role' => 'author' ] );

		$response = self::dispatch(
			'POST',
			'/entries/details',
			[
				'entry_ids' => [ $entry_id ],
				'author_id' => $other_id,
			]
		);

		$this->assertSame( 403, $response->get_status(), 'An author should be refused.' );
		$this->assertSame( $author_id, (int) get_post_field( 'post_author', $entry_id ), 'The entry should keep its author.' );
	}

	/**
	 * Only someone who can write entries can be credited with them.
	 */
	public function test_rejects_an_author_who_cannot_write_entries() {
		$coverage_id = self::create_coverage();
		$author_id   = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_id    = self::create_entry( $coverage_id, [ 'post_author' => $author_id ] );
		$subscriber  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$response = self::dispatch(
			'POST',
			'/entries/details',
			[
				'entry_ids' => [ $entry_id ],
				'author_id' => $subscriber,
			]
		);

		$this->assertSame( 400, $response->get_status(), 'A subscriber should be rejected as an author.' );
		$this->assertSame( $author_id, (int) get_post_field( 'post_author', $entry_id ), 'The entry should keep its author.' );
	}

	/**
	 * An archived entry is locked, so its author stays as it was.
	 */
	public function test_skips_archived_entries() {
		$old_author = self::factory()->user->create( [ 'role' => 'author' ] );
		$new_author = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_id   = self::create_entry( self::create_coverage(), [ 'post_author' => $old_author ] );
		update_post_meta( $entry_id, Newspack_Rolling_Coverage\Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );

		$results = self::change_author( $entry_id, $new_author )->get_data()['results'];

		$this->assertFalse( $results[0]['updated'], 'The entry should be reported as failed.' );
		$this->assertSame( $old_author, (int) get_post_field( 'post_author', $entry_id ), 'The entry should keep its author.' );
	}

	/**
	 * Trashed entries and posts that aren't entries are reported as failures
	 * without stopping the rest.
	 */
	public function test_skips_trashed_entries_and_other_posts() {
		$coverage_id = self::create_coverage();
		$old_author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$new_author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_id    = self::create_entry( $coverage_id, [ 'post_author' => $old_author ] );
		$trashed_id  = self::create_entry( $coverage_id, [ 'post_author' => $old_author ] );
		$post_id     = self::factory()->post->create( [ 'post_author' => $old_author ] );
		wp_trash_post( $trashed_id );

		$results = self::dispatch(
			'POST',
			'/entries/details',
			[
				'entry_ids' => [ $entry_id, $trashed_id, $post_id ],
				'author_id' => $new_author,
			]
		)->get_data()['results'];

		$this->assertSame(
			[
				$entry_id   => true,
				$trashed_id => false,
				$post_id    => false,
			],
			wp_list_pluck( $results, 'updated', 'entryId' ),
			'Only the live entry should be updated.'
		);
		$this->assertSame( $old_author, (int) get_post_field( 'post_author', $trashed_id ), 'The trashed entry should keep its author.' );
		$this->assertSame( $old_author, (int) get_post_field( 'post_author', $post_id ), 'The regular post should keep its author.' );
	}

	/**
	 * Stand in for Co-Authors Plus, recording what it's asked to do. Like the
	 * real plugin, add_coauthors() writes `post_author` for the co-author it
	 * finds by nicename, so a wrong lookup would show on the entry.
	 *
	 * @param bool        $enabled      Whether it's on for entries.
	 * @param bool        $can_set      Whether the current user may set bylines.
	 * @param bool        $sets         What add_coauthors() returns.
	 * @param string|null $guest_holder Nicename a guest author holds, so it is found instead of the user.
	 * @param bool        $guest_linked Whether that guest author is linked to the user with the nicename.
	 * @return object The stand-in, also set as the global.
	 */
	private static function fake_coauthors_plus( $enabled = true, $can_set = true, $sets = true, $guest_holder = null, $guest_linked = false ) {
		$GLOBALS['coauthors_plus'] = new class( $enabled, $can_set, $sets, $guest_holder, $guest_linked ) {
			/**
			 * Calls to add_coauthors(), with the entry's author at the time.
			 *
			 * @var array
			 */
			public $calls = [];

			/**
			 * Set up the answers.
			 *
			 * @param bool        $enabled      Whether it's on for entries.
			 * @param bool        $can_set      Whether the current user may set bylines.
			 * @param bool        $sets         What add_coauthors() returns.
			 * @param string|null $guest_holder Nicename a guest author holds.
			 * @param bool        $guest_linked Whether that guest author is linked to the user.
			 */
			public function __construct( private bool $enabled, private bool $can_set, private bool $sets, private ?string $guest_holder, private bool $guest_linked ) {}

			/**
			 * Finds a co-author by nicename: the guest author holding it, if
			 * any, otherwise the user.
			 *
			 * @param string $key   Field to search by.
			 * @param string $value Value to search for.
			 * @return object|false
			 */
			public function get_coauthor_by( $key, $value ) {
				if ( $value === $this->guest_holder ) {
					$guest_author = (object) [
						'ID'            => 999999,
						'user_nicename' => $value,
						'type'          => 'guest-author',
					];

					if ( $this->guest_linked ) {
						$guest_author->wp_user = get_user_by( 'slug', $value );
					}

					return $guest_author;
				}

				return get_user_by( 'slug', $value );
			}

			/**
			 * Whether Co-Authors Plus is on for a post type.
			 *
			 * @return bool
			 */
			public function is_post_type_enabled() {
				return $this->enabled;
			}

			/**
			 * Whether the current user may set bylines.
			 *
			 * @return bool
			 */
			public function current_user_can_set_authors() {
				return $this->can_set;
			}

			/**
			 * Records the co-authors set on a post.
			 *
			 * @param int   $post_id   Post ID.
			 * @param array $coauthors Co-author nicenames.
			 * @return bool
			 */
			public function add_coauthors( $post_id, $coauthors ) {
				global $wpdb;

				$this->calls[] = [ $post_id, $coauthors, (int) get_post_field( 'post_author', $post_id ) ];

				if ( ! $this->sets ) {
					return false;
				}

				$coauthor  = $this->get_coauthor_by( 'user_nicename', $coauthors[0] );
				$author_id = isset( $coauthor->wp_user ) ? $coauthor->wp_user->ID : $coauthor->ID;
				$wpdb->update( $wpdb->posts, [ 'post_author' => (int) $author_id ], [ 'ID' => $post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $post_id );

				return true;
			}
		};

		return $GLOBALS['coauthors_plus'];
	}

	/**
	 * Change one entry's author and return the response.
	 *
	 * @param int $entry_id  Entry post ID.
	 * @param int $author_id New author's user ID.
	 * @return WP_REST_Response
	 */
	private static function change_author( $entry_id, $author_id ) {
		return self::dispatch(
			'POST',
			'/entries/details',
			[
				'entry_ids' => [ $entry_id ],
				'author_id' => $author_id,
			]
		);
	}

	/**
	 * With Co-Authors Plus on for entries, the new author becomes the only
	 * co-author, set before `post_author` so its re-read keeps the new one.
	 */
	public function test_replaces_coauthors_when_coauthors_plus_is_on() {
		$old_author     = self::factory()->user->create( [ 'role' => 'author' ] );
		$new_author     = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_id       = self::create_entry( self::create_coverage(), [ 'post_author' => $old_author ] );
		$coauthors_plus = self::fake_coauthors_plus();

		self::change_author( $entry_id, $new_author );

		$this->assertSame(
			[ [ $entry_id, [ get_userdata( $new_author )->user_nicename ], $old_author ] ],
			$coauthors_plus->calls,
			'The new author should replace the co-authors before post_author changes.'
		);
		$this->assertSame( $new_author, (int) get_post_field( 'post_author', $entry_id ), 'The entry should have the new author.' );
	}

	/**
	 * Co-Authors Plus is left alone when it isn't on for entries.
	 */
	public function test_leaves_coauthors_plus_alone_when_it_is_off_for_entries() {
		$new_author     = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_id       = self::create_entry( self::create_coverage() );
		$coauthors_plus = self::fake_coauthors_plus( false );

		self::change_author( $entry_id, $new_author );

		$this->assertSame( [], $coauthors_plus->calls, 'Co-Authors Plus should not be asked to set co-authors.' );
		$this->assertSame( $new_author, (int) get_post_field( 'post_author', $entry_id ), 'The entry should still get the new author.' );
	}

	/**
	 * A site can limit who sets bylines through Co-Authors Plus, and that
	 * holds here too.
	 */
	public function test_respects_who_coauthors_plus_lets_set_bylines() {
		$old_author = self::factory()->user->create( [ 'role' => 'author' ] );
		$new_author = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_id   = self::create_entry( self::create_coverage(), [ 'post_author' => $old_author ] );
		self::fake_coauthors_plus( true, false );

		$response = self::change_author( $entry_id, $new_author );

		$this->assertSame( 403, $response->get_status(), 'The editor should be refused.' );
		$this->assertSame( $old_author, (int) get_post_field( 'post_author', $entry_id ), 'The entry should keep its author.' );
	}

	/**
	 * When Co-Authors Plus can't set the byline, `post_author` stays put so
	 * the two don't disagree, and the entry is reported as failed.
	 */
	public function test_keeps_the_author_when_coauthors_plus_cannot_set_it() {
		$old_author = self::factory()->user->create( [ 'role' => 'author' ] );
		$new_author = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_id   = self::create_entry( self::create_coverage(), [ 'post_author' => $old_author ] );
		self::fake_coauthors_plus( true, true, false );

		$results = self::change_author( $entry_id, $new_author )->get_data()['results'];

		$this->assertFalse( $results[0]['updated'], 'The entry should be reported as failed.' );
		$this->assertSame( $old_author, (int) get_post_field( 'post_author', $entry_id ), 'The entry should keep its author.' );
	}

	/**
	 * Permission is checked per entry, so an entry the editor can't edit is
	 * reported as failed and left alone.
	 */
	public function test_skips_entries_the_user_cannot_edit() {
		$old_author = self::factory()->user->create( [ 'role' => 'author' ] );
		$new_author = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_id   = self::create_entry( self::create_coverage(), [ 'post_author' => $old_author ] );
		$deny       = function ( $caps, $cap, $user_id, $args ) use ( $entry_id ) {
			return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $entry_id ? [ 'do_not_allow' ] : $caps;
		};

		add_filter( 'map_meta_cap', $deny, 10, 4 );
		try {
			$results = self::change_author( $entry_id, $new_author )->get_data()['results'];
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}

		$this->assertFalse( $results[0]['updated'], 'The entry should be reported as failed.' );
		$this->assertSame( $old_author, (int) get_post_field( 'post_author', $entry_id ), 'The entry should keep its author.' );
	}

	/**
	 * Changing the author leaves the content alone, even when the editor
	 * can't post the HTML or block CSS it holds.
	 */
	public function test_keeps_html_the_editor_cannot_post() {
		self::log_in_as( 'administrator' );
		kses_init();
		$entry_id   = self::create_entry( self::create_coverage(), [ 'post_content' => '<!-- wp:html --><iframe src="https://example.test/embed"></iframe><!-- /wp:html --><!-- wp:paragraph {"style":{"css":"color:red"}} --><p>Styled</p><!-- /wp:paragraph -->' ] );
		$content    = get_post_field( 'post_content', $entry_id );
		$new_author = self::factory()->user->create( [ 'role' => 'author' ] );
		$deny       = function ( $caps, $cap ) {
			return in_array( $cap, [ 'unfiltered_html', 'edit_css' ], true ) ? [ 'do_not_allow' ] : $caps;
		};

		self::log_in_as( 'editor' );
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		kses_init();

		try {
			self::change_author( $entry_id, $new_author );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
			kses_init();
		}

		clean_post_cache( $entry_id );
		$this->assertStringContainsString( '<iframe', $content, 'The administrator should be able to post the iframe.' );
		$this->assertSame( $new_author, (int) get_post_field( 'post_author', $entry_id ), 'The entry should have the new author.' );
		$this->assertSame( $content, get_post_field( 'post_content', $entry_id ), 'The content should be kept as stored.' );
	}

	/**
	 * When the new author's nicename leads Co-Authors Plus to a guest author
	 * instead, nothing is written, so the entry is never credited to the
	 * guest author.
	 */
	public function test_refuses_a_nicename_that_leads_to_a_guest_author() {
		$old_author     = self::factory()->user->create( [ 'role' => 'author' ] );
		$new_author     = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_id       = self::create_entry( self::create_coverage(), [ 'post_author' => $old_author ] );
		$coauthors_plus = self::fake_coauthors_plus( true, true, true, get_userdata( $new_author )->user_nicename );

		$results = self::change_author( $entry_id, $new_author )->get_data()['results'];

		$this->assertFalse( $results[0]['updated'], 'The entry should be reported as failed.' );
		$this->assertSame( [], $coauthors_plus->calls, 'Co-Authors Plus should not be asked to set co-authors.' );
		$this->assertSame( $old_author, (int) get_post_field( 'post_author', $entry_id ), 'The entry should keep its author.' );
	}

	/**
	 * A guest author linked to the new author's account leads back to that
	 * user, so the entry is credited as asked.
	 */
	public function test_accepts_a_guest_author_linked_to_the_user() {
		$old_author     = self::factory()->user->create( [ 'role' => 'author' ] );
		$new_author     = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_id       = self::create_entry( self::create_coverage(), [ 'post_author' => $old_author ] );
		$coauthors_plus = self::fake_coauthors_plus( true, true, true, get_userdata( $new_author )->user_nicename, true );

		$results = self::change_author( $entry_id, $new_author )->get_data()['results'];

		$this->assertTrue( $results[0]['updated'], 'The entry should be reported as updated.' );
		$this->assertCount( 1, $coauthors_plus->calls, 'Co-Authors Plus should be asked to set the co-author.' );
		$this->assertSame( $new_author, (int) get_post_field( 'post_author', $entry_id ), 'The entry should have the new author.' );
	}

	/**
	 * A term created for the request is deleted again when every entry fails
	 * to save, so it doesn't linger unused.
	 */
	public function test_deletes_a_created_term_when_every_entry_fails() {
		$new_author = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_id   = self::create_entry( self::create_coverage() );
		self::fake_coauthors_plus( true, true, false );

		$results = self::dispatch(
			'POST',
			'/entries/details',
			[
				'entry_ids' => [ $entry_id ],
				'author_id' => $new_author,
				'tags'      => [ 'names' => [ 'Recount' ] ],
			]
		)->get_data()['results'];

		$this->assertFalse( $results[0]['updated'], 'The entry should be reported as failed.' );
		$this->assertFalse( get_term_by( 'name', 'Recount', 'post_tag' ), 'The tag created for the request should be deleted.' );
	}
}
