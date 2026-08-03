<?php
/**
 * Register the rolling_coverage_entry custom post type.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration of the rolling_coverage custom post type and the
 * custom entries-view REST endpoint used by the admin DataViews.
 */
class Post_Type {

	const CPT_SLUG  = 'rolling_cov_entry'; // allows 20 characters max hence the disparity.
	const REST_BASE = 'rolling-coverage-entries';

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
	 * Statuses returned by the page-mode endpoint (excludes `trash`).
	 */
	const ALLOWED_STATUSES = [ 'publish', 'draft', 'pending', 'future', 'private' ];

	/**
	 * Statuses returned by the sync-mode endpoint. Includes `trash` so that
	 * trashed entries appear in the `changed` set with `post_status = 'trash'`,
	 * eliminating the need for the per-coverage removed-entries transient.
	 */
	const SYNC_STATUSES = [ 'publish', 'draft', 'pending', 'future', 'private', 'trash' ];

	const ALLOWED_ORDERBY = [ 'date', 'modified' ];
	const ALLOWED_ORDER   = [ 'asc', 'desc' ];

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
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_filter( 'rest_prepare_' . self::CPT_SLUG, [ __CLASS__, 'filter_rest_response' ], 10, 3 );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'save_post_' . self::CPT_SLUG, [ __CLASS__, 'on_save_post' ], 10, 2 );
		add_action( 'set_object_terms', [ __CLASS__, 'on_set_object_terms' ], 10, 6 );
		add_action( 'trashed_post', [ __CLASS__, 'on_trash_post' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete_post' ] );
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
	 * Register the entries-view REST route.
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
					'term_id'  => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_term_id' ],
					],
					'page'     => [
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					],
					'per_page' => [
						'type'    => 'integer',
						'default' => self::PER_PAGE_MAX,
						'minimum' => 1,
						'maximum' => self::PER_PAGE_MAX,
					],
					'orderby'  => [
						'type'    => 'string',
						'default' => 'date',
						'enum'    => self::ALLOWED_ORDERBY,
					],
					'order'    => [
						'type'    => 'string',
						'default' => 'desc',
						'enum'    => self::ALLOWED_ORDER,
					],
					'search'   => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'status'   => [
						'type' => 'string',
						'enum' => self::ALLOWED_STATUSES,
					],
					'since'    => [
						'type'              => 'string',
						'validate_callback' => [ __CLASS__, 'validate_since' ],
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
	 * Validates the term_id route parameter is numeric.
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function validate_term_id( $value ) {
		return is_numeric( $value );
	}

	/**
	 * Validates the `since` cursor parameter is a parseable date string.   
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function validate_since( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		return false !== strtotime( $value );
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
			'page'     => max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) ),
			'per_page' => self::clamp_per_page( (int) ( $request->get_param( 'per_page' ) ?? self::PER_PAGE_MAX ) ),
			'orderby'  => (string) $request->get_param( 'orderby' ),
			'order'    => (string) $request->get_param( 'order' ),
			'search'   => is_string( $request->get_param( 'search' ) ) ? $request->get_param( 'search' ) : '',
			'statuses' => self::parse_statuses( $request->get_param( 'status' ) ),
		];

		return self::run_page_mode( $term_id, $params );
	}

	/**
	 * Page mode: one paginated page of entries.
	 *
	 * @param int   $term_id Coverage term ID.
	 * @param array $params  Resolved parameters.
	 * @return WP_REST_Response
	 */
	private static function run_page_mode( $term_id, array $params ) {
		$order_by_col = 'date' === $params['orderby'] ? 'date' : 'modified';

		$query = new \WP_Query(
			[
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
			]
		);

		$entries = [];

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$entries[] = self::map_row( $post );
		}

		// The sync cursor is the server time at page fetch, not the page's
		// max modified — otherwise sync on a deep page returns all newer
		// entries from earlier pages as false "added" notices.
		$cursor      = gmdate( 'c' );
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
	 * Sync mode: delta of entries modified strictly after `since`.
	 *
	 * @param int    $term_id Coverage term ID.
	 * @param string $since   ISO-8601 cursor.
	 * @return WP_REST_Response
	 */
	private static function run_sync_mode( $term_id, $since ) {
		$last_modified = (string) get_term_meta( $term_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, true );

		// Short-circuit: skip the SQL query when the coverage's last-modified
		// term meta has not advanced past the cursor.
		if ( $last_modified && $last_modified <= $since ) {
			return new WP_REST_Response(
				[
					'changed' => [],
					'cursor'  => $since,
				]
			);
		}

		global $wpdb;

		$rows = $wpdb->get_results( self::build_sync_query( $wpdb, $term_id, $since, self::SYNC_STATUSES ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( count( $rows ) > self::PER_PAGE_MAX ) {
			$rows = array_slice( $rows, 0, self::PER_PAGE_MAX );
		}

		$post_ids = wp_list_pluck( $rows, 'ID' );

		if ( ! empty( $post_ids ) ) {
			update_postmeta_cache( $post_ids );
			update_object_term_cache( $post_ids, self::CPT_SLUG );
		}

		$changed = [];
		$max_gmt = '';

		foreach ( $rows as $row ) {
			$post = get_post( $row->ID );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$changed[] = self::map_row( $post );

			if ( $row->post_modified_gmt > $max_gmt ) {
				$max_gmt = $row->post_modified_gmt;
			}
		}

		$cursor = $max_gmt ? mysql2date( 'c', $max_gmt, true ) : $since;

		return new WP_REST_Response(
			[
				'changed' => $changed,
				'cursor'  => $cursor,
			]
		);
	}

	/**
	 * Build the prepared SQL for sync mode.
	 *
	 * Kept as raw SQL (rather than WP_Query) because:
	 *  - The `post_modified_gmt > %s` predicate against the ISO cursor is the
	 *    core filter and benefits from the composite index
	 *    `(post_type, post_status, post_modified_gmt)` added on activation.
	 *  - We project only `(ID, post_modified_gmt)` and cap with LIMIT; no
	 *    pagination or found-rows count is needed.
	 *
	 * @param \wpdb  $wpdb     WordPress database handle.
	 * @param int    $term_id  Coverage term ID.
	 * @param string $since    ISO-8601 cursor.
	 * @param array  $statuses Allowed post statuses.
	 * @return string Prepared SQL.
	 */
	private static function build_sync_query( $wpdb, $term_id, $since, array $statuses ) {
		$placeholders = [];
		$args         = [];

		$args[] = Taxonomy::TAXONOMY_SLUG;
		$args[] = $term_id;
		$args[] = self::CPT_SLUG;

		foreach ( $statuses as $status ) {
			$placeholders[] = '%s';
			$args[]         = $status;
		}

		$status_in = implode( ',', $placeholders );

		$since_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $since ) );

		$sql = "SELECT p.ID, p.post_modified_gmt
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.taxonomy = %s AND tt.term_id = %d
			AND p.post_type = %s
			AND p.post_status IN ({$status_in})
			AND p.post_modified_gmt > %s
			ORDER BY p.post_modified_gmt DESC
			LIMIT %d";

		$args[] = $since_gmt;
		$args[] = self::PER_PAGE_MAX + 1;

		return $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
			'author'           => $author ? [
				'id'   => $author->ID,
				'name' => $author->display_name,
				'link' => get_author_posts_url( $author->ID ),
			] : null,
			'source'           => (string) ( get_post_meta( $post->ID, self::META_ENTRY_SOURCE, true ) ?? 'wordpress' ),
			'categories'       => self::map_terms( $post->ID, 'category' ),
			'tags'             => self::map_terms( $post->ID, 'post_tag' ),
			'breakout_post_id' => $breakout_id,
			'breakout_status'  => ! empty( $breakout_status ) ? (string) $breakout_status : null,
		];
	}

	/**
	 * Map post terms into the {id,name,slug,link} shape.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array
	 */
	private static function map_terms( $post_id, $taxonomy ) {
		$terms = wp_get_post_terms( $post_id, $taxonomy );

		if ( is_wp_error( $terms ) ) {
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
		$modified_datetime = get_post_datetime( $post, 'modified', 'gmt' );

		if ( ! $modified_datetime ) {
			return;
		}

		self::update_coverage_last_modified( $post->ID, $modified_datetime->format( DATE_ATOM ) );
	}

	/**
	 * Advance the coverage's last-modified term meta to the current GMT time.
	 * Used by trash/delete where the post's modified time may be stale and
	 * the cursor simply needs to advance past any client's `since` value.
	 *
	 * @param WP_Post $post Entry post object.
	 */
	private static function touch_coverage_last_modified_now( WP_Post $post ): void {
		self::update_coverage_last_modified( $post->ID, gmdate( DATE_ATOM ) );
	}

	/**
	 * Update the last-modified term meta for every coverage term assigned to
	 * the given entry post.
	 *
	 * @param int    $post_id  Entry post ID.
	 * @param string $modified ISO-8601 timestamp to store.
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

		if ( ! $post instanceof WP_Post || self::CPT_SLUG !== $post->post_type ) {
			return;
		}

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
}
