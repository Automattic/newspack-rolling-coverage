<?php
/**
 * Rolling Coverage Gutenberg block: registration, SSR, and the dedicated
 * polling/pagination REST route.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use Google\Site_Kit\Modules\Analytics_4;
use Google\Site_Kit\Modules\Analytics_4\Settings as Site_Kit_Analytics_4_Settings;
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

	// The block's registered name.
	const BLOCK_NAME = 'newspack-rolling-coverage/rolling-coverage';

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
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'localize_block_config' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'localize_frontend_config' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'delete_term', [ __CLASS__, 'delete_coverage_template_options' ], 10, 3 );
		add_action( 'transition_post_status', [ __CLASS__, 'update_coverage_last_modified' ], 10, 3 );
	}

	/**
	 * Updates the coverage's last-modified term meta when an entry's status
	 * changes to or from 'publish', and on saves while already published.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Entry post object.
	 */
	public static function update_coverage_last_modified( string $new_status, string $old_status, WP_Post $post ): void {
		if ( Post_Type::CPT_SLUG !== $post->post_type ) {
			return;
		}

		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}

		$term_ids = wp_get_post_terms( $post->ID, Taxonomy::TAXONOMY_SLUG, [ 'fields' => 'ids' ] );

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
	 * Registers the block type.
	 */
	public static function register_block() {
		register_block_type(
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/rolling-coverage',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Localizes the block editor script with config data.
	 *
	 * Runs on enqueue_block_editor_assets (after init) so that
	 * AI_Service::is_available() sees a fully initialized AI client.
	 */
	public static function localize_block_config() {
		if ( ! wp_should_load_block_editor_scripts_and_styles() ) {
			return;
		}

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK_NAME );

		if ( ! $block_type instanceof WP_Block_Type ) {
			return;
		}

		foreach ( $block_type->editor_script_handles as $handle ) {
			wp_localize_script(
				$handle,
				'newspackRollingCoverageBlock',
				[
					'coveragesRestBase'           => esc_url_raw( rest_url( 'wp/v2/' . Taxonomy::REST_BASE ) ),
					'statusMetaKey'               => Taxonomy::STATUS_META_KEY,
					'adsDisabledMetaKey'          => Taxonomy::ADS_DISABLED_META_KEY,
					'entriesPreviewRestBase'      => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/coverages' ) ),
					'aiEndpoint'                  => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/coverages' ) ),
					'aiAvailable'                 => AI_Service::is_available(),
					'newspackAdsAvailable'        => Ads::is_available(),
					'newspackAdsPlacementEnabled' => Ads::is_placement_enabled(),
					'canonicalUrlMetaKey'         => Taxonomy::CANONICAL_URL_META_KEY,
				]
			);
		}
	}

	/**
	 * Localizes the block's view script with config data.
	 *
	 * Runs on wp_enqueue_scripts so the config reaches the frontend, where
	 * the view script actually runs.
	 */
	public static function localize_frontend_config() {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK_NAME );

		if ( ! $block_type instanceof WP_Block_Type ) {
			return;
		}

		$should_track_reader_events = ! current_user_can( 'edit_posts' );

		foreach ( $block_type->view_script_handles as $handle ) {
			wp_localize_script(
				$handle,
				'newspackRollingCoverageFrontend',
				[
					'readerTrackingEnabled' => $should_track_reader_events,
					'siteKitGa4Enabled'     => $should_track_reader_events && self::is_site_kit_ga4_tracking_ready(),
				]
			);
		}
	}

	/**
	 * Checks whether Site Kit's GA4 module is ready to receive frontend events.
	 *
	 * @return bool Whether GA4 tracking via Site Kit is ready for this request.
	 */
	private static function is_site_kit_ga4_tracking_ready(): bool {
		if ( ! class_exists( Analytics_4::class ) || ! class_exists( Site_Kit_Analytics_4_Settings::class ) ) {
			return false;
		}

		$settings = get_option( Site_Kit_Analytics_4_Settings::OPTION, [] );

		if ( ! is_array( $settings ) ) {
			return false;
		}

		return ! empty( $settings['useSnippet'] ) && ! empty( $settings['measurementID'] );
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
		$cta_block_type = WP_Block_Type_Registry::get_instance()->get_registered( Deep_Link_CTA_Block::BLOCK_NAME );

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
		$ads_interval     = max( 1, (int) ( $attributes['adsInterval'] ?? 4 ) );
		$status           = get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );
		$status           = $status ? $status : 'active';
		$ads_enabled_attr = ! empty( $attributes['enableAds'] );
		$ads_enabled      = $ads_enabled_attr && ! self::is_coverage_ads_disabled( $coverage_id );

		// A trashed coverage is effectively invisible on the frontend.
		if ( 'trash' === $status ) {
			return sprintf(
				'<p %s>%s</p>',
				get_block_wrapper_attributes(),
				esc_html__( 'This coverage is no longer available.', 'newspack-rolling-coverage' )
			);
		}

		$query = new WP_Query(
			[
				'post_type'           => Post_Type::CPT_SLUG,
				'post_status'         => [ 'publish', Archive_Mode::ENTRY_ARCHIVED_STATUS ],
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
		$template_key = self::persist_block_config( $coverage_id, $template, $ads_enabled_attr, $ads_interval );

		$entries_html = '';
		$entry_index  = 0;

		foreach ( $query->posts as $entry ) {
			$entry_index++;
			$entries_html .= self::render_entry( $entry, $template, 'initial' );

			if ( $ads_enabled && Ads::is_capped_ad_position( $entry_index, $ads_interval ) ) {
				$entries_html .= Ads::render_placement()['html'];
			}
		}

		wp_reset_postdata();

		$cursor     = self::latest_cursor( $query->posts );
		$oldest_iso = ! empty( $query->posts ) ? self::post_date_iso( $query->posts[ count( $query->posts ) - 1 ] ) : '';
		$has_more   = count( $query->posts ) === $entries_per_page;

		$notice = '';

		if ( Taxonomy::STATUS_ARCHIVED === $status ) {
			$notice = sprintf(
				'<p class="%1$s-notice %1$s-notice--archived">%2$s</p>',
				self::MARKUP_PREFIX,
				esc_html__( 'This coverage has ended and is preserved as an archive.', 'newspack-rolling-coverage' )
			);
		} elseif ( 'paused' === $status ) {
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

		// Follow button: rendered once at the top of the coverage, not per entry.
		$follow_html = self::maybe_render_follow_button( $block, $coverage_id, $status );

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
				'<div %1$s>%6$s%2$s%5$s<div class="%3$s-status" role="status" aria-live="polite"></div><button type="button" class="%3$s-new-entries" hidden></button><div class="%3$s-entries">%4$s</div><div class="%3$s-sentinel" aria-hidden="true"></div></div>',
				$wrapper_attributes,
				$notice,
				self::MARKUP_PREFIX,
				$entries_html,
				$cta_html,
				$follow_html
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
		if ( $coverage_id && ! has_term( $coverage_id, Taxonomy::TAXONOMY_SLUG, $entry ) ) {
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
			if ( Deep_Link_CTA_Block::BLOCK_NAME === ( $inner['blockName'] ?? '' ) ) {
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
				'blockName'    => Deep_Link_CTA_Block::BLOCK_NAME,
				'attrs'        => $cta_attrs,
				'innerBlocks'  => $cta_inner_blocks,
				'innerHTML'    => '',
				'innerContent' => array_fill( 0, count( $cta_inner_blocks ), null ),
			]
		);
	}

	/**
	 * Renders the follow button once at the top of the coverage.
	 *
	 * The block is removable, so this returns an empty string if the editor
	 * deleted it (or if the follow button shouldn't render at all).
	 *
	 * @param WP_Block $block       The parent rolling-coverage block instance.
	 * @param int      $coverage_id Coverage term id.
	 * @param string   $status      Coverage status.
	 * @return string Follow button HTML, or an empty string.
	 */
	private static function maybe_render_follow_button( WP_Block $block, int $coverage_id, string $status ): string {
		if ( ! Coverage_Follow_Block::should_render( $status ) ) {
			return '';
		}

		$follow_block = null;

		foreach ( $block->parsed_block['innerBlocks'] ?? [] as $inner ) {
			if ( Coverage_Follow_Block::BLOCK_NAME === ( $inner['blockName'] ?? '' ) ) {
				$follow_block = $inner;
				break;
			}
		}

		if ( null === $follow_block ) {
			return '';
		}

		// Preload the follow button styles and view script. The button is
		// rendered via render_block() below, so WordPress doesn't
		// auto-enqueue its assets — we must do it manually.
		$follow_block_type = WP_Block_Type_Registry::get_instance()->get_registered( Coverage_Follow_Block::BLOCK_NAME );

		if ( $follow_block_type ) {
			foreach ( $follow_block_type->style_handles as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}

			foreach ( $follow_block_type->view_script_handles as $script_handle ) {
				wp_enqueue_script( $script_handle );
			}
		}

		$attrs               = $follow_block['attrs'] ?? [];
		$attrs['coverageId'] = $coverage_id;
		$attrs['status']     = $status;

		return render_block(
			[
				'blockName'    => Coverage_Follow_Block::BLOCK_NAME,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
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

		if ( empty( $inner_blocks ) ) {
			return self::default_entry_template();
		}

		// The saved inner blocks also include two blocks that render once at
		// the top of the coverage, not per entry.
		$singleton_blocks = [ Deep_Link_CTA_Block::BLOCK_NAME, Coverage_Follow_Block::BLOCK_NAME ];
		$template         = [];

		foreach ( $inner_blocks as $inner_block ) {
			if ( ! in_array( $inner_block['blockName'] ?? '', $singleton_blocks, true ) ) {
				$template[] = $inner_block;
			}
		}

		return $template;
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
	 * Stores the entry template plus the block's ad settings in the options
	 * table and returns a hash key identifying that exact combination.
	 *
	 * @param int   $coverage_id  Coverage term ID.
	 * @param array $template     Per-entry inner-block template.
	 * @param bool  $ads_enabled  The block's own Enable Ads toggle.
	 * @param int   $ads_interval Show an ad after every N entries.
	 * @return string Hash key identifying this config.
	 */
	private static function persist_block_config( int $coverage_id, array $template, bool $ads_enabled, int $ads_interval ): string {
		$config = [
			'template'    => $template,
			'adsEnabled'  => $ads_enabled,
			'adsInterval' => $ads_interval,
		];

		$hash       = substr( md5( wp_json_encode( $config ) ), 0, 12 );
		$option_key = self::TEMPLATE_OPTION_PREFIX . $coverage_id . '_' . $hash;

		if ( false === get_option( $option_key ) ) {
			update_option( $option_key, $config, false );
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
	 * Loads a persisted block config (entry template + ad settings) by
	 * coverage ID and hash key, falling back to defaults if the option is
	 * missing.
	 *
	 * @param int    $coverage_id  Coverage term ID.
	 * @param string $template_key Hash returned by persist_block_config().
	 * @return array{template: array[], adsEnabled: bool, adsInterval: int}
	 */
	private static function load_block_config( int $coverage_id, string $template_key ): array {
		$defaults = [
			'template'    => self::default_entry_template(),
			'adsEnabled'  => true,
			'adsInterval' => 4,
		];

		if ( ! $template_key ) {
			return $defaults;
		}

		$option_key = self::TEMPLATE_OPTION_PREFIX . $coverage_id . '_' . $template_key;
		$config     = get_option( $option_key );

		if ( ! is_array( $config ) || ! isset( $config['template'] ) ) {
			return $defaults;
		}

		return wp_parse_args( $config, $defaults );
	}

	/**
	 * Whether ads are disabled for a coverage, regardless of any block's own
	 * Enable Ads toggle.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return bool Whether ads are disabled for the coverage.
	 */
	private static function is_coverage_ads_disabled( int $coverage_id ): bool {
		return (bool) get_term_meta( $coverage_id, Taxonomy::ADS_DISABLED_META_KEY, true );
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
	 * @param string  $arrival  How the entry first reaches the client:
	 *                          'initial', 'poll', or 'load_more'. Stamped as
	 *                          data-arrival for frontend entry-seen tracking.
	 * @return string Rendered HTML for the entry.
	 */
	public static function render_entry( WP_Post $entry, array $template, string $arrival = 'initial' ) {
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
			'<article id="%1$s-entry-%2$d" class="%3$s" data-entry-id="%2$d" data-entry-slug="%6$s" data-arrival="%5$s">%4$s</article>',
			self::MARKUP_PREFIX,
			$entry->ID,
			esc_attr( $post_classes ),
			$entry_content,
			esc_attr( $arrival ),
			esc_attr( $entry->post_name )
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
					'entry_offset' => [
						'type'    => 'integer',
						'default' => 0,
					],
					'polled_count' => [
						'type'    => 'integer',
						'default' => 0,
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
				'post_status'         => [ 'publish', Archive_Mode::ENTRY_ARCHIVED_STATUS ],
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

		// Do not serve entries for a trashed coverage.
		$coverage_status = get_term_meta( $term_id, Taxonomy::STATUS_META_KEY, true );

		if ( 'trash' === $coverage_status ) {
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
			'post_status'         => [ 'publish', Archive_Mode::ENTRY_ARCHIVED_STATUS ],
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

		$config           = self::load_block_config( $term_id, $template_key );
		$template         = $config['template'];
		$ads_interval     = max( 1, (int) $config['adsInterval'] );
		$ads_enabled_attr = (bool) $config['adsEnabled'];
		$ads_enabled      = $ads_enabled_attr && ! self::is_coverage_ads_disabled( $term_id );

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
						'entries'     => [],
						'cursor'      => $cursor,
						'overflow'    => false,
						'polledCount' => max( 0, (int) ( $params['polled_count'] ?? 0 ) ),
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

			$args[ Post_Type::SKIP_PIN_ORDER_VAR ] = true;

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
			$polled_count = max( 0, (int) ( $params['polled_count'] ?? 0 ) );
			$new_entry_count = 0;

			foreach ( $query->posts as $entry ) {
				$entry_modified = self::post_modified_iso( $entry );

				if ( $entry->ID === $cursor_id && $entry_modified === $cursor_modified ) {
					continue;
				}

				if ( empty( $entries ) ) {
					$new_cursor = $entry->ID . ':' . $entry_modified;
				}

				// Counts only if published after the poll cursor.
				$is_new_entry = self::post_date_iso( $entry ) > $cursor_modified;
				$ad_slot      = null;
				$ad_html      = null;

				if ( $is_new_entry ) {
					++$new_entry_count;

					if ( $ads_enabled && 0 === ( $polled_count + $new_entry_count ) % $ads_interval ) {
						$placement = Ads::render_placement();
						if ( $placement['html'] ) {
							$ad_html = $placement['html'];
							$ad_slot = $placement['slots'][0] ?? null;
						}
					}
				}

				// For updates to entries already on the client, data-arrival is left
				// blank: the client preserves the original value across the replace.
				$entries[] = [
					'id'     => $entry->ID,
					'html'   => self::render_entry( $entry, $template, $is_new_entry ? 'poll' : '' ),
					'type'   => $is_new_entry ? 'insert' : 'update',
					'adHtml' => $ad_html,
					'adSlot' => $ad_slot,
				];
			}
			wp_reset_postdata();

			return new WP_REST_Response(
				[
					'entries'     => $entries,
					'cursor'      => $new_cursor,
					'overflow'    => false,
					'polledCount' => ( $polled_count + $new_entry_count ) % $ads_interval,
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

		$entry_offset = max( 0, (int) ( $params['entry_offset'] ?? 0 ) );

		$query = new WP_Query( $args );

		$html        = '';
		$ad_slots    = [];
		$entry_index = 0;

		foreach ( $query->posts as $entry ) {
			$entry_index++;
			$html .= self::render_entry( $entry, $template, 'load_more' );

			$position = $entry_offset + $entry_index;
			if ( $ads_enabled && Ads::is_capped_ad_position( $position, $ads_interval ) ) {
				$placement = Ads::render_placement();
				$html     .= $placement['html'];
				$ad_slots  = array_merge( $ad_slots, $placement['slots'] );
			}
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
				'adSlots' => $ad_slots,
			]
		);
	}
}
