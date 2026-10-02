<?php
/**
 * The publish date of each coverage's newest entry.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the time each coverage's newest entry was published in term meta, so
 * the Coverage Status block and polls can say when the coverage was last
 * updated without querying entries.
 */
class Newest_Entry {

	// GMT `Y-m-d H:i:s` of when the newest entry was published, or '' when there is none.
	const META_KEY = 'rolling_coverage_newest_entry';

	/**
	 * Coverages of entries about to be deleted, by entry ID.
	 *
	 * @var array<int, int[]>
	 */
	private static $deleting = [];

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		// After Post_Type records the publish time at priority 10, which the refresh reads.
		add_action( 'transition_post_status', [ __CLASS__, 'on_status_change' ], 20, 3 );
		add_action( 'set_object_terms', [ __CLASS__, 'on_terms_change' ], 10, 6 );
		add_action( 'before_delete_post', [ __CLASS__, 'before_delete' ], 10, 2 );
		add_action( 'deleted_post', [ __CLASS__, 'after_delete' ], 10, 1 );
	}

	/**
	 * When the newest entry was published, filled in on first read for
	 * coverages from before it was kept.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string GMT `Y-m-d H:i:s`, or '' when the coverage has no published entries.
	 */
	public static function get( int $coverage_id ): string {
		if ( ! term_exists( $coverage_id, Taxonomy::TAXONOMY_SLUG ) ) {
			return '';
		}

		if ( ! metadata_exists( 'term', $coverage_id, self::META_KEY ) ) {
			return self::refresh( $coverage_id );
		}

		return (string) get_term_meta( $coverage_id, self::META_KEY, true );
	}

	/**
	 * When the newest entry was published, as ISO 8601, for the page and polls.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string|null ISO 8601 date, or null when there are no published entries.
	 */
	public static function get_iso( int $coverage_id ): ?string {
		$gmt       = self::get( $coverage_id );
		$timestamp = '' === $gmt ? false : strtotime( $gmt . ' UTC' );

		return false === $timestamp ? null : gmdate( 'c', $timestamp );
	}

	/**
	 * Looks up and stores when the coverage's newest entry was published,
	 * which is the recorded publish time, or the post date for entries
	 * without one.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string GMT `Y-m-d H:i:s`, or ''.
	 */
	public static function refresh( int $coverage_id ): string {
		$base = [
			'post_type'                   => Post_Type::CPT_SLUG,
			'post_status'                 => 'publish',
			'tax_query'                   => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy'         => Taxonomy::TAXONOMY_SLUG,
					'field'            => 'term_id',
					'terms'            => $coverage_id,
					'include_children' => false,
				],
			],
			'order'                       => 'DESC',
			'posts_per_page'              => 1,
			'no_found_rows'               => true,
			'ignore_sticky_posts'         => true,
			Post_Type::SKIP_PIN_ORDER_VAR => true,
		];

		$without_meta = new WP_Query(
			array_merge(
				$base,
				[
					'orderby'    => 'date',
					'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						[
							'key'     => Post_Type::META_PUBLISHED_GMT,
							'compare' => 'NOT EXISTS',
						],
					],
				]
			)
		);
		$with_meta    = new WP_Query(
			array_merge(
				$base,
				[
					'orderby'  => 'meta_value',
					'meta_key' => Post_Type::META_PUBLISHED_GMT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				]
			)
		);

		$candidates = [ '' ];

		if ( $without_meta->posts ) {
			$candidates[] = (string) $without_meta->posts[0]->post_date_gmt;
		}

		if ( $with_meta->posts ) {
			$candidates[] = (string) get_post_meta( $with_meta->posts[0]->ID, Post_Type::META_PUBLISHED_GMT, true );
		}

		$newest = max( $candidates );

		update_term_meta( $coverage_id, self::META_KEY, $newest );

		return $newest;
	}

	/**
	 * Refreshes an entry's coverages when it is published or unpublished, or
	 * saved while published, which can change its date.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function on_status_change( string $new_status, string $old_status, WP_Post $post ): void {
		if ( Post_Type::CPT_SLUG !== $post->post_type || ( 'publish' !== $new_status && 'publish' !== $old_status ) ) {
			return;
		}

		self::refresh_all( self::coverage_ids( $post->ID ) );
	}

	/**
	 * Refreshes the coverages a published entry joins or leaves.
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Terms passed in.
	 * @param array  $tt_ids     Term taxonomy IDs now set.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param bool   $append     Whether terms were appended.
	 * @param array  $old_tt_ids Term taxonomy IDs set before.
	 */
	public static function on_terms_change( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( Taxonomy::TAXONOMY_SLUG !== $taxonomy || 'publish' !== get_post_status( $object_id ) || Post_Type::CPT_SLUG !== get_post_type( $object_id ) ) {
			return;
		}

		$coverage_ids = [];

		foreach ( array_unique( array_merge( $tt_ids, $old_tt_ids ) ) as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, Taxonomy::TAXONOMY_SLUG );

			if ( $term ) {
				$coverage_ids[] = (int) $term->term_id;
			}
		}

		self::refresh_all( $coverage_ids );
	}

	/**
	 * Remembers a published entry's coverages before it is deleted, as its
	 * terms are gone by the time the deletion finishes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function before_delete( int $post_id, WP_Post $post ): void {
		if ( Post_Type::CPT_SLUG === $post->post_type && 'publish' === $post->post_status ) {
			self::$deleting[ $post_id ] = self::coverage_ids( $post_id );
		}
	}

	/**
	 * Refreshes a deleted entry's coverages.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function after_delete( int $post_id ): void {
		$coverage_ids = self::$deleting[ $post_id ] ?? [];
		unset( self::$deleting[ $post_id ] );

		self::refresh_all( $coverage_ids );
	}

	/**
	 * The coverages an entry belongs to.
	 *
	 * @param int $post_id Entry ID.
	 * @return int[]
	 */
	private static function coverage_ids( int $post_id ): array {
		$term_ids = wp_get_post_terms( $post_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] );

		return is_wp_error( $term_ids ) ? [] : array_map( 'intval', $term_ids );
	}

	/**
	 * Refreshes each coverage once.
	 *
	 * @param int[] $coverage_ids Coverage term IDs.
	 */
	private static function refresh_all( array $coverage_ids ): void {
		foreach ( array_unique( $coverage_ids ) as $coverage_id ) {
			self::refresh( $coverage_id );
		}
	}
}
