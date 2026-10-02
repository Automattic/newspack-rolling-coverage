<?php
/**
 * Coverage Status Gutenberg block: registration and SSR.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;
use WP_Block_Type;
use WP_Block_Type_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `newspack-rolling-coverage/coverage-status` block, which shows
 * the status of the Rolling Coverage block on its page wherever it is placed:
 * in post content, next to the title in a template, or in a header.
 */
class Coverage_Status_Block {

	// Block name, as registered in block.json.
	const BLOCK_NAME = 'newspack-rolling-coverage/coverage-status';

	// The badge's modifier classes for each status.
	const BADGE_CLASSES = [
		Taxonomy::STATUS_ACTIVE   => 'newspack-ui__badge--success newspack-ui__badge--dot newspack-ui__badge--pulse',
		Taxonomy::STATUS_PAUSED   => 'newspack-ui__badge--secondary',
		Taxonomy::STATUS_ARCHIVED => 'newspack-ui__badge--error',
	];

	/**
	 * Coverage IDs of the feeds found in each post, keyed by post ID and content hash.
	 *
	 * @var array<string, int[]>
	 */
	private static $feeds = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'localize_editor_config' ] );
	}

	/**
	 * Registers the block type.
	 */
	public static function register_block() {
		register_block_type(
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/coverage-status',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Gives the editor script what its preview needs.
	 */
	public static function localize_editor_config() {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK_NAME );

		if ( ! $block_type instanceof WP_Block_Type ) {
			return;
		}

		foreach ( $block_type->editor_script_handles as $handle ) {
			wp_localize_script(
				$handle,
				'newspackCoverageStatusBlock',
				[
					'statusLabels'     => Status_Labels::get_all(),
					'statusMetaKey'    => Taxonomy::STATUS_META_KEY,
					'taxonomySlug'     => Taxonomy::TAXONOMY_SLUG,
					'taxonomyRestBase' => Taxonomy::REST_BASE,
					'entryPostType'    => Post_Type::CPT_SLUG,
				]
			);
		}
	}

	/**
	 * Server-side render callback.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block content (unused).
	 * @param WP_Block $block      Block instance.
	 * @return string Rendered HTML, or '' when the page has no feed to follow.
	 */
	public static function render_block( $attributes, $content, WP_Block $block ) {
		$coverage_id = self::followed_coverage_id( (array) $attributes, $block );

		if ( ! $coverage_id ) {
			return '';
		}

		$status  = Rolling_Coverage_Block::coverage_status( $coverage_id );
		$labels  = self::labels( (array) $attributes );
		$wrapper = [
			'data-coverage-id' => $coverage_id,
			'data-status'      => $status,
		];

		foreach ( $labels as $key => $label ) {
			$wrapper[ 'data-label-' . $key ] = $label;
		}

		$html = sprintf(
			'<span class="%s">%s</span>',
			esc_attr( 'newspack-ui__badge ' . self::BADGE_CLASSES[ $status ] ),
			esc_html( $labels[ $status ] )
		);

		if ( ! empty( $attributes['showLastUpdated'] ) ) {
			$html .= ' ' . self::render_last_updated( $coverage_id, $status );
		}

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( $wrapper ), $html );
	}

	/**
	 * Coverage IDs of the Rolling Coverage blocks in a post, in page order,
	 * including those in synced patterns, without missing or trashed ones.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function feed_coverage_ids( int $post_id ): array {
		$post = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
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
	 * The coverage the block follows: its chosen one while that is on the
	 * page, otherwise the page's first.
	 *
	 * @param array    $attributes Block attributes.
	 * @param WP_Block $block      Block instance.
	 * @return int Coverage term ID, or 0.
	 */
	private static function followed_coverage_id( array $attributes, WP_Block $block ): int {
		$feeds = self::feed_coverage_ids( self::page_id( $block ) );

		if ( ! $feeds ) {
			return 0;
		}

		$chosen = (int) ( $attributes['coverageId'] ?? 0 );

		return in_array( $chosen, $feeds, true ) ? $chosen : $feeds[0];
	}

	/**
	 * The post whose feeds the block follows: the one it sits in, or, in a
	 * template part, the page being viewed.
	 *
	 * @param WP_Block $block Block instance.
	 * @return int Post ID, or 0 on views that aren't a single post or page.
	 */
	private static function page_id( WP_Block $block ): int {
		if ( ! empty( $block->context['postId'] ) ) {
			return (int) $block->context['postId'];
		}

		if ( in_the_loop() ) {
			return (int) get_the_ID();
		}

		return is_singular() ? (int) get_queried_object_id() : 0;
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
				$ids[] = (int) ( $block['attrs']['coverageId'] ?? 0 );
				continue;
			}

			if ( 'core/block' === $name ) {
				$ref     = (int) ( $block['attrs']['ref'] ?? 0 );
				$pattern = $ref && ! in_array( $ref, $seen_refs, true ) ? get_post( $ref ) : null;

				if ( $pattern && 'wp_block' === $pattern->post_type && 'publish' === $pattern->post_status ) {
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

	/**
	 * Whether a feed's coverage can be followed: it exists and isn't trashed.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return bool
	 */
	private static function is_followable( int $coverage_id ): bool {
		return $coverage_id > 0
			&& term_exists( $coverage_id, Taxonomy::TAXONOMY_SLUG )
			&& 'trash' !== get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );
	}

	/**
	 * The text for each status: the block's, the site's, or the built-in one.
	 *
	 * @param array $attributes Block attributes.
	 * @return array<string, string>
	 */
	private static function labels( array $attributes ): array {
		$own    = is_array( $attributes['labels'] ?? null ) ? $attributes['labels'] : [];
		$labels = Status_Labels::get_all();

		foreach ( array_keys( self::BADGE_CLASSES ) as $status ) {
			$label = is_string( $own[ $status ] ?? null ) ? trim( $own[ $status ] ) : '';

			if ( '' !== $label ) {
				$labels[ $status ] = $label;
			}
		}

		return $labels;
	}

	/**
	 * "Updated 2 minutes ago", hidden unless the coverage is live and has an
	 * entry, so the view script only has to show it and fill in the time.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $status      Coverage status.
	 * @return string
	 */
	private static function render_last_updated( int $coverage_id, string $status ): string {
		$gmt = Newest_Entry::get( $coverage_id );
		$iso = Newest_Entry::get_iso( $coverage_id );
		$ago = $iso
			? sprintf(
				/* translators: %s: Time difference, e.g. "2 minutes". */
				__( '%s ago', 'newspack-rolling-coverage' ),
				human_time_diff( (int) strtotime( $gmt . ' UTC' ) )
			)
			: '';

		return sprintf(
			'<span class="newspack-rolling-coverage-updated"%s>%s</span>',
			Taxonomy::STATUS_ACTIVE === $status && $iso ? '' : ' hidden',
			sprintf(
				/* translators: %s: How long ago the newest entry was published, e.g. "2 minutes ago". */
				esc_html__( 'Updated %s', 'newspack-rolling-coverage' ),
				sprintf( '<time datetime="%s" data-rc-relative>%s</time>', esc_attr( (string) $iso ), esc_html( $ago ) )
			)
		);
	}
}
