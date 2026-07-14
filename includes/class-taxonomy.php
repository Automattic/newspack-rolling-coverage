<?php
/**
 * Register the rolling_coverage taxonomy.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration of the rolling_coverage taxonomy, its termmeta,
 * and REST endpoints for coverage trash/restore/delete operations.
 */
class Taxonomy {

	const TAXONOMY_SLUG   = 'rolling_coverage';
	const REST_BASE       = 'rolling-coverage';

	/**
	 * Meta key for the coverage status.
	 *
	 * Valid values: 'active', 'paused', 'archived', 'trash'.
	 */
	const STATUS_META_KEY = 'rolling_coverage_status';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
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

		// Tracks the coverage status. Valid values: 'active', 'paused', 'archived', 'trash'.
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
	 * Register REST routes for coverage trash/restore/delete operations.
	 */
	public static function register_routes() {
		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/coverages/(?P<coverage_id>\d+)/trash',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_trash_coverage' ],
				'permission_callback' => [ __CLASS__, 'can_manage_coverage' ],
				'args'                => [
					'coverage_id' => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_coverage_id' ],
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/coverages/(?P<coverage_id>\d+)/restore',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_restore_coverage' ],
				'permission_callback' => [ __CLASS__, 'can_manage_coverage' ],
				'args'                => [
					'coverage_id' => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_coverage_id' ],
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/coverages/(?P<coverage_id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'handle_delete_coverage' ],
				'permission_callback' => [ __CLASS__, 'can_manage_coverage' ],
				'args'                => [
					'coverage_id' => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_coverage_id' ],
					],
				],
			]
		);
	}

	/**
	 * Permission check for coverage operations: requires manage_categories.
	 *
	 * @return bool
	 */
	public static function can_manage_coverage(): bool {
		return current_user_can( 'manage_categories' );
	}

	/**
	 * Validate that the coverage_id route parameter is a positive integer.
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function validate_coverage_id( $value ): bool {
		return absint( $value ) > 0;
	}

	/**
	 * Get a coverage term by ID, returning a WP_Error if not found.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return \WP_Term|\WP_Error Term object on success, error on not found.
	 */
	private static function get_coverage_term( int $coverage_id ): \WP_Term|\WP_Error {
		$term = get_term( $coverage_id, self::TAXONOMY_SLUG );

		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error(
				'rolling_coverage_coverage_not_found',
				__( 'Coverage not found.', 'newspack-rolling-coverage' ),
				[ 'status' => 404 ]
			);
		}

		return $term;
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
	 * private, etc.) so the count should reflect that. Trashed entries are
	 * excluded so the count reflects only visible entries.
	 *
	 * @param string[]     $post_statuses List of post statuses to include in the count (excludes 'trash').
	 * @param \WP_Taxonomy $taxonomy      Current taxonomy object.
	 * @return string[] Filtered list of post statuses.
	 */
	public static function count_all_visible_statuses( $post_statuses, $taxonomy ) {
		if ( self::TAXONOMY_SLUG !== $taxonomy->name ) {
			return $post_statuses;
		}

		return [ 'publish', 'draft', 'pending', 'future', 'private' ];
	}

	/**
	 * Soft-delete a coverage: set status to 'trash' and trash all
	 * its non-trashed entries, storing recovery context in post-meta.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_trash_coverage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$coverage_id = (int) $request->get_param( 'coverage_id' );
		$term        = self::get_coverage_term( $coverage_id );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		// Trash all non-trashed entries assigned to this coverage.
		$entries = get_posts(
			[
				'post_type'      => Post_Type::CPT_SLUG,
				'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private' ],
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => self::TAXONOMY_SLUG,
						'field'    => 'term_id',
						'terms'    => $coverage_id,
					],
				],
			]
		);

		foreach ( $entries as $entry ) {
			// Store the entry's previous status if not already set (first trash).
			// The coverage context (ID/name/slug) is already synced by the
			// save_post hook in Post_Type::sync_coverage_context_meta().
			Post_Type::store_previous_status_on_trash( $entry->ID );

			wp_update_post(
				[
					'ID'          => $entry->ID,
					'post_status' => 'trash',
				]
			);
		}

		// Set coverage status to trash.
		update_term_meta( $coverage_id, self::STATUS_META_KEY, 'trash' );

		return new \WP_REST_Response(
			[
				'trashed'         => true,
				'entries_trashed' => count( $entries ),
			],
			200
		);
	}

	/**
	 * Restore a coverage from trash: set status to 'active'.
	 * Entries remain trashed — each must be recovered individually.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_restore_coverage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$coverage_id = (int) $request->get_param( 'coverage_id' );
		$term        = self::get_coverage_term( $coverage_id );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$current_status = get_term_meta( $coverage_id, self::STATUS_META_KEY, true );

		if ( 'trash' !== $current_status ) {
			return new \WP_Error(
				'rolling_coverage_coverage_not_trashed',
				__( 'Coverage is not in trash.', 'newspack-rolling-coverage' ),
				[ 'status' => 400 ]
			);
		}

		update_term_meta( $coverage_id, self::STATUS_META_KEY, 'active' );

		return new \WP_REST_Response(
			[
				'restored' => true,
			],
			200
		);
	}

	/**
	 * Permanently delete a coverage term. Entries remain in trash
	 * with their post-meta context intact.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_delete_coverage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$coverage_id = (int) $request->get_param( 'coverage_id' );
		$term        = self::get_coverage_term( $coverage_id );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$result = wp_delete_term( $coverage_id, self::TAXONOMY_SLUG );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return new \WP_Error(
				'rolling_coverage_coverage_not_deleted',
				__( 'Coverage could not be deleted.', 'newspack-rolling-coverage' ),
				[ 'status' => 500 ]
			);
		}

		return new \WP_REST_Response(
			[
				'deleted' => true,
			],
			200
		);
	}
}
