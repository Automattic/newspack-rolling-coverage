<?php
/**
 * Register the rolling_coverage_entry custom post type.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration of the rolling_coverage_entry custom post type,
 * its post-meta, REST endpoints for entry restore operations, and its
 * custom entries-view REST endpoint used by the admin DataViews.
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

	// Entries-view endpoint constants.
	const PER_PAGE_MAX = 100;

	/**
	 * Statuses returned by the page-mode and sync-mode endpoints. Includes
	 * `trash` so that trashed entries appear alongside other statuses.
	 */
	const ALLOWED_STATUSES = [ 'publish', 'draft', 'pending', 'future', 'private', 'trash' ];

	/**
	 * Statuses polled by the sync-mode endpoint. Mirrors ALLOWED_STATUSES.
	 */
	const SYNC_STATUSES = self::ALLOWED_STATUSES;

	const ALLOWED_ORDERBY = [ 'date', 'modified' ];
	const ALLOWED_ORDER   = [ 'asc', 'desc' ];

	// Cron hook for orphaned entry cleanup after coverage deletion.
	const CLEANUP_CRON_HOOK = 'rolling_coverage_cleanup_orphaned_entries';

	// Max entries to delete per cron batch.
	const CLEANUP_BATCH_SIZE = 50;

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
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'set_object_terms', [ __CLASS__, 'sync_coverage_context_meta' ], 10, 6 );
		add_action( 'rest_api_init', [ __CLASS__, 'register_pinned_rest_field' ] );
		add_filter( 'posts_orderby', [ __CLASS__, 'orderby_pinned_first' ], 10, 2 );
		add_filter( 'rest_prepare_' . self::CPT_SLUG, [ __CLASS__, 'filter_rest_response' ], 10, 3 );
		add_action( 'save_post_' . self::CPT_SLUG, [ __CLASS__, 'on_save_post' ], 10, 2 );
		add_action( 'set_object_terms', [ __CLASS__, 'on_set_object_terms' ], 10, 6 );
		add_action( 'trashed_post', [ __CLASS__, 'on_trash_post' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete_post' ] );
		add_filter( 'rest_' . self::CPT_SLUG . '_query', [ __CLASS__, 'filter_rest_query' ], 10, 2 );
		add_action( self::CLEANUP_CRON_HOOK, [ __CLASS__, 'cleanup_orphaned_entries' ] );
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
				'public'              => true,
				'publicly_queryable'  => true,
				'exclude_from_search' => true,
				'show_in_rest'        => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'query_var'           => false,
				'rest_base'           => self::REST_BASE,
				'supports'            => [ 'title', 'editor', 'author', 'revisions', 'custom-fields', 'thumbnail' ],
				'taxonomies'          => [
					Taxonomy::TAXONOMY_SLUG,
					'category',
					'post_tag',
				],
				'rewrite'             => [
					'slug'       => 'rolling-cov-entry',
					'with_front' => false,
				],
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
	 * Filter the WP_Query args for the entries REST endpoint so that
	 * entries from trashed or permanently deleted coverages are not
	 * exposed in non-edit (public) context.
	 *
	 * Uses a tax_query that only matches entries assigned to non-trashed
	 * coverage terms. This also excludes orphaned entries (no coverage
	 * term) since the operator is IN.
	 *
	 * Edit context (admin) is not filtered so trashed entries remain
	 * visible in the Trashed Entries view.
	 *
	 * @param array            $args    Query arguments.
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return array Filtered query arguments.
	 */
	public static function filter_rest_query( array $args, \WP_REST_Request $request ): array {
		if ( 'edit' === $request->get_param( 'context' ) ) {
			return $args;
		}

		// Get all non-trashed coverage term IDs in a single query.
		$active_term_ids = get_terms(
			[
				'taxonomy'   => Taxonomy::TAXONOMY_SLUG,
				'fields'     => 'ids',
				'hide_empty' => false,
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => Taxonomy::STATUS_META_KEY,
						'value'   => 'trash',
						'compare' => 'NOT LIKE',
					],
				],
			]
		);

		$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			[
				'taxonomy' => Taxonomy::TAXONOMY_SLUG,
				'field'    => 'term_id',
				'terms'    => is_array( $active_term_ids ) && ! empty( $active_term_ids ) ? $active_term_ids : [ 0 ],
				'operator' => 'IN',
			],
		];

		return $args;
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/coverages/(?P<term_id>\d+)/entries-view',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_entries_view' ],
				'permission_callback' => [ __CLASS__, 'check_permission' ],
				'args'                => [
					'term_id'                 => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_term_id' ],
					],
					'page'                    => [
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					],
					'per_page'                => [
						'type'    => 'integer',
						'default' => self::PER_PAGE_MAX,
						'minimum' => 1,
						'maximum' => self::PER_PAGE_MAX,
					],
					'orderby'                 => [
						'type'    => 'string',
						'default' => 'date',
						'enum'    => self::ALLOWED_ORDERBY,
					],
					'order'                   => [
						'type'    => 'string',
						'default' => 'desc',
						'enum'    => self::ALLOWED_ORDER,
					],
					'search'                  => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'status'                  => [
						// CSV validated by parse_statuses().
						'type' => 'string',
					],
					'status_exclude'          => [
						// CSV validated by parse_excluded_statuses().
						'type' => 'string',
					],
					'source'                  => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'source_exclude'          => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'author'                  => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'title'                   => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'post_id'                 => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'breakout_status'         => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'breakout_status_exclude' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'category_search'         => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'tag_search'              => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'date_filter'             => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'modified_filter'         => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'since'                   => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => [ __CLASS__, 'validate_since' ],
					],
				],
			]
		);

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
	 * Permission callback for the entries-view route.
	 *
	 * @return bool
	 */
	public static function check_permission() {
		return current_user_can( 'edit_posts' );
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
	 * Validates the term_id route parameter is numeric.
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function validate_term_id( $value ) {
		return is_numeric( $value );
	}

	/**
	 * Validates the `since` cursor parameter.
	 *
	 * Accepts "{id}:{modified_gmt}" or bare `Y-m-d H:i:s`. The timestamp
	 * portion is a raw GMT string (the verbatim post_modified_gmt column
	 * value), so it can be lexicographically compared against stored UTC
	 * cursor strings and passed directly to date_query. Rejects relative
	 * times like "yesterday" and arbitrary strings.
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function validate_since( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		$cursor_modified = self::parse_cursor_modified( $value );

		/*
		 * Raw GMT format: `2026-08-28 12:00:00`.
		 * createFromFormat validates the structure and getLastErrors
		 * catches overflow values (month 13, hour 25, Feb 30, etc.)
		 * that createFromFormat silently rolls over. No timezone
		 * component exists — the format is inherently UTC.
		 */
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $cursor_modified );

		if ( false === $dt ) {
			return false;
		}

		$errors = DateTimeImmutable::getLastErrors();

		if ( $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
			return false;
		}

		return true;
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
	 * REST callback: dispatches to page or sync mode based on the `since` param.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_entries_view( WP_REST_Request $request ) {
		$term_id = (int) $request->get_param( 'term_id' );

		if ( ! term_exists( $term_id, Taxonomy::TAXONOMY_SLUG ) ) {
			return new WP_Error(
				'rolling_coverage_coverage_not_found',
				__( 'Coverage not found.', 'newspack-rolling-coverage' ),
				[ 'status' => 404 ]
			);
		}

		$since = $request->get_param( 'since' );

		if ( is_string( $since ) && '' !== $since ) {
			return self::run_sync_mode( $term_id, $since );
		}

		$params = [
			'page'                    => max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) ),
			'per_page'                => self::clamp_per_page( (int) ( $request->get_param( 'per_page' ) ?? self::PER_PAGE_MAX ) ),
			'orderby'                 => (string) $request->get_param( 'orderby' ),
			'order'                   => (string) $request->get_param( 'order' ),
			'search'                  => is_string( $request->get_param( 'search' ) ) ? $request->get_param( 'search' ) : '',
			'statuses'                => self::parse_statuses( $request->get_param( 'status' ) ),
			'excluded_statuses'       => self::parse_excluded_statuses( $request->get_param( 'status_exclude' ) ),
			'source'                  => is_string( $request->get_param( 'source' ) ) ? $request->get_param( 'source' ) : '',
			'source_exclude'          => is_string( $request->get_param( 'source_exclude' ) ) ? $request->get_param( 'source_exclude' ) : '',
			'author'                  => is_string( $request->get_param( 'author' ) ) ? $request->get_param( 'author' ) : '',
			'title'                   => is_string( $request->get_param( 'title' ) ) ? $request->get_param( 'title' ) : '',
			'post_id'                 => is_string( $request->get_param( 'post_id' ) ) ? $request->get_param( 'post_id' ) : '',
			'breakout_status'         => is_string( $request->get_param( 'breakout_status' ) ) ? $request->get_param( 'breakout_status' ) : '',
			'breakout_status_exclude' => is_string( $request->get_param( 'breakout_status_exclude' ) ) ? $request->get_param( 'breakout_status_exclude' ) : '',
			'category_search'         => is_string( $request->get_param( 'category_search' ) ) ? $request->get_param( 'category_search' ) : '',
			'tag_search'              => is_string( $request->get_param( 'tag_search' ) ) ? $request->get_param( 'tag_search' ) : '',
			'date_filter'             => is_string( $request->get_param( 'date_filter' ) ) ? $request->get_param( 'date_filter' ) : '',
			'modified_filter'         => is_string( $request->get_param( 'modified_filter' ) ) ? $request->get_param( 'modified_filter' ) : '',
		];

		return self::run_page_mode( $term_id, $params );
	}

	/**
	 * Page mode: one paginated page of entries.
	 *
	 * The sync cursor is formed as "{id}:{modified_gmt}" matching the
	 * reader-facing polling strategy, so same-second entries are not lost.
	 *
	 * @param int   $term_id Coverage term ID.
	 * @param array $params  Resolved parameters.
	 * @return WP_REST_Response
	 */
	private static function run_page_mode( $term_id, array $params ) {
		$order_by_col = 'date' === $params['orderby'] ? 'date' : 'modified';

		$query_args = [
			'post_type'              => self::CPT_SLUG,
			'post_status'            => $params['statuses'],
			'tax_query'              => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => Taxonomy::TAXONOMY_SLUG,
					'field'    => 'term_id',
					'terms'    => $term_id,
				],
			],
			's'                      => $params['search'],
			'orderby'                => $order_by_col,
			'order'                  => strtoupper( $params['order'] ),
			'posts_per_page'         => $params['per_page'],
			'paged'                  => $params['page'],
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
			'no_found_rows'          => false,
		];

		if ( ! empty( $params['excluded_statuses'] ) ) {
			$params['statuses'] = array_values(
				array_diff( $params['statuses'], $params['excluded_statuses'] )
			);

			$query_args['post_status'] = $params['statuses'];
		}

		if ( '' !== $params['source'] || '' !== $params['source_exclude'] ) {
			$meta_query = [ 'relation' => 'AND' ];

			// The meta value is the lowercase machine slug for the WP editor source.
			$wordpress_source = 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- machine meta value, not the word "WordPress".

			if ( '' !== $params['source'] ) {
				if ( $wordpress_source === $params['source'] ) {
					// Legacy entries with no meta default to WP source.
					$meta_query[] = [
						'relation' => 'OR',
						[
							'key'   => self::META_ENTRY_SOURCE,
							'value' => $wordpress_source,
						],
						[
							'key'     => self::META_ENTRY_SOURCE,
							'compare' => 'NOT EXISTS',
						],
					];
				} else {
					$meta_query[] = [
						'key'   => self::META_ENTRY_SOURCE,
						'value' => $params['source'],
					];
				}
			}

			if ( '' !== $params['source_exclude'] ) {
				if ( $wordpress_source === $params['source_exclude'] ) {
					// Exclude entries with no meta too (same default applies).
					$meta_query[] = [
						'relation' => 'AND',
						[
							'key'     => self::META_ENTRY_SOURCE,
							'compare' => 'EXISTS',
						],
						[
							'key'     => self::META_ENTRY_SOURCE,
							'value'   => $wordpress_source,
							'compare' => '!=',
						],
					];
				} else {
					// Use OR-with-NOT-EXISTS so entries with no meta row (default WP source) are kept.
					$meta_query[] = [
						'relation' => 'OR',
						[
							'key'     => self::META_ENTRY_SOURCE,
							'compare' => 'NOT EXISTS',
						],
						[
							'key'     => self::META_ENTRY_SOURCE,
							'value'   => $params['source_exclude'],
							'compare' => '!=',
						],
					];
				}
			}

			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// Author filter: display-name substring, resolved to user IDs
		// (mirrors the DataViews "author name contains X" filter semantics).
		if ( '' !== $params['author'] ) {
			$author_ids = self::search_author_ids( $params['author'] );
			// No matching authors: force an empty result set.
			$query_args['author__in'] = ! empty( $author_ids ) ? $author_ids : [ PHP_INT_MAX ];
		}

		// Title filter: substring search on post_title.
		if ( '' !== $params['title'] ) {
			// WP_Query 's' searches title+content+excerpt; use a title-only filter for a targeted match.
			add_filter( 'posts_where', [ __CLASS__, 'title_filter_where' ], 10, 2 );
			$query_args['rolling_coverage_title_filter'] = $params['title'];
		}

		// Post ID filter: exact match on ID.
		if ( '' !== $params['post_id'] ) {
			$query_args['p'] = absint( $params['post_id'] );
		}

		// Breakout status filter: meta_query on breakout status meta.
		if ( '' !== $params['breakout_status'] || '' !== $params['breakout_status_exclude'] ) {
			$meta = isset( $query_args['meta_query'] )
				? $query_args['meta_query']
				: [ 'relation' => 'AND' ];

			if ( '' !== $params['breakout_status'] ) {
				if ( 'none' === $params['breakout_status'] ) {
					$meta[] = [
						'key'     => Breakout::BREAKOUT_STATUS_FIELD,
						'compare' => 'NOT EXISTS',
					];
				} else {
					$meta[] = [
						'key'   => Breakout::BREAKOUT_STATUS_FIELD,
						'value' => $params['breakout_status'],
					];
				}
			}

			if ( '' !== $params['breakout_status_exclude'] ) {
				if ( 'none' === $params['breakout_status_exclude'] ) {
					$meta[] = [
						'key'     => Breakout::BREAKOUT_STATUS_FIELD,
						'compare' => 'EXISTS',
					];
				} else {
					// OR-with-NOT-EXISTS so entries with no meta row are kept.
					$meta[] = [
						'relation' => 'OR',
						[
							'key'     => Breakout::BREAKOUT_STATUS_FIELD,
							'compare' => 'NOT EXISTS',
						],
						[
							'key'     => Breakout::BREAKOUT_STATUS_FIELD,
							'value'   => $params['breakout_status_exclude'],
							'compare' => '!=',
						],
					];
				}
			}

			$query_args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// Category/Tag filters: resolve the DataViews name-substring filter value to matching term IDs, then add tax_query clauses.
		$tax_clauses = [];

		foreach ( [
			'category' => $params['category_search'],
			'post_tag' => $params['tag_search'],
		] as $taxonomy => $search ) {
			if ( '' === $search ) {
				continue;
			}

			$term_ids = self::search_term_ids( $taxonomy, $search );

			if ( empty( $term_ids ) ) {
				// No matching terms: force an empty result set so the filter never silently falls back to unfiltered rows.
				$term_ids = [ PHP_INT_MAX ];
			}

			$tax_clauses[] = [
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $term_ids,
				'operator' => 'IN',
			];
		}

		if ( ! empty( $tax_clauses ) ) {
			$query_args['tax_query'] = array_merge( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[ 'relation' => 'AND' ],
				$query_args['tax_query'],
				$tax_clauses
			);
		}

		// Date/Modified filters: decode the JSON filter into a date_query clause.
		$date_clauses = [];

		foreach ( [
			'date'     => $params['date_filter'],
			'modified' => $params['modified_filter'],
		] as $column => $json ) {
			if ( '' === $json ) {
				continue;
			}

			$clause = self::build_date_query_clause( $json, $column );

			// null => invalid filter; force an empty result set.
			$date_clauses[] = null === $clause
				? [
					'column'   => 'post_' . $column . '_gmt',
					'year'     => 1,
					'monthnum' => 1,
					'day'      => 1,
				]
				: $clause;
		}

		if ( ! empty( $date_clauses ) ) {
			$query_args['date_query'] = $date_clauses; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query
		}

		$query = new \WP_Query( $query_args );

		// Clean up the title filter if it was added.
		if ( '' !== $params['title'] ) {
			remove_filter( 'posts_where', [ __CLASS__, 'title_filter_where' ], 10 );
		}

		$entries = [];

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$entries[] = self::map_row( $post );
		}

		// Cursor anchored to the coverage's latest entry, not the fetched page.
		$cursor      = self::coverage_sync_cursor( $term_id );
		$per_page    = $params['per_page'];
		$total_pages = $per_page > 0 ? (int) $query->max_num_pages : 1;
		$total_pages = max( 1, $total_pages );

		return new WP_REST_Response(
			[
				'entries'    => $entries,
				'totalItems' => (int) $query->found_posts,
				'totalPages' => $total_pages,
				'page'       => $params['page'],
				'cursor'     => $cursor,
			]
		);
	}

	/**
	 * Builds a sync cursor anchored to the coverage's latest-modified entry.
	 *
	 * Returns "{id}:{modified_gmt}". Falls back to server time when the
	 * coverage has no entries.
	 *
	 * @param int $term_id Coverage term ID.
	 * @return string Cursor in "{id}:{modified_gmt}" format.
	 */
	private static function coverage_sync_cursor( int $term_id ): string {
		$last_modified = (string) get_term_meta( $term_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, true );

		if ( '' === $last_modified ) {
			return '0:' . gmdate( 'Y-m-d H:i:s' );
		}

		$latest = new \WP_Query(
			[
				'post_type'              => self::CPT_SLUG,
				'post_status'            => self::SYNC_STATUSES,
				'tax_query'              => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => Taxonomy::TAXONOMY_SLUG,
						'field'    => 'term_id',
						'terms'    => $term_id,
					],
				],
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				self::SKIP_PIN_ORDER_VAR => true,
			]
		);

		if ( empty( $latest->posts ) || ! $latest->posts[0] instanceof WP_Post ) {
			return '0:' . gmdate( 'Y-m-d H:i:s' );
		}

		return $latest->posts[0]->ID . ':' . self::post_modified_gmt( $latest->posts[0] );
	}

	/**
	 * Sync mode: delta of entries modified after `since`.
	 *
	 * Uses WP_Query with a date_query on post_modified_gmt. On overflow
	 * (> PER_PAGE_MAX changed entries), signals the client to reload.
	 *
	 * @param int    $term_id Coverage term ID.
	 * @param string $since   Cursor in "{id}:{modified_gmt}" or bare Y-m-d H:i:s format.
	 * @return WP_REST_Response
	 */
	private static function run_sync_mode( $term_id, $since ) {
		$last_modified = (string) get_term_meta( $term_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, true );

		$cursor_modified = self::parse_cursor_modified( $since );

		// Short-circuit when the coverage's last-modified hasn't advanced.
		if ( $last_modified && $last_modified <= $cursor_modified ) {
			return new WP_REST_Response(
				[
					'changed'  => [],
					'cursor'   => $since,
					'overflow' => false,
				]
			);
		}

		$cursor_parts = explode( ':', $since, 2 );
		$cursor_id    = (int) ( $cursor_parts[0] ?? 0 );

		// The cursor is already a raw GMT `Y-m-d H:i:s` string, so it
		// can be passed directly to date_query without conversion.
		$query = new \WP_Query(
			[
				'post_type'              => self::CPT_SLUG,
				'post_status'            => self::SYNC_STATUSES,
				'tax_query'              => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => Taxonomy::TAXONOMY_SLUG,
						'field'    => 'term_id',
						'terms'    => $term_id,
					],
				],
				'date_query'             => [
					'column'    => 'post_modified_gmt',
					'after'     => $cursor_modified,
					'inclusive' => true,
				],
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'posts_per_page'         => self::PER_PAGE_MAX + 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				self::SKIP_PIN_ORDER_VAR => true,
			]
		);

		// Signal the client to reload when the delta exceeds the cap.
		if ( count( $query->posts ) > self::PER_PAGE_MAX ) {
			return new WP_REST_Response(
				[
					'changed'  => [],
					'cursor'   => $since,
					'overflow' => true,
				]
			);
		}

		$posts = $query->posts;

		// Prime post, postmeta, and term caches in one call.
		$post_ids = wp_list_pluck( $posts, 'ID' );

		if ( ! empty( $post_ids ) ) {
			_prime_post_caches( $post_ids, true, true );
		}

		$changed = [];

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			// Skip the cursor entry so it isn't re-reported each poll. Same-second entries are still included (inclusive date query).
			$post_modified_gmt = self::post_modified_gmt( $post );

			if ( $post->ID === $cursor_id && $post_modified_gmt === $cursor_modified ) {
				continue;
			}

			$row = self::map_row( $post );

			// Classify server-side: 'new' if published after cursor, else 'update'.
			$row['change_type'] = self::post_date_gmt( $post ) > $cursor_modified
				? 'new'
				: 'update';

			$changed[] = $row;
		}

		$cursor = $posts ? self::latest_sync_cursor( $posts ) : $since;

		return new WP_REST_Response(
			[
				'changed'  => $changed,
				'cursor'   => $cursor,
				'overflow' => false,
			]
		);
	}

	/**
	 * Builds a sync cursor from an array of posts: "{id}:{modified_gmt}".
	 *
	 * @param WP_Post[] $posts Entry post objects.
	 * @return string Cursor in "{id}:{modified_gmt}" format.
	 */
	private static function latest_sync_cursor( array $posts ): string {
		$latest_post     = null;
		$latest_modified = '';

		foreach ( $posts as $post ) {
			$modified = self::post_modified_gmt( $post );

			if ( '' === $latest_modified || $modified > $latest_modified ) {
				$latest_modified = $modified;
				$latest_post     = $post;
			}
		}

		if ( null === $latest_post ) {
			return '0:' . gmdate( 'Y-m-d H:i:s' );
		}

		return $latest_post->ID . ':' . $latest_modified;
	}

	/**
	 * Extracts the raw GMT modified timestamp from a sync cursor.
	 *
	 * Handles both "{id}:{Y-m-d H:i:s}" and bare "Y-m-d H:i:s". Only
	 * splits when the remainder starts with a 4-digit year so the
	 * time-portion colons are preserved.
	 *
	 * @param string $cursor The sync cursor.
	 * @return string GMT modified timestamp in Y-m-d H:i:s format.
	 */
	private static function parse_cursor_modified( string $cursor ): string {
		$parts = explode( ':', $cursor, 2 );

		if ( isset( $parts[1] ) && preg_match( '/^\d{4}-/', $parts[1] ) ) {
			return $parts[1];
		}

		return $cursor;
	}

	/**
	 * Raw GMT last-modified string for a post (Y-m-d H:i:s).
	 *
	 * Uses the verbatim post_modified_gmt column value, which is already
	 * UTC, lexicographically sortable, and directly comparable by
	 * date_query — no DateTime/ISO/timezone conversion needed.
	 *
	 * @param WP_Post $post Post object.
	 * @return string GMT timestamp in Y-m-d H:i:s format.
	 */
	private static function post_modified_gmt( WP_Post $post ): string {
		return $post->post_modified_gmt;
	}

	/**
	 * Raw GMT creation date string for a post (Y-m-d H:i:s).
	 *
	 * @param WP_Post $post Post object.
	 * @return string GMT timestamp in Y-m-d H:i:s format.
	 */
	private static function post_date_gmt( WP_Post $post ): string {
		return $post->post_date_gmt;
	}

	/**
	 * Map a WP_Post into the EntryViewRow shape.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function map_row( WP_Post $post ) {
		$author = get_userdata( $post->post_author );

		$breakout_id     = (int) get_post_meta( $post->ID, Breakout::ENTRY_BREAKOUT_POST_ID_META, true );
		$breakout_status = $breakout_id ? get_post_meta( $post->ID, Breakout::BREAKOUT_STATUS_FIELD, true ) : null;

		return [
			'id'               => $post->ID,
			'title'            => $post->post_title,
			'date'             => mysql2date( 'c', $post->post_date, false ),
			'modified'         => mysql2date( 'c', $post->post_modified, false ),
			'status'           => $post->post_status,
			'pinned'           => self::is_pinned( $post->ID ),
			'author'           => $author ? [
				'id'   => $author->ID,
				'name' => $author->display_name,
				'link' => get_author_posts_url( $author->ID ),
			] : null,
			'source'           => (string) get_post_meta( $post->ID, self::META_ENTRY_SOURCE, true ),
			'categories'       => self::map_terms( $post, 'category' ),
			'tags'             => self::map_terms( $post, 'post_tag' ),
			'breakout_post_id' => $breakout_id,
			'breakout_status'  => ! empty( $breakout_status ) ? (string) $breakout_status : null,
		];
	}

	/**
	 * Maps post terms into the {id,name,slug,link} shape.
	 *
	 * Uses get_the_terms() (object-cache backed). Guards false/WP_Error.
	 *
	 * @param WP_Post $post     Post object.
	 * @param string  $taxonomy Taxonomy slug.
	 * @return array
	 */
	private static function map_terms( WP_Post $post, $taxonomy ) {
		$terms = get_the_terms( $post, $taxonomy );

		if ( false === $terms || is_wp_error( $terms ) ) {
			return [];
		}

		$mapped = [];

		foreach ( $terms as $term ) {
			$mapped[] = [
				'id'   => $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
				'link' => get_term_link( $term ),
			];
		}

		return $mapped;
	}

	/**
	 * On entry save: advance the coverage's last-modified term meta for
	 * non-publish, non-trash saves (draft, pending, future, private).
	 *
	 * The block's `update_coverage_last_modified` handles publish-status
	 * saves. This hook covers the remaining statuses that the admin sync
	 * endpoint polls (via `SYNC_STATUSES`), including the restore-to-draft
	 * case. Publish and trash are skipped to avoid duplicating the block's
	 * writer and the trash hook respectively.
	 *
	 * @param int     $post_id Entry post ID.
	 * @param WP_Post $post    Entry post object.
	 */
	public static function on_save_post( int $post_id, WP_Post $post ): void {
		if ( 'publish' === $post->post_status || 'trash' === $post->post_status ) {
			return;
		}

		self::touch_coverage_last_modified_from_post( $post );
	}

	/**
	 * On trash: advance the coverage's last-modified so the sync endpoint
	 * returns the trashed entry (with `post_status = 'trash'`) in its
	 * `changed` set, letting the DataViews list remove it client-side.
	 *
	 * The block's save_post hook skips trash (publish-only), and
	 * set_object_terms does not fire on trash, so without this hook the
	 * short-circuit would never let the sync query run for a trash event.
	 *
	 * @param int $post_id Post ID being trashed.
	 */
	public static function on_trash_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || self::CPT_SLUG !== $post->post_type ) {
			return;
		}

		self::touch_coverage_last_modified_now( $post );
	}

	/**
	 * On permanent delete: advance the coverage's last-modified so the sync
	 * endpoint's query runs. The deleted entry will not appear in the
	 * `changed` set (it no longer exists), but the `last_modified` advance
	 * prevents the short-circuit from hiding concurrent changes.
	 *
	 * Fires on `before_delete_post` so term relationships are still available
	 * for lookup.
	 *
	 * @param int $post_id Post ID being deleted.
	 */
	public static function on_delete_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || self::CPT_SLUG !== $post->post_type ) {
			return;
		}

		self::touch_coverage_last_modified_now( $post );
	}

	/**
	 * Advance the coverage's last-modified term meta using the post's actual
	 * modified time. Used by save_post so the cursor matches the value the
	 * sync query's `post_modified_gmt > since` predicate compares against.
	 *
	 * @param WP_Post $post Entry post object.
	 */
	private static function touch_coverage_last_modified_from_post( WP_Post $post ): void {
		self::update_coverage_last_modified( $post->ID, $post->post_modified_gmt );
	}

	/**
	 * Advance the coverage's last-modified term meta to the current GMT time.
	 * Used by trash/delete where the post's modified time may be stale and
	 * the cursor simply needs to advance past any client's `since` value.
	 *
	 * @param WP_Post $post Entry post object.
	 */
	private static function touch_coverage_last_modified_now( WP_Post $post ): void {
		self::update_coverage_last_modified( $post->ID, gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Update the last-modified term meta for every coverage term assigned to
	 * the given entry post.
	 *
	 * @param int    $post_id  Entry post ID.
	 * @param string $modified GMT timestamp in Y-m-d H:i:s format to store.
	 */
	private static function update_coverage_last_modified( int $post_id, string $modified ): void {
		$term_ids = wp_get_post_terms( $post_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
			return;
		}

		foreach ( $term_ids as $term_id ) {
			update_term_meta( (int) $term_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, $modified );
		}
	}

	/**
	 * On term assignment: update last-modified for coverage terms assigned to
	 * an entry. This catches the insert-then-assign pattern where save_post
	 * fires before wp_set_object_terms (REST API creation, Slack ingestion),
	 * so the short-circuit detects the new entry on the next sync poll.
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Term IDs or slugs assigned.
	 * @param array  $tt_ids     Term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param bool   $append     Whether terms were appended.
	 * @param array  $old_tt_ids Old term taxonomy IDs.
	 */
	public static function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( Taxonomy::TAXONOMY_SLUG !== $taxonomy ) {
			return;
		}

		$post = get_post( $object_id );

		if ( ! $post || self::CPT_SLUG !== $post->post_type ) {
			return;
		}

		// Don't sync during trash — the term relationship may be gone.
		if ( 'trash' === $post->post_status ) {
			return;
		}

		self::touch_coverage_last_modified_from_post( $post );
	}

	/**
	 * Clamp per_page to [1, PER_PAGE_MAX], defaulting to PER_PAGE_MAX.
	 *
	 * @param int $per_page Raw per_page value.
	 * @return int
	 */
	private static function clamp_per_page( $per_page ) {
		if ( $per_page < 1 ) {
			return self::PER_PAGE_MAX;
		}

		return min( $per_page, self::PER_PAGE_MAX );
	}

	/**
	 * Posts_where filter for title substring matching.
	 *
	 * @param string   $where Current WHERE clause.
	 * @param WP_Query $query The query instance.
	 * @return string
	 */
	public static function title_filter_where( string $where, WP_Query $query ): string {
		$title = $query->get( 'rolling_coverage_title_filter' );

		if ( ! is_string( $title ) || '' === $title ) {
			return $where;
		}

		global $wpdb;
		$like = '%' . $wpdb->esc_like( $title ) . '%';
		$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s", $like );

		return $where;
	}

	/**
	 * Parse and validate the CSV `status` param.
	 *
	 * Invalid values are dropped silently; if all invalid, fall back to
	 * the default allowed list.
	 *
	 * @param mixed $raw Raw status param.
	 * @return string[]
	 */
	private static function parse_statuses( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return self::ALLOWED_STATUSES;
		}

		$values = array_map( 'trim', explode( ',', $raw ) );
		$valid  = array_values(
			array_filter(
				$values,
				static function ( $v ) {
					return in_array( $v, self::ALLOWED_STATUSES, true );
				}
			)
		);

		if ( empty( $valid ) ) {
			return self::ALLOWED_STATUSES;
		}

		return $valid;
	}

	/**
	 * Resolves a term-name substring to matching term IDs.
	 *
	 * The DataViews category/tag filters are free-text "contains" filters, so
	 * the value is a name substring, not an ID. This resolves it against the
	 * taxonomy's terms (mirroring the client-side name-contains semantics).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $search   Term name substring.
	 * @return int[] Matching term IDs.
	 */
	private static function search_term_ids( string $taxonomy, string $search ) {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'search'     => $search,
				'fields'     => 'ids',
				'hide_empty' => false,
				'orderby'    => 'none',
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		return array_map( 'absint', (array) $terms );
	}

	/**
	 * Resolves an author display-name substring to matching user IDs.
	 *
	 * The DataViews author filter is a free-text "contains" filter over the
	 * display name, so the value is a substring, not a nicename.
	 *
	 * @param string $search Author display-name substring.
	 * @return int[] Matching user IDs.
	 */
	private static function search_author_ids( string $search ) {
		$users = get_users(
			[
				'search'         => '*' . $search . '*',
				'search_columns' => [ 'display_name' ],
				'fields'         => 'ID',
			]
		);

		if ( empty( $users ) ) {
			return [];
		}

		return array_map( 'absint', (array) $users );
	}

	/**
	 * Builds a WP_Date_Query clause from a JSON-encoded DataViews datetime filter.
	 *
	 * The client sends `{ operator, value }` where value is an ISO string for
	 * absolute operators or `{ value, unit }` for relative operators.
	 *
	 * Returns null on any parse failure so the caller forces an empty result.
	 *
	 * @param string $json   JSON-encoded filter.
	 * @param string $column 'date' or 'modified' (maps to post_{column}_gmt).
	 * @return array|null WP_Date_Query clause or null on failure.
	 */
	private static function build_date_query_clause( string $json, string $column ) {
		$f = json_decode( $json, true );

		if ( ! is_array( $f ) || ! isset( $f['operator'] ) ) {
			return null;
		}

		$column  = 'post_' . $column . '_gmt';
		$op      = $f['operator'];
		$value   = $f['value'] ?? null;
		$clause  = [ 'column' => $column ];

		// Relative operators: inThePast, over.
		if ( 'inThePast' === $op || 'over' === $op ) {
			if ( ! is_array( $value ) || ! isset( $value['value'], $value['unit'] ) ) {
				return null;
			}

			$units = [
				'days'   => DAY_IN_SECONDS,
				'weeks'  => WEEK_IN_SECONDS,
				'months' => MONTH_IN_SECONDS,
				'years'  => YEAR_IN_SECONDS,
			];

			if ( ! isset( $units[ $value['unit'] ] ) ) {
				return null;
			}

			$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $value['value'] * $units[ $value['unit'] ] );

			if ( 'inThePast' === $op ) {
				return [
					'column'   => $column,
					'relation' => 'AND',
					[
						'column' => $column,
						'after'  => $cutoff,
					],
					[
						'column' => $column,
						'before' => gmdate( 'Y-m-d H:i:s' ),
					],
				];
			}

			// over: older than N units.
			return [
				'column' => $column,
				'before' => $cutoff,
			];
		}

		// Absolute operators: on, notOn, before, after, beforeInc, afterInc.
		if ( ! is_string( $value ) ) {
			return null;
		}

		$ts = strtotime( $value );

		if ( false === $ts ) {
			return null;
		}

		$iso = gmdate( 'Y-m-d H:i:s', $ts );

		// The user picks a calendar date in site timezone; extract Y/m/d in
		// site time so day-boundary operators match what they see.
		$site_y = (int) wp_date( 'Y', $ts );
		$site_m = (int) wp_date( 'n', $ts );
		$site_d = (int) wp_date( 'j', $ts );

		// Site-local midnight boundaries, shifted to GMT for the _gmt column.
		$offset    = (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
		$day_start = gmdate( 'Y-m-d H:i:s', gmmktime( 0, 0, 0, $site_m, $site_d, $site_y ) - $offset );
		$day_end   = gmdate( 'Y-m-d H:i:s', gmmktime( 23, 59, 59, $site_m, $site_d, $site_y ) - $offset );

		switch ( $op ) {
			case 'on':
				return [
					'column'   => $column,
					'relation' => 'AND',
					[
						'column'    => $column,
						'after'     => $day_start,
						'inclusive' => true,
					],
					[
						'column'    => $column,
						'before'    => $day_end,
						'inclusive' => true,
					],
				];
			case 'notOn':
				return [
					'column'   => $column,
					'relation' => 'OR',
					[
						'column' => $column,
						'before' => $day_start,
					],
					[
						'column' => $column,
						'after'  => $day_end,
					],
				];
			case 'before':
				return [
					'column' => $column,
					'before' => $iso,
				];
			case 'after':
				return [
					'column' => $column,
					'after'  => $iso,
				];
			case 'beforeInc':
				return [
					'column'    => $column,
					'before'    => $iso,
					'inclusive' => true,
				];
			case 'afterInc':
				return [
					'column'    => $column,
					'after'     => $iso,
					'inclusive' => true,
				];
			default:
				return null;
		}
	}

	/**
	 * Parses the CSV `status_exclude` param.
	 *
	 * Unlike parse_statuses, absent/empty returns an empty array
	 * (nothing excluded).
	 *
	 * @param mixed $raw Raw status_exclude param.
	 * @return string[]
	 */
	private static function parse_excluded_statuses( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}

		$values = array_map( 'trim', explode( ',', $raw ) );

		return array_values(
			array_filter(
				$values,
				static function ( $v ) {
					return in_array( $v, self::ALLOWED_STATUSES, true );
				}
			)
		);
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
	 * when terms are assigned. Fires on the set_object_terms hook, which
	 * runs after the term relationship has been written to the database,
	 * guaranteeing the terms are available.
	 *
	 * Does not overwrite existing meta — once set, the original coverage
	 * identity is preserved even if the entry is later reassigned to a
	 * recovery term.
	 *
	 * @param int    $object_id  Post ID.
	 * @param array  $terms      Term IDs being assigned.
	 * @param array  $tt_ids     Term taxonomy IDs being assigned.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param bool   $append     Whether terms are being appended.
	 * @param array  $old_tt_ids Old term taxonomy IDs.
	 */
	public static function sync_coverage_context_meta( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( Taxonomy::TAXONOMY_SLUG !== $taxonomy ) {
			return;
		}

		$post = get_post( $object_id );

		if ( ! $post || self::CPT_SLUG !== $post->post_type ) {
			return;
		}

		// Don't sync during trash — the term relationship may be gone.
		if ( 'trash' === $post->post_status ) {
			return;
		}
  
		// Only populate on first save — don't overwrite existing context.
		if ( get_post_meta( $object_id, self::META_ORIGINAL_COVERAGE_SLUG, true ) ) {
			return;
		}

		if ( empty( $terms ) ) {
			return;
		}

		$term = get_term( $terms[0], Taxonomy::TAXONOMY_SLUG );

		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		update_post_meta( $object_id, self::META_ORIGINAL_COVERAGE_ID, $term->term_id );
		update_post_meta( $object_id, self::META_ORIGINAL_COVERAGE_NAME, $term->name );
		update_post_meta( $object_id, self::META_ORIGINAL_COVERAGE_SLUG, $term->slug );
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

			// No recovery context available — keep the entry in trash.
			if ( ! $recovery_slug || ! $original_name ) {
				return new \WP_Error(
					'rolling_coverage_no_recovery_context',
					__( 'This entry has no coverage context to restore to.', 'newspack-rolling-coverage' ),
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

		// Use core's wp_untrash_post to properly clean up _wp_trash_meta_status and restore to the original status.
		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		$untrashed = wp_untrash_post( $entry_id );
		remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );

		if ( ! $untrashed ) {
			return new \WP_Error(
				'rolling_coverage_restore_failed',
				__( 'Failed to restore entry.', 'newspack-rolling-coverage' ),
				[ 'status' => 500 ]
			);
		}

		$previous_status = get_post_status( $entry_id );

		// Core's _wp_trash_meta_status is cleaned up by wp_untrash_post, so no manual cleanup is needed.
		$coverage_status = get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );

		return [
			'restored'        => true,
			'coverageId'      => $coverage_id,
			'coverageStatus'  => $coverage_status ? $coverage_status : 'active',
			'coverageCreated' => $coverage_created,
			'entryStatus'     => $previous_status,
		];
	}

	/**
	 * Cron handler: delete orphaned entries in batches.
	 *
	 * An orphaned entry is one that has no coverage term assigned but
	 * has META_ORIGINAL_COVERAGE_ID set — meaning its coverage was
	 * permanently deleted. Uses fields=ids and a fixed batch size to
	 * avoid memory or timeout issues. Reschedules itself if more
	 * orphaned entries remain.
	 */
	public static function cleanup_orphaned_entries(): void {
		$all_term_ids = get_terms(
			[
				'taxonomy'   => Taxonomy::TAXONOMY_SLUG,
				'fields'     => 'ids',
				'hide_empty' => false,
				'number'     => 0,
			]
		);

		if ( is_wp_error( $all_term_ids ) ) {
			$all_term_ids = [];
		}

		$query = new WP_Query(
			[
				'post_type'      => self::CPT_SLUG,
				'post_status'    => 'any', // excludes trash by default.
				'posts_per_page' => self::CLEANUP_BATCH_SIZE,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => self::META_ORIGINAL_COVERAGE_ID,
						'compare' => 'EXISTS',
					],
				],
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => Taxonomy::TAXONOMY_SLUG,
						'field'    => 'term_id',
						'terms'    => $all_term_ids,
						'operator' => 'NOT IN',
					],
				],
			]
		);

		if ( empty( $query->posts ) ) {
			return;
		}

		foreach ( $query->posts as $entry_id ) {
			wp_delete_post( (int) $entry_id, true );
		}

		// Reschedule if more orphaned entries remain.
		if ( $query->found_posts > count( $query->posts ) ) {
			wp_schedule_single_event( time() + 60, self::CLEANUP_CRON_HOOK );
		}
	}
}
