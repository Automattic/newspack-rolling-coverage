<?php
/**
 * The coverages a page holds feeds of.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which coverage the blocks that show or follow a coverage from
 * outside its feed are about: a chosen one, or the one the page being
 * viewed belongs to through its Rolling Coverage blocks or, for a breakout
 * post, its source entry.
 */
class Page_Coverages {

	/**
	 * Coverage IDs of the feeds found in each post, keyed by post ID and content hash.
	 *
	 * @var array<string, int[]>
	 */
	private static $feeds = [];

	/**
	 * Coverage IDs of the uncapped Rolling Coverage blocks in a post, in page
	 * order, including those in synced patterns, without missing or trashed
	 * ones. A capped block only previews a coverage, so it never decides
	 * which coverage the page is about.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function feed_coverage_ids( int $post_id ): array {
		$post = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || post_password_required( $post ) ) {
			return [];
		}

		if ( ! has_block( Rolling_Coverage_Block::BLOCK_NAME, $post ) && ! has_block( 'core/block', $post ) ) {
			return [];
		}

		$key = $post_id . ':' . md5( $post->post_content );

		if ( ! isset( self::$feeds[ $key ] ) ) {
			$ids                 = array_unique( self::collect_feeds( parse_blocks( $post->post_content ), [] ) );
			self::$feeds[ $key ] = array_values( array_filter( $ids, [ __CLASS__, 'is_followable' ] ) );
		}

		return self::$feeds[ $key ];
	}

	/**
	 * The coverage a block outside a feed's entries is about. Inside a
	 * Rolling Coverage block it is always that block's. Elsewhere a chosen
	 * coverage wins while it can be followed; otherwise it is the page's
	 * automatic one.
	 *
	 * @param WP_Block $block  Block instance.
	 * @param int      $chosen The block's chosen coverage ID; 0 for Automatic.
	 * @return int Coverage term ID, or 0 when it is about none.
	 */
	public static function coverage_for_block( WP_Block $block, int $chosen ): int {
		if ( isset( $block->context[ Entry_Bindings::COVERAGE_ID_CONTEXT ] ) ) {
			return (int) $block->context[ Entry_Bindings::COVERAGE_ID_CONTEXT ];
		}

		if ( self::is_followable( $chosen ) ) {
			return $chosen;
		}

		$post_id = self::page_id( $block );

		return self::feed_coverage_ids( $post_id )[0] ?? self::breakout_coverage_id( $post_id );
	}

	/**
	 * The coverage of the entry a breakout post was made from. An entry
	 * normally has one coverage; should it have more, the oldest followable
	 * one wins, so the choice doesn't change between requests.
	 *
	 * @param int $post_id Post ID.
	 * @return int Coverage term ID, or 0 when the post isn't a breakout post.
	 */
	private static function breakout_coverage_id( int $post_id ): int {
		$post = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || post_password_required( $post ) ) {
			return 0;
		}

		$entry_id = (int) get_post_meta( $post_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, true );

		if ( ! $entry_id || Post_Type::CPT_SLUG !== get_post_type( $entry_id ) ) {
			return 0;
		}

		$terms = get_the_terms( $entry_id, Taxonomy::TAXONOMY_SLUG );

		if ( ! is_array( $terms ) ) {
			return 0;
		}

		$ids = array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) );
		sort( $ids );

		foreach ( $ids as $id ) {
			if ( self::is_followable( $id ) ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * The post whose coverage a block follows: the one it sits in, or, in a
	 * template part, the page being viewed. Core hands the first listed post
	 * to blocks on archives, so nothing is followed outside single views.
	 *
	 * @param WP_Block $block Block instance.
	 * @return int Post ID, or 0 on views that aren't a single post or page.
	 */
	public static function page_id( WP_Block $block ): int {
		if ( ! is_singular() ) {
			return 0;
		}

		if ( ! empty( $block->context['postId'] ) ) {
			return (int) $block->context['postId'];
		}

		return (int) get_queried_object_id();
	}

	/**
	 * Whether a coverage can be followed: it exists and isn't trashed.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return bool
	 */
	public static function is_followable( int $coverage_id ): bool {
		return $coverage_id > 0
			&& term_exists( $coverage_id, Taxonomy::TAXONOMY_SLUG )
			&& 'trash' !== get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );
	}

	/**
	 * Collects feed coverage IDs from parsed blocks, reading each synced
	 * pattern once so one that includes itself can't loop.
	 *
	 * @param array $blocks    Parsed blocks.
	 * @param int[] $seen_refs Synced patterns already read.
	 * @return int[]
	 */
	private static function collect_feeds( array $blocks, array $seen_refs ): array {
		$ids = [];

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';

			if ( Rolling_Coverage_Block::BLOCK_NAME === $name ) {
				if ( empty( $block['attrs']['latestOnly'] ) ) {
					$ids[] = (int) ( $block['attrs']['coverageId'] ?? 0 );
				}
				continue;
			}

			if ( 'core/block' === $name ) {
				$ref     = (int) ( $block['attrs']['ref'] ?? 0 );
				$pattern = $ref && ! in_array( $ref, $seen_refs, true ) ? get_post( $ref ) : null;

				if ( $pattern && 'wp_block' === $pattern->post_type && 'publish' === $pattern->post_status && '' === $pattern->post_password ) {
					$ids = array_merge( $ids, self::collect_feeds( parse_blocks( $pattern->post_content ), array_merge( $seen_refs, [ $ref ] ) ) );
				}

				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$ids = array_merge( $ids, self::collect_feeds( $block['innerBlocks'], $seen_refs ) );
			}
		}

		return $ids;
	}
}
