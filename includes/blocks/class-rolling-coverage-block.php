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
use WP_Block_Type_Registry;
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

	// Option name prefix for persisted entry templates: rc_tpl_{coverage_id}_{hash}.
	const TEMPLATE_OPTION_PREFIX = 'rc_tpl_';

	// Term meta key storing the coverage's latest entry modified timestamp.
	const LAST_MODIFIED_META_KEY = 'rolling_coverage_last_modified';

	/**
	 * The host page's post ID, captured at the start of render_block()
	 * before the global $post is swapped to individual entries. Used by
	 * Social_Sharing::get_entry_share_url() to build the share URL with
	 * an rc_source pointing back to this page.
	 *
	 * @var int
	 */
	private static $host_post_id = 0;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'delete_term', [ __CLASS__, 'delete_coverage_template_options' ], 10, 3 );
		add_action( 'save_post_' . Post_Type::CPT_SLUG, [ __CLASS__, 'update_coverage_last_modified' ], 10, 2 );
	}

	/**
	 * Updates the coverage's last-modified term meta when a published entry is
	 * saved, so the polling endpoint can skip WP_Query when nothing has changed.
	 *
	 * @param int     $post_id Entry post ID.
	 * @param WP_Post $post    Entry post object.
	 */
	public static function update_coverage_last_modified( int $post_id, WP_Post $post ): void {
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$term_ids = wp_get_post_terms( $post_id, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
			return;
		}

		$modified_datetime = get_post_datetime( $post, 'modified', 'gmt' );

		if ( ! $modified_datetime ) {
			return;
		}

		$modified = $modified_datetime->format( DATE_ATOM );

		foreach ( $term_ids as $term_id ) {
			update_term_meta( (int) $term_id, self::LAST_MODIFIED_META_KEY, $modified );
		}
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
					'coveragesRestBase'      => esc_url_raw( rest_url( 'wp/v2/' . Taxonomy::REST_BASE ) ),
					'statusMetaKey'          => Taxonomy::STATUS_META_KEY,
					'entriesPreviewRestBase' => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/coverages' ) ),
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
		// Capture the host page's post ID before any entry rendering
		// swaps the global $post. Used by the share-link block to build
		// share URLs pointing back to this page. Save the previous
		// value so nested rolling-coverage renders restore it on exit.
		$previous_post_id   = self::$host_post_id;
		self::$host_post_id = (int) get_the_ID();

		// Preload so polled entries are styled even if no breakout buttons appeared on initial render.
		$breakout_block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'newspack-rolling-coverage/breakout-post-link' );

		if ( $breakout_block_type ) {
			foreach ( $breakout_block_type->style_handles as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}
		}

		// Preload the share block styles and view script for the same reason.
		$share_link_block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'newspack-rolling-coverage/share' );

		if ( $share_link_block_type ) {
			foreach ( $share_link_block_type->style_handles as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}

			foreach ( $share_link_block_type->view_script_handles as $script_handle ) {
				wp_enqueue_script( $script_handle );
			}
		}

		// Preload the deep-link CTA styles and view script. The CTA is
		// rendered via render_block() inside this callback, so WordPress
		// doesn't auto-enqueue its assets — we must do it manually.
		$cta_block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'newspack-rolling-coverage/deep-link-cta' );

		if ( $cta_block_type ) {
			foreach ( $cta_block_type->style_handles as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}

			foreach ( $cta_block_type->view_script_handles as $script_handle ) {
				wp_enqueue_script( $script_handle );
			}
		}

		$coverage_id = (int) ( $attributes['coverageId'] ?? 0 );

		if ( ! $coverage_id || ! term_exists( $coverage_id, Taxonomy::TAXONOMY_SLUG ) ) {
			self::$host_post_id = $previous_post_id;

			return sprintf(
				'<p %s>%s</p>',
				get_block_wrapper_attributes(),
				esc_html__( 'Select a coverage to display its entries.', 'newspack-rolling-coverage' )
			);
		}

		$entries_per_page = min( max( 1, (int) ( $attributes['entriesPerPage'] ?? 20 ) ), self::PER_PAGE_MAX );
		$poll_interval    = max( 1, (int) ( $attributes['pollInterval'] ?? 10 ) );
		$status           = get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );
		$status           = $status ? $status : 'active';

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

		$template     = self::get_entry_template( $block );
		$template_key = self::persist_entry_template( $coverage_id, $template );

		$entries_html = '';

		foreach ( $query->posts as $entry ) {
			$entries_html .= self::render_entry( $entry, $template );
		}

		wp_reset_postdata();

		$cursor     = self::latest_cursor( $query->posts );
		$oldest_iso = ! empty( $query->posts ) ? self::post_date_iso( $query->posts[ count( $query->posts ) - 1 ] ) : '';
		$has_more   = count( $query->posts ) === $entries_per_page;

		$notice = '';

		if ( 'paused' === $status ) {
			$notice = sprintf(
				'<p class="%1$s-notice %1$s-notice--paused">%2$s</p>',
				self::MARKUP_PREFIX,
				esc_html__( 'This coverage is currently paused. New updates will appear once it resumes.', 'newspack-rolling-coverage' )
			);
		}

		if ( empty( $query->posts ) ) {
			$entries_html = sprintf(
				'<p class="%s-entries__empty">%s</p>',
				self::MARKUP_PREFIX,
				esc_html__( 'No entries yet.', 'newspack-rolling-coverage' )
			);
		}

		// Deep-link CTA: SSR-populated when the deep-link query var
		// points to an entry not in the initial SSR set; empty otherwise.
		$cta_html = self::maybe_render_deep_link_cta( $query->posts, $block );

		$wrapper_attributes = get_block_wrapper_attributes(
			[
				'data-coverage-id'      => $coverage_id,
				'data-poll-interval'    => $poll_interval,
				'data-entries-per-page' => $entries_per_page,
				'data-cursor'           => $cursor,
				'data-before'           => $oldest_iso,
				'data-has-more'         => $has_more ? '1' : '0',
				'data-status'           => $status,
				'data-template-key'     => $template_key,
				'data-host-post-id'     => (int) self::$host_post_id,
				'data-rest-url'         => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/coverages/' . $coverage_id . '/entries' ) ),
			]
		);

		try {
			return sprintf(
				'<div %1$s>%2$s%5$s<div class="%3$s-status" role="status" aria-live="polite"></div><button type="button" class="%3$s-new-entries" hidden></button><div class="%3$s-entries">%4$s</div><div class="%3$s-sentinel" aria-hidden="true"></div></div>',
				$wrapper_attributes,
				$notice,
				self::MARKUP_PREFIX,
				$entries_html,
				$cta_html
			);
		} finally {
			self::$host_post_id = $previous_post_id;
		}
	}

	/**
	 * Renders the deep-link CTA when the deep-link query var points to
	 * an entry not in the initial SSR set; returns empty string otherwise.
	 *
	 * The query var `rolling-coverage-entry` powers OG tags and CTA SSR;
	 * the hash fragment powers smooth scroll. When the deep-linked entry
	 * is already in the initial SSR set, no CTA is needed — the browser
	 * scrolls to it via the #hash.
	 *
	 * @param WP_Post[] $entries Entries rendered in the initial SSR set.
	 * @param WP_Block  $block   The parent rolling-coverage block instance.
	 * @return string CTA HTML, or empty string.
	 */
	private static function maybe_render_deep_link_cta( array $entries, WP_Block $block ): string {
		$raw = trim( (string) get_query_var( Social_Sharing::ENTRY_QUERY_VAR ) );

		if ( '' === $raw ) {
			return '';
		}

		$entry = Social_Sharing::resolve_entry_by_slug( $raw );

		if ( ! $entry instanceof WP_Post ) {
			return '';
		}

		// Only render the CTA if the entry belongs to this block's coverage.
		$coverage_id = (int) ( $block->parsed_block['attrs']['coverageId'] ?? 0 );
		if ( $coverage_id && ! has_term( $entry->ID, Taxonomy::TAXONOMY_SLUG, $coverage_id ) ) {
			return '';
		}

		// If the entry is in the initial SSR set, no CTA needed — browser scrolls.
		$page_slugs = array_map(
			static function ( $e ) {
				return $e->post_name;
			},
			$entries
		);

		if ( in_array( $entry->post_name, $page_slugs, true ) ) {
			return '';
		}

		$entry_title = get_the_title( $entry );

		// Read the CTA block's saved attributes and inner blocks from the parent block.
		$cta_attrs = [
			'entryId'    => $entry->ID,
			'entryTitle' => $entry_title,
		];

		$cta_inner_blocks = [];

		foreach ( $block->parsed_block['innerBlocks'] ?? [] as $inner ) {
			if ( 'newspack-rolling-coverage/deep-link-cta' === ( $inner['blockName'] ?? '' ) ) {
				if ( ! empty( $inner['attrs']['ctaText'] ) ) {
					$cta_attrs['ctaText'] = $inner['attrs']['ctaText'];
				}
				if ( ! empty( $inner['attrs']['buttonText'] ) ) {
					$cta_attrs['buttonText'] = $inner['attrs']['buttonText'];
				}
				$cta_inner_blocks = $inner['innerBlocks'] ?? [];
				break;
			}
		}

		return render_block(
			[
				'blockName'    => 'newspack-rolling-coverage/deep-link-cta',
				'attrs'        => $cta_attrs,
				'innerBlocks'  => $cta_inner_blocks,
				'innerHTML'    => '',
				'innerContent' => array_fill( 0, count( $cta_inner_blocks ), null ),
			]
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
			// Filter out the deep-link CTA — it renders once at the top, not per-entry.
			return array_values(
				array_filter(
					$inner_blocks,
					static function ( $b ) {
						return 'newspack-rolling-coverage/deep-link-cta' !== ( $b['blockName'] ?? '' );
					}
				)
			);
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
			[
				'blockName'    => 'newspack-rolling-coverage/share',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
		];
	}

	/**
	 * Stores the entry template in the options table and returns its hash key.
	 *
	 * @param int   $coverage_id Coverage term ID.
	 * @param array $template    Per-entry inner-block template.
	 * @return string Hash key   identifying this template.
	 */
	private static function persist_entry_template( int $coverage_id, array $template ): string {
		$hash       = substr( md5( wp_json_encode( $template ) ), 0, 12 );
		$option_key = self::TEMPLATE_OPTION_PREFIX . $coverage_id . '_' . $hash;

		if ( false === get_option( $option_key ) ) {
			update_option( $option_key, $template, false );
		}

		// Prune older template option rows for this coverage so the
		// options table doesn't grow unbounded across template edits.
		$current_template_meta_key = 'rolling_coverage_template_hash';
		$previous_hash             = get_term_meta( $coverage_id, $current_template_meta_key, true );

		if ( $previous_hash && $previous_hash !== $hash ) {
			$old_option_key = self::TEMPLATE_OPTION_PREFIX . $coverage_id . '_' . $previous_hash;
			delete_option( $old_option_key );
		}

		update_term_meta( $coverage_id, $current_template_meta_key, $hash );

		return $hash;
	}

	/**
	 * Loads a persisted entry template by coverage ID and hash key, falling
	 * back to the default template if the option is missing.
	 *
	 * @param int    $coverage_id  Coverage term ID.
	 * @param string $template_key Hash returned by persist_entry_template().
	 * @return array[] Per-entry   inner-block template.
	 */
	private static function load_entry_template( int $coverage_id, string $template_key ): array {
		if ( ! $template_key ) {
			return self::default_entry_template();
		}

		$option_key = self::TEMPLATE_OPTION_PREFIX . $coverage_id . '_' . $template_key;
		$template   = get_option( $option_key );

		return is_array( $template ) ? $template : self::default_entry_template();
	}

	/**
	 * Deletes all persisted entry-template options for a coverage when it is deleted.
	 *
	 * @param int    $term_id  Term ID of the deleted coverage.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function delete_coverage_template_options( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( Taxonomy::TAXONOMY_SLUG !== $taxonomy ) {
			return;
		}

		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::TEMPLATE_OPTION_PREFIX . $term_id . '_' ) . '%'
			)
		);
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

		try {
			$entry_content = ( new WP_Block(
				[
					'blockName'    => null,
					'attrs'        => [],
					'innerBlocks'  => $template,
					'innerHTML'    => '',
					'innerContent' => array_fill( 0, count( $template ), null ),
				],
				[
					'postId'   => $entry->ID,
					'postType' => $entry->post_type,
				]
			) )->render( [ 'dynamic' => false ] );
		} finally {
			$post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $previous_post );
		}

		$post_classes = implode( ' ', get_post_class( [ self::MARKUP_PREFIX . '-entry', 'wp-block-post' ], $entry ) );

		$html = sprintf(
			'<article id="%1$s" class="%2$s" data-entry-id="%3$d">%4$s</article>',
			esc_attr( $entry->post_name ),
			esc_attr( $post_classes ),
			$entry->ID,
			$entry_content
		);

		return $html;
	}

	/**
	 * Returns the host page's post ID, captured at the start of
	 * render_block() before entry rendering swaps the global $post.
	 *
	 * @return int Host page post ID, or 0 if not in a render context.
	 */
	public static function get_host_post_id(): int {
		return self::$host_post_id;
	}

	/**
	 * ISO 8601 (GMT) date string for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string ISO 8601 date string.
	 */
	private static function post_date_iso( WP_Post $post ) {
		$datetime = get_post_datetime( $post, 'date', 'gmt' );

		return $datetime ? $datetime->format( DATE_ATOM ) : '';
	}

	/**
	 * ISO 8601 (GMT) last-modified string for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string ISO 8601 date string.
	 */
	private static function post_modified_iso( WP_Post $post ) {
		$datetime = get_post_datetime( $post, 'modified', 'gmt' );

		return $datetime ? $datetime->format( DATE_ATOM ) : '';
	}

	/**
	 * Poll cursor for a set of entries: "{id}:{modified_iso}".
	 * Falls back to "0:{current_time}" when there are none.
	 *
	 * @param WP_Post[] $posts Entry post objects.
	 * @return string Cursor in "{id}:{modified_iso}" format.
	 */
	private static function latest_cursor( array $posts ): string {
		$latest_post     = null;
		$latest_modified = '';

		foreach ( $posts as $post ) {
			$modified = self::post_modified_iso( $post );
			if ( '' === $latest_modified || $modified > $latest_modified ) {
				$latest_modified = $modified;
				$latest_post     = $post;
			}
		}

		if ( null === $latest_post ) {
			return '0:' . gmdate( 'c' );
		}

		return $latest_post->ID . ':' . $latest_modified;
	}

	/**
	 * Register the dedicated REST route used for both polling (cursor) and
	 * pagination (before), plus the lightweight editor-only preview route
	 * (see get_entries_preview()).
	 */
	public static function register_routes() {
		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/coverages/(?P<term_id>\d+)/entries',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_entries' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'term_id'      => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_term_id' ],
					],
					'template_key' => [
						'required' => true,
						'type'     => 'string',
					],
					'cursor'       => [
						'type' => 'string',
					],
					'before'       => [
						'type' => 'string',
					],
					'per_page'     => [
						'type' => 'integer',
					],
					'host_post_id' => [
						'type' => 'integer',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/coverages/(?P<term_id>\d+)/entries-preview',
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
	 * coverage's current published entries, newest first, for the block
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
				'rolling_coverage_coverage_not_found',
				__( 'Coverage not found.', 'newspack-rolling-coverage' ),
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
	 * - `cursor` (forward/polling): entries modified at or after the cursor
	 *   timestamp, including new entries and edits. If the result exceeds
	 *   POLL_CAP, the response is flagged `overflow` so the client can reload.
	 * - `before` (backward/pagination): entries published before the given
	 *   date, DESC order, capped at the request's per_page (entriesPerPage).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_entries( WP_REST_Request $request ) {
		$params       = $request->get_params();
		$term_id      = (int) ( $params['term_id'] ?? 0 );
		$template_key = (string) ( $params['template_key'] ?? '' );
		$cursor       = $params['cursor'] ?? '';
		$before       = $params['before'] ?? '';
		$per_page     = min( max( 1, (int) ( $params['per_page'] ?? 20 ) ), self::PER_PAGE_MAX );

		// Set the host post ID so share-link blocks rendered during this REST request can build correct share URLs.
		$raw_post_id = (int) ( $params['host_post_id'] ?? 0 );

		if ( $raw_post_id && get_post( $raw_post_id ) ) {
			self::$host_post_id = $raw_post_id;
		} else {
			self::$host_post_id = 0;
		}

		if ( ! term_exists( $term_id, Taxonomy::TAXONOMY_SLUG ) ) {
			return new WP_Error(
				'rolling_coverage_coverage_not_found',
				__( 'Coverage not found.', 'newspack-rolling-coverage' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! $cursor && ! $before ) {
			return new WP_Error(
				'rolling_coverage_missing_cursor',
				__( 'Either cursor or before must be provided.', 'newspack-rolling-coverage' ),
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

		$template = self::load_entry_template( $term_id, $template_key );

		// Forward/polling branch: entries modified at or after the cursor, newest first.
		if ( $cursor ) {
			$cursor_parts    = explode( ':', $cursor, 2 );
			$cursor_id       = (int) ( $cursor_parts[0] ?? 0 );
			$cursor_modified = $cursor_parts[1] ?? '';

			// Skip WP_Query entirely when the coverage has not changed since the cursor.
			$last_modified = get_term_meta( $term_id, self::LAST_MODIFIED_META_KEY, true );

			if ( $last_modified && $last_modified <= $cursor_modified ) {
				return new WP_REST_Response(
					[
						'entries'  => [],
						'cursor'   => $cursor,
						'overflow' => false,
					]
				);
			}

			$args = array_merge(
				$base_args,
				[
					'date_query'     => [
						[
							'column'    => 'post_modified_gmt',
							'after'     => $cursor_modified,
							'inclusive' => true,
						],
					],
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'posts_per_page' => self::POLL_CAP + 1, // Request one extra post to detect poll overflow.
				]
			);

			$query = new WP_Query( $args );

			// Signal the client to refresh when the poll result reaches the cap.
			if ( count( $query->posts ) > self::POLL_CAP ) {
				return new WP_REST_Response(
					[
						'entries'  => [],
						'cursor'   => $cursor,
						'overflow' => true,
					]
				);
			}

			$entries    = [];
			$new_cursor = $cursor;

			foreach ( $query->posts as $entry ) {
				$entry_modified = self::post_modified_iso( $entry );

				if ( $entry->ID === $cursor_id && $entry_modified === $cursor_modified ) {
					continue;
				}

				if ( empty( $entries ) ) {
					$new_cursor = $entry->ID . ':' . $entry_modified;
				}

				$is_new_entry = self::post_date_iso( $entry ) > $cursor_modified;

				$entries[] = [
					'id'   => $entry->ID,
					'html' => self::render_entry( $entry, $template ),
					'type' => $is_new_entry ? 'insert' : 'update',
				];
			}
			wp_reset_postdata();

			return new WP_REST_Response(
				[
					'entries'  => $entries,
					'cursor'   => $new_cursor,
					'overflow' => false,
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

		wp_reset_postdata();

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
