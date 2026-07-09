<?php
/**
 * Register the rolling_coverage taxonomy.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration of the rolling_coverage taxonomy and its termmeta.
 */
class Taxonomy {

	// Config constants.
	const TAXONOMY_SLUG   = 'rolling_coverage';
	const REST_BASE       = 'rolling-coverage';

	// Related constants.
	const STATUS_META_KEY = 'rolling_coverage_status';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'created_' . self::TAXONOMY_SLUG, [ __CLASS__, 'set_term_created_date' ] );
		add_action( 'edited_' . self::TAXONOMY_SLUG, [ __CLASS__, 'update_term_modified_date' ] );
		add_filter( 'update_post_term_count_statuses', [ __CLASS__, 'count_all_visible_statuses' ], 10, 2 );
	}

	/**
	 * Register the taxonomy, termmeta and any other taxonomy-related functionality.
	 */
	public static function register() {
		register_taxonomy(
			self::TAXONOMY_SLUG,
			Post_Type::CPT_SLUG,
			[
				'labels'             => [
					'name'          => __( 'Rolling Coverage', 'newspack-rolling-coverage' ),
					'singular_name' => __( 'Rolling Coverage', 'newspack-rolling-coverage' ),
				],
				'public'             => true,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
				'rest_base'          => self::REST_BASE,
				'hierarchical'       => false,
				'rewrite'            => false,
				'query_var'          => true,
				'meta_box_cb'        => false,
			]
		);

		// Tracks the status of the blogs (active, paused, archived).
		register_term_meta(
			self::TAXONOMY_SLUG,
			self::STATUS_META_KEY,
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => 'active',
			]
		);

		// Created date stored as ISO 8601 string.
		register_term_meta(
			self::TAXONOMY_SLUG,
			'created_at',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => '',
			]
		);

		// Modified date stored as ISO 8601 string.
		register_term_meta(
			self::TAXONOMY_SLUG,
			'modified_at',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => '',
			]
		);
	}

	/**
	 * Set created_at when a term is first created.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function set_term_created_date( $term_id ) {
		$created = get_term_meta( $term_id, 'created_at', true );
		if ( empty( $created ) ) {
			$now = gmdate( 'c' );
			update_term_meta( $term_id, 'created_at', $now );
			update_term_meta( $term_id, 'modified_at', $now );
		}
	}

	/**
	 * Update modified_at when a term is edited.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function update_term_modified_date( $term_id ) {
		update_term_meta( $term_id, 'modified_at', gmdate( 'c' ) );
	}

	/**
	 * Include all visible post statuses in term counts for this taxonomy.
	 *
	 * WordPress core's _update_post_term_count() only counts published posts.
	 * This plugin's admin UI shows entries of all statuses (draft, pending,
	 * private, etc.) so the count should reflect that.
	 *
	 * @param string[]    $post_statuses List of post statuses to include in the count.
	 * @param WP_Taxonomy $taxonomy      Current taxonomy object.
	 * @return string[] Filtered list of post statuses.
	 */
	public static function count_all_visible_statuses( $post_statuses, $taxonomy ) {
		if ( self::TAXONOMY_SLUG !== $taxonomy->name ) {
			return $post_statuses;
		}

		return [ 'publish', 'draft', 'pending', 'future', 'private' ];
	}
}
