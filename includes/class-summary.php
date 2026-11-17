<?php
/**
 * A coverage's Summary: the one entry pinned above the feed as its summary card.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The Summary is an entry flagged with META_KEY. A coverage holds at most one
 * outside the trash. It shows only as the layout's summary card, so every
 * entry query leaves it out unless it asks for it (QUERY_VAR), and nothing
 * that reacts to a new entry treats it as one.
 */
class Summary {

	// Post meta flagging an entry as its coverage's Summary. Protected, so only the create route sets it.
	const META_KEY = '_rolling_coverage_summary';

	// Query var: 'include' lists the Summary with the entries, 'only' lists nothing else. Absent leaves it out.
	const QUERY_VAR = 'rolling_coverage_summary';

	// Query var putting the Summary ahead of every other entry, as the entries list shows it.
	const FIRST_QUERY_VAR = 'rolling_coverage_summary_first';

	// REST field on the core entries route.
	const REST_FIELD = 'isSummary';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_query' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_field' ] );
		add_filter( 'rest_pre_insert_' . Post_Type::CPT_SLUG, [ __CLASS__, 'check_rest_save' ], 10, 2 );
	}

	/**
	 * Register the flag. It stays out of REST so no request can turn an entry
	 * into a Summary; the read-only REST_FIELD reports it instead.
	 */
	public static function register_meta(): void {
		register_post_meta(
			Post_Type::CPT_SLUG,
			self::META_KEY,
			[
				'type'         => 'boolean',
				'single'       => true,
				'show_in_rest' => false,
			]
		);
	}

	/**
	 * Expose whether an entry is a Summary wherever entries are read.
	 */
	public static function register_rest_field(): void {
		register_rest_field(
			Post_Type::CPT_SLUG,
			self::REST_FIELD,
			[
				'get_callback' => static fn( array $post ): bool => self::is_summary( (int) $post['id'] ),
				'schema'       => [
					'description' => __( 'Whether the entry is its coverage’s Summary, shown above the feed rather than in it.', 'newspack-rolling-coverage' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
			]
		);
	}

	/**
	 * Whether an entry is a Summary.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return bool
	 */
	public static function is_summary( int $entry_id ): bool {
		return $entry_id > 0 && (bool) get_post_meta( $entry_id, self::META_KEY, true );
	}

	/**
	 * A coverage's Summary in one of the given statuses, the most recently
	 * edited where more than one matches.
	 *
	 * @param int      $coverage_id Coverage term ID.
	 * @param string[] $statuses    Post statuses to look in.
	 * @param int      $except_id   An entry to skip.
	 * @return WP_Post|null
	 */
	public static function get_summary( int $coverage_id, array $statuses = [ 'publish' ], int $except_id = 0 ): ?WP_Post {
		if ( $coverage_id <= 0 ) {
			return null;
		}

		$query = new WP_Query(
			[
				'post_type'                   => Post_Type::CPT_SLUG,
				'post_status'                 => $statuses,
				self::QUERY_VAR               => 'only',
				'tax_query'                   => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy'         => Taxonomy::TAXONOMY_SLUG,
						'field'            => 'term_id',
						'terms'            => $coverage_id,
						'include_children' => false,
					],
				],
				'post__not_in'                => $except_id ? [ $except_id ] : [], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- One ID, in a query a coverage term already narrows.
				'orderby'                     => 'modified',
				'order'                       => 'DESC',
				'posts_per_page'              => 1,
				'no_found_rows'               => true,
				'ignore_sticky_posts'         => true,
				'update_post_term_cache'      => false,
				Post_Type::SKIP_PIN_ORDER_VAR => true,
			]
		);

		return $query->posts && $query->posts[0] instanceof WP_Post ? $query->posts[0] : null;
	}

	/**
	 * The Summary readers see: the coverage's published one.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return WP_Post|null
	 */
	public static function get_published( int $coverage_id ): ?WP_Post {
		return self::get_summary( $coverage_id );
	}

	/**
	 * The coverage's Summary outside the trash, other than `$except_id`: the
	 * one a new or restored Summary would collide with. Auto-drafts count, so
	 * Add Summary reopens one left behind rather than starting another.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @param int $except_id   The entry being saved, which doesn't collide with itself.
	 * @return WP_Post|null
	 */
	public static function get_current( int $coverage_id, int $except_id = 0 ): ?WP_Post {
		return self::get_summary( $coverage_id, [ 'publish', 'future', 'draft', 'pending', 'private', 'auto-draft' ], $except_id );
	}

	/**
	 * The error for a second Summary in a coverage.
	 *
	 * @return WP_Error
	 */
	public static function exists_error(): WP_Error {
		return new WP_Error(
			'rolling_coverage_summary_exists',
			__( 'This liveblog already has a summary. Trash it before adding another.', 'newspack-rolling-coverage' ),
			[ 'status' => 409 ]
		);
	}

	/**
	 * The error for an entry action the Summary doesn't take, such as pinning.
	 *
	 * @return WP_Error
	 */
	public static function unsupported_error(): WP_Error {
		return new WP_Error(
			'rolling_coverage_summary_unsupported',
			__( 'The summary can’t be pinned, archived or given a breakout post.', 'newspack-rolling-coverage' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * The coverage IDs an entry belongs to.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return int[]
	 */
	private static function coverage_ids( int $entry_id ): array {
		$ids = wp_get_post_terms( $entry_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] );

		return is_wp_error( $ids ) ? [] : array_map( 'intval', $ids );
	}

	/**
	 * Whether another Summary outside the trash shares a coverage with an
	 * entry.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return bool
	 */
	public static function collides( int $entry_id ): bool {
		foreach ( self::coverage_ids( $entry_id ) as $coverage_id ) {
			$other = self::get_current( $coverage_id, $entry_id );

			if ( $other && 'auto-draft' !== $other->post_status ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Start a coverage's Summary for Quick Edit to open: an auto-draft flagged
	 * as the Summary, titled "What we know". An auto-draft one already in the
	 * coverage is handed back instead, so a cancelled start isn't a second
	 * Summary; any other refuses.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return int|WP_Error The Summary's ID, or why there is none.
	 */
	public static function start( int $coverage_id ) {
		$current = self::get_current( $coverage_id );

		if ( $current ) {
			return 'auto-draft' === $current->post_status ? $current->ID : self::exists_error();
		}

		// The auto-draft starts with no content, which wp_insert_post() refuses by default.
		$allow_empty = static fn() => false;

		add_filter( 'wp_insert_post_empty_content', $allow_empty );
		try {
			$summary_id = wp_insert_post(
				[
					'post_type'    => Post_Type::CPT_SLUG,
					'post_status'  => 'auto-draft',
					'post_title'   => __( 'What we know', 'newspack-rolling-coverage' ),
					'post_content' => '',
					'post_author'  => get_current_user_id(),
					'meta_input'   => [ self::META_KEY => true ],
				],
				true
			);
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $allow_empty );
		}

		if ( is_wp_error( $summary_id ) ) {
			return $summary_id;
		}

		$assigned = wp_set_object_terms( $summary_id, [ $coverage_id ], Taxonomy::TAXONOMY_SLUG );

		if ( is_wp_error( $assigned ) ) {
			wp_delete_post( $summary_id, true );

			return $assigned;
		}

		return $summary_id;
	}

	/**
	 * Refuse a save through the core entries route that would leave a
	 * coverage with two Summaries outside the trash, such as publishing a
	 * second auto-draft started in another tab.
	 *
	 * @param \stdClass|WP_Error $prepared_post The post about to be saved.
	 * @param WP_REST_Request    $request       Request object.
	 * @return \stdClass|WP_Error
	 */
	public static function check_rest_save( $prepared_post, $request ) {
		if ( is_wp_error( $prepared_post ) || empty( $prepared_post->ID ) || ! self::is_summary( (int) $prepared_post->ID ) ) {
			return $prepared_post;
		}

		$status = $prepared_post->post_status ?? get_post_status( (int) $prepared_post->ID );

		if ( in_array( $status, [ 'trash', 'auto-draft' ], true ) ) {
			return $prepared_post;
		}

		return self::collides( (int) $prepared_post->ID ) ? self::exists_error() : $prepared_post;
	}

	/**
	 * Leave the Summary out of every entry query, unless it asks for it with
	 * QUERY_VAR. This runs for get_posts() too, which skips the SQL filters.
	 *
	 * @param WP_Query $query The query about to run.
	 */
	public static function filter_query( WP_Query $query ): void {
		$post_type = $query->get( 'post_type' );

		if ( Post_Type::CPT_SLUG !== $post_type && [ Post_Type::CPT_SLUG ] !== $post_type ) {
			return;
		}

		$mode = $query->get( self::QUERY_VAR );

		if ( 'include' === $mode ) {
			return;
		}

		$clause = [
			'key'     => self::META_KEY,
			'compare' => 'only' === $mode ? 'EXISTS' : 'NOT EXISTS',
		];

		$meta_query = $query->get( 'meta_query' );

		// The main query too: the Summary has no page of its own.
		$query->set( // phpcs:ignore WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts
			'meta_query',
			empty( $meta_query ) || ! is_array( $meta_query )
				? [ $clause ]
				: [
					'relation' => 'AND',
					$clause,
					$meta_query,
				]
		);
	}

	/**
	 * The ORDER BY clause putting the Summary first.
	 *
	 * @return string
	 */
	public static function first_order(): string {
		global $wpdb;

		return $wpdb->prepare(
			"CASE WHEN EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS summary_meta WHERE summary_meta.post_id = {$wpdb->posts}.ID AND summary_meta.meta_key = %s) THEN 0 ELSE 1 END",
			self::META_KEY
		);
	}

	/**
	 * Count a coverage's entries outside the trash, leaving its Summary out,
	 * which isn't an update. Mirrors core's _update_post_term_count() with
	 * the statuses Taxonomy::count_all_visible_statuses() gives.
	 *
	 * @param int[]        $tt_ids   Term taxonomy IDs.
	 * @param \WP_Taxonomy $taxonomy The taxonomy.
	 */
	public static function update_term_count( $tt_ids, $taxonomy ): void {
		global $wpdb;

		/** This filter is documented in wp-includes/taxonomy.php */
		$statuses     = (array) apply_filters( 'update_post_term_count_statuses', [ 'publish' ], $taxonomy );
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		foreach ( (array) $tt_ids as $tt_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The statuses' placeholders are built to match.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$wpdb->term_relationships} AS tr INNER JOIN {$wpdb->posts} AS p ON p.ID = tr.object_id LEFT JOIN {$wpdb->postmeta} AS summary_meta ON summary_meta.post_id = p.ID AND summary_meta.meta_key = %s WHERE tr.term_taxonomy_id = %d AND p.post_type = %s AND p.post_status IN ({$placeholders}) AND summary_meta.post_id IS NULL",
					array_merge( [ self::META_KEY, (int) $tt_id, Post_Type::CPT_SLUG ], $statuses )
				)
			);

			/** This action is documented in wp-includes/taxonomy.php */
			do_action( 'edit_term_taxonomy', $tt_id, $taxonomy->name );
			$wpdb->update( $wpdb->term_taxonomy, [ 'count' => $count ], [ 'term_taxonomy_id' => $tt_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			/** This action is documented in wp-includes/taxonomy.php */
			do_action( 'edited_term_taxonomy', $tt_id, $taxonomy->name );
		}
	}
}
