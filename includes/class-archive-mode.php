<?php
/**
 * Archive Mode: read-only enforcement for archived coverages and entries.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces read-only behavior for archived coverages and entries.
 *
 * Archive Mode prevents editorial actions such as editing, deleting,
 * restoring, or creating breakout posts.
 */
class Archive_Mode {

	/**
	 * Post status for individually archived entries.
	 */
	const ENTRY_ARCHIVED_STATUS = 'archived';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_entry_status' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_filter( 'map_meta_cap', [ __CLASS__, 'restrict_archived_entry_caps' ], 10, 4 );
		add_filter( 'rest_pre_insert_' . Post_Type::CPT_SLUG, [ __CLASS__, 'block_rest_writes' ], 10, 2 );
		add_action( 'load-post.php', [ __CLASS__, 'block_post_edit_screen' ] );
	}

	/**
	 * Registers the 'archived' post status for individually archived entries.
	 */
	public static function register_entry_status() {
		register_post_status(
			self::ENTRY_ARCHIVED_STATUS,
			[
				'label'               => _x( 'Archived', 'post status', 'newspack-rolling-coverage' ),
				/* translators: %s: Number of archived entries. */
				'label_count'         => _n_noop(
					'Archived <span class="count">(%s)</span>',
					'Archived <span class="count">(%s)</span>',
					'newspack-rolling-coverage'
				),
				'public'              => true,
				'exclude_from_search' => false,
			]
		);
	}

	/**
	 * Registers the entry archive REST route.
	 */
	public static function register_routes() {
		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/entries/(?P<entry_id>\d+)/archive',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'handle_set_entry_archived' ],
				'permission_callback' => [ Post_Type::class, 'can_edit_entry' ],
				'args'                => [
					'entry_id' => [
						'required'          => true,
						'validate_callback' => [ Post_Type::class, 'validate_numeric_id' ],
					],
					'archived' => [
						'required' => true,
						'type'     => 'boolean',
					],
				],
			]
		);
	}

	/**
	 * Checks whether a coverage is archived.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return bool
	 */
	public static function is_coverage_archived( int $coverage_id ) {
		return Taxonomy::STATUS_ARCHIVED === get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );
	}

	/**
	 * Checks whether an entry is locked by Archive Mode.
	 *
	 * Entries are locked when individually archived or assigned to an
	 * archived coverage.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return bool
	 */
	public static function is_entry_locked( int $entry_id ) {
		$post = get_post( $entry_id );

		if ( ! $post ) {
			return false;
		}

		if ( self::ENTRY_ARCHIVED_STATUS === $post->post_status ) {
			return true;
		}

		$terms = get_the_terms( $post->ID, Taxonomy::TAXONOMY_SLUG );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return false;
		}

		foreach ( $terms as $term ) {
			if ( self::is_coverage_archived( (int) $term->term_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The error returned for any write against a locked entry.
	 *
	 * @return WP_Error
	 */
	public static function archived_error() {
		return new WP_Error(
			'rolling_coverage_entry_locked',
			__( 'This entry cannot be modified while it or its coverage is archived.', 'newspack-rolling-coverage' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Prevents locked entries from being trashed.
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Meta capability being checked.
	 * @param int      $user_id User ID (unused).
	 * @param array    $args    Additional arguments; $args[0] is the post ID.
	 * @return string[] Filtered primitive capabilities.
	 */
	public static function restrict_archived_entry_caps( $caps, $cap, $user_id, $args ) {
		if ( 'delete_post' !== $cap || empty( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );

		if ( ! $post || Post_Type::CPT_SLUG !== $post->post_type || 'trash' === $post->post_status ) {
			return $caps;
		}

		if ( self::is_entry_locked( $post->ID ) ) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}

	/**
	 * Blocks REST writes for locked entries and archived coverages.
	 *
	 * @param \stdClass       $prepared_post Post object about to be inserted.
	 * @param WP_REST_Request $request       Request object.
	 * @return \stdClass|WP_Error Prepared post, or error when archived.
	 */
	public static function block_rest_writes( $prepared_post, WP_REST_Request $request ) {
		if ( ! empty( $prepared_post->ID ) && self::is_entry_locked( (int) $prepared_post->ID ) ) {
			return self::archived_error();
		}

		$requested_coverages = wp_parse_id_list( $request[ Taxonomy::REST_BASE ] ?? [] );

		if ( empty( $requested_coverages ) ) {
			return $prepared_post;
		}

		foreach ( $requested_coverages as $coverage_id ) {
			if ( self::is_coverage_archived( $coverage_id ) ) {
				return self::archived_error();
			}
		}

		return $prepared_post;
	}

	/**
	 * Blocks the wp-admin edit screen for locked entries.
	 *
	 * The guard runs on screen load so archived entries cannot be modified
	 * through classic editor form submissions.
	 */
	public static function block_post_edit_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only guard on an admin screen load.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		if ( ! $post_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only guard before post.php handles the save.
			$post_id = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;
		}

		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post || Post_Type::CPT_SLUG !== $post->post_type ) {
			return;
		}

		if ( self::is_entry_locked( $post->ID ) ) {
			wp_die(
				esc_html__( 'This entry cannot be modified while it or its coverage is archived.', 'newspack-rolling-coverage' ),
				esc_html__( 'Entry archived', 'newspack-rolling-coverage' ),
				[
					'response'  => 403,
					'back_link' => true,
				]
			);
		}
	}

	/**
	 * Archives or unarchives a single entry.
	 *
	 * Only published entries can be archived, so unarchiving always
	 * restores 'publish'.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_set_entry_archived( WP_REST_Request $request ) {
		$entry_id = (int) $request->get_param( 'entry_id' );
		$archived = (bool) $request->get_param( 'archived' );

		$post = get_post( $entry_id );

		if ( ! $post || Post_Type::CPT_SLUG !== $post->post_type ) {
			return new WP_Error(
				'rolling_coverage_entry_not_found',
				__( 'Entry not found.', 'newspack-rolling-coverage' ),
				[ 'status' => 404 ]
			);
		}

		$is_archived = self::ENTRY_ARCHIVED_STATUS === $post->post_status;

		if ( $archived === $is_archived ) {
			return new WP_REST_Response( [ 'status' => $post->post_status ], 200 );
		}

		if ( $archived && 'publish' !== $post->post_status ) {
			return new WP_Error(
				'rolling_coverage_cannot_archive_unpublished',
				__( 'Only published entries can be archived.', 'newspack-rolling-coverage' ),
				[ 'status' => 400 ]
			);
		}

		$new_status = $archived ? self::ENTRY_ARCHIVED_STATUS : 'publish';

		$updated = wp_update_post(
			[
				'ID'          => $entry_id,
				'post_status' => $new_status,
			],
			true
		);

		if ( is_wp_error( $updated ) || 0 === $updated ) {
			return new WP_Error(
				'rolling_coverage_archive_failed',
				__( 'Failed to update entry status.', 'newspack-rolling-coverage' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( [ 'status' => $new_status ], 200 );
	}
}
