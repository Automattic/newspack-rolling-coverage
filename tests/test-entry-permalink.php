<?php
/**
 * Tests for when an entry's own permalink answers.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Trashing a coverage leaves its entries published, so the entry's permalink
 * and the oEmbed data other sites read from it check the coverage too, as the
 * feed and the entries REST route do.
 */
class Test_Entry_Permalink extends Rolling_Coverage_TestCase {

	/**
	 * An entry whose coverage is trashed or deleted, or that has none, is not
	 * found, for editors as well as readers, as with a post in the trash. The
	 * query keeps nothing a template could render, the embed template included.
	 */
	public function test_permalink_is_not_found_without_a_visible_coverage() {
		$deleted_coverage_id = self::create_coverage();
		$entries             = [
			'a trashed coverage' => self::create_entry( self::create_coverage( 'trash' ) ),
			'a deleted coverage' => self::create_entry( $deleted_coverage_id ),
			'no coverage'        => self::create_entry(),
		];
		wp_delete_term( $deleted_coverage_id, Taxonomy::TAXONOMY_SLUG );

		foreach ( $entries as $case => $entry_id ) {
			$this->go_to( get_permalink( $entry_id ) );

			$this->assertTrue( is_404(), "An entry with {$case} should not be found." );
			$this->assertFalse( have_posts(), "An entry with {$case} should leave nothing to render." );
		}

		self::log_in_as( 'editor' );
		$this->go_to( get_permalink( $entries['a trashed coverage'] ) );

		$this->assertTrue( is_404(), 'Editors should not find an entry of a trashed coverage either.' );
	}

	/**
	 * Every status but trash keeps the entry readable, Ended included, and so
	 * does one other coverage that isn't trashed.
	 */
	public function test_permalink_renders_while_a_coverage_is_visible() {
		$entries = [];

		foreach ( [ '', Taxonomy::STATUS_ACTIVE, Taxonomy::STATUS_PAUSED, Taxonomy::STATUS_ARCHIVED ] as $status ) {
			$entries[ "status '{$status}'" ] = self::create_entry( self::create_coverage( $status ) );
		}

		$entries['one coverage trashed'] = self::create_entry( self::create_coverage( 'trash' ) );
		wp_set_object_terms( $entries['one coverage trashed'], [ self::create_coverage() ], Taxonomy::TAXONOMY_SLUG, true );

		foreach ( $entries as $case => $entry_id ) {
			$this->go_to( get_permalink( $entry_id ) );

			$this->assertTrue( is_singular( Post_Type::CPT_SLUG ), "An entry with {$case} should render." );
			$this->assertFalse( is_404() );
		}
	}

	/**
	 * Other sites embedding the permalink get nothing for an entry of a
	 * trashed coverage, not even its title.
	 */
	public function test_oembed_leaves_out_entries_of_trashed_coverages() {
		$this->assertIsArray( get_oembed_response_data( self::create_entry( self::create_coverage() ), 600 ) );
		$this->assertFalse( get_oembed_response_data( self::create_entry( self::create_coverage( 'trash' ) ), 600 ) );
	}
}
