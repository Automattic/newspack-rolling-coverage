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
use WP_HTML_Tag_Processor;
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

	// Seconds a poll response may be cached: enough for readers polling at the
	// same moment to share one response. At most half the block's default poll
	// interval, because a Batcache hit sends this same max-age again, so the
	// edge can serve a response for up to twice as long.
	const POLL_MAX_AGE = 5;

	// Max number of entries returned per page.
	const PER_PAGE_MAX = 100;

	// CSS class/ID prefix for the block's front-end markup.
	const MARKUP_PREFIX = 'newspack-rolling-coverage';

	// Word count cap for an archived entry's collapsed-content summary; CSS clips it to one line regardless.
	const ARCHIVED_ENTRY_SUMMARY_WORD_CAP = 50;

	// Option name prefix for persisted entry templates: rc_tpl_{coverage_id}_{hash}.
	const TEMPLATE_OPTION_PREFIX = 'rc_tpl_';

	// Term meta key storing the coverage's latest entry modified timestamp.
	const LAST_MODIFIED_META_KEY = 'rolling_coverage_last_modified';

	// Handle of the Newspack Theme editor script that unregisters the post blocks.
	const THEME_BLOCK_REMOVAL_SCRIPT = 'newspack-hide-fse-blocks';

	// Post blocks the entry and deep link modal templates are built from.
	const TEMPLATE_POST_BLOCKS = [
		'core/post-title',
		'core/post-date',
		'core/post-content',
		'core/post-excerpt',
		'core/post-featured-image',
		'core/post-author-name',
	];

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
	 * How many entries are rendering right now (entries can nest through the
	 * deep link modal). The entry filters below act only while it's non-zero,
	 * so an entry's own single page is left alone.
	 *
	 * @var int
	 */
	private static $entry_render_depth = 0;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'localize_block_config' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'keep_template_post_blocks' ], 11 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'localize_frontend_config' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'delete_term', [ __CLASS__, 'delete_coverage_template_options' ], 10, 3 );
		add_action( 'transition_post_status', [ __CLASS__, 'update_coverage_last_modified' ], 10, 3 );
		add_filter( 'render_block_core/post-date', [ __CLASS__, 'mark_relative_entry_date' ], 10, 3 );
		add_filter( 'render_block_core/post-content', [ __CLASS__, 'drop_entry_content_class' ], 10, 3 );
		add_filter( 'render_block_core/group', [ __CLASS__, 'apply_entry_block_gap' ], 10, 3 );
		add_filter( 'render_block_core/buttons', [ __CLASS__, 'drop_empty_entry_buttons' ], 10, 1 );
	}

	/**
	 * Renders an entry's blocks with the entry filters active.
	 *
	 * @param callable $render Renders the entry and returns its HTML.
	 * @return string
	 */
	public static function render_as_entry( callable $render ): string {
		++self::$entry_render_depth;

		try {
			return (string) $render();
		} finally {
			--self::$entry_render_depth;
		}
	}

	/**
	 * Whether an entry is being rendered, so the entry-only filters apply.
	 *
	 * @return bool
	 */
	public static function is_rendering_entry(): bool {
		return self::$entry_render_depth > 0;
	}

	/**
	 * A spacing value as CSS: a preset such as `var:preset|spacing|20`
	 * becomes its custom property, as core writes it; anything else is kept.
	 *
	 * @param string $value Spacing value.
	 * @return string
	 */
	private static function spacing_css_value( string $value ): string {
		if ( ! str_starts_with( $value, 'var:preset|spacing|' ) ) {
			return $value;
		}

		return 'var(--wp--preset--spacing--' . _wp_to_kebab_case( substr( $value, strlen( 'var:preset|spacing|' ) ) ) . ')';
	}

	/**
	 * Drops fixed dates from an entry template's post date blocks so each
	 * entry shows its own date.
	 *
	 * Templates saved before the post date carried its `core/post-data`
	 * binding have the time they were saved stored as a custom date, which
	 * core would show on every entry. Only the template is touched, so a
	 * custom date written in an entry's own content stays.
	 *
	 * @param array[] $blocks Parsed template blocks.
	 * @return array[]
	 */
	public static function drop_fixed_template_dates( array $blocks ): array {
		foreach ( $blocks as $index => $block ) {
			if (
				'core/post-date' === ( $block['blockName'] ?? '' ) &&
				isset( $block['attrs']['datetime'] ) &&
				! isset( $block['attrs']['metadata']['bindings']['datetime'] )
			) {
				unset( $blocks[ $index ]['attrs']['datetime'] );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::drop_fixed_template_dates( $block['innerBlocks'] );
			}
		}

		return $blocks;
	}

	/**
	 * Writes an entry's flex group block spacing (e.g. the date and title
	 * stack) onto the group on the Newspack Theme.
	 *
	 * Core only outputs block spacing for themes that support it through
	 * theme.json; the classic Newspack Theme doesn't, so core falls back to
	 * its 0.5em default and ignores the value set in the editor. Themes that
	 * support block spacing, like the Newspack Block Theme, are left to core.
	 *
	 * Parameters stay untyped because this runs for every group block on the
	 * site, after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string   $block_content Rendered block.
	 * @param array    $block         Parsed block.
	 * @param WP_Block $instance      Block instance.
	 * @return string
	 */
	public static function apply_entry_block_gap( $block_content, $block, $instance ) {
		if (
			! is_string( $block_content ) ||
			'flex' !== ( $block['attrs']['layout']['type'] ?? '' ) ||
			! self::$entry_render_depth ||
			'newspack-theme' !== get_template() ||
			null !== wp_get_global_settings( [ 'spacing', 'blockGap' ] )
		) {
			return $block_content;
		}

		$gap = wp_sanitize_block_gap_value( $block['attrs']['style']['spacing']['blockGap'] ?? null );
		$gap = is_array( $gap ) ? [ $gap['top'] ?? null, $gap['left'] ?? null ] : [ $gap ];
		$gap = array_filter( $gap, fn( $value ) => is_scalar( $value ) && '' !== (string) $value );

		if ( ! $gap ) {
			return $block_content;
		}

		$gap = array_map( fn( $value ) => self::spacing_css_value( (string) $value ), $gap );

		$declaration = ( new \WP_Style_Engine_CSS_Declarations( [ 'gap' => implode( ' ', $gap ) ] ) )->get_declarations_string();
		$group       = new WP_HTML_Tag_Processor( $block_content );

		if ( $declaration && $group->next_tag() ) {
			$style = trim( (string) $group->get_attribute( 'style' ), " \t\n\r;" );
			$group->set_attribute( 'style', ( $style ? $style . ';' : '' ) . $declaration );
		}

		return $group->get_updated_html();
	}

	/**
	 * Drops an entry's Buttons block when none of its buttons render, e.g.
	 * "Read more" before the breakout post is published, so the empty row
	 * doesn't add the entry's block spacing twice.
	 *
	 * The parameter stays untyped because this runs for every Buttons block on
	 * the site, after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string $block_content Rendered block.
	 * @return string
	 */
	public static function drop_empty_entry_buttons( $block_content ) {
		if ( ! is_string( $block_content ) || ! self::$entry_render_depth ) {
			return $block_content;
		}

		$buttons = new WP_HTML_Tag_Processor( $block_content );

		while ( $buttons->next_tag() ) {
			if ( $buttons->has_class( 'wp-block-button' ) ) {
				return $block_content;
			}
		}

		return '';
	}

	/**
	 * Drops the `entry-content` class core adds to an entry's post content.
	 * Entries render inside the host page's own `.entry-content`, so themes'
	 * rules for top-level page content would space the entry's paragraphs as
	 * if they were the page's.
	 *
	 * Parameters stay untyped because this runs for every post content block on
	 * the site, after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string   $block_content Rendered block.
	 * @param array    $block         Parsed block.
	 * @param WP_Block $instance      Block instance.
	 * @return string
	 */
	public static function drop_entry_content_class( $block_content, $block, $instance ) {
		if (
			! is_string( $block_content ) ||
			! $instance instanceof WP_Block ||
			! self::$entry_render_depth
		) {
			return $block_content;
		}

		$content = new WP_HTML_Tag_Processor( $block_content );

		if ( $content->next_tag() ) {
			$content->remove_class( 'entry-content' );
		}

		return $content->get_updated_html();
	}

	/**
	 * Marks an entry's relative date ("5 mins ago") so the front-end script
	 * keeps it current; the text is only true when the page is rendered.
	 *
	 * Parameters stay untyped because this runs for every post date block on
	 * the site, after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string   $block_content Rendered block.
	 * @param array    $block         Parsed block.
	 * @param WP_Block $instance      Block instance.
	 * @return string
	 */
	public static function mark_relative_entry_date( $block_content, $block, $instance ) {
		if (
			! is_string( $block_content ) ||
			! $instance instanceof WP_Block ||
			'human-diff' !== ( $block['attrs']['format'] ?? '' ) ||
			! self::$entry_render_depth
		) {
			return $block_content;
		}

		$time = new WP_HTML_Tag_Processor( $block_content );

		if ( $time->next_tag( 'time' ) ) {
			$time->set_attribute( 'data-rc-relative', '' );
		}

		return $time->get_updated_html();
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

		$modified = $post->post_modified_gmt;

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
					'readMoreTextMetaKey'         => Breakout::ENTRY_READ_MORE_TEXT_META,
					'onesignalConfigured'         => Push_Notifications::is_onesignal_configured(),
				]
			);
		}
	}

	/**
	 * Keeps the post blocks used by the block's templates registered in the
	 * editor when the Newspack Theme is active.
	 *
	 * The theme unregisters most post blocks in the editor, which leaves the
	 * entry template unable to render. Its script reads the list from the
	 * `updateAllowedBlocks` global, so localizing that global once more, after
	 * the theme has localized it, hands the script a list that leaves these
	 * blocks out. Every other block the theme removes stays removed.
	 */
	public static function keep_template_post_blocks() {
		if ( ! function_exists( 'newspack_fse_blocks_to_remove' ) || ! wp_script_is( self::THEME_BLOCK_REMOVAL_SCRIPT, 'enqueued' ) ) {
			return;
		}

		$blocks_to_remove = array_diff(
			explode( ',', newspack_fse_blocks_to_remove()['removeblocks'] ?? '' ),
			self::TEMPLATE_POST_BLOCKS
		);

		wp_localize_script(
			self::THEME_BLOCK_REMOVAL_SCRIPT,
			'updateAllowedBlocks',
			[ 'removeblocks' => implode( ',', $blocks_to_remove ) ]
		);
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

		// Preload so polled entries' blocks are styled and share even if none appeared on initial render.
		foreach ( [ 'core/buttons', 'core/button', 'core/separator', 'core/icon' ] as $entry_block_name ) {
			$entry_block_type = WP_Block_Type_Registry::get_instance()->get_registered( $entry_block_name );

			foreach ( $entry_block_type ? $entry_block_type->style_handles : [] as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}
		}

		$share_link_block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'newspack-rolling-coverage/share' );

		if ( $share_link_block_type ) {
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
		$pinned_label     = trim( (string) ( $attributes['pinnedLabel'] ?? '' ) );

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
		$layout_class = self::entry_layout_class( $attributes );
		$template_key = self::persist_block_config( $coverage_id, $template, $ads_enabled_attr, $ads_interval, $pinned_label, $layout_class );

		$entries_html = '';
		$entry_index  = 0;

		foreach ( $query->posts as $entry ) {
			$entry_index++;
			$entries_html .= self::render_entry( $entry, $template, 'initial', $pinned_label, $layout_class );

			if ( $ads_enabled && Ads::is_capped_ad_position( $entry_index, $ads_interval ) ) {
				$entries_html .= Ads::render_placement()['html'];
			}
		}

		wp_reset_postdata();

		$cursor     = self::latest_cursor( $query->posts );
		$oldest_gmt = ! empty( $query->posts ) ? self::post_date_gmt( $query->posts[ count( $query->posts ) - 1 ] ) : '';
		$has_more   = count( $query->posts ) === $entries_per_page;

		$coverage_archived_notice_html = Taxonomy::STATUS_ARCHIVED === $status
			? self::render_coverage_archived_notice( $block )
			: '';

		if ( empty( $query->posts ) ) {
			self::store_template_layout_styles( $template );

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
				'data-before'           => $oldest_gmt,
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
				$coverage_archived_notice_html,
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
	 * Stores the layout styles of the template's blocks, as rendering an
	 * entry would. Core prints them only for blocks rendered on the page, so
	 * without this, entries that reach a coverage that loaded empty would
	 * arrive by polling with no layout, e.g. Share not opposite the title.
	 *
	 * @param array[] $blocks        Parsed template blocks.
	 * @param array   $parent_layout The parent block's layout, as core passes
	 *                               it to child blocks when rendering.
	 */
	private static function store_template_layout_styles( array $blocks, array $parent_layout = [] ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			if ( $parent_layout ) {
				$block['parentLayout'] = $parent_layout;
			}

			// Dynamic blocks have no saved markup; core needs a tag to store their styles.
			$markup = trim( (string) ( $block['innerHTML'] ?? '' ) );
			wp_render_layout_support_flag( '' !== $markup ? $markup : '<div></div>', $block );

			self::store_template_layout_styles( $block['innerBlocks'] ?? [], (array) ( $block['attrs']['layout'] ?? [] ) );
		}
	}

	/**
	 * Lays each entry out as a core flow layout spaced by the block's Block
	 * spacing setting (`spacing-20` when unset), and returns the container
	 * class the entries carry. Core prints the layout's styles with the
	 * page's other block styles; the class depends only on the spacing, so
	 * entries added by polling or load more share it.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Container class.
	 */
	private static function entry_layout_class( array $attributes ): string {
		$gap   = wp_sanitize_block_gap_value( $attributes['style']['spacing']['blockGap'] ?? null );
		$gap   = is_array( $gap ) ? ( $gap['top'] ?? null ) : $gap;
		$gap   = is_string( $gap ) && '' !== $gap ? $gap : 'var:preset|spacing|20';
		$class = self::MARKUP_PREFIX . '-entry-layout-' . substr( md5( $gap ), 0, 8 );

		wp_get_layout_style( '.' . $class, [ 'type' => 'default' ], true, $gap );

		return $class;
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
	 * The button is removable, so this returns an empty string if the editor
	 * deleted it (or if the follow button shouldn't render at all). It's a
	 * core button bound to the coverage, or the legacy Follow block on
	 * coverages saved before it.
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
			if ( Coverage_Follow_Block::BLOCK_NAME === ( $inner['blockName'] ?? '' ) || Entry_Bindings::is_follow_buttons( $inner ) ) {
				$follow_block = $inner;
				break;
			}
		}

		if ( null === $follow_block ) {
			return '';
		}

		// Preload the follow button's view script and the legacy block's
		// styles: the button renders inside this callback, so WordPress
		// doesn't enqueue its assets.
		$follow_block_type = WP_Block_Type_Registry::get_instance()->get_registered( Coverage_Follow_Block::BLOCK_NAME );

		if ( $follow_block_type ) {
			foreach ( $follow_block_type->style_handles as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}

			foreach ( $follow_block_type->view_script_handles as $script_handle ) {
				wp_enqueue_script( $script_handle );
			}
		}

		if ( Coverage_Follow_Block::BLOCK_NAME !== $follow_block['blockName'] ) {
			$add_coverage_context = fn( $context ) => array_merge(
				(array) $context,
				[
					Entry_Bindings::COVERAGE_ID_CONTEXT => $coverage_id,
					Entry_Bindings::COVERAGE_STATUS_CONTEXT => $status,
				]
			);

			add_filter( 'render_block_context', $add_coverage_context );

			try {
				return render_block( $follow_block );
			} finally {
				remove_filter( 'render_block_context', $add_coverage_context );
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
	 * Renders the coverage-archived-notice inner block once, at the top.
	 *
	 * @param WP_Block $block The parent rolling-coverage block instance.
	 * @return string Rendered HTML.
	 */
	private static function render_coverage_archived_notice( WP_Block $block ): string {
		$archived_notice_block_type = WP_Block_Type_Registry::get_instance()->get_registered( Coverage_Archived_Notice_Block::BLOCK_NAME );
		if ( $archived_notice_block_type ) {
			foreach ( $archived_notice_block_type->style_handles as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}
		}

		$notice_attrs        = [];
		$notice_inner_blocks = [];

		foreach ( $block->parsed_block['innerBlocks'] ?? [] as $inner ) {
			if ( Coverage_Archived_Notice_Block::BLOCK_NAME === ( $inner['blockName'] ?? '' ) ) {
				$notice_attrs        = $inner['attrs'] ?? [];
				$notice_inner_blocks = $inner['innerBlocks'] ?? [];
				break;
			}
		}

		return render_block(
			[
				'blockName'    => Coverage_Archived_Notice_Block::BLOCK_NAME,
				'attrs'        => $notice_attrs,
				'innerBlocks'  => $notice_inner_blocks,
				'innerHTML'    => '',
				'innerContent' => array_fill( 0, count( $notice_inner_blocks ), null ),
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

		// The saved inner blocks also include blocks that render once at the
		// top of the coverage, not per entry.
		$singleton_blocks = [
			Deep_Link_CTA_Block::BLOCK_NAME,
			Coverage_Follow_Block::BLOCK_NAME,
			Coverage_Archived_Notice_Block::BLOCK_NAME,
		];
		$template         = [];

		foreach ( $inner_blocks as $inner_block ) {
			if ( ! in_array( $inner_block['blockName'] ?? '', $singleton_blocks, true ) && ! Entry_Bindings::is_follow_buttons( $inner_block ) ) {
				$template[] = $inner_block;
			}
		}

		return $template;
	}

	/**
	 * The hardcoded fallback per-entry template: the pinned row, date and
	 * title stacked with the share button opposite, content, the breakout post
	 * link, then a separator.
	 *
	 * @return array[] Array of parsed-block-shaped arrays.
	 */
	private static function default_entry_template() {
		$separator_html = '<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide" style="margin-top:var(--wp--preset--spacing--50);margin-bottom:var(--wp--preset--spacing--50)"/>';

		return [
			[
				'blockName'    => 'core/group',
				'attrs'        => [
					'layout'   => [
						'type'              => 'flex',
						'flexWrap'          => 'nowrap',
						'justifyContent'    => 'space-between',
						'verticalAlignment' => 'center',
					],
					'style'    => [ 'spacing' => [ 'blockGap' => 'var:preset|spacing|30' ] ],
					'metadata' => [ 'name' => __( 'Header', 'newspack-rolling-coverage' ) ],
				],
				'innerBlocks'  => [
					[
						'blockName'    => 'core/group',
						'attrs'        => [
							'layout'   => [
								'type'        => 'flex',
								'orientation' => 'vertical',
							],
							'style'    => [ 'spacing' => [ 'blockGap' => 'var:preset|spacing|20' ] ],
							'metadata' => [ 'name' => __( 'Meta', 'newspack-rolling-coverage' ) ],
						],
						'innerBlocks'  => [
							self::pinned_row_block(),
							[
								'blockName'    => 'core/post-date',
								'attrs'        => [ 'format' => 'human-diff' ],
								'innerBlocks'  => [],
								'innerHTML'    => '',
								'innerContent' => [],
							],
							[
								'blockName'    => 'core/post-title',
								'attrs'        => [ 'level' => 4 ],
								'innerBlocks'  => [],
								'innerHTML'    => '',
								'innerContent' => [],
							],
						],
						'innerHTML'    => '<div class="wp-block-group"></div>',
						'innerContent' => [ '<div class="wp-block-group">', null, null, null, '</div>' ],
					],
					[
						'blockName'    => 'core/buttons',
						'attrs'        => [],
						'innerBlocks'  => [
							self::entry_button_block(
								__( 'Share', 'newspack-rolling-coverage' ),
								[
									'url' => [
										'source' => Entry_Bindings::SOURCE_NAME,
										'args'   => [ 'key' => 'shareUrl' ],
									],
								],
								[
									'border' => [ 'radius' => '9999px' ],
									'color'  => [
										'background' => 'var(--wp--preset--color--base-2, var(--newspack-theme-color-bg-light, #f0f0f0))',
										'text'       => 'var(--wp--preset--color--contrast, var(--newspack-theme-color-text-main, currentcolor))',
									],
								]
							),
						],
						'innerHTML'    => '<div class="wp-block-buttons"></div>',
						'innerContent' => [ '<div class="wp-block-buttons">', null, '</div>' ],
					],
				],
				'innerHTML'    => '<div class="wp-block-group"></div>',
				'innerContent' => [ '<div class="wp-block-group">', null, null, '</div>' ],
			],
			[
				'blockName'    => 'core/post-content',
				'attrs'        => [
					'style' => [
						'spacing' => [
							'padding' => [
								'top'    => '0',
								'right'  => '0',
								'bottom' => '0',
								'left'   => '0',
							],
						],
					],
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[
				'blockName'    => 'core/buttons',
				'attrs'        => [ 'metadata' => [ 'name' => __( 'Read more', 'newspack-rolling-coverage' ) ] ],
				'innerBlocks'  => [
					self::entry_button_block(
						__( 'Read more', 'newspack-rolling-coverage' ),
						[
							'url'  => [
								'source' => Entry_Bindings::SOURCE_NAME,
								'args'   => [ 'key' => 'breakoutUrl' ],
							],
							'text' => [
								'source' => Entry_Bindings::SOURCE_NAME,
								'args'   => [ 'key' => 'breakoutLabel' ],
							],
						]
					),
				],
				'innerHTML'    => '<div class="wp-block-buttons"></div>',
				'innerContent' => [ '<div class="wp-block-buttons">', null, '</div>' ],
			],
			[
				'blockName'    => 'core/separator',
				'attrs'        => [
					'className' => 'is-style-wide',
					'style'     => [
						'spacing' => [
							'margin' => [
								'top'    => 'var:preset|spacing|50',
								'bottom' => 'var:preset|spacing|50',
							],
						],
					],
				],
				'innerBlocks'  => [],
				'innerHTML'    => $separator_html,
				'innerContent' => [ $separator_html ],
			],
		];
	}

	/**
	 * A parsed row of the pin icon and the pinned label, shown only on pinned
	 * entries.
	 *
	 * @return array Parsed-block-shaped array.
	 */
	private static function pinned_row_block(): array {
		$label_html = '<p class="use-header-font has-small-font-size" style="font-weight:700"></p>';

		return [
			'blockName'    => 'core/group',
			'attrs'        => [
				'layout'   => [
					'type'              => 'flex',
					'flexWrap'          => 'nowrap',
					'verticalAlignment' => 'center',
				],
				'style'    => [ 'spacing' => [ 'blockGap' => 'var:preset|spacing|20' ] ],
				'metadata' => [ 'name' => __( 'Pinned', 'newspack-rolling-coverage' ) ],
			],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/icon',
					'attrs'        => [
						'icon'  => Block_Icons::PIN,
						'style' => [ 'dimensions' => [ 'width' => '24px' ] ],
					],
					'innerBlocks'  => [],
					'innerHTML'    => '',
					'innerContent' => [],
				],
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [
						'className' => 'use-header-font',
						'fontSize'  => 'small',
						'style'     => [ 'typography' => [ 'fontWeight' => '700' ] ],
						'metadata'  => [
							'bindings' => [
								'content' => [
									'source' => Entry_Bindings::SOURCE_NAME,
									'args'   => [ 'key' => 'pinnedLabel' ],
								],
							],
						],
					],
					'innerBlocks'  => [],
					'innerHTML'    => $label_html,
					'innerContent' => [ $label_html ],
				],
			],
			'innerHTML'    => '<div class="wp-block-group"></div>',
			'innerContent' => [ '<div class="wp-block-group">', null, null, '</div>' ],
		];
	}

	/**
	 * A parsed core/button block whose link, and optionally label, are bound
	 * to the entry.
	 *
	 * @param string $text     Button label.
	 * @param array  $bindings Block bindings keyed by attribute.
	 * @param array  $style    Border, color and spacing styles, as the editor saves them.
	 * @return array Parsed-block-shaped array.
	 */
	private static function entry_button_block( string $text, array $bindings, array $style = [] ): array {
		$link_style   = [];
		$link_classes = [ 'wp-block-button__link' ];

		if ( isset( $style['border']['radius'] ) ) {
			$link_style[] = 'border-radius:' . $style['border']['radius'];
		}

		if ( isset( $style['color']['text'] ) ) {
			$link_style[]   = 'color:' . $style['color']['text'];
			$link_classes[] = 'has-text-color';
		}

		if ( isset( $style['color']['background'] ) ) {
			$link_style[]   = 'background-color:' . $style['color']['background'];
			$link_classes[] = 'has-background';
		}

		$link_classes[] = 'wp-element-button';

		foreach ( $style['spacing']['padding'] ?? [] as $side => $value ) {
			$link_style[] = 'padding-' . $side . ':' . $value;
		}

		$html = sprintf(
			'<div class="wp-block-button"><a class="%s"%s>%s</a></div>',
			esc_attr( implode( ' ', $link_classes ) ),
			$link_style ? ' style="' . esc_attr( implode( ';', $link_style ) ) . '"' : '',
			esc_html( $text )
		);
		$attrs = [ 'metadata' => [ 'bindings' => $bindings ] ];

		if ( $style ) {
			$attrs['style'] = $style;
		}

		return [
			'blockName'    => 'core/button',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * Stores the entry template plus the block's ad settings and pinned label
	 * in the options table and returns a hash key identifying that exact
	 * combination.
	 *
	 * @param int    $coverage_id  Coverage term ID.
	 * @param array  $template     Per-entry inner-block template.
	 * @param bool   $ads_enabled  The block's own Enable Ads toggle.
	 * @param int    $ads_interval Show an ad after every N entries.
	 * @param string $pinned_label The block's label for pinned entries.
	 * @param string $layout_class The entries' layout container class.
	 * @return string Hash key identifying this config.
	 */
	private static function persist_block_config( int $coverage_id, array $template, bool $ads_enabled, int $ads_interval, string $pinned_label = '', string $layout_class = '' ): string {
		$config = [
			'template'    => $template,
			'adsEnabled'  => $ads_enabled,
			'adsInterval' => $ads_interval,
			'pinnedLabel' => $pinned_label,
			'layoutClass' => $layout_class,
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
	 * @return array{template: array[], adsEnabled: bool, adsInterval: int, pinnedLabel: string, layoutClass: string}
	 */
	private static function load_block_config( int $coverage_id, string $template_key ): array {
		$defaults = [
			'template'    => self::default_entry_template(),
			'adsEnabled'  => true,
			'adsInterval' => 4,
			'pinnedLabel' => '',
			'layoutClass' => '',
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
	 * @param string  $pinned_label The Rolling Coverage block's label for
	 *                              pinned entries; empty for the default.
	 * @param string  $layout_class The entries' layout container class, from
	 *                              entry_layout_class().
	 * @return string Rendered HTML for the entry.
	 */
	public static function render_entry( WP_Post $entry, array $template, string $arrival = 'initial', string $pinned_label = '', string $layout_class = '' ): string {
		$template = self::drop_fixed_template_dates( $template );
		global $post;

		$previous_post = $post;
		$post          = $entry; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $entry );

		$is_archived = Archive_Mode::is_entry_archived( $entry->ID );
		if ( $is_archived ) {
			add_filter( 'render_block_core/post-content', [ __CLASS__, 'render_archived_entry_content' ] );
		}

		try {
			$entry_content = self::render_as_entry(
				fn() => ( new WP_Block(
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
						Entry_Bindings::PINNED_LABEL_CONTEXT => $pinned_label,
					]
				) )->render( [ 'dynamic' => false ] )
			);
		} finally {
			if ( $is_archived ) {
				remove_filter( 'render_block_core/post-content', [ __CLASS__, 'render_archived_entry_content' ] );
			}

			$post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $previous_post );
		}

		$post_classes = implode( ' ', get_post_class( array_filter( [ self::MARKUP_PREFIX . '-entry', 'wp-block-post', $layout_class ? 'is-layout-flow' : '', $layout_class ] ), $entry ) );

		$html = sprintf(
			'<article id="%1$s-entry-%2$d" class="%3$s" data-entry-id="%2$d" data-entry-slug="%6$s" data-arrival="%5$s"%7$s>%4$s</article>',
			self::MARKUP_PREFIX,
			$entry->ID,
			esc_attr( $post_classes ),
			$entry_content,
			esc_attr( $arrival ),
			esc_attr( $entry->post_name ),
			Post_Type::is_pinned( $entry->ID ) ? ' data-pinned' : ''
		);

		return $html;
	}

	/**
	 * Renders the notice shown above an individually archived entry's content.
	 *
	 * @return string Rendered HTML.
	 */
	private static function render_archived_entry_notice(): string {
		$text = apply_filters(
			'newspack_rolling_coverage_entry_archived_notice',
			__( 'This entry is now out of date compared to newer entries, but is preserved as it originally appeared.', 'newspack-rolling-coverage' )
		);

		return sprintf(
			'<p class="%s-entry-archived-notice">%s</p>',
			self::MARKUP_PREFIX,
			wp_kses_post( $text )
		);
	}

	/**
	 * Prepends the archived-entry notice and collapses the content into a
	 * `<details>` element, with a one-line summary clipped by CSS.
	 *
	 * @param string $block_content The block's rendered HTML.
	 * @return string The notice plus the (possibly collapsed) content.
	 */
	public static function render_archived_entry_content( string $block_content ): string {
		$plain_text = wp_strip_all_tags( $block_content );
		$summary    = wp_trim_words( $plain_text, self::ARCHIVED_ENTRY_SUMMARY_WORD_CAP, '' );

		$content = $summary === $plain_text
			? $block_content
			: sprintf(
				'<details class="%1$s-archived-entry-content"><summary>%2$s</summary>%3$s</details>',
				self::MARKUP_PREFIX,
				$summary,
				$block_content
			);

		return self::render_archived_entry_notice() . $content;
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
	 * Raw GMT creation date string for a post (Y-m-d H:i:s).
	 *
	 * @param WP_Post $post Post object.
	 * @return string GMT timestamp in Y-m-d H:i:s format.
	 */
	private static function post_date_gmt( WP_Post $post ): string {
		return $post->post_date_gmt;
	}

	/**
	 * Raw GMT last-modified string for a post (Y-m-d H:i:s).
	 *
	 * @param WP_Post $post Post object.
	 * @return string GMT timestamp in Y-m-d H:i:s format.
	 */
	private static function post_modified_gmt( WP_Post $post ): string {
		return $post->post_modified_gmt;
	}

	/**
	 * Poll cursor for a set of entries: "{id}:{modified_gmt}".
	 * Falls back to "0:{current_time}" when there are none.
	 *
	 * @param WP_Post[] $posts Entry post objects.
	 * @return string Cursor in "{id}:{modified_gmt}" format.
	 */
	private static function latest_cursor( array $posts ): string {
		$latest_post     = null;
		$latest_modified = '';

		foreach ( $posts as $post ) {
			$modified = self::post_modified_gmt( $post );
			if ( '' === $latest_modified || $modified > $latest_modified ) {
				$latest_modified = $modified;
				$latest_post     = $post;
			}
		}

		if ( null === $latest_post ) {
			return '0:' . gmdate( 'Y-m-d H:i:s' );
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
	 * the bare `{ id, type, pinned }` shape the editor preview needs.
	 *
	 * @param int $id Entry post ID.
	 * @return array{id: int, type: string, pinned: bool}
	 */
	private static function map_entry_preview( int $id ): array {
		return [
			'id'     => $id,
			'type'   => Post_Type::CPT_SLUG,
			'pinned' => Post_Type::is_pinned( $id ),
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
	 *   Sends a POLL_MAX_AGE-second Cache-Control; see poll_response().
	 * - `before` (backward/pagination): entries published before the given
	 *   date, DESC order, capped at the request's per_page (entriesPerPage).
	 *   Sends no Cache-Control, so it keeps the page cache's default lifetime,
	 *   the same as the page it extends: rendering a page of entries costs
	 *   more than answering an idle poll. A cached copy can predate an edit
	 *   the reader's poll has already delivered, so the view script keeps
	 *   those edits and applies them when load more brings the entry in.
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

		$config           = self::load_block_config( $term_id, $template_key );
		$template         = $config['template'];
		$ads_interval     = max( 1, (int) $config['adsInterval'] );
		$ads_enabled_attr = (bool) $config['adsEnabled'];
		$ads_enabled      = $ads_enabled_attr && ! self::is_coverage_ads_disabled( $term_id );
		$pinned_label     = (string) $config['pinnedLabel'];
		$layout_class     = sanitize_html_class( (string) $config['layoutClass'] );

		// Forward/polling branch: entries modified at or after the cursor, newest first.
		if ( $cursor ) {
			$cursor_parts    = explode( ':', $cursor, 2 );
			$cursor_id       = (int) ( $cursor_parts[0] ?? 0 );
			$cursor_modified = $cursor_parts[1] ?? '';

			// Skip WP_Query entirely when the coverage has not changed since the cursor.
			$last_modified = get_term_meta( $term_id, self::LAST_MODIFIED_META_KEY, true );

			if ( $last_modified && $last_modified <= $cursor_modified ) {
				return self::poll_response(
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
				return self::poll_response(
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
				$entry_modified = self::post_modified_gmt( $entry );

				if ( $entry->ID === $cursor_id && $entry_modified === $cursor_modified ) {
					continue;
				}

				if ( empty( $entries ) ) {
					$new_cursor = $entry->ID . ':' . $entry_modified;
				}

				// Counts only if first published after the poll cursor.
				$is_new_entry = Post_Type::get_entry_published_gmt( $entry ) > $cursor_modified;
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
					'html'   => self::render_entry( $entry, $template, $is_new_entry ? 'poll' : '', $pinned_label, $layout_class ),
					'type'   => $is_new_entry ? 'insert' : 'update',
					'adHtml' => $ad_html,
					'adSlot' => $ad_slot,
				];
			}
			wp_reset_postdata();

			return self::poll_response(
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

		// Prevents duplicate pinned entries on frontend.
		$args[ Post_Type::SKIP_PIN_ORDER_VAR ] = true;

		$entry_offset = max( 0, (int) ( $params['entry_offset'] ?? 0 ) );

		$query = new WP_Query( $args );

		$html        = '';
		$ad_slots    = [];
		$entry_index = 0;

		foreach ( $query->posts as $entry ) {
			$entry_index++;
			$html .= self::render_entry( $entry, $template, 'load_more', $pinned_label, $layout_class );

			$position = $entry_offset + $entry_index;
			if ( $ads_enabled && Ads::is_capped_ad_position( $position, $ads_interval ) ) {
				$placement = Ads::render_placement();
				$html     .= $placement['html'];
				$ad_slots  = array_merge( $ad_slots, $placement['slots'] );
			}
		}

		wp_reset_postdata();

		$next_before = ! empty( $query->posts )
			? self::post_date_gmt( $query->posts[ count( $query->posts ) - 1 ] )
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

	/**
	 * Builds a poll response that caches for POLL_MAX_AGE seconds.
	 *
	 * An idle poll's URL only changes once something new is published, so
	 * this response's cache lifetime is how long a new entry can take to
	 * reach open pages. Left unset, Batcache and the edge keep it for five
	 * minutes. Batcache adopts the max-age sent here as its own lifetime, so
	 * this one header sets both. Authenticated requests still get core's
	 * no-cache headers, which replace it.
	 *
	 * @param array $data Poll response body.
	 * @return WP_REST_Response Response with a short Cache-Control header.
	 */
	private static function poll_response( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'public, max-age=' . self::POLL_MAX_AGE );

		return $response;
	}
}
