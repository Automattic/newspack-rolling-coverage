<?php
/**
 * Emits schema.org/LiveBlogPosting JSON-LD for pages embedding a Rolling
 * Coverage block.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and prints LiveBlogPosting structured data on the front end.
 */
class Schema {

	const BLOCK_NAME = 'newspack-rolling-coverage/rolling-coverage';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'wp_head', [ __CLASS__, 'print_schema' ] );
	}

	/**
	 * Prints one JSON-LD script tag per Rolling Coverage block on the page.
	 */
	public static function print_schema() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// Withhold all metadata for password-protected posts before touching any
		// entry content, so protected bodies can't leak through the JSON-LD.
		if ( post_password_required( $post ) ) {
			return;
		}

		if ( ! has_block( self::BLOCK_NAME, $post ) ) {
			return;
		}

		$coverage_blocks = self::get_coverage_blocks( $post );

		foreach ( $coverage_blocks as $coverage_id => $entries_per_page ) {
			$metadata = self::build_metadata( $post, $coverage_id, $entries_per_page );

			if ( empty( $metadata ) ) {
				continue;
			}

			// JSON_HEX_TAG escapes `<`/`>` so a user-authored `</script>` inside an
			// entry body can't break out of the JSON-LD script tag.
			printf(
				'<script type="application/ld+json">%s</script>' . "\n",
				wp_json_encode( $metadata, JSON_HEX_TAG )
			);
		}
	}

	/**
	 * Collects the Rolling Coverage blocks embedded in a post, deduped by coverage ID.
	 *
	 * @param WP_Post $post Host post being rendered.
	 * @return array<int,int> Map of coverage term id => entries-per-page attribute.
	 */
	private static function get_coverage_blocks( WP_Post $post ): array {
		$coverages = [];

		foreach ( self::flatten_blocks( parse_blocks( $post->post_content ) ) as $block ) {
			if ( self::BLOCK_NAME !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}

			$coverage_id = (int) ( $block['attrs']['coverageId'] ?? 0 );
			if ( ! $coverage_id || isset( $coverages[ $coverage_id ] ) ) {
				continue;
			}

			$entries_per_page = min(
				max( 1, (int) ( $block['attrs']['entriesPerPage'] ?? 20 ) ),
				Rolling_Coverage_Block::PER_PAGE_MAX
			);

			$coverages[ $coverage_id ] = $entries_per_page;
		}

		return $coverages;
	}

	/**
	 * Recursively flattens a parsed-block tree so nested coverage blocks are found too.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return array[] Flat list of parsed blocks.
	 */
	private static function flatten_blocks( array $blocks ): array {
		$flat = [];

		foreach ( $blocks as $block ) {
			$flat[] = $block;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, self::flatten_blocks( $block['innerBlocks'] ) );
			}
		}

		return $flat;
	}

	/**
	 * Builds the LiveBlogPosting metadata for one coverage embedded in the host post.
	 *
	 * @param WP_Post $post             Host post the block is embedded in.
	 * @param int     $coverage_id      Coverage term id.
	 * @param int     $entries_per_page Number of entries to include, mirroring the block render.
	 * @return array|null Metadata array, or null when the coverage should not emit schema.
	 */
	private static function build_metadata( WP_Post $post, int $coverage_id, int $entries_per_page ): ?array {
		if ( ! term_exists( $coverage_id, Taxonomy::TAXONOMY_SLUG ) ) {
			return null;
		}

		$status = get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );

		$permalink = get_permalink( $post );

		$coverage_name = get_term_field( 'name', $coverage_id, Taxonomy::TAXONOMY_SLUG );
		$headline      = ( is_wp_error( $coverage_name ) || '' === trim( (string) $coverage_name ) )
			? get_the_title( $post )
			: $coverage_name;

		$metadata = [
			'@context'         => 'https://schema.org',
			'@type'            => 'LiveBlogPosting',
			'headline'         => $headline,
			'url'              => $permalink,
			'mainEntityOfPage' => $permalink,
		];

		$published_datetime = get_post_datetime( $post, 'date', 'gmt' );
		if ( false !== $published_datetime ) {
			$metadata['datePublished'] = $published_datetime->format( 'c' );
		}

		// dateModified must reflect entry activity, which is tracked by the block's
		// LAST_MODIFIED_META_KEY term meta (updated on every entry save).
		$last_modified = get_term_meta( $coverage_id, Rolling_Coverage_Block::LAST_MODIFIED_META_KEY, true );
		if ( ! empty( $last_modified ) ) {
			$metadata['dateModified'] = $last_modified;
		} else {
			// No entry activity recorded yet: fall back to the host post's own
			// modified date so the field is still present when available.
			$modified_datetime = get_post_datetime( $post, 'modified', 'gmt' );
			if ( false !== $modified_datetime ) {
				$metadata['dateModified'] = $modified_datetime->format( 'c' );
			}
		}

		$created_at = get_term_meta( $coverage_id, 'created_at', true );
		if ( ! empty( $created_at ) ) {
			$metadata['coverageStartTime'] = $created_at;
		}

		if ( 'archived' === $status && ! empty( $last_modified ) ) {
			$metadata['coverageEndTime'] = $last_modified;
		}

		$metadata['liveBlogUpdate'] = self::build_updates( $coverage_id, $entries_per_page, $permalink );

		/**
		 * Filters the LiveBlogPosting metadata before it is printed.
		 *
		 * @param array   $metadata    Metadata array.
		 * @param int     $coverage_id Coverage term id.
		 * @param WP_Post $post        Host post the block is embedded in.
		 */
		return apply_filters( 'newspack_rolling_coverage_schema_metadata', $metadata, $coverage_id, $post );
	}

	/**
	 * Builds the liveBlogUpdate array of BlogPosting entities for a coverage's entries.
	 *
	 * @param int    $coverage_id      Coverage term id.
	 * @param int    $entries_per_page Number of entries to include.
	 * @param string $permalink        Host post permalink used to anchor each entry.
	 * @return array[] Array of BlogPosting arrays.
	 */
	private static function build_updates( int $coverage_id, int $entries_per_page, string $permalink ): array {
		$query = new WP_Query(
			[
				'post_type'           => Post_Type::CPT_SLUG,
				'post_status'         => 'publish',
				'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => Taxonomy::TAXONOMY_SLUG,
						'field'    => 'term_id',
						'terms'    => $coverage_id,
					],
				],
				'orderby'             => 'date',
				'order'               => 'DESC',
				'posts_per_page'      => $entries_per_page,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			]
		);

		$updates = [];

		foreach ( $query->posts as $entry ) {
			$update = self::build_entry_update( $entry, $permalink );
			if ( null !== $update ) {
				$updates[] = $update;
			}
		}

		wp_reset_postdata();

		return $updates;
	}

	/**
	 * Maps an entry post to a BlogPosting liveBlogUpdate item, or null if it's empty.
	 *
	 * @param WP_Post $entry     Entry post.
	 * @param string  $permalink Host post permalink used to anchor the entry.
	 * @return array|null BlogPosting array, or null to skip the entry.
	 */
	private static function build_entry_update( WP_Post $entry, string $permalink ): ?array {
		// Replace tags with spaces to preserve word boundaries, decode entities,
		// then collapse whitespace so headline/articleBody read as clean prose.
		$article_body = preg_replace( '/<[^>]+>/', ' ', do_blocks( $entry->post_content ) );
		$article_body = html_entity_decode( $article_body, ENT_QUOTES, 'UTF-8' );
		$article_body = preg_replace( '/\s+/', ' ', $article_body );
		$article_body = trim( $article_body );

		if ( '' === $article_body ) {
			return null;
		}

		$headline = get_the_title( $entry );
		if ( '' === trim( wp_strip_all_tags( $headline ) ) ) {
			$headline = wp_trim_words( $article_body, 10, '…' );
		}

		$update = [
			'@type'            => 'BlogPosting',
			'headline'         => $headline,
			'url'              => $permalink . '#' . Rolling_Coverage_Block::MARKUP_PREFIX . '-entry-' . $entry->ID,
			'mainEntityOfPage' => $permalink . '#' . Rolling_Coverage_Block::MARKUP_PREFIX . '-entry-' . $entry->ID,
			'articleBody'      => $article_body,
		];

		$published_datetime = get_post_datetime( $entry, 'date', 'gmt' );
		if ( false !== $published_datetime ) {
			$update['datePublished'] = $published_datetime->format( 'c' );
		}

		$modified_datetime = get_post_datetime( $entry, 'modified', 'gmt' );
		if ( false !== $modified_datetime ) {
			$update['dateModified'] = $modified_datetime->format( 'c' );
		}

		$author = self::build_author( (int) $entry->post_author );
		if ( null !== $author ) {
			$update['author'] = $author;
		}

		return $update;
	}

	/**
	 * Builds a schema.org Person object for an entry's author.
	 *
	 * @param int $author_id Author user id.
	 * @return array|null Person array, or null when the author has no name.
	 */
	private static function build_author( int $author_id ): ?array {
		if ( $author_id <= 0 ) {
			return null;
		}

		$name = get_the_author_meta( 'display_name', $author_id );
		if ( '' === trim( (string) $name ) ) {
			return null;
		}

		$author = [
			'@type' => 'Person',
			'name'  => $name,
		];

		// A URL helps search engines disambiguate the author (Google's guidance).
		$url = get_author_posts_url( $author_id );
		if ( ! empty( $url ) ) {
			$author['url'] = $url;
		}

		return $author;
	}
}
