<?php
/**
 * Breakout post feature: clone an entry into a standalone draft post.
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
 * Handles creation, linking, and cleanup of breakout posts.
 *
 * A breakout post is a standard `post` cloned from a rolling coverage entry.
 * The entry stores a forward link to it (self::ENTRY_BREAKOUT_POST_ID_META)
 * and an optional "read more" label (self::ENTRY_READ_MORE_TEXT_META).
 */
class Breakout {

	// Stores the breakout post ID on the entry.
	const ENTRY_BREAKOUT_POST_ID_META = 'rolling_coverage_breakout_post_id';

	// Configurable "read more" text for the entry's breakout link.
	const ENTRY_READ_MORE_TEXT_META = 'rolling_coverage_breakout_read_more_text';

	// Stores the source entry ID on the breakout post (reverse link).
	const BREAKOUT_SOURCE_ENTRY_META = 'rolling_coverage_source_entry_id';

	// Computed (non-meta) REST field exposing the breakout post's live status.
	const BREAKOUT_STATUS_FIELD = 'rolling_coverage_breakout_status';

	// Namespace for the custom REST route.
	const REST_NAMESPACE = 'rolling-coverage/v1';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_field' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'cleanup_on_breakout_delete' ] );
	}

	/**
	 * Register postmeta used by the breakout feature.
	 */
	public static function register_meta() {
		register_post_meta(
			Post_Type::CPT_SLUG,
			self::ENTRY_BREAKOUT_POST_ID_META,
			[
				'show_in_rest'  => [
					'schema' => [
						'type'    => 'integer',
						'context' => [ 'edit' ],
					],
				],
				'single'        => true,
				'type'          => 'integer',
				'default'       => 0,
				'auth_callback' => '__return_false', // Read-only over REST.
			]
		);

		register_post_meta(
			Post_Type::CPT_SLUG,
			self::ENTRY_READ_MORE_TEXT_META,
			[
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			]
		);
	}

	/**
	 * Expose the breakout post's live status as a computed REST field on
	 * the entry, so the admin DataViews column always reflects the current
	 * status without a separate request.
	 */
	public static function register_rest_field() {
		register_rest_field(
			Post_Type::CPT_SLUG,
			self::BREAKOUT_STATUS_FIELD,
			[
				'get_callback' => [ __CLASS__, 'get_breakout_status_field' ],
				'schema'       => [
					'type'    => [ 'string', 'null' ],
					'context' => [ 'edit' ],
				],
			]
		);
	}

	/**
	 * REST field callback returning the breakout post's live status.
	 *
	 * @param array $object Entry REST object data.
	 * @return string|null Breakout post status, or null if none exists.
	 */
	public static function get_breakout_status_field( array $object ) {
		$breakout_id = self::get_existing_breakout_id( (int) $object['id'] );

		return $breakout_id ? get_post_status( $breakout_id ) : null;
	}

	/**
	 * Register the custom REST route for creating a breakout post.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/entries/(?P<entry_id>\d+)/breakout',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_breakout' ],
				'permission_callback' => [ __CLASS__, 'can_create_breakout' ],
				'args'                => [
					'entry_id' => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_entry_id' ],
					],
				],
			]
		);
	}

	/**
	 * Validates the entry_id route parameter is numeric.
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function validate_entry_id( $value ) {
		return is_numeric( $value );
	}

	/**
	 * Permission check for the create-breakout route.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function can_create_breakout( WP_REST_Request $request ) {
		$entry_id = (int) $request->get_param( 'entry_id' );

		return current_user_can( 'edit_post', $entry_id );
	}

	/**
	 * Clone an entry into a standalone draft post.
	 *
	 * Copies title, content, categories, tags, and featured image from the
	 * entry. The new post's author is always the user performing the
	 * action, not the entry's original author.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_breakout( WP_REST_Request $request ) {
		$entry_id = (int) $request->get_param( 'entry_id' );
		$entry    = get_post( $entry_id );

		if ( ! $entry instanceof WP_Post || Post_Type::CPT_SLUG !== $entry->post_type ) {
			return new WP_Error(
				'rolling_coverage_entry_not_found',
				__( 'Entry not found.', 'newspack-rolling-coverage' ),
				[ 'status' => 404 ]
			);
		}

		if ( self::get_existing_breakout_id( $entry_id ) ) {
			return new WP_Error(
				'rolling_coverage_already_broken_out',
				__( 'This entry already has a breakout post.', 'newspack-rolling-coverage' ),
				[ 'status' => 400 ]
			);
		}

		$title = $entry->post_title
			? $entry->post_title
			: wp_trim_words( wp_strip_all_tags( $entry->post_content ), 10, '…' );

		$new_post_id = wp_insert_post(
			[
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $entry->post_content,
				'post_author'  => get_current_user_id(),
			],
			true
		);

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		$categories = wp_get_post_categories( $entry_id );
		if ( ! empty( $categories ) ) {
			wp_set_post_categories( $new_post_id, $categories );
		}

		$tags = wp_get_post_tags( $entry_id, [ 'fields' => 'ids' ] );
		if ( ! empty( $tags ) ) {
			wp_set_post_tags( $new_post_id, $tags );
		}

		$thumbnail_id = get_post_thumbnail_id( $entry_id );
		if ( $thumbnail_id ) {
			set_post_thumbnail( $new_post_id, $thumbnail_id );
		}

		update_post_meta( $entry_id, self::ENTRY_BREAKOUT_POST_ID_META, $new_post_id );
		update_post_meta( $new_post_id, self::BREAKOUT_SOURCE_ENTRY_META, $entry_id );

		return new WP_REST_Response(
			[
				'breakoutPostId' => $new_post_id,
				'editLink'       => get_edit_post_link( $new_post_id, 'raw' ),
				'status'         => get_post_status( $new_post_id ),
			],
			201
		);
	}

	/**
	 * Resolve the entry's breakout post ID, self-healing if the stored ID
	 * points at a post that no longer exists (e.g. deleted through a path
	 * that bypassed cleanup_on_breakout_delete()).
	 *
	 * @param int $entry_id Entry post ID.
	 * @return int Breakout post ID, or 0 if none exists.
	 */
	public static function get_existing_breakout_id( int $entry_id ) {
		$breakout_id = (int) get_post_meta( $entry_id, self::ENTRY_BREAKOUT_POST_ID_META, true );

		if ( ! $breakout_id ) {
			return 0;
		}

		if ( ! get_post( $breakout_id ) ) {
			delete_post_meta( $entry_id, self::ENTRY_BREAKOUT_POST_ID_META );
			delete_post_meta( $entry_id, self::ENTRY_READ_MORE_TEXT_META );
			return 0;
		}

		return $breakout_id;
	}

	/**
	 * Clean up the source entry's breakout meta when its breakout post is
	 * permanently deleted, so a new breakout can be created afterward.
	 *
	 * Reads the source entry from the breakout post's reverse link
	 * (self::BREAKOUT_SOURCE_ENTRY_META).
	 *
	 * @param int $post_id ID of the post being deleted.
	 */
	public static function cleanup_on_breakout_delete( int $post_id ) {
		$entry_id = (int) get_post_meta( $post_id, self::BREAKOUT_SOURCE_ENTRY_META, true );

		if ( $entry_id ) {
			delete_post_meta( $entry_id, self::ENTRY_BREAKOUT_POST_ID_META );
			delete_post_meta( $entry_id, self::ENTRY_READ_MORE_TEXT_META );
		}
	}
}
