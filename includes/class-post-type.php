<?php
/**
 * Register the rolling_coverage_entry custom post type.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration of the rolling_coverage_entry custom post type,
 * its post-meta, and REST endpoints for entry restore operations.
 */
class Post_Type {

	const CPT_SLUG  = 'rolling_cov_entry'; // WP limits post type slugs to 20 characters.
	const REST_BASE = 'rolling-coverage-entries';

	// REST field name for the pinned boolean.
	const PINNED_REST_FIELD = 'pinned';

	// Option key for the ordered list of pinned entry IDs. Autoloaded array of post IDs in pin order. Isolates pinned state to this CPT, avoiding pollution of the global sticky_posts option.
	const PINNED_OPTION_KEY = 'rolling_coverage_pinned_entries';

	// Query var used to opt out of pinned-first ordering for a specific query.
	const SKIP_PIN_ORDER_VAR = 'rolling_coverage_skip_pin_order';

	// Source related meta key for any chat-source adapter (Slack now, others in future).
	const META_ENTRY_SOURCE = 'rolling_coverage_entry_source';

	// Slack integration post-meta keys.
	const META_SLACK_TS          = 'rolling_coverage_slack_ts';
	const META_SLACK_USER_ID     = 'rolling_coverage_slack_user_id';
	const META_SLACK_AUTHOR_NAME = 'rolling_coverage_slack_author_name';
	const META_SLACK_CHANNEL_ID  = 'rolling_coverage_slack_channel_id';
	const META_SLACK_THREAD_TS   = 'rolling_coverage_slack_thread_ts';

	// Generic chat-source post-meta key: the canonical dedup key for any chat-source adapter
	// Used by Entry_Ingestion_Service::ingest()'s add_option mutex and meta_query dedup.
	const META_SOURCE_REF = 'rolling_coverage_source_ref';

	/**
	 * Meta keys that are sensitive and should only be exposed in the edit
	 * context (authenticated requests with edit_posts capability).
	 */
	const RESTRICTED_META = [
		self::META_SLACK_TS,
		self::META_SLACK_USER_ID,
		self::META_SLACK_AUTHOR_NAME,
		self::META_SLACK_CHANNEL_ID,
		self::META_SLACK_THREAD_TS,
		self::META_SOURCE_REF,
	];

	/**
	 * Post-meta key storing the original coverage term ID at trash time.
	 */
	const META_ORIGINAL_COVERAGE_ID = 'rolling_coverage_original_coverage_id';

	/**
	 * Post-meta key storing the original coverage name at trash time.
	 */
	const META_ORIGINAL_COVERAGE_NAME = 'rolling_coverage_original_coverage_name';

	/**
	 * Post-meta key storing the original coverage slug at trash time.
	 */
	const META_ORIGINAL_COVERAGE_SLUG = 'rolling_coverage_original_coverage_slug';

	/**
	 * Post-meta key storing the entry's post status before trashing.
	 */
	const META_PREVIOUS_STATUS = 'rolling_coverage_previous_status';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'save_post_' . self::CPT_SLUG, [ __CLASS__, 'sync_coverage_context_meta' ] );
		add_action( 'wp_trash_post', [ __CLASS__, 'store_previous_status_on_trash' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_pinned_rest_field' ] );
		add_filter( 'posts_orderby', [ __CLASS__, 'orderby_pinned_first' ], 10, 2 );
		add_filter( 'rest_prepare_' . self::CPT_SLUG, [ __CLASS__, 'filter_rest_response' ], 10, 3 );
	}

	/**
	 * Register the custom post type.
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
				'description'         => __( 'Individual entries within a Rolling Coverage.', 'newspack-rolling-coverage' ),
				'public'              => false,
				'publicly_queryable'  => true,
				'exclude_from_search' => false,
				'show_in_rest'        => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'query_var'           => true,
				'rest_base'           => self::REST_BASE,
				'supports'            => [ 'title', 'editor', 'author', 'revisions', 'custom-fields', 'thumbnail' ],
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

		// Post-meta for any chat-source adapter (Slack now, others in future).
		$source_meta = [
			// Entry origin — 'slack' for Slack-ingested entries vs 'wordpress' for admin-created; drives the Source column icon in DataViews.
			self::META_ENTRY_SOURCE      => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
				'default'      => 'wordpress',
			],
			// Original Slack message timestamp; used to deduplicate entries on Slack webhook retries.
			self::META_SLACK_TS          => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],
			// Slack user ID of the message author; provenance only (the WP post author is always the bot user).
			self::META_SLACK_USER_ID     => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],
			// Display name of the Slack message author; shown via the author-display filter as "Slack: {name}".
			self::META_SLACK_AUTHOR_NAME => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],
			// Slack channel ID the entry was ingested from; links an entry back to its source channel.
			self::META_SLACK_CHANNEL_ID  => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],
			// Thread timestamp when the message was a threaded reply.
			self::META_SLACK_THREAD_TS   => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],
			// Generic source-ref: the platform-agnostic canonical dedup key written with unique=true
			// by Entry_Ingestion_Service::ingest() — REST-exposed for REST-side filter/dedup queries.
			self::META_SOURCE_REF        => [
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			],
		];

		foreach ( $source_meta as $meta_key => $meta_args ) {
			register_post_meta( self::CPT_SLUG, $meta_key, $meta_args );
		}
	}

	/**
	 * Strip sensitive Slack/source meta from the REST response for requests
	 * that are not in the edit context. The edit context is only available to
	 * authenticated users with edit_posts capability, so unauthenticated
	 * requests (view context) will have these meta keys removed.
	 *
	 * META_ENTRY_SOURCE is left exposed because it is non-sensitive (a
	 * simple source identifier) and may be used by the frontend.
	 *
	 * @param \WP_REST_Response $response The REST response object.
	 * @param \WP_Post          $post     Post object.
	 * @param \WP_REST_Request  $request  Full details about the request.
	 * @return \WP_REST_Response Filtered response.
	 */
	public static function filter_rest_response( \WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request ): \WP_REST_Response {
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

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/coverages/(?P<coverage_id>\d+)/generate-key-takeaways',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_generate_key_takeaways' ],
				'permission_callback' => [ __CLASS__, 'can_generate_key_takeaways' ],
				'args'                => [
					'coverage_id'   => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_numeric_id' ],
					],
					'max_takeaways' => [
						'type'              => 'integer',
						'default'           => 5,
						'minimum'           => 1,
						'maximum'           => 10,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/entries/(?P<entry_id>\d+)/pin',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_toggle_pin' ],
				'permission_callback' => [ __CLASS__, 'can_toggle_pin' ],
				'args'                => [
					'entry_id' => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_numeric_id' ],
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/entries/(?P<entry_id>\d+)/restore',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_restore_entry' ],
				'permission_callback' => [ __CLASS__, 'can_edit_entry' ],
				'args'                => [
					'entry_id' => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_numeric_id' ],
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/entries/restore',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_bulk_restore_entries' ],
				'permission_callback' => [ __CLASS__, 'can_edit_posts' ],
				'args'                => [
					'entry_ids' => [
						'required' => true,
						'type'     => 'array',
						'items'    => [ 'type' => 'integer' ],
					],
				],
			]
		);
	}

	/**
	 * Register a computed `pinned` boolean REST field.
	 *
	 * Reads from the autoloaded pinned-entries option via self::is_pinned(),
	 * which uses a cached in-memory lookup. No database query is issued per post.
	 */
	public static function register_pinned_rest_field() {
		register_rest_field(
			self::CPT_SLUG,
			self::PINNED_REST_FIELD,
			[
				'get_callback' => [ __CLASS__, 'get_pinned_rest_field' ],
				'schema'       => [
					'type'    => 'boolean',
					'context' => [ 'edit', 'view' ],
				],
			]
		);
	}

	/**
	 * REST field callback returning the pinned status of an entry.
	 *
	 * @param array $post Entry REST object data.
	 * @return bool Whether the entry is pinned.
	 */
	public static function get_pinned_rest_field( array $post ): bool {
		return self::is_pinned( (int) $post['id'] );
	}

	/**
	 * Get the ordered list of pinned entry IDs.
	 *
	 * @return int[]
	 */
	public static function get_pinned_ids(): array {
		$ids = get_option( self::PINNED_OPTION_KEY, [] );
		return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Whether a given entry is pinned.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return bool
	 */
	public static function is_pinned( int $entry_id ): bool {
		return in_array( $entry_id, self::get_pinned_ids(), true );
	}

	/**
	 * Add an entry ID to the pinned list (append = newest pin).
	 *
	 * @param int $entry_id Entry post ID.
	 */
	public static function pin_entry( int $entry_id ): void {
		$ids = self::get_pinned_ids();
		if ( ! in_array( $entry_id, $ids, true ) ) {
			$ids[] = $entry_id;
			update_option( self::PINNED_OPTION_KEY, $ids );
		}
	}

	/**
	 * Remove an entry ID from the pinned list.
	 *
	 * @param int $entry_id Entry post ID.
	 */
	public static function unpin_entry( int $entry_id ): void {
		$ids = self::get_pinned_ids();
		$ids = array_values( array_diff( $ids, [ $entry_id ] ) );
		update_option( self::PINNED_OPTION_KEY, $ids );
	}

	/**
	 * Rewrites the ORDER BY clause to sort pinned entries first.
	 *
	 * Registered globally on posts_orderby. Guarded by a post_type check
	 * so non-CPT queries pass through untouched. Queries that need to
	 * skip pinned ordering (e.g. forward polling) can set the
	 * rolling_coverage_skip_pin_order query var to true.
	 *
	 * @param string   $orderby Current ORDER BY clause.
	 * @param WP_Query $query   WP_Query instance.
	 * @return string
	 */
	public static function orderby_pinned_first( string $orderby, WP_Query $query ): string {
		if ( self::CPT_SLUG !== $query->get( 'post_type' ) ) {
			return $orderby;
		}

		if ( $query->get( self::SKIP_PIN_ORDER_VAR ) ) {
			return $orderby;
		}

		$pinned_ids = self::get_pinned_ids();

		if ( empty( $pinned_ids ) ) {
			return $orderby;
		}

		global $wpdb;
		$ids_list = implode( ',', $pinned_ids );

		$pinned_order = "CASE WHEN {$wpdb->posts}.ID IN ({$ids_list}) THEN 0 ELSE 1 END, FIELD({$wpdb->posts}.ID, {$ids_list})";

		return '' === $orderby ? $pinned_order : $pinned_order . ', ' . $orderby;
	}

	/**
	 * Permission check for key takeaways generation.
	 *
	 * Matches the Abilities API permission check.
	 *
	 * @return bool
	 */
	public static function can_generate_key_takeaways(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission check for pin toggling.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function can_toggle_pin( WP_REST_Request $request ): bool {
		$entry_id = (int) $request->get_param( 'entry_id' );
		return current_user_can( 'edit_post', $entry_id );
	}

	/**
	 * POST handler — generates key takeaways from coverage entries.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_generate_key_takeaways( WP_REST_Request $request ) {
		$coverage_id   = (int) $request->get_param( 'coverage_id' );
		$max_takeaways = (int) $request->get_param( 'max_takeaways' );

		$result = AI_Service::generate_key_takeaways(
			$coverage_id,
			$max_takeaways
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( [ 'result' => $result ], 200 );
	}

	/**
	 * POST handler — toggles the pinned status of an entry.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_toggle_pin( WP_REST_Request $request ) {
		$entry_id = (int) $request->get_param( 'entry_id' );
		$entry    = get_post( $entry_id );

		if ( ! $entry || self::CPT_SLUG !== $entry->post_type ) {
			return new WP_Error(
				'rolling_coverage_entry_not_found',
				__( 'Entry not found.', 'newspack-rolling-coverage' ),
				[ 'status' => 404 ]
			);
		}

		$is_pinned = self::is_pinned( $entry_id );

		if ( $is_pinned ) {
			self::unpin_entry( $entry_id );
		} else {
			self::pin_entry( $entry_id );
		}

		return new WP_REST_Response( [ 'pinned' => ! $is_pinned ], 200 );
	}

	/**
	 * Register post-meta used for soft-delete context recovery.
	 *
	 * These keys store the original coverage context at the time a
	 * coverage is trashed, so entries can be recovered even if the
	 * coverage term is later permanently deleted.
	 */
	public static function register_meta() {
		$meta_keys = [
			self::META_ORIGINAL_COVERAGE_ID   => 'integer',
			self::META_ORIGINAL_COVERAGE_NAME => 'string',
			self::META_ORIGINAL_COVERAGE_SLUG => 'string',
			self::META_PREVIOUS_STATUS        => 'string',
		];

		foreach ( $meta_keys as $key => $type ) {
			register_post_meta(
				self::CPT_SLUG,
				$key,
				[
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => [ __CLASS__, 'can_edit_post_meta' ],
				]
			);
		}
	}

	/**
	 * Populate the recovery context meta with the entry's coverage term
	 * on first save. Does not overwrite existing meta — once set, the
	 * original coverage identity is preserved even if the entry is later
	 * reassigned to a recovery term. This ensures the entry can be
	 * recovered to a term based on the original coverage name/slug even
	 * if a recovery term is subsequently deleted.
	 *
	 * @param int $post_id Post ID being saved.
	 */
	public static function sync_coverage_context_meta( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post || self::CPT_SLUG !== $post->post_type ) {
			return;
		}

		// Don't sync during trash — the term relationship may be gone.
		if ( 'trash' === $post->post_status ) {
			return;
		}

		// Only populate on first save — don't overwrite existing context.
		if ( get_post_meta( $post_id, self::META_ORIGINAL_COVERAGE_SLUG, true ) ) {
			return;
		}

		$terms = wp_get_post_terms( $post_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'all' ] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$term = $terms[0];

		update_post_meta( $post_id, self::META_ORIGINAL_COVERAGE_ID, $term->term_id );
		update_post_meta( $post_id, self::META_ORIGINAL_COVERAGE_NAME, $term->name );
		update_post_meta( $post_id, self::META_ORIGINAL_COVERAGE_SLUG, $term->slug );
	}

	/**
	 * Store the entry's previous post status in post-meta when it is
	 * trashed, so the entry can be restored to its original status.
	 *
	 * The coverage context (ID/name/slug) is already kept in sync by
	 * sync_coverage_context_meta(), so only the status needs to be
	 * stored at trash time.
	 *
	 * @param int $post_id Post ID being trashed.
	 */
	public static function store_previous_status_on_trash( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post || self::CPT_SLUG !== $post->post_type ) {
			return;
		}

		// Only store if not already set (first trash).
		if ( ! get_post_meta( $post_id, self::META_PREVIOUS_STATUS, true ) ) {
			update_post_meta( $post_id, self::META_PREVIOUS_STATUS, $post->post_status );
		}
	}

	/**
	 * Validate a route parameter is a positive integer.
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function validate_numeric_id( $value ): bool {
		return absint( $value ) > 0;
	}

	/**
	 * Permission check for entry-level operations.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function can_edit_entry( \WP_REST_Request $request ): bool {
		$entry_id = (int) $request->get_param( 'entry_id' );
		return current_user_can( 'edit_post', $entry_id );
	}

	/**
	 * Permission check for bulk entry operations: requires edit_posts.
	 *
	 * @return bool
	 */
	public static function can_edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Auth callback for post-meta registration: requires edit_post for
	 * the specific post being modified.
	 *
	 * @param bool   $allowed  Whether the user can edit the meta.
	 * @param string $meta_key Meta key being checked.
	 * @param int    $post_id   Post ID the meta belongs to.
	 * @return bool
	 */
	public static function can_edit_post_meta( $allowed, $meta_key, $post_id ): bool {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * REST handler: restore a single trashed entry.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_restore_entry( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$entry_id = (int) $request->get_param( 'entry_id' );

		$result = self::restore_entry( $entry_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: bulk restore trashed entries.
	 *
	 * Processes all entries in a single request so that recovery term
	 * creation is atomic — entries from the same deleted coverage share
	 * one recovery term instead of each creating its own.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_bulk_restore_entries( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$entry_ids = (array) $request->get_param( 'entry_ids' );
		$entry_ids = array_filter( array_map( 'intval', $entry_ids ) );

		if ( empty( $entry_ids ) ) {
			return new \WP_Error(
				'rolling_coverage_no_entry_ids',
				__( 'No entry IDs provided.', 'newspack-rolling-coverage' ),
				[ 'status' => 400 ]
			);
		}

		// Cache of recovery terms keyed by original coverage slug, so
		// all entries from the same deleted coverage share one term.
		$recovery_cache = [];

		$results = [];

		foreach ( $entry_ids as $entry_id ) {
			if ( ! current_user_can( 'edit_post', $entry_id ) ) {
				$results[] = [
					'entryId'  => $entry_id,
					'restored' => false,
					'error'    => __( 'You do not have permission to restore this entry.', 'newspack-rolling-coverage' ),
				];
				continue;
			}

			$result = self::restore_entry( $entry_id, $recovery_cache );

			if ( is_wp_error( $result ) ) {
				$results[] = [
					'entryId'  => $entry_id,
					'restored' => false,
					'error'    => $result->get_error_message(),
				];
			} else {
				$results[] = [
					'entryId'         => $entry_id,
					'restored'        => true,
					'coverageId'      => $result['coverageId'],
					'coverageStatus'  => $result['coverageStatus'],
					'coverageCreated' => $result['coverageCreated'],
					'entryStatus'     => $result['entryStatus'],
				];
			}
		}

		return new \WP_REST_Response(
			[
				'results' => $results,
			],
			200
		);
	}

	/**
	 * Ensure a coverage term's status meta is 'active', flipping it
	 * back from 'trash' if it was soft-deleted.
	 *
	 * @param int $coverage_id Coverage term ID.
	 */
	private static function ensure_coverage_active( int $coverage_id ): void {
		$current_status = get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );
		if ( 'trash' === $current_status ) {
			update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, 'active' );
		}
	}

	/**
	 * Restore a single trashed entry.
	 *
	 * Priority order for finding the coverage to assign to:
	 * 1. If the entry still has a coverage term assigned (relationship
	 *    intact), use that term and restore it to active if trashed.
	 * 2. If no term is assigned but META_ORIGINAL_COVERAGE_ID exists and
	 *    the term still exists, reassign to it and restore to active.
	 * 3. If neither, create or reuse a "{name} - recovery" term.
	 *
	 * @param int   $entry_id       Entry post ID.
	 * @param array $recovery_cache Optional cache of recovery terms keyed by
	 *                              original coverage slug, for bulk dedup.
	 * @return array|\WP_Error Result array or error.
	 */
	private static function restore_entry( int $entry_id, array &$recovery_cache = [] ): array|\WP_Error {
		$entry = get_post( $entry_id );

		if ( ! $entry || self::CPT_SLUG !== $entry->post_type ) {
			return new \WP_Error(
				'rolling_coverage_entry_not_found',
				__( 'Entry not found.', 'newspack-rolling-coverage' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'trash' !== $entry->post_status ) {
			return new \WP_Error(
				'rolling_coverage_entry_not_trashed',
				__( 'Entry is not in trash.', 'newspack-rolling-coverage' ),
				[ 'status' => 400 ]
			);
		}

		$previous_status = get_post_meta( $entry_id, self::META_PREVIOUS_STATUS, true );
		$previous_status = $previous_status ? $previous_status : 'publish';

		$coverage_id      = 0;
		$coverage_created = false;

		// Step 1: Check if the entry still has a coverage term assigned.
		$terms = wp_get_post_terms( $entry_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'all' ] );

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			// Term relationship is intact — use the assigned coverage.
			$coverage_id = (int) $terms[0]->term_id;

			self::ensure_coverage_active( $coverage_id );
		}

		// Step 2: No term assigned — check META_ORIGINAL_COVERAGE_ID.
		if ( ! $coverage_id ) {
			$original_id = (int) get_post_meta( $entry_id, self::META_ORIGINAL_COVERAGE_ID, true );

			if ( $original_id && term_exists( $original_id, Taxonomy::TAXONOMY_SLUG ) ) {
				// Original coverage still exists — reassign the entry to it.
				$coverage_id = $original_id;

				self::ensure_coverage_active( $coverage_id );

				wp_set_post_terms( $entry_id, [ $coverage_id ], Taxonomy::TAXONOMY_SLUG );
			}
		}

		// Step 3: Coverage no longer exists — create or reuse a recovery term.
		if ( ! $coverage_id ) {
			$original_name = get_post_meta( $entry_id, self::META_ORIGINAL_COVERAGE_NAME, true );
			$original_slug = get_post_meta( $entry_id, self::META_ORIGINAL_COVERAGE_SLUG, true );

			// Build a deterministic recovery slug so all entries from the
			// same deleted coverage share one recovery term.
			if ( $original_slug ) {
				$recovery_slug = $original_slug . '-recovery';
			} elseif ( $original_name ) {
				$recovery_slug = sanitize_title( $original_name ) . '-recovery';
			} else {
				$recovery_slug = '';
			}

			// No recovery context available - retain the entry in trash and return an error.
			if ( ! $recovery_slug || ! $original_name ) {
				return new \WP_Error(
					'rolling_coverage_no_recovery_context',
					__( 'This entry has no coverage context to restore to. Please assign it to a coverage before restoring.', 'newspack-rolling-coverage' ),
					[ 'status' => 400 ]
				);
			}

			$recovery_name = $original_name . ' - recovery';

			// Check the in-memory cache first (bulk dedup).
			if ( isset( $recovery_cache[ $recovery_slug ] ) ) {
				$coverage_id = $recovery_cache[ $recovery_slug ];
			} else {
				// Check if a recovery term already exists in the database.
				$existing = get_term_by( 'slug', $recovery_slug, Taxonomy::TAXONOMY_SLUG );

				if ( $existing ) {
					$coverage_id = (int) $existing->term_id;
				} else {
					$insert_result = wp_insert_term(
						$recovery_name,
						Taxonomy::TAXONOMY_SLUG,
						[ 'slug' => $recovery_slug ]
					);

					if ( is_wp_error( $insert_result ) ) {
						// Slug conflict — try again without a custom slug.
						$insert_result = wp_insert_term(
							$recovery_name,
							Taxonomy::TAXONOMY_SLUG
						);

						if ( is_wp_error( $insert_result ) ) {
							return $insert_result;
						}
					}

					$coverage_id      = (int) $insert_result['term_id'];
					$coverage_created = true;
				}

				// Cache for subsequent entries in the same bulk request.
				$recovery_cache[ $recovery_slug ] = $coverage_id;
			}

			// Assign entry to the coverage term.
			wp_set_post_terms( $entry_id, [ $coverage_id ], Taxonomy::TAXONOMY_SLUG );

			// Update post-meta with new coverage ID.
			update_post_meta( $entry_id, self::META_ORIGINAL_COVERAGE_ID, $coverage_id );
		}

		// Restore the entry to its previous status.
		$updated = wp_update_post(
			[
				'ID'          => $entry_id,
				'post_status' => $previous_status,
			],
			true
		);

		if ( is_wp_error( $updated ) || 0 === $updated ) {
			return new \WP_Error(
				'rolling_coverage_restore_failed',
				__( 'Failed to restore entry.', 'newspack-rolling-coverage' ),
				[ 'status' => 500 ]
			);
		}

		// Only clean up the previous status — keep the original coverage
		// name/slug meta so the entry can be recovered again if the
		// (recovery) coverage term is later permanently deleted.
		delete_post_meta( $entry_id, self::META_PREVIOUS_STATUS );

		$coverage_status = get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );

		return [
			'restored'        => true,
			'coverageId'      => $coverage_id,
			'coverageStatus'  => $coverage_status ? $coverage_status : 'active',
			'coverageCreated' => $coverage_created,
			'entryStatus'     => $previous_status,
		];
	}
}
