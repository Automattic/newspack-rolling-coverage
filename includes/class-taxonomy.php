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

	// Status values.
	const STATUS_ACTIVE   = 'active';
	const STATUS_PAUSED   = 'paused';
	const STATUS_ARCHIVED = 'archived';

	// Term meta keys for created/modified timestamps.
	const CREATED_AT_META_KEY  = 'created_at';
	const MODIFIED_AT_META_KEY = 'modified_at';

	/**
	 * ISO 8601 snapshot of LAST_MODIFIED_META_KEY, taken when the coverage is
	 * archived. Used for schema.org's coverageEndTime.
	 */
	const END_TIME_META_KEY = 'rolling_coverage_end_time';

	// Slack integration term-meta keys.
	const META_SLACK_CHANNEL_ID   = 'rolling_coverage_slack_channel_id';
	const META_SLACK_CHANNEL_NAME = 'rolling_coverage_slack_channel_name';

	// Generic chat-source term-meta keys: link each term to a single chat source.
	// META_SOURCE     : the platform slug (e.g. 'slack', 'beeper', 'whatsapp', 'telegram').
	// META_SOURCE_REF : the platform-native conversation id.
	const META_SOURCE     = 'rolling_coverage_source';
	const META_SOURCE_REF = 'rolling_coverage_source_ref';

	/**
	 * Term meta keys that are sensitive and should only be exposed in the edit
	 * context (authenticated requests with manage_options capability).
	 */
	const RESTRICTED_META = [
		self::META_SLACK_CHANNEL_ID,
		self::META_SLACK_CHANNEL_NAME,
		self::META_SOURCE,
		self::META_SOURCE_REF,
	];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'created_' . self::TAXONOMY_SLUG, [ __CLASS__, 'set_term_created_date' ] );
		add_action( 'edited_' . self::TAXONOMY_SLUG, [ __CLASS__, 'update_term_modified_date' ] );
		add_action( 'added_term_meta', [ __CLASS__, 'maybe_snapshot_end_time' ], 10, 4 );
		add_action( 'updated_term_meta', [ __CLASS__, 'maybe_snapshot_end_time' ], 10, 4 );
		add_filter( 'update_post_term_count_statuses', [ __CLASS__, 'count_all_visible_statuses' ], 10, 2 );
		add_filter( 'rest_prepare_' . self::TAXONOMY_SLUG, [ __CLASS__, 'filter_rest_response' ], 10, 3 );
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

		$term_meta = [
			// Coverage status — 'active', 'paused', or 'archived' (terminal); controls frontend polling vs static archive.
			self::STATUS_META_KEY                          => [
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => self::STATUS_ACTIVE,
			],
			// ISO 8601 timestamp the coverage term was first created (set once via the created_ hook).
			self::CREATED_AT_META_KEY                      => [
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => '',
			],
			// ISO 8601 timestamp of the last edit (updated via the edited_ hook).
			self::MODIFIED_AT_META_KEY                     => [
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => '',
			],
			// ISO 8601 snapshot of the coverage's last-modified time, taken when
			// archived. See maybe_snapshot_end_time().
			self::END_TIME_META_KEY                        => [
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => '',
			],
			// ISO 8601 timestamp of the coverage's latest entry activity.
			Rolling_Coverage_Block::LAST_MODIFIED_META_KEY => [
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => '',
			],
			// Slack channel ID linked to this coverage term; the channel→coverage forward link. manage_options-gated via auth_callback.
			self::META_SLACK_CHANNEL_ID                    => [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'default'       => '',
				'auth_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
			// Slack channel display name cached alongside the ID for the DataViews Slack column; manage_options-gated via auth_callback.
			self::META_SLACK_CHANNEL_NAME                  => [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'default'       => '',
				'auth_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
			// Generic source platform slug (e.g. 'slack', 'beeper', 'whatsapp', 'telegram'); manage_options-gated via auth_callback.
			self::META_SOURCE                              => [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'default'       => '',
				'auth_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
			// Generic source conversation id (Slack channel id, Beeper chat id, WhatsApp phone_jid, Telegram chat id); manage_options-gated via auth_callback.
			self::META_SOURCE_REF                          => [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'default'       => '',
				'auth_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
		];

		foreach ( $term_meta as $meta_key => $meta_args ) {
			register_term_meta( self::TAXONOMY_SLUG, $meta_key, $meta_args );
		}
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
		$created = get_term_meta( $term_id, self::CREATED_AT_META_KEY, true );
		if ( empty( $created ) ) {
			$now = gmdate( 'c' );
			update_term_meta( $term_id, self::CREATED_AT_META_KEY, $now );
			update_term_meta( $term_id, self::MODIFIED_AT_META_KEY, $now );
		}
	}

	/**
	 * Update modified_at when a term is edited.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function update_term_modified_date( $term_id ) {
		update_term_meta( $term_id, self::MODIFIED_AT_META_KEY, gmdate( 'c' ) );
	}

	/**
	 * Snapshots the coverage's last-modified time into END_TIME_META_KEY when
	 * its status is set to 'archived'.
	 *
	 * Hooked to both added_term_meta and updated_term_meta to catch the status
	 * write regardless of call site. Re-archiving overwrites the previous
	 * snapshot, so the value stays accurate across un-archive/re-archive cycles.
	 *
	 * @param int    $meta_id    Meta row id (unused).
	 * @param int    $term_id    Term id the meta belongs to.
	 * @param string $meta_key   Meta key being written.
	 * @param mixed  $meta_value Meta value being written.
	 */
	public static function maybe_snapshot_end_time( $meta_id, $term_id, $meta_key, $meta_value ) {
		if ( self::STATUS_META_KEY !== $meta_key || self::STATUS_ARCHIVED !== $meta_value ) {
			return;
		}

		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term || self::TAXONOMY_SLUG !== $term->taxonomy ) {
			return;
		}

		$last_modified = get_term_meta( $term_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, true );
		update_term_meta( $term_id, self::END_TIME_META_KEY, $last_modified );
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
	 * Soft-delete a coverage: set status to 'trash'. Entries are left
	 * as-is and access is restricted on the frontend via the coverage
	 * status check in the block SSR and the entries REST endpoint.
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

		// Set coverage status to trash. Entries are not modified.
		update_term_meta( $coverage_id, self::STATUS_META_KEY, 'trash' );

		return new \WP_REST_Response(
			[
				'trashed' => true,
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
	 * Permanently delete a coverage term and schedule async cleanup
	 * of its orphaned entries via WP Cron.
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

		// Schedule async cleanup of orphaned entries.
		if ( ! wp_next_scheduled( Post_Type::CLEANUP_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 60, Post_Type::CLEANUP_CRON_HOOK );
		}

		return new \WP_REST_Response(
			[
				'deleted' => true,
			],
			200
		);
	}

	/**
	 * Strip sensitive Slack channel and source term meta from the REST
	 * response for requests that are not in the edit context. The
	 * auth_callback on these meta keys only restricts writes, so read access
	 * must be blocked separately here.
	 *
	 * @param \WP_REST_Response $response The REST response object.
	 * @param \WP_Term          $item     Term object.
	 * @param \WP_REST_Request  $request  Full details about the request.
	 * @return \WP_REST_Response Filtered response.
	 */
	public static function filter_rest_response( \WP_REST_Response $response, \WP_Term $item, \WP_REST_Request $request ): \WP_REST_Response {
		$context = $request->get_param( 'context' );

		if ( 'edit' === $context ) {
			return $response;
		}

		$data = $response->get_data();

		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( self::RESTRICTED_META as $meta_key ) {
				unset( $data['meta'][ $meta_key ] );
			}
		}

		$response->set_data( $data );

		return $response;
	}
}
