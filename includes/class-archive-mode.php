<?php
/**
 * Archive Mode: editorial restrictions for archived coverages and entries.
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
 * Applies Archive Mode restrictions to coverages and entries.
 *
 * Locks archived entries against deletion, restoration, and breakout post
 * creation, while preventing new entries from being added to archived
 * coverages. Content edits remain unrestricted.
 */
class Archive_Mode {

	/**
	 * Post meta key flagging an individually archived entry. Stores the
	 * archive timestamp; any non-empty value means the entry is archived.
	 */
	const ENTRY_ARCHIVED_META_KEY = '_rolling_coverage_archived_at';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_filter( 'map_meta_cap', [ __CLASS__, 'restrict_archived_entry_caps' ], 10, 4 );
		add_filter( 'rest_pre_insert_' . Post_Type::CPT_SLUG, [ __CLASS__, 'block_rest_writes' ], 10, 2 );
	}

	/**
	 * Whether an entry is individually archived.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return bool
	 */
	public static function is_entry_archived( int $entry_id ): bool {
		return '' !== (string) get_post_meta( $entry_id, self::ENTRY_ARCHIVED_META_KEY, true );
	}

	/**
	 * The Unix timestamp when an entry was archived, or 0 when not archived.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return int
	 */
	public static function get_entry_archived_at( int $entry_id ): int {
		return (int) get_post_meta( $entry_id, self::ENTRY_ARCHIVED_META_KEY, true );
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
				'permission_callback' => [ __CLASS__, 'can_set_entry_archived' ],
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
	 * Permission check for archiving or unarchiving an entry.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function can_set_entry_archived( WP_REST_Request $request ): bool {
		$entry_id = (int) $request->get_param( 'entry_id' );

		return current_user_can( 'edit_post', $entry_id ) && current_user_can( 'publish_post', $entry_id );
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

		if ( self::is_entry_archived( $post->ID ) ) {
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
	 * The error returned when an entry is assigned to an archived coverage.
	 *
	 * @return WP_Error
	 */
	public static function archived_error() {
		return new WP_Error(
			'rolling_coverage_entry_locked',
			__( 'This entry cannot be assigned to an archived coverage.', 'newspack-rolling-coverage' ),
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
	 * Blocks REST requests from adding entries to archived coverages.
	 *
	 * Existing coverage assignments are ignored, allowing entries to be saved
	 * without changing their current archived coverage.
	 *
	 * @param \stdClass       $prepared_post Post object about to be inserted.
	 * @param WP_REST_Request $request       Request object.
	 * @return \stdClass|WP_Error Prepared post, or error when archived.
	 */
	public static function block_rest_writes( $prepared_post, WP_REST_Request $request ) {
		$requested_coverages = wp_parse_id_list( $request[ Taxonomy::REST_BASE ] ?? [] );

		if ( empty( $requested_coverages ) ) {
			return $prepared_post;
		}

		$new_coverages = array_diff( $requested_coverages, self::get_existing_coverage_ids( $prepared_post ) );

		foreach ( $new_coverages as $coverage_id ) {
			if ( self::is_coverage_archived( $coverage_id ) ) {
				return self::archived_error();
			}
		}

		return $prepared_post;
	}

	/**
	 * Coverage term IDs already assigned to a post, or an empty array for a
	 * new post or on lookup failure.
	 *
	 * @param \stdClass $prepared_post Post object about to be inserted.
	 * @return int[]
	 */
	private static function get_existing_coverage_ids( $prepared_post ): array {
		if ( empty( $prepared_post->ID ) ) {
			return [];
		}

		$terms = wp_get_post_terms( $prepared_post->ID, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] );

		return is_wp_error( $terms ) ? [] : $terms;
	}

	/**
	 * Archives or unarchives a single entry.
	 *
	 * Archive state lives in post meta and the entry stays published, so
	 * feed queries and the publish transition are unaffected.
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

		$coverage_ids = wp_get_post_terms( $entry_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] );

		if ( ! is_wp_error( $coverage_ids ) ) {
			foreach ( $coverage_ids as $coverage_id ) {
				if ( self::is_coverage_archived( $coverage_id ) ) {
					return new WP_Error(
						'rolling_coverage_coverage_archived',
						__( "This entry's coverage is archived; unarchive the coverage first.", 'newspack-rolling-coverage' ),
						[ 'status' => 403 ]
					);
				}
			}
		}

		$is_archived = self::is_entry_archived( $entry_id );

		if ( $archived === $is_archived ) {
			return new WP_REST_Response( [ 'archived' => $is_archived ], 200 );
		}

		if ( $archived && 'publish' !== $post->post_status ) {
			return new WP_Error(
				'rolling_coverage_cannot_archive_unpublished',
				__( 'Only published entries can be archived.', 'newspack-rolling-coverage' ),
				[ 'status' => 400 ]
			);
		}

		if ( $archived ) {
			update_post_meta( $entry_id, self::ENTRY_ARCHIVED_META_KEY, time() );
		} else {
			delete_post_meta( $entry_id, self::ENTRY_ARCHIVED_META_KEY );
		}

		// Bump post_modified so live feeds re-render the entry.
		$updated = wp_update_post( [ 'ID' => $entry_id ], true );

		if ( is_wp_error( $updated ) || 0 === $updated ) {
			return new WP_Error(
				'rolling_coverage_archive_failed',
				__( 'Failed to update entry.', 'newspack-rolling-coverage' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( [ 'archived' => $archived ], 200 );
	}
}
