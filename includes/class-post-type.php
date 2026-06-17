<?php
/**
 * Register the rolling_coverage_entry custom post type.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use Newspack_Rolling_Coverage\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration of the rolling_coverage custom post type.
 */
class Post_Type {

	const CPT_SLUG  = 'rolling_coverage_ent'; // allows 20 characters max i.e. current length.
	const REST_BASE = 'rolling-coverage-entries';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'pre_delete_term', [ __CLASS__, 'delete_term_entries' ], 10, 2 );
	}

	/**
	 * Register the custom post type and other related functionality.
	 */
	public static function register() {
		register_post_type(
			self::CPT_SLUG,
			[
				'labels'              => [
					'name'          => __( 'Entries', 'newspack-rolling-coverage' ),
					'singular_name' => __( 'Entry', 'newspack-rolling-coverage' ),
					'add_new_item'  => __( 'Add Entry', 'newspack-rolling-coverage' ),
					'edit_item'     => __( 'Edit Entry', 'newspack-rolling-coverage' ),
					'new_item'      => __( 'New Entry', 'newspack-rolling-coverage' ),
					'view_item'     => __( 'View Entry', 'newspack-rolling-coverage' ),
					'search_items'  => __( 'Search Entries', 'newspack-rolling-coverage' ),
					'not_found'     => __( 'No entries found.', 'newspack-rolling-coverage' ),
					'all_items'     => __( 'All Entries', 'newspack-rolling-coverage' ),
				],
				'description'         => __( 'Individual entries within a Rolling Coverage liveblog.', 'newspack-rolling-coverage' ),
				'public'              => false,
				'publicly_queryable'  => true,
				'exclude_from_search' => false,
				'show_in_rest'        => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'query_var'           => true,
				'rest_base'           => self::REST_BASE,
				'supports'            => [ 'title', 'editor', 'author', 'revisions', 'custom-fields' ],
				'taxonomies'          => [
					Taxonomy::TAXONOMY_SLUG,
					'category',
					'post_tag',
				],
				'rewrite'             => false,
				'can_export'          => true,
				'delete_with_user'    => false,
			]
		);
	}

	/**
	 * When a term is deleted, cascade-delete all entries assigned to it.
	 *
	 * @param int    $term_id   The term ID.
	 * @param string $taxonomy  The taxonomy slug.
	 */
	public static function delete_term_entries( $term_id, $taxonomy ) {
		if ( Taxonomy::TAXONOMY_SLUG !== $taxonomy ) {
			return;
		}

		$entry_ids = get_posts(
			[
				'post_type'      => self::CPT_SLUG,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => [
					[
						'taxonomy' => Taxonomy::TAXONOMY_SLUG,
						'field'    => 'term_id',
						'terms'    => $term_id,
					],
				],
			]
		);

		foreach ( $entry_ids as $entry_id ) {
			wp_delete_post( $entry_id, true );
		}
	}
}
