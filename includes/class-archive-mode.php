<?php
/**
 * Archive Mode: read-only enforcement for archived coverages.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces Archive Mode for entries in archived coverages: entries cannot
 * be edited, added, trashed, restored, or given new breakout posts — as
 * opposed to 'paused', which only stops live updates but still allows
 * editorial work.
 */
class Archive_Mode {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'map_meta_cap', [ __CLASS__, 'restrict_archived_entry_caps' ], 10, 4 );
		add_filter( 'rest_pre_insert_' . Post_Type::CPT_SLUG, [ __CLASS__, 'block_rest_writes' ], 10, 2 );
		add_action( 'load-post.php', [ __CLASS__, 'block_post_edit_screen' ] );
	}

	/**
	 * Checks whether a coverage is archived.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return bool
	 */
	public static function is_coverage_archived( int $coverage_id ): bool {
		return 'archived' === get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );
	}

	/**
	 * Checks whether an entry is locked by Archive Mode.
	 *
	 * True when the entry belongs to an archived coverage, or — for
	 * unassigned entries such as trashed ones — when its recorded original
	 * coverage is archived.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return bool
	 */
	public static function is_entry_locked( int $entry_id ): bool {
		$terms = get_the_terms( $entry_id, Taxonomy::TAXONOMY_SLUG );

		if ( is_wp_error( $terms ) ) {
			return false;
		}

		if ( ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( self::is_coverage_archived( (int) $term->term_id ) ) {
					return true;
				}
			}

			return false;
		}

		$original_id = (int) get_post_meta( $entry_id, Post_Type::META_ORIGINAL_COVERAGE_ID, true );

		return $original_id && self::is_coverage_archived( $original_id );
	}

	/**
	 * The error returned for any entry write against an archived coverage.
	 *
	 * @return WP_Error
	 */
	public static function archived_error(): WP_Error {
		return new WP_Error(
			'rolling_coverage_coverage_archived',
			__( 'Entries in archived coverages can no longer be modified.', 'newspack-rolling-coverage' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Denies delete_post for entries locked by Archive Mode, blocking the
	 * initial trash via REST and wp-admin alike. Trashed entries are exempt,
	 * so permanent deletion remains available.
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
	 * Blocks REST writes touched by Archive Mode: updating an entry that
	 * belongs to an archived coverage, and creating or reassigning an entry
	 * into an archived coverage.
	 *
	 * @param \stdClass       $prepared_post Post object about to be inserted.
	 * @param WP_REST_Request $request       Request object.
	 * @return \stdClass|WP_Error Prepared post, or error when archived.
	 */
	public static function block_rest_writes( $prepared_post, WP_REST_Request $request ) {
		// Updates: the entry is already in an archived coverage.
		if ( ! empty( $prepared_post->ID ) && self::is_entry_locked( (int) $prepared_post->ID ) ) {
			return self::archived_error();
		}

		// Creates/reassignments: the request targets an archived coverage.
		$requested_coverages = $request[ Taxonomy::REST_BASE ] ?? null;

		if ( empty( $requested_coverages ) || ! is_array( $requested_coverages ) ) {
			return $prepared_post;
		}

		foreach ( array_map( 'intval', $requested_coverages ) as $coverage_id ) {
			if ( self::is_coverage_archived( $coverage_id ) ) {
				return self::archived_error();
			}
		}

		return $prepared_post;
	}

	/**
	 * Blocks the wp-admin post.php screen for entries locked by Archive
	 * Mode.
	 */
	public static function block_post_edit_screen(): void {
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
				esc_html__( 'Entries in archived coverages can no longer be modified.', 'newspack-rolling-coverage' ),
				esc_html__( 'Coverage archived', 'newspack-rolling-coverage' ),
				[
					'response'  => 403,
					'back_link' => true,
				]
			);
		}
	}
}
