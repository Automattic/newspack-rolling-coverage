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
