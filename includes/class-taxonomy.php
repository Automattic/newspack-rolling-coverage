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
	const CANONICAL_URL_META_KEY = 'rolling_coverage_canonical_url';

	// REST field holding the URL of the published page that displays the coverage.
	const PAGE_URL_REST_FIELD = 'pageUrl';

	// Cache last-changed group for the coverage-to-page map.
	const PAGE_IDS_CACHE_GROUP = 'newspack-rolling-coverage-pages';

	// Term meta key for disabling ads on a coverage term.
	const ADS_DISABLED_META_KEY = 'rolling_coverage_ads_disabled';

	// Status values.
	const STATUS_ACTIVE   = 'active';
	const STATUS_PAUSED   = 'paused';
	const STATUS_ARCHIVED = 'archived';

	// Term meta keys for created/modified timestamps.
	const CREATED_AT_META_KEY  = 'created_at';
	const MODIFIED_AT_META_KEY = 'modified_at';

	/**
	 * Raw GMT snapshot of LAST_MODIFIED_META_KEY, taken when the coverage is
	 * archived. Used for schema.org's coverageEndTime (normalized to ISO 8601
	 * at output time by Schema::build_metadata).
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
	 * context. Core gates the edit context on `edit_term`, which maps to
	 * `manage_categories` for this taxonomy, so Editors and Administrators
	 * receive these keys; read access must be blocked separately for the
	 * `view` context (see filter_rest_response()).
	 *
	 * The generic source keys stay manager-only; the Slack channel ID/name
	 * are exposed to any user who can access the plugin (see
	 * filter_rest_response()) because they only power the read-only Slack
	 * column and are not credentials.
	 */
	const RESTRICTED_META = [
		self::META_SOURCE,
		self::META_SOURCE_REF,
	];

	/**
	 * Term meta keys exposed to any logged-in user with `edit_posts` (i.e.
	 * anyone who can open the Rolling Coverage admin page). These identify
	 * the linked Slack channel and are not sensitive credentials.
	 */
	const VIEWABLE_META = [
		self::META_SLACK_CHANNEL_ID,
		self::META_SLACK_CHANNEL_NAME,
	];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'clean_post_cache', [ __CLASS__, 'flush_coverage_page_ids' ], 10, 2 );
		add_action( 'transition_post_status', [ __CLASS__, 'touch_pages_after_status_change' ], 10, 3 );
		add_action( 'set_object_terms', [ __CLASS__, 'touch_pages_after_term_change' ], 10, 4 );
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
			// Canonical URL to build push-notification links from; empty until set.
			self::CANONICAL_URL_META_KEY                   => [
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => [ __CLASS__, 'sanitize_canonical_url' ],
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
			// Raw GMT snapshot of the coverage's last-modified time, taken when
			// archived. See maybe_snapshot_end_time().
			self::END_TIME_META_KEY                        => [
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => '',
			],
			// Raw GMT timestamp (Y-m-d H:i:s) of the coverage's latest entry activity.
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
			// Boolean flag to disable ads on the coverage page; manage_options-gated via auth_callback.
			self::ADS_DISABLED_META_KEY                    => [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'boolean',
				'default'       => false,
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
	 * Sanitizes the canonical URL meta value, rejecting anything not on the
	 * site's own host.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string Sanitized URL, or '' if empty or off-site.
	 */
	public static function sanitize_canonical_url( $value ): string {
		$url = esc_url_raw( (string) $value );

		if ( '' === $url ) {
			return '';
		}

		$url_host  = wp_parse_url( $url, PHP_URL_HOST ) ?? '';
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? '';

		return strtolower( $url_host ) === strtolower( $site_host ) ? $url : '';
	}

	/**
	 * Register REST routes for coverage trash/restore/delete operations.
	 */
	public static function register_routes() {
		register_rest_field(
			self::TAXONOMY_SLUG,
			self::PAGE_URL_REST_FIELD,
			[
				'get_callback' => [ __CLASS__, 'get_page_url_rest_field' ],
				'schema'       => [
					'type'     => 'string',
					'context'  => [ 'edit', 'view' ],
					'readonly' => true,
				],
			]
		);

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

			// The Slack channel label powers a read-only admin column and is
			// not sensitive, so keep it for anyone who can open the plugin.
			if ( ! current_user_can( 'edit_posts' ) ) {
				foreach ( self::VIEWABLE_META as $meta_key ) {
					unset( $data['meta'][ $meta_key ] );
				}
			}
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * REST field callback returning the URL of the page that displays a coverage.
	 *
	 * Only editors see it, so public term requests never pay for the lookup.
	 *
	 * @param array $term Coverage REST object data.
	 * @return string Page URL, or '' when there is none or the user can't edit posts.
	 */
	public static function get_page_url_rest_field( array $term ): string {
		if ( ! isset( $term['id'] ) || ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return self::get_coverage_page_url( (int) $term['id'] );
	}

	/**
	 * Returns the URL of the page that displays a coverage: its canonical URL
	 * when set, since share links and notifications send readers there, else
	 * the newest published post embedding a Rolling Coverage block for it.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string Page URL, or '' when the coverage has no page.
	 */
	public static function get_coverage_page_url( int $coverage_id ): string {
		$canonical_url = (string) get_term_meta( $coverage_id, self::CANONICAL_URL_META_KEY, true );

		if ( '' !== $canonical_url ) {
			return $canonical_url;
		}

		$post_id = self::get_coverage_page_ids()[ $coverage_id ][0] ?? 0;

		return $post_id ? (string) get_permalink( $post_id ) : '';
	}

	/**
	 * Invalidates the coverage-to-page map when a post that could host the
	 * block changes. Entries, their revisions and autosaves are ignored: they
	 * change constantly during live coverage and never host the block.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function flush_coverage_page_ids( $post_id, $post ): void {
		if ( $post instanceof \WP_Post && ! self::can_host_coverage_block( $post->post_type ) ) {
			return;
		}

		wp_cache_set_last_changed( self::PAGE_IDS_CACHE_GROUP );
	}

	/**
	 * Dates the pages showing an entry's coverages to a change readers can see:
	 * an entry published, edited while published, or taken down.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       Post object.
	 */
	public static function touch_pages_after_status_change( string $new_status, string $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || Post_Type::CPT_SLUG !== $post->post_type ) {
			return;
		}

		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}

		self::touch_coverage_pages( $post );
	}

	/**
	 * Dates the pages showing a published entry's coverages when the entry is
	 * assigned to them. Entries created through REST or Slack get their
	 * coverage only after they're published, so the status change misses them.
	 *
	 * @param int    $object_id Object ID.
	 * @param array  $terms     Term IDs or slugs assigned.
	 * @param array  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy slug.
	 */
	public static function touch_pages_after_term_change( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( self::TAXONOMY_SLUG !== $taxonomy ) {
			return;
		}

		$post = get_post( $object_id );

		if ( ! $post instanceof \WP_Post || Post_Type::CPT_SLUG !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		self::touch_coverage_pages( $post );
	}

	/**
	 * Moves the modified date of every page showing the entry's coverages up to
	 * the entry's, so the byline, the SEO plugin's dates and the sitemap all
	 * report the page as changed. A page is never moved back in time.
	 *
	 * @param \WP_Post $entry Entry post object.
	 */
	private static function touch_coverage_pages( \WP_Post $entry ): void {
		$modified_gmt = $entry->post_modified_gmt;

		if ( '' === $modified_gmt || '0000-00-00 00:00:00' === $modified_gmt ) {
			return;
		}

		$coverage_ids = wp_get_post_terms( $entry->ID, self::TAXONOMY_SLUG, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $coverage_ids ) || empty( $coverage_ids ) ) {
			return;
		}

		$page_ids = self::get_coverage_page_ids();

		global $wpdb;

		foreach ( $coverage_ids as $coverage_id ) {
			foreach ( $page_ids[ (int) $coverage_id ] ?? [] as $page_id ) {
				$page = get_post( $page_id );

				if ( ! $page instanceof \WP_Post || $page->post_modified_gmt >= $modified_gmt ) {
					continue;
				}

				// Written directly because wp_update_post() re-saves the whole page:
				// it would run the page's content through the current user's HTML
				// filters, and entries are often published by Authors or by the Slack
				// integration, who can't post unfiltered HTML. Only the post cache is
				// cleared, not clean_post_cache(), because the page's content, and so
				// this map, hasn't changed.
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->posts,
					[
						'post_modified'     => $entry->post_modified,
						'post_modified_gmt' => $modified_gmt,
					],
					[ 'ID' => $page_id ]
				);
				wp_cache_delete( $page_id, 'posts' );
			}
		}
	}

	/**
	 * Whether posts of a type can be the page that shows a coverage.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private static function can_host_coverage_block( string $post_type ): bool {
		return Post_Type::CPT_SLUG !== $post_type && 'attachment' !== $post_type && is_post_type_viewable( $post_type );
	}

	/**
	 * Maps each coverage to the published posts embedding it, newest first.
	 *
	 * @return array<int,int[]> Map of coverage term ID => post IDs.
	 */
	private static function get_coverage_page_ids(): array {
		$cache_key = 'coverage_pages:' . wp_cache_get_last_changed( self::PAGE_IDS_CACHE_GROUP );
		$cached    = wp_cache_get( $cache_key, self::PAGE_IDS_CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$post_types = array_values( array_filter( get_post_types(), [ __CLASS__, 'can_host_coverage_block' ] ) );

		$map = [];

		if ( $post_types ) {
			global $wpdb;

			$posts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN (" . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ') AND post_content LIKE %s ORDER BY post_date DESC, ID DESC',
					array_merge( $post_types, [ '%' . $wpdb->esc_like( '<!-- wp:' . Schema::BLOCK_NAME . ' ' ) . '%' ] )
				)
			);

			foreach ( $posts as $post ) {
				foreach ( Schema::flatten_blocks( parse_blocks( $post->post_content ) ) as $block ) {
					$coverage_id = (int) ( $block['attrs']['coverageId'] ?? 0 );

					if ( Schema::BLOCK_NAME === ( $block['blockName'] ?? '' ) && $coverage_id && ! in_array( (int) $post->ID, $map[ $coverage_id ] ?? [], true ) ) {
						$map[ $coverage_id ][] = (int) $post->ID;
					}
				}
			}
		}

		wp_cache_set( $cache_key, $map, self::PAGE_IDS_CACHE_GROUP );

		return $map;
	}
}
