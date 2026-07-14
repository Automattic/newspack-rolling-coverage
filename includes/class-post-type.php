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
 * Handles registration of the rolling_coverage custom post type.
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

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_pinned_rest_field' ] );
		add_filter( 'posts_orderby', [ __CLASS__, 'orderby_pinned_first' ], 10, 2 );
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
					'coverage_id'          => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_numeric_id' ],
					],
					'max_takeaways'        => [
						'type'              => 'integer',
						'default'           => 5,
						'minimum'           => 1,
						'maximum'           => 10,
						'sanitize_callback' => 'absint',
					],
					'system_prompt'        => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'key_takeaways_prompt' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
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

		return "CASE WHEN {$wpdb->posts}.ID IN ({$ids_list}) THEN 0 ELSE 1 END, FIELD({$wpdb->posts}.ID, {$ids_list}), " . $orderby;
	}

	/**
	 * Validate a route parameter is a positive integer.
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function validate_numeric_id( $value ): bool {
		return ctype_digit( (string) $value ) && (int) $value > 0;
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
	 * Delegates to AI_Service::generate_key_takeaways(). When the request
	 * includes system_prompt or key_takeaways_prompt, those override the
	 * global AI settings defaults for this call only.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_generate_key_takeaways( WP_REST_Request $request ) {
		$coverage_id   = (int) $request->get_param( 'coverage_id' );
		$max_takeaways = (int) $request->get_param( 'max_takeaways' );

		$custom_system_prompt        = $request->get_param( 'system_prompt' );
		$custom_key_takeaways_prompt = $request->get_param( 'key_takeaways_prompt' );

		$result = AI_Service::generate_key_takeaways(
			$coverage_id,
			$max_takeaways,
			$custom_system_prompt,
			$custom_key_takeaways_prompt
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
}
