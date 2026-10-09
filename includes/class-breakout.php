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
 * The entry stores a forward link to it (self::ENTRY_BREAKOUT_POST_ID_META).
 */
class Breakout {

	// Stores the breakout post ID on the entry.
	const ENTRY_BREAKOUT_POST_ID_META = 'rolling_coverage_breakout_post_id';

	// Stores the source entry ID on the breakout post (reverse link).
	const BREAKOUT_SOURCE_ENTRY_META = 'rolling_coverage_source_entry_id';

	// Read-only REST field exposing the source entry ID to the block editor.
	const BREAKOUT_SOURCE_ENTRY_FIELD = 'rolling_coverage_source_entry';

	// Cached breakout post status stored on the source entry; also used as the REST field name.
	const BREAKOUT_STATUS_FIELD = 'rolling_coverage_breakout_status';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_field' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'cleanup_on_breakout_delete' ] );
		add_action( 'transition_post_status', [ __CLASS__, 'sync_breakout_status_to_entry' ], 10, 3 );
		add_action( 'transition_post_status', [ __CLASS__, 'on_breakout_post_status_change' ], 10, 3 );
	}

	/**
	 * Register the meta linking an entry and its breakout post, and the
	 * breakout's cached status. Only the breakout code writes them, so they
	 * are kept out of REST and can't be edited as custom fields.
	 */
	public static function register_meta(): void {
		$args = [
			'show_in_rest'  => false,
			'single'        => true,
			'type'          => 'integer',
			'default'       => 0,
			'auth_callback' => '__return_false',
		];

		register_post_meta( Post_Type::CPT_SLUG, self::ENTRY_BREAKOUT_POST_ID_META, $args );
		register_post_meta( 'post', self::BREAKOUT_SOURCE_ENTRY_META, $args );
		register_post_meta(
			Post_Type::CPT_SLUG,
			self::BREAKOUT_STATUS_FIELD,
			array_merge(
				$args,
				[
					'type'    => 'string',
					'default' => '',
				]
			)
		);
	}

	/**
	 * Expose the breakout's cached status as a REST field on the entry, and
	 * the source entry on the breakout post so the coverage blocks' editors
	 * can show the coverage it belongs to. The source entry is a read-only
	 * field rather than registered meta: the block editor sends all of a
	 * post's meta back on save, and read-only meta would refuse that save.
	 */
	public static function register_rest_field(): void {
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

		register_rest_field(
			'post',
			self::BREAKOUT_SOURCE_ENTRY_FIELD,
			[
				'get_callback' => [ __CLASS__, 'get_source_entry_field' ],
				'schema'       => [
					'description' => __( 'The entry this post was broken out from, or 0.', 'newspack-rolling-coverage' ),
					'type'        => 'integer',
					'context'     => [ 'edit' ],
					'readonly'    => true,
				],
			]
		);
	}

	/**
	 * REST field callback returning the entry a post was broken out from.
	 *
	 * @param array $object Post REST object data.
	 * @return int Source entry ID, or 0.
	 */
	public static function get_source_entry_field( array $object ): int {
		return self::viewable_source_entry_id( (int) $object['id'] );
	}

	/**
	 * The entry a post was broken out from, while readers can see it. A
	 * trashed or unpublished entry no longer gives the post a coverage.
	 *
	 * @param int $post_id Post ID.
	 * @return int Source entry ID, or 0.
	 */
	public static function viewable_source_entry_id( int $post_id ): int {
		$entry_id = (int) get_post_meta( $post_id, self::BREAKOUT_SOURCE_ENTRY_META, true );

		if ( ! $entry_id || Post_Type::CPT_SLUG !== get_post_type( $entry_id ) || ! is_post_publicly_viewable( $entry_id ) ) {
			return 0;
		}

		return $entry_id;
	}

	/**
	 * REST field callback returning the cached breakout post status.
	 *
	 * @param array $object Entry REST object data.
	 * @return string|null Breakout post status, or null if none exists.
	 */
	public static function get_breakout_status_field( array $object ): ?string {
		$status = get_post_meta( (int) $object['id'], self::BREAKOUT_STATUS_FIELD, true );
		return $status ? $status : null;
	}

	/**
	 * Update the cached breakout status on the source entry whenever the
	 * breakout post's status changes.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function sync_breakout_status_to_entry( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'post' !== $post->post_type ) {
			return;
		}

		$entry_id = (int) get_post_meta( $post->ID, self::BREAKOUT_SOURCE_ENTRY_META, true );

		if ( ! $entry_id ) {
			return;
		}

		update_post_meta( $entry_id, self::BREAKOUT_STATUS_FIELD, $new_status );
	}

	/**
	 * Register the custom REST route for creating a breakout post.
	 */
	public static function register_routes() {
		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
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

		if ( Archive_Mode::is_entry_locked( $entry_id ) ) {
			return false;
		}

		return current_user_can( Post_Type::EDIT_ENTRIES_CAP );
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
			: htmlspecialchars( Entry_Bindings::public_name( $entry, 10 ), ENT_NOQUOTES, 'UTF-8' );

		$new_post_id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_title'   => $title,
					'post_content' => $entry->post_content,
					'post_author'  => get_current_user_id(),
				]
			),
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

		$breakout_status = get_post_status( $new_post_id );

		update_post_meta( $entry_id, self::ENTRY_BREAKOUT_POST_ID_META, $new_post_id );
		update_post_meta( $new_post_id, self::BREAKOUT_SOURCE_ENTRY_META, $entry_id );
		update_post_meta( $entry_id, self::BREAKOUT_STATUS_FIELD, $breakout_status );

		return new WP_REST_Response(
			[
				'breakoutPostId' => $new_post_id,
				'editLink'       => get_edit_post_link( $new_post_id, 'raw' ),
				'status'         => $breakout_status,
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
			delete_post_meta( $entry_id, self::BREAKOUT_STATUS_FIELD );
			return 0;
		}

		return $breakout_id;
	}

	/**
	 * The link to an entry's breakout post, once that post is published.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return string|null
	 */
	public static function get_published_breakout_url( int $entry_id ): ?string {
		$breakout_id = self::get_existing_breakout_id( $entry_id );

		if ( ! $breakout_id || 'publish' !== get_post_status( $breakout_id ) ) {
			return null;
		}

		return get_permalink( $breakout_id ) ?: null; // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
	}

	/**
	 * Touches the source entry when its breakout post is published or stops
	 * being published, so the polling endpoint re-renders the entry (its title
	 * link and "Read more" come and go with the breakout) and delivers it to
	 * active readers on the next poll cycle.
	 *
	 * @param string  $new_status Incoming post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function on_breakout_post_status_change( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'post' !== $post->post_type || ( 'publish' === $new_status ) === ( 'publish' === $old_status ) ) {
			return;
		}

		self::touch_source_entry( $post->ID );
	}

	/**
	 * Bump the source entry's modified date so polling readers get it again.
	 *
	 * @param int $breakout_id Breakout post ID.
	 */
	private static function touch_source_entry( int $breakout_id ): void {
		$entry_id = (int) get_post_meta( $breakout_id, self::BREAKOUT_SOURCE_ENTRY_META, true );

		if ( $entry_id && get_post( $entry_id ) ) {
			Post_Type::touch_entry( $entry_id );
		}
	}

	/**
	 * Clean up the source entry's breakout meta when its breakout post is
	 * permanently deleted, so a new breakout can be created afterward. A
	 * published breakout deleted outright also refreshes the entry for readers.
	 *
	 * Reads the source entry from the breakout post's reverse link
	 * (self::BREAKOUT_SOURCE_ENTRY_META).
	 *
	 * @param int $post_id ID of the post being deleted.
	 */
	public static function cleanup_on_breakout_delete( int $post_id ) {
		$entry_id = (int) get_post_meta( $post_id, self::BREAKOUT_SOURCE_ENTRY_META, true );

		if ( ! $entry_id ) {
			return;
		}

		delete_post_meta( $entry_id, self::ENTRY_BREAKOUT_POST_ID_META );
		delete_post_meta( $entry_id, self::BREAKOUT_STATUS_FIELD );

		if ( 'publish' === get_post_status( $post_id ) ) {
			self::touch_source_entry( $post_id );
		}
	}
}
