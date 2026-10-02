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
 * Keeps the date of each coverage's newest published entry in term meta, so
 * the Coverage Status block and polls can say when the coverage was last
 * updated without querying entries.
 */
class Newest_Entry {

	// GMT `Y-m-d H:i:s` of the newest published entry, or '' when there is none.
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
		add_action( 'transition_post_status', [ __CLASS__, 'on_status_change' ], 10, 3 );
		add_action( 'set_object_terms', [ __CLASS__, 'on_terms_change' ], 10, 6 );
		add_action( 'before_delete_post', [ __CLASS__, 'before_delete' ], 10, 2 );
		add_action( 'deleted_post', [ __CLASS__, 'after_delete' ], 10, 1 );
	}

	/**
	 * The newest published entry's date, filled in on first read for
	 * coverages from before it was kept.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string GMT `Y-m-d H:i:s`, or '' when the coverage has no published entries.
	 */
	public static function get( int $coverage_id ): string {
		if ( ! metadata_exists( 'term', $coverage_id, self::META_KEY ) ) {
			return self::refresh( $coverage_id );
		}

		return (string) get_term_meta( $coverage_id, self::META_KEY, true );
	}

	/**
	 * The newest published entry's date as ISO 8601, for the page and polls.
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
	 * Looks up and stores the coverage's newest published entry date.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string GMT `Y-m-d H:i:s`, or ''.
	 */
	public static function refresh( int $coverage_id ): string {
		$query = new WP_Query(
			[
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
				'orderby'                     => 'date',
				'order'                       => 'DESC',
				'posts_per_page'              => 1,
				'no_found_rows'               => true,
				'ignore_sticky_posts'         => true,
				Post_Type::SKIP_PIN_ORDER_VAR => true,
			]
		);

		$newest = $query->posts ? (string) $query->posts[0]->post_date_gmt : '';

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
	public static function on_terms_change( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( Taxonomy::TAXONOMY_SLUG !== $taxonomy || 'publish' !== get_post_status( (int) $object_id ) || Post_Type::CPT_SLUG !== get_post_type( (int) $object_id ) ) {
			return;
		}

		$coverage_ids = [];

		foreach ( array_unique( array_merge( (array) $tt_ids, (array) $old_tt_ids ) ) as $tt_id ) {
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
	public static function before_delete( $post_id, $post ): void {
		if ( $post instanceof WP_Post && Post_Type::CPT_SLUG === $post->post_type && 'publish' === $post->post_status ) {
			self::$deleting[ (int) $post_id ] = self::coverage_ids( (int) $post_id );
		}
	}

	/**
	 * Refreshes a deleted entry's coverages.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function after_delete( $post_id ): void {
		$coverage_ids = self::$deleting[ (int) $post_id ] ?? [];
		unset( self::$deleting[ (int) $post_id ] );

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
