<?php
/**
 * Tests for where the entry editor sends the user when they leave it.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Admin;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Entries are managed in the plugin's own screen, so the editor's back
 * button and its trash redirect must not land on the entries list core hides.
 */
class Test_Entry_Editor_Navigation extends Rolling_Coverage_TestCase {

	/**
	 * The editor script appends `/<id>` to this URL, so it must end at the
	 * coverages route and nowhere else.
	 */
	public function test_coverages_url_ends_at_the_coverages_route() {
		$this->assertSame( admin_url( 'admin.php?page=rolling-coverage' ) . '#/coverages', Admin::get_coverages_url() );
		$this->assertSame( 1, substr_count( Admin::get_coverages_url(), '#' ), 'The URL should carry a single hash.' );
	}

	/**
	 * The editor's back-link redirect is wired to the hidden list's screen
	 * load, and the redirect is registered by Admin::init().
	 */
	public function test_redirect_is_hooked_to_the_entries_list() {
		Admin::init();

		$this->assertNotFalse( has_action( 'load-edit.php', [ Admin::class, 'redirect_entry_list' ] ) );
		$this->assertNotFalse( has_action( 'enqueue_block_editor_assets', [ Admin::class, 'enqueue_entry_editor' ] ) );
	}

	/**
	 * Only GET requests are redirected: a POST to the list is left to core.
	 * The test stops before any redirect, which would exit the process.
	 */
	public function test_redirect_ignores_non_get_requests() {
		$method                    = $_SERVER['REQUEST_METHOD'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Restored as-is.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET                      = [ 'post_type' => Post_Type::CPT_SLUG ];

		Admin::redirect_entry_list();

		$this->assertTrue( true, 'A POST should return without redirecting.' );

		$_GET = [];
		if ( null === $method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $method;
		}
	}

	/**
	 * After a trash the redirect flags it, before the hash, so the screen
	 * can confirm it.
	 */
	public function test_trash_redirect_flags_the_trash() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		wp_trash_post( $entry_id );

		$this->assertSame(
			admin_url( 'admin.php?page=rolling-coverage&trashed=1' ) . '#/coverages/' . $coverage_id,
			Admin::get_entry_list_redirect(
				[
					'post_type' => Post_Type::CPT_SLUG,
					'trashed'   => '1',
					'ids'       => (string) $entry_id,
				]
			)
		);
		$this->assertStringNotContainsString( 'trashed', Admin::get_entry_return_url( $entry_id ), 'A plain return should not flag a trash.' );
	}

	/**
	 * An entry in a coverage returns to that coverage's entries.
	 */
	public function test_entry_returns_to_its_coverage() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );

		$this->assertSame(
			admin_url( 'admin.php?page=rolling-coverage' ) . '#/coverages/' . $coverage_id,
			Admin::get_entry_return_url( $entry_id )
		);
	}

	/**
	 * An entry in no coverage returns to All Coverages.
	 */
	public function test_entry_without_coverage_returns_to_all_coverages() {
		$entry_id = self::create_entry();

		$this->assertSame(
			admin_url( 'admin.php?page=rolling-coverage' ) . '#/coverages',
			Admin::get_entry_return_url( $entry_id )
		);
	}

	/**
	 * An entry in several coverages returns to the first one, which is the
	 * first by name, as WordPress lists an entry's terms.
	 */
	public function test_entry_in_several_coverages_returns_to_the_first() {
		$second_id = self::create_coverage( '', [ 'name' => 'B Coverage' ] );
		$first_id  = self::create_coverage( '', [ 'name' => 'A Coverage' ] );
		$entry_id  = self::create_entry();
		wp_set_object_terms( $entry_id, [ $second_id, $first_id ], Taxonomy::TAXONOMY_SLUG );

		$this->assertStringEndsWith( '#/coverages/' . $first_id, Admin::get_entry_return_url( $entry_id ) );
	}

	/**
	 * The hidden list redirects to All Coverages when no entry is known.
	 */
	public function test_hidden_list_redirects_to_all_coverages() {
		$this->assertSame(
			Admin::get_coverages_url(),
			Admin::get_entry_list_redirect( [ 'post_type' => Post_Type::CPT_SLUG ] )
		);
	}

	/**
	 * After trashing from the editor, WordPress passes the trashed ID, so the
	 * redirect lands on that entry's coverage. A trashed entry keeps its terms.
	 */
	public function test_trash_redirect_returns_to_the_trashed_entrys_coverage() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		wp_trash_post( $entry_id );

		$this->assertSame(
			Admin::get_coverages_url( $coverage_id ),
			Admin::get_entry_list_redirect(
				[
					'post_type' => Post_Type::CPT_SLUG,
					'trashed'   => '1',
					'ids'       => (string) $entry_id,
				]
			)
		);
	}

	/**
	 * List actions and other post types are left alone.
	 */
	public function test_other_requests_are_not_redirected() {
		$this->assertNull( Admin::get_entry_list_redirect( [ 'post_type' => 'post' ] ), 'Other post types should keep their list.' );
		$this->assertNull( Admin::get_entry_list_redirect( [] ), 'A request with no post type should be left alone.' );
		$this->assertNull(
			Admin::get_entry_list_redirect(
				[
					'post_type' => Post_Type::CPT_SLUG,
					'action'    => 'trash',
				]
			),
			'A list action should not be redirected.'
		);
		$this->assertSame(
			Admin::get_coverages_url(),
			Admin::get_entry_list_redirect(
				[
					'post_type' => Post_Type::CPT_SLUG,
					'action'    => '-1',
				]
			),
			'The "no action" sentinel should not count as an action.'
		);
	}
}
