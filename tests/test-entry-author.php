<?php
/**
 * Tests for changing the author of several entries at once.
 *
 * @package Newspack_Rolling_Coverage
 */

/**
 * The entries list's Change Author action credits one person with every
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
			'/entries/author',
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
			'/entries/author',
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
			'/entries/author',
			[
				'entry_ids' => [ $entry_id ],
				'author_id' => $subscriber,
			]
		);

		$this->assertSame( 400, $response->get_status(), 'A subscriber should be rejected as an author.' );
		$this->assertSame( $author_id, (int) get_post_field( 'post_author', $entry_id ), 'The entry should keep its author.' );
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
			'/entries/author',
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
	 * With Co-Authors Plus on for entries, the new author becomes the only
	 * co-author, set before `post_author` so its re-read keeps the new one.
	 */
	public function test_replaces_coauthors_when_coauthors_plus_is_on() {
		$coverage_id = self::create_coverage();
		$old_author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$new_author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$entry_id    = self::create_entry( $coverage_id, [ 'post_author' => $old_author ] );

		$GLOBALS['coauthors_plus'] = new class() {
			/**
			 * Calls to add_coauthors(), with the entry's author at the time.
			 *
			 * @var array
			 */
			public $calls = [];

			/**
			 * Whether Co-Authors Plus is on for a post type.
			 *
			 * @return bool
			 */
			public function is_post_type_enabled() {
				return true;
			}

			/**
			 * Records the co-authors set on a post.
			 *
			 * @param int   $post_id   Post ID.
			 * @param array $coauthors Co-author nicenames.
			 * @return bool
			 */
			public function add_coauthors( $post_id, $coauthors ) {
				$this->calls[] = [ $post_id, $coauthors, (int) get_post_field( 'post_author', $post_id ) ];
				return true;
			}
		};

		self::dispatch(
			'POST',
			'/entries/author',
			[
				'entry_ids' => [ $entry_id ],
				'author_id' => $new_author,
			]
		);

		$this->assertSame(
			[ [ $entry_id, [ get_userdata( $new_author )->user_nicename ], $old_author ] ],
			$GLOBALS['coauthors_plus']->calls,
			'The new author should replace the co-authors before post_author changes.'
		);
		$this->assertSame( $new_author, (int) get_post_field( 'post_author', $entry_id ), 'The entry should have the new author.' );
	}
}
