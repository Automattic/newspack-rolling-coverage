<?php
/**
 * Tests for when an entry's own permalink answers.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Trashing a coverage leaves its entries published, so the entry's permalink,
 * listings of entries, and what other sites read from the permalink (oEmbed
 * data, Yoast SEO's head) check the coverage too, as the feed and the entries
 * REST route do.
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
	 * Listings of entries, alone or mixed with other post types, leave the
	 * hidden ones out and count only what they show, so their pages and feeds
	 * carry no hidden text.
	 */
	public function test_listings_leave_out_entries_hidden_by_coverage() {
		$visible_entry_id = self::create_entry( self::create_coverage() );
		$post_id          = self::factory()->post->create();
		self::create_entry( self::create_coverage( 'trash' ) );

		$this->go_to( add_query_arg( 'post_type', Post_Type::CPT_SLUG, home_url( '/' ) ) );

		$this->assertSame( [ $visible_entry_id ], wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertSame( 1, $GLOBALS['wp_query']->found_posts );

		$this->go_to(
			add_query_arg(
				[
					'post_type' => [ 'post', Post_Type::CPT_SLUG ],
					'feed'      => 'rss2',
				],
				home_url( '/' )
			)
		);

		$this->assertEqualsCanonicalizing( [ $visible_entry_id, $post_id ], wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	/**
	 * Yoast SEO's public head route is not found for a hidden entry's
	 * permalink. The tests don't load Yoast, so a stand-in route answers for
	 * it.
	 */
	public function test_seo_head_route_is_not_found_for_hidden_entries() {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'yoast/v1',
					'/get_head',
					[
						'methods'             => 'GET',
						'callback'            => static fn() => new WP_REST_Response( [ 'head' => '<title>Entry</title>' ] ),
						'permission_callback' => '__return_true',
					]
				);
			}
		);
		$GLOBALS['wp_rest_server'] = null;

		$get_head = static function ( int $entry_id ): int {
			$request = new WP_REST_Request( 'GET', '/yoast/v1/get_head' );
			$request->set_query_params( [ 'url' => get_permalink( $entry_id ) ] );

			return rest_get_server()->dispatch( $request )->get_status();
		};

		try {
			$this->assertSame( 200, $get_head( self::create_entry( self::create_coverage() ) ) );
			$this->assertSame( 404, $get_head( self::create_entry( self::create_coverage( 'trash' ) ) ) );
		} finally {
			$GLOBALS['wp_rest_server'] = null;
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
