<?php
/**
 * Rolling Coverage Gutenberg block: registration, SSR, and the dedicated
 * polling/pagination REST route.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;
use WP_Block_Type;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `newspack-rolling-coverage/rolling-coverage` block, its
 * server-side render, and the REST route used for both forward polling
 * (new entries) and backward pagination (older entries).
 */
class Rolling_Coverage_Block {

	// Max number of entries returned per poll response.
	const POLL_CAP = 50;

	// Max number of entries returned per page.
	const PER_PAGE_MAX = 100;

	// CSS class/ID prefix for the block's front-end markup.
	const MARKUP_PREFIX = 'newspack-rolling-coverage';

	/**
	 * Entry currently being rendered by render_entry(), read by
	 * filter_block_context() while its render_block_context filter is active.
	 *
	 * @var WP_Post|null
	 */
	private static $context_entry = null;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Registers the block type and localizes its editor script.
	 */
	public static function register_block() {
		$block_type = register_block_type(
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/rolling-coverage',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);

		if ( ! $block_type instanceof WP_Block_Type ) {
			return;
		}

		foreach ( $block_type->editor_script_handles as $handle ) {
			wp_localize_script(
				$handle,
				'newspackRollingCoverageBlock',
				[
					'liveblogsRestBase'      => '/wp/v2/' . Taxonomy::REST_BASE,
					'statusMetaKey'          => Taxonomy::STATUS_META_KEY,
					'entriesPreviewRestBase' => '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/liveblogs',
				]
			);
		}
	}

	/**
	 * SSR render callback for the block.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Saved inner content.
	 * @param WP_Block $block      Block instance, carrying the saved
	 *                              per-entry template in its parsed_block.
	 * @return string Rendered HTML.
	 */
	public static function render_block( $attributes, $content, WP_Block $block ) {
		$liveblog_id = (int) ( $attributes['liveblogId'] ?? 0 );

		if ( ! $liveblog_id || ! term_exists( $liveblog_id, Taxonomy::TAXONOMY_SLUG ) ) {
			return sprintf(
				'<p %s>%s</p>',
				get_block_wrapper_attributes(),
				esc_html__( 'Select a liveblog to display its entries.', 'newspack-rolling-coverage' )
			);
		}

		$entries_per_page = min( max( 1, (int) ( $attributes['entriesPerPage'] ?? 20 ) ), self::PER_PAGE_MAX );
		$poll_interval    = max( 1, (int) ( $attributes['pollInterval'] ?? 10 ) );
		$status           = get_term_meta( $liveblog_id, Taxonomy::STATUS_META_KEY, true );
		$status           = $status ? $status : 'active';
		$instance_id      = (string) ( $attributes['instanceId'] ?? '' );
		$source_post_id   = (int) get_the_ID();

		$query = new WP_Query(
			[
				'post_type'           => Post_Type::CPT_SLUG,
				'post_status'         => 'publish',
				'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => Taxonomy::TAXONOMY_SLUG,
						'field'    => 'term_id',
						'terms'    => $liveblog_id,
					],
				],
				'orderby'             => 'date',
				'order'               => 'DESC',
				'posts_per_page'      => $entries_per_page,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			]
		);

		$template = self::get_entry_template( $block );

		$entries_html = '';
		foreach ( $query->posts as $entry ) {
			$entries_html .= self::render_entry( $entry, $template );
		}

		$newest_iso = ! empty( $query->posts ) ? self::post_date_iso( $query->posts[0] ) : gmdate( 'c' );
		$oldest_iso = ! empty( $query->posts ) ? self::post_date_iso( $query->posts[ count( $query->posts ) - 1 ] ) : '';

		$notice = '';
		if ( 'paused' === $status ) {
			$notice = sprintf(
				'<p class="%1$s-notice %1$s-notice--paused">%2$s</p>',
				self::MARKUP_PREFIX,
				esc_html__( 'This liveblog is currently paused. New updates will appear once it resumes.', 'newspack-rolling-coverage' )
			);
		}

		if ( empty( $query->posts ) ) {
			$entries_html = sprintf(
				'<p class="%s-entries__empty">%s</p>',
				self::MARKUP_PREFIX,
				esc_html__( 'No entries yet.', 'newspack-rolling-coverage' )
			);
		}

		$wrapper_attributes = get_block_wrapper_attributes(
			[
				'data-liveblog-id'      => $liveblog_id,
				'data-poll-interval'    => $poll_interval,
				'data-entries-per-page' => $entries_per_page,
				'data-since'            => $newest_iso,
				'data-before'           => $oldest_iso,
				'data-status'           => $status,
				'data-source-post-id'   => $source_post_id,
				'data-instance-id'      => $instance_id,
				'data-rest-url'         => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/liveblogs/' . $liveblog_id . '/entries' ) ),
			]
		);

		return sprintf(
			'<div %1$s>%2$s<div class="%3$s-entries">%4$s</div><div class="%3$s-sentinel" aria-hidden="true"></div></div>',
			$wrapper_attributes,
			$notice,
			self::MARKUP_PREFIX,
			$entries_html
		);
	}

	/**
	 * Builds the per-entry inner-block template used to render every entry:
	 * the user's saved template if the block has one, otherwise a hardcoded
	 * fallback.
	 *
	 * @param WP_Block $block The parent rolling-coverage block instance.
	 * @return array[] Array of parsed-block-shaped arrays, suitable for the
	 *                  `innerBlocks` key of a WP_Block source array.
	 */
	private static function get_entry_template( WP_Block $block ) {
		$inner_blocks = $block->parsed_block['innerBlocks'] ?? [];

		if ( ! empty( $inner_blocks ) ) {
			return $inner_blocks;
		}

		return self::default_entry_template();
	}

	/**
	 * The hardcoded fallback per-entry template: title, date, content, and
	 * the breakout post link block.
	 *
	 * @return array[] Array of parsed-block-shaped arrays.
	 */
	private static function default_entry_template() {
		return [
			[
				'blockName'    => 'core/post-title',
				'attrs'        => [ 'level' => 3 ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[
				'blockName'    => 'core/post-date',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[
				'blockName'    => 'core/post-content',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[
				'blockName'    => 'newspack-rolling-coverage/breakout-post-link',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
		];
	}

	/**
	 * Looks up the exact per-entry template for one rendered block instance,
	 * used by the poll/pagination REST endpoint: re-parses the instance's
	 * originating post and returns that specific block's saved template,
	 * falling back to default_entry_template() if it can't be found.
	 *
	 * @param int    $post_id     ID of the post that rendered this block instance.
	 * @param string $instance_id The block instance's persisted instanceId attribute.
	 * @return array[] Array of parsed-block-shaped arrays.
	 */
	private static function find_entry_template_for_instance( $post_id, $instance_id ) {
		$post = get_post( $post_id );

		if ( ! $post || ! $instance_id ) {
			return self::default_entry_template();
		}

		$found = self::find_block_by_instance_id( parse_blocks( $post->post_content ), $instance_id );

		return empty( $found ) ? self::default_entry_template() : $found;
	}

	/**
	 * Recursively searches a parsed-block tree for the rolling-coverage
	 * block with the given instanceId attribute.
	 *
	 * @param array[] $blocks      Parsed blocks, as returned by parse_blocks().
	 * @param string  $instance_id The instanceId attribute to match.
	 * @return array[]|null The matching block's innerBlocks (possibly empty),
	 *                       or null if no block with this instanceId exists.
	 */
	private static function find_block_by_instance_id( array $blocks, $instance_id ) {
		foreach ( $blocks as $candidate ) {
			if (
				'newspack-rolling-coverage/rolling-coverage' === $candidate['blockName']
				&& ( $candidate['attrs']['instanceId'] ?? '' ) === $instance_id
			) {
				return $candidate['innerBlocks'];
			}

			if ( ! empty( $candidate['innerBlocks'] ) ) {
				$found = self::find_block_by_instance_id( $candidate['innerBlocks'], $instance_id );

				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Renders a single entry against the supplied per-entry template.
	 *
	 * @global WP_Post $post Global post object, temporarily swapped to the
	 *                       entry for the duration of this render and
	 *                       restored to its previous value afterwards.
	 *
	 * @param WP_Post $entry    Entry post object.
	 * @param array[] $template Per-entry inner-block template, as returned
	 *                          by get_entry_template().
	 * @return string Rendered HTML for the entry.
	 */
	public static function render_entry( WP_Post $entry, array $template ) {
		global $post;

		$previous_post = $post;
		$post          = $entry; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $entry );

		self::$context_entry = $entry;
		add_filter( 'render_block_context', [ __CLASS__, 'filter_block_context' ], 1 );

		$entry_content = ( new WP_Block(
			[
				'blockName'    => null,
				'attrs'        => [],
				'innerBlocks'  => $template,
				'innerHTML'    => '',
				'innerContent' => array_fill( 0, count( $template ), null ),
			]
		) )->render( [ 'dynamic' => false ] );

		remove_filter( 'render_block_context', [ __CLASS__, 'filter_block_context' ], 1 );
		self::$context_entry = null;

		$post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$post_classes = implode( ' ', get_post_class( [ self::MARKUP_PREFIX . '-entry', 'wp-block-post' ], $entry ) );

		$html = sprintf(
			'<article id="%1$s-entry-%2$d" class="%3$s" data-entry-id="%2$d">%4$s</article>',
			self::MARKUP_PREFIX,
			$entry->ID,
			esc_attr( $post_classes ),
			$entry_content
		);

		return $html;
	}

	/**
	 * Adds the current entry's postId/postType to a block's render context.
	 *
	 * @param array $context Block render context.
	 * @return array Filtered block render context.
	 */
	public static function filter_block_context( $context ) {
		if ( self::$context_entry ) {
			$context['postId']   = self::$context_entry->ID;
			$context['postType'] = self::$context_entry->post_type;
		}

		return $context;
	}

	/**
	 * ISO 8601 (GMT) date string for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string ISO 8601 date string.
	 */
	private static function post_date_iso( WP_Post $post ) {
		return get_post_datetime( $post, 'date', 'gmt' )->format( DATE_ATOM );
	}

	/**
	 * Register the dedicated REST route used for both polling (since) and
	 * pagination (before), plus the lightweight editor-only preview route
	 * (see get_entries_preview()).
	 */
	public static function register_routes() {
		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/liveblogs/(?P<term_id>\d+)/entries',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_entries' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'term_id'        => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_term_id' ],
					],
					'source_post_id' => [
						'required' => true,
						'type'     => 'integer',
					],
					'instance_id'    => [
						'required' => true,
						'type'     => 'string',
					],
					'since'          => [
						'type' => 'string',
					],
					'before'         => [
						'type' => 'string',
					],
					'per_page'       => [
						'type' => 'integer',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/liveblogs/(?P<term_id>\d+)/entries-preview',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_entries_preview' ],
				'permission_callback' => [ __CLASS__, 'can_preview_entries' ],
				'args'                => [
					'term_id'  => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_term_id' ],
					],
					'per_page' => [
						'type' => 'integer',
					],
				],
			]
		);
	}

	/**
	 * Permission check for the editor-only entries-preview route.
	 *
	 * @return bool Whether the current user can edit posts.
	 */
	public static function can_preview_entries() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * REST callback: returns the IDs (and post type) of up to `per_page` of a
	 * liveblog's current published entries, newest first, for the block
	 * editor's per-entry template preview.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_entries_preview( WP_REST_Request $request ) {
		$params  = $request->get_params();
		$term_id = (int) ( $params['term_id'] ?? 0 );

		if ( ! term_exists( $term_id, Taxonomy::TAXONOMY_SLUG ) ) {
			return new WP_Error(
				'rolling_coverage_liveblog_not_found',
				__( 'Liveblog not found.', 'newspack-rolling-coverage' ),
				[ 'status' => 404 ]
			);
		}

		$per_page = min( max( 1, (int) ( $params['per_page'] ?? 20 ) ), self::PER_PAGE_MAX );

		$query = new WP_Query(
			[
				'post_type'           => Post_Type::CPT_SLUG,
				'post_status'         => 'publish',
				'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => Taxonomy::TAXONOMY_SLUG,
						'field'    => 'term_id',
						'terms'    => $term_id,
					],
				],
				'orderby'             => 'date',
				'order'               => 'DESC',
				'posts_per_page'      => $per_page,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'fields'              => 'ids',
			]
		);

		$entries = array_map( [ __CLASS__, 'map_entry_preview' ], $query->posts );

		return new WP_REST_Response( $entries );
	}

	/**
	 * Array_map() callback for get_entries_preview(): reduces a post ID to
	 * the bare `{ id, type }` shape the editor preview needs.
	 *
	 * @param int $id Entry post ID.
	 * @return array{id: int, type: string}
	 */
	private static function map_entry_preview( int $id ): array {
		return [
			'id'   => $id,
			'type' => Post_Type::CPT_SLUG,
		];
	}

	/**
	 * Validates that the route's term_id parameter is numeric.
	 *
	 * @param mixed $value Parameter value to validate.
	 * @return bool Whether the value is numeric.
	 */
	public static function validate_term_id( $value ) {
		return is_numeric( $value );
	}

	/**
	 * REST callback: returns pre-rendered HTML for either direction.
	 *
	 * - `since` (forward/polling): entries newer than the given date, ASC order, capped at POLL_CAP.
	 * - `before` (backward/pagination): entries older than the given date, DESC order, capped at the request's per_page (entriesPerPage).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_entries( WP_REST_Request $request ) {
		$params         = $request->get_params();
		$term_id        = (int) ( $params['term_id'] ?? 0 );
		$source_post_id = (int) ( $params['source_post_id'] ?? 0 );
		$instance_id    = (string) ( $params['instance_id'] ?? '' );
		$since          = $params['since'] ?? '';
		$before         = $params['before'] ?? '';
		$per_page       = min( max( 1, (int) ( $params['per_page'] ?? 20 ) ), self::PER_PAGE_MAX );

		if ( ! term_exists( $term_id, Taxonomy::TAXONOMY_SLUG ) ) {
			return new WP_Error(
				'rolling_coverage_liveblog_not_found',
				__( 'Liveblog not found.', 'newspack-rolling-coverage' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! $since && ! $before ) {
			return new WP_Error(
				'rolling_coverage_missing_cursor',
				__( 'Either since or before must be provided.', 'newspack-rolling-coverage' ),
				[ 'status' => 400 ]
			);
		}

		$base_args = [
			'post_type'           => Post_Type::CPT_SLUG,
			'post_status'         => 'publish',
			'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => Taxonomy::TAXONOMY_SLUG,
					'field'    => 'term_id',
					'terms'    => $term_id,
				],
			],
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		];

		$template = self::find_entry_template_for_instance( $source_post_id, $instance_id );

		// Forward/polling branch: entries newer than $since, oldest first.
		if ( $since ) {
			$args = array_merge(
				$base_args,
				[
					'date_query'     => [
						[
							'column'    => 'post_date_gmt',
							'after'     => $since,
							'inclusive' => false,
						],
					],
					'orderby'        => 'date',
					'order'          => 'ASC',
					'posts_per_page' => self::POLL_CAP,
				]
			);

			$query = new WP_Query( $args );

			$html = '';
			foreach ( $query->posts as $entry ) {
				$html .= self::render_entry( $entry, $template );
			}

			$next_since = ! empty( $query->posts )
				? self::post_date_iso( $query->posts[ count( $query->posts ) - 1 ] )
				: $since;

			return new WP_REST_Response(
				[
					'html'  => $html,
					'since' => $next_since,
					'count' => count( $query->posts ),
				]
			);
		}

		$args = array_merge(
			$base_args,
			[
				'date_query'     => [
					[
						'column'    => 'post_date_gmt',
						'before'    => $before,
						'inclusive' => false,
					],
				],
				'orderby'        => 'date',
				'order'          => 'DESC',
				'posts_per_page' => $per_page,
			]
		);

		$query = new WP_Query( $args );

		$html = '';
		foreach ( $query->posts as $entry ) {
			$html .= self::render_entry( $entry, $template );
		}

		$next_before = ! empty( $query->posts )
			? self::post_date_iso( $query->posts[ count( $query->posts ) - 1 ] )
			: null;

		return new WP_REST_Response(
			[
				'html'    => $html,
				'before'  => $next_before,
				'hasMore' => count( $query->posts ) === $per_page,
				'count'   => count( $query->posts ),
			]
		);
	}
}
