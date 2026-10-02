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
use WP_Term;

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
	// edge can serve a response for up to twice as long. A site's minimum poll
	// interval raises it; see get_min_poll_interval().
	const POLL_MAX_AGE = 5;

	// Max number of entries returned per page.
	const PER_PAGE_MAX = 100;

	// Newer entries a feed opened at a shared entry counts up to: one past a hundred, which reads as "more than 100".
	const NEWER_COUNT_CAP = 101;

	// CSS class/ID prefix for the block's front-end markup.
	const MARKUP_PREFIX = 'newspack-rolling-coverage';

	// Word count cap for an archived entry's collapsed-content summary; CSS clips it to one line regardless.
	const ARCHIVED_ENTRY_SUMMARY_WORD_CAP = 50;

	// Option name prefix for persisted entry templates: rc_tpl_{coverage_id}_{hash}.
	const TEMPLATE_OPTION_PREFIX = 'rc_tpl_';

	/**
	 * How many stored block configs to keep per coverage.
	 *
	 * @var int
	 */
	const CONFIGS_KEPT = 5;

	const PINNED_CARD_CLASS = 'newspack-rolling-coverage-pinned-card';

	/**
	 * Class of the group that shows an entry that isn't pinned.
	 */
	const REGULAR_ENTRY_CLASS = 'newspack-rolling-coverage-regular-entry';

	/**
	 * Class of the layout's Feed group, which holds everything the coverage
	 * shows.
	 */
	const FEED_CLASS = 'newspack-rolling-coverage-feed';

	/**
	 * Object cache group for the coverage's latest breakout post. Keys carry
	 * the posts and terms last-changed stamps, so they expire on their own.
	 */
	const BREAKOUT_CACHE_GROUP = 'newspack-rolling-coverage-breakouts';

	/**
	 * The custom property holding the space between the coverage's items:
	 * the archived notice, Follow, each entry, the separator and ads. It
	 * comes from the Feed group's Block spacing; its fallback, `spacing-50`,
	 * is in the block's stylesheet.
	 */
	const FEED_GAP_PROPERTY = '--newspack-rolling-coverage-gap';

	/**
	 * The space between the blocks of an entry group or pinned card whose
	 * Block spacing is unset.
	 */
	const DEFAULT_ENTRY_GAP = 'var:preset|spacing|20';

	/**
	 * The fallback pinned card's background and text colors: the theme's
	 * accent and its contrast color.
	 */
	const ACCENT          = 'var(--wp--preset--color--accent, var(--newspack-theme-color-primary))';
	const ACCENT_CONTRAST = 'var(--wp--preset--color--accent-contrast, var(--wp--preset--color--base, var(--newspack-theme-color-against-primary)))';

	// Term meta key storing the coverage's latest entry modified timestamp.
	const LAST_MODIFIED_META_KEY = 'rolling_coverage_last_modified';

	// Handle of the Newspack Theme editor script that unregisters the post blocks.
	const THEME_BLOCK_REMOVAL_SCRIPT = 'newspack-hide-fse-blocks';

	// Post blocks the entry template is built from.
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
	 * an rc_source pointing back to this page, and to link the "Jump to
	 * latest" button to the live feed.
	 *
	 * @var int
	 */
	private static $host_post_id = 0;

	/**
	 * How many entries are rendering right now. The entry filters below act
	 * only while it's non-zero, so an entry's own single page is left alone.
	 *
	 * @var int
	 */
	private static $entry_render_depth = 0;

	/**
	 * How many coverage-level block lists are rendering right now. The
	 * filters that space an entry's blocks and drop its empty Buttons act
	 * on them too.
	 *
	 * @var int
	 */
	private static $coverage_render_depth = 0;

	/**
	 * Whether the entry rendering now shows as unpinned, whatever its pinned
	 * state. Read by the entry bindings that show the pinned label and row.
	 *
	 * @var bool
	 */
	private static $ignoring_pinning = false;

	/**
	 * The coverage page URL the "See all updates" paragraph links to while
	 * the coverage-level blocks render; empty otherwise.
	 *
	 * @var string
	 */
	private static $all_updates_url = '';

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
		add_filter( 'render_block_core/columns', [ __CLASS__, 'apply_entry_block_gap' ], 10, 3 );
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
	 * Whether an entry or the coverage-level blocks are being rendered, so
	 * the filters shaping the layout's blocks apply.
	 *
	 * @return bool
	 */
	private static function is_rendering_template_blocks(): bool {
		return self::$entry_render_depth > 0 || self::$coverage_render_depth > 0;
	}

	/**
	 * Whether the entry rendering now is shown as unpinned, whatever its
	 * pinned state.
	 *
	 * @return bool
	 */
	public static function is_ignoring_pinning(): bool {
		return self::$ignoring_pinning;
	}

	/**
	 * The URL the "See all updates" paragraph links to now, or an empty
	 * string outside the coverage-level blocks.
	 *
	 * @return string
	 */
	public static function get_all_updates_url(): string {
		return self::$all_updates_url;
	}

	/**
	 * Whether a URL is the page being requested: same host as the site and
	 * the same path, ignoring the trailing slash, the case of percent-encoded
	 * octets and the fragment. The URL's query arguments must all be present
	 * in the request with the same values; the request may carry more.
	 *
	 * @param string $url URL to compare.
	 * @return bool
	 */
	public static function is_coverage_page( string $url ): bool {
		$target = wp_parse_url( $url );
		$home   = wp_parse_url( home_url() );

		if ( empty( $target['host'] ) || strtolower( $target['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return false;
		}

		$request_uri  = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_path = untrailingslashit( rawurldecode( (string) wp_parse_url( $request_uri, PHP_URL_PATH ) ) );
		$target_path  = untrailingslashit( rawurldecode( (string) ( $target['path'] ?? '' ) ) );

		if ( $request_path !== $target_path ) {
			return false;
		}

		if ( empty( $target['query'] ) ) {
			return true;
		}

		wp_parse_str( $target['query'], $target_args );
		wp_parse_str( (string) wp_parse_url( $request_uri, PHP_URL_QUERY ), $request_args );

		foreach ( $target_args as $name => $value ) {
			if ( ! isset( $request_args[ $name ] ) || $request_args[ $name ] !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * How many entries a capped feed shows, as an entries request states it:
	 * the page's own count, so a page whose stored config has been pruned
	 * still polls capped. 0 when the request states no positive count.
	 *
	 * @param array $params Request parameters.
	 * @return int
	 */
	private static function requested_latest_count( array $params ): int {
		$latest = (int) ( $params['latest'] ?? 0 );

		if ( $latest < 1 ) {
			return 0;
		}

		return self::latest_count(
			[
				'latestOnly'  => true,
				'latestCount' => $latest,
			]
		);
	}

	/**
	 * How many entries a capped feed shows, from the block's attributes or
	 * its stored config: at least one, or 0 when the feed is not capped.
	 *
	 * @param array $settings Block attributes or stored block config.
	 * @return int
	 */
	private static function latest_count( array $settings ): int {
		if ( empty( $settings['latestOnly'] ) ) {
			return 0;
		}

		return min( max( 1, (int) ( $settings['latestCount'] ?? 5 ) ), self::PER_PAGE_MAX );
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
	 * A Block spacing value as the declaration setting the space between the
	 * coverage's items, or an empty string when it's unset or not a valid gap.
	 *
	 * @param mixed $block_gap Block spacing value, a string or an array with a `top` value.
	 * @return string
	 */
	private static function feed_gap_declaration( $block_gap ): string {
		$gap = wp_sanitize_block_gap_value( $block_gap );
		$gap = is_array( $gap ) ? ( $gap['top'] ?? null ) : $gap;
		$gap = is_string( $gap ) ? trim( explode( ';', $gap )[0] ) : '';

		if ( '' === $gap ) {
			return '';
		}

		return self::FEED_GAP_PROPERTY . ':' . self::spacing_css_value( $gap );
	}

	/**
	 * The layout's Feed group: the group holding everything the coverage
	 * shows, whose Block spacing sets the space between its items.
	 *
	 * @param WP_Block $block The Rolling Coverage block instance.
	 * @return array|null Parsed Feed group, or null for a layout without one.
	 */
	private static function feed_group( WP_Block $block ): ?array {
		foreach ( $block->parsed_block['innerBlocks'] ?? [] as $inner_block ) {
			if (
				is_array( $inner_block ) &&
				'core/group' === ( $inner_block['blockName'] ?? '' ) &&
				in_array( self::FEED_CLASS, explode( ' ', (string) ( $inner_block['attrs']['className'] ?? '' ) ), true )
			) {
				return $inner_block;
			}
		}

		return null;
	}

	/**
	 * The layout's items: the blocks inside its Feed group, or for a layout
	 * without one, its top-level blocks.
	 *
	 * @param WP_Block $block The Rolling Coverage block instance.
	 * @return array[] Parsed blocks.
	 */
	private static function layout_items( WP_Block $block ): array {
		$feed = self::feed_group( $block );

		return $feed ? ( $feed['innerBlocks'] ?? [] ) : ( $block->parsed_block['innerBlocks'] ?? [] );
	}

	/**
	 * The layout's items split by where they render: the coverage-level
	 * items before the first per-entry item render above the entries, the
	 * per-entry items make the entry template, and the coverage-level items
	 * after it render below the entries. "Jump to Latest" renders in its own
	 * place, so it's in neither list.
	 *
	 * @param WP_Block $block The Rolling Coverage block instance.
	 * @return array{header: array[], template: array[], footer: array[]} Parsed blocks.
	 */
	private static function layout_parts( WP_Block $block ): array {
		$parts = [
			'header'   => [],
			'template' => [],
			'footer'   => [],
		];

		foreach ( self::layout_items( $block ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( ! Entry_Bindings::is_coverage_item( $item ) ) {
				$parts['template'][] = $item;
			} elseif ( ! Entry_Bindings::is_latest_buttons( $item ) ) {
				$parts[ $parts['template'] ? 'footer' : 'header' ][] = $item;
			}
		}

		return $parts;
	}

	/**
	 * Wraps the coverage's items in the Feed group, rendered by core so its
	 * classes and styles apply, or in a plain container for a layout without
	 * one.
	 *
	 * @param array|null $feed  Parsed Feed group.
	 * @param string     $items The items' HTML.
	 * @return string
	 */
	private static function render_feed( ?array $feed, string $items ): string {
		$content = array_values( array_filter( $feed['innerContent'] ?? [], 'is_string' ) );

		if ( count( $content ) < 2 ) {
			return '<div class="' . esc_attr( self::FEED_CLASS ) . '">' . $items . '</div>';
		}

		$placeholder = '<!-- newspack-rolling-coverage-feed-items -->';
		$shell       = $feed;

		$shell['innerBlocks']  = [];
		$shell['innerHTML']    = $content[0] . end( $content );
		$shell['innerContent'] = [ $content[0], $placeholder, end( $content ) ];

		$html = render_block( $shell );

		if ( false === strpos( $html, $placeholder ) ) {
			return '<div class="' . esc_attr( self::FEED_CLASS ) . '">' . $items . '</div>';
		}

		return str_replace( $placeholder, $items, $html );
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
	 * Writes an entry's flex group and columns block spacing (e.g. the date
	 * and title stack) onto the block on the Newspack Theme.
	 *
	 * Core only outputs block spacing for themes that support it through
	 * theme.json; the classic Newspack Theme doesn't, so core falls back to
	 * its 0.5em default and ignores the value set in the editor. Themes that
	 * support block spacing, like the Newspack Block Theme, are left to core.
	 *
	 * Columns are flex by default, so they count without a layout attribute. A
	 * columns gap that only sets the horizontal value is written as a column
	 * gap.
	 *
	 * Parameters stay untyped because this runs for every group and columns
	 * block on the site, after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string   $block_content Rendered block.
	 * @param array    $block         Parsed block.
	 * @param WP_Block $instance      Block instance.
	 * @return string
	 */
	public static function apply_entry_block_gap( $block_content, $block, $instance ) {
		if (
			! is_string( $block_content ) ||
			! is_array( $block ) ||
			'flex' !== ( $block['attrs']['layout']['type'] ?? ( 'core/columns' === ( $block['blockName'] ?? '' ) ? 'flex' : '' ) ) ||
			! self::is_rendering_template_blocks() ||
			'newspack-theme' !== get_template() ||
			null !== wp_get_global_settings( [ 'spacing', 'blockGap' ] )
		) {
			return $block_content;
		}

		$gap = wp_sanitize_block_gap_value( $block['attrs']['style']['spacing']['blockGap'] ?? null );
		$gap = is_array( $gap ) ? [ $gap['top'] ?? null, $gap['left'] ?? null ] : [ $gap ];
		$gap = array_map(
			fn( $value ) => is_scalar( $value ) && '' !== (string) $value ? self::spacing_css_value( (string) $value ) : null,
			$gap
		);

		if ( ! array_filter( $gap, 'is_string' ) ) {
			return $block_content;
		}

		$properties = 2 === count( $gap ) && null === $gap[0] ? [ 'column-gap' => $gap[1] ] : [ 'gap' => implode( ' ', array_filter( $gap, 'is_string' ) ) ];

		$declaration = ( new \WP_Style_Engine_CSS_Declarations( $properties ) )->get_declarations_string();
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
		if ( ! is_string( $block_content ) || ! self::is_rendering_template_blocks() ) {
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
			self::block_type_args()
		);
	}

	/**
	 * The block type's server-side settings.
	 *
	 * @return array
	 */
	public static function block_type_args(): array {
		return [
			'render_callback'   => [ __CLASS__, 'render_block' ],
			// The layout is rendered per entry by render_block(), never as the block's own content.
			'skip_inner_blocks' => true,
		];
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
					'onesignalConfigured'         => Push_Notifications::is_onesignal_configured(),
					'statusLabels'                => Status_Labels::get_all(),
					'layoutIds'                   => array_combine(
						Layout::BUILT_IN_SLUGS,
						array_map( [ Layout::class, 'get_layout_id' ], Layout::BUILT_IN_SLUGS )
					),
					'layoutsRestBase'             => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/layouts' ) ),
					'adminUrl'                    => esc_url_raw( admin_url() ),
					'isBlockTheme'                => wp_is_block_theme(),
					'canEditThemeOptions'         => current_user_can( 'edit_theme_options' ),
					'layoutCategoryId'            => Layout::get_pattern_category_id(),
					'entryPostType'               => Post_Type::CPT_SLUG,
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

		$can_edit_posts             = current_user_can( 'edit_posts' );
		$should_track_reader_events = ! $can_edit_posts;

		foreach ( $block_type->view_script_handles as $handle ) {
			wp_localize_script(
				$handle,
				'newspackRollingCoverageFrontend',
				[
					'readerTrackingEnabled' => $should_track_reader_events,
					'siteKitGa4Enabled'     => $should_track_reader_events && self::is_site_kit_ga4_tracking_ready(),
					// The view script requests entries past the caches for these users.
					'canEditPosts'          => $can_edit_posts,
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

		// Preload so polled entries' blocks, including the photos Slack messages add, are styled and share even if none appeared on initial render.
		foreach ( [ 'core/buttons', 'core/button', 'core/separator', 'core/icon', 'core/image', 'core/gallery' ] as $entry_block_name ) {
			$entry_block_type = WP_Block_Type_Registry::get_instance()->get_registered( $entry_block_name );

			foreach ( $entry_block_type ? $entry_block_type->style_handles : [] as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}
		}

		self::enqueue_template_block_styles( ! empty( $block->parsed_block['innerBlocks'] ) ? $block->parsed_block['innerBlocks'] : self::default_entry_template() );

		$share_link_block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'newspack-rolling-coverage/share' );

		if ( $share_link_block_type ) {
			foreach ( $share_link_block_type->view_script_handles as $script_handle ) {
				wp_enqueue_script( $script_handle );
			}
		}

		$coverage_id     = (int) ( $attributes['coverageId'] ?? 0 );
		$latest_count    = self::latest_count( $attributes );
		$is_capped       = $latest_count > 0;
		// Capped feeds sit in site-wide placements, where readers must never see the notices meant for editors.
		$hides_when_gone = ! empty( $attributes['hideWhenEnded'] ) || ( $is_capped && ! wp_is_serving_rest_request() );

		if ( ! $coverage_id || ! term_exists( $coverage_id, Taxonomy::TAXONOMY_SLUG ) ) {
			self::$host_post_id = $previous_post_id;

			return $hides_when_gone ? '' : sprintf(
				'<p %s>%s</p>',
				get_block_wrapper_attributes(),
				esc_html__( 'Select a coverage to display its entries.', 'newspack-rolling-coverage' )
			);
		}

		$entries_per_page = $is_capped ? $latest_count : min( max( 1, (int) ( $attributes['entriesPerPage'] ?? 20 ) ), self::PER_PAGE_MAX );
		$poll_interval    = max( 1, (int) ( $attributes['pollInterval'] ?? 10 ) );
		$ads_interval     = max( 1, (int) ( $attributes['adsInterval'] ?? 4 ) );
		$status           = get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );
		$status           = $status ? $status : 'active';

		if ( ! empty( $attributes['hideWhenEnded'] ) && Taxonomy::STATUS_ARCHIVED === $status ) {
			self::$host_post_id = $previous_post_id;

			return '';
		}

		$ads_enabled_attr = ! empty( $attributes['enableAds'] );
		$ads_enabled      = ! $is_capped && $ads_enabled_attr && ! self::is_coverage_ads_disabled( $coverage_id );

		// A trashed coverage is effectively invisible on the frontend.
		if ( 'trash' === $status ) {
			self::$host_post_id = $previous_post_id;

			return $hides_when_gone ? '' : sprintf(
				'<p %s>%s</p>',
				get_block_wrapper_attributes(),
				esc_html__( 'This coverage is no longer available.', 'newspack-rolling-coverage' )
			);
		}

		$query_args = array_merge(
			self::coverage_entries_args( $coverage_id ),
			[
				'orderby'        => 'date',
				'order'          => 'DESC',
				'posts_per_page' => $entries_per_page,
			]
		);

		if ( $is_capped ) {
			$query_args[ Post_Type::SKIP_PIN_ORDER_VAR ] = true;
		}

		$query = new WP_Query( $query_args );

		$template     = self::get_entry_template( $block );
		$template_key = self::persist_block_config( $coverage_id, $template, $ads_enabled_attr, $ads_interval, $latest_count );

		self::store_entry_layout_styles( $template );

		$posts        = $query->posts;
		$has_more     = ! $is_capped && count( $posts ) === $entries_per_page;
		$linked_entry = $is_capped ? null : self::get_linked_entry( $coverage_id );
		$shared_entry = self::get_shared_entry( $linked_entry, $posts );

		if ( $shared_entry ) {
			$page = self::query_unpinned_entries(
				array_merge(
					self::coverage_entries_args( $coverage_id ),
					[
						'date_query' => [
							[
								'column'    => 'post_date_gmt',
								'before'    => self::gmt_date_bound( self::post_date_gmt( $shared_entry ) ),
								'inclusive' => true,
							],
						],
						'orderby'    => 'date',
						'order'      => 'DESC',
					]
				),
				$entries_per_page
			);

			$posts    = array_merge( self::query_pinned_entries( $coverage_id ), $page['posts'] );
			$has_more = $page['has_more'];
		}

		$entries_html  = '';
		$entry_index   = 0;
		$shows_pinned  = false;
		$shows_regular = false;

		foreach ( $posts as $entry ) {
			$entry_index++;
			$is_pinned     = ! $is_capped && Post_Type::is_pinned( $entry->ID );
			$shows_pinned  = $shows_pinned || $is_pinned;
			$shows_regular = $shows_regular || ! $is_pinned;
			$entries_html .= self::render_entry( $entry, $template, 'initial', is_last: ! $has_more && count( $posts ) === $entry_index, is_linked: $linked_entry && $linked_entry->ID === $entry->ID, is_capped: $is_capped );

			if ( $ads_enabled && Ads::is_capped_ad_position( $entry_index, $ads_interval ) ) {
				$entries_html .= Ads::render_placement()['html'];
			}
		}

		wp_reset_postdata();

		$cursor     = $shared_entry ? self::coverage_cursor( $coverage_id ) : self::latest_cursor( $posts );
		$oldest_gmt = ! empty( $posts ) ? self::post_date_gmt( $posts[ count( $posts ) - 1 ] ) : '';

		if ( $posts && ! $shows_pinned && ! $is_capped ) {
			self::store_template_layout_styles( self::pinned_cards( $template ) );
		}

		if ( $posts && ! $shows_regular ) {
			self::store_template_layout_styles( array_filter( $template, static fn( $block ) => is_array( $block ) && self::is_regular_entry( $block ) ) );
		}

		$title_rows = self::title_rows( $template );

		if ( $title_rows ) {
			self::store_template_layout_styles( $title_rows );
			self::store_template_layout_styles( self::with_centered_title_rows( $title_rows ) );
		}

		if ( empty( $posts ) ) {
			self::store_template_layout_styles( $template );

			$entries_html = sprintf(
				'<p class="%s-entries__empty">%s</p>',
				self::MARKUP_PREFIX,
				esc_html__( 'No entries yet.', 'newspack-rolling-coverage' )
			);
		}

		$layout_parts = self::layout_parts( $block );
		$feed         = self::feed_group( $block );
		$wrapper_data = [
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
			'style'                 => self::feed_gap_declaration( $feed['attrs']['style']['spacing']['blockGap'] ?? null ),
		];

		if ( $shared_entry ) {
			$wrapper_data['data-view'] = 'entry';
		}

		if ( $is_capped ) {
			$wrapper_data['data-latest'] = $latest_count;
		}

		// Polls carry the minimum too; the page has it so a first poll that fails still waits.
		$min_poll_interval = self::get_min_poll_interval();

		if ( $min_poll_interval ) {
			$wrapper_data['data-min-poll-interval'] = $min_poll_interval;
		}

		// Ads need their page's own setup, so the view script never swaps such a feed in place.
		if ( $ads_enabled && Ads::is_placement_enabled() ) {
			$wrapper_data['data-ads'] = '1';
		}

		$wrapper_attributes = get_block_wrapper_attributes( $wrapper_data );
		$all_updates_url    = $is_capped && false !== ( $attributes['allUpdatesLink'] ?? true ) && self::holds_block( array_merge( $layout_parts['header'], $layout_parts['footer'] ), [ Entry_Bindings::class, 'is_all_updates_paragraph' ] )
			? Taxonomy::get_coverage_page_url( $coverage_id )
			: '';

		if ( self::is_coverage_page( $all_updates_url ) ) {
			$all_updates_url = '';
		}

		try {
			$items_html = sprintf(
				'%5$s%3$s<div class="%1$s-status" role="status" aria-live="polite"></div>%4$s<div class="%1$s-entries">%2$s</div>%7$s%6$s',
				self::MARKUP_PREFIX,
				$entries_html,
				self::render_coverage_blocks( $layout_parts['header'], $coverage_id, $status, $all_updates_url ),
				$is_capped ? '' : self::render_new_entries_control( $block, (bool) $shared_entry, $shared_entry ? self::count_newer_entries( $coverage_id, $shared_entry ) : 0 ),
				Taxonomy::STATUS_ARCHIVED === $status ? self::render_archived_notice( $attributes, $coverage_id ) : '',
				$is_capped ? '' : sprintf( '<div class="%s-sentinel" aria-hidden="true"></div>', self::MARKUP_PREFIX ),
				self::render_coverage_blocks( $layout_parts['footer'], $coverage_id, $status, $all_updates_url )
			);

			return sprintf(
				'<div %s>%s</div>',
				$wrapper_attributes,
				self::render_feed( $feed, $items_html )
			);
		} finally {
			self::$host_post_id = $previous_post_id;
		}
	}

	/**
	 * The entry the page's link names: a published entry of this coverage.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return WP_Post|null The linked entry, or null when the link names none.
	 */
	private static function get_linked_entry( int $coverage_id ): ?WP_Post {
		$slug = get_query_var( Social_Sharing::ENTRY_QUERY_VAR );

		if ( ! is_string( $slug ) || '' === trim( $slug ) ) {
			return null;
		}

		$entry = Social_Sharing::resolve_entry_by_slug( $slug );

		if ( ! $entry instanceof WP_Post || ! has_term( $coverage_id, Taxonomy::TAXONOMY_SLUG, $entry ) ) {
			return null;
		}

		return $entry;
	}

	/**
	 * The linked entry, when the feed has to open at it: an unpinned entry
	 * that the normal first page does not show.
	 *
	 * @param WP_Post|null $linked_entry The entry the page's link names, from get_linked_entry().
	 * @param WP_Post[]    $first_page   Entries of the normal first page.
	 * @return WP_Post|null The shared entry, or null to keep the normal view.
	 */
	private static function get_shared_entry( ?WP_Post $linked_entry, array $first_page ): ?WP_Post {
		if ( ! $linked_entry || Post_Type::is_pinned( $linked_entry->ID ) ) {
			return null;
		}

		foreach ( $first_page as $post ) {
			if ( $post->ID === $linked_entry->ID ) {
				return null;
			}
		}

		return $linked_entry;
	}

	/**
	 * Query arguments shared by every query for a coverage's published entries.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return array WP_Query arguments.
	 */
	private static function coverage_entries_args( int $coverage_id ): array {
		return [
			'post_type'           => Post_Type::CPT_SLUG,
			'post_status'         => 'publish',
			'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => Taxonomy::TAXONOMY_SLUG,
					'field'    => 'term_id',
					'terms'    => $coverage_id,
				],
			],
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		];
	}

	/**
	 * A GMT datetime as a date query bound. WP_Date_Query reads a datetime
	 * string in the site timezone, which moves a GMT value that falls in the
	 * timezone's skipped daylight-saving hour; the array form is used as is.
	 *
	 * @param string $gmt GMT datetime, `Y-m-d H:i:s`.
	 * @return array|string Date query bound, or the input when it is not a valid full datetime.
	 */
	private static function gmt_date_bound( string $gmt ): array|string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $gmt, $parts ) ) {
			return $gmt;
		}

		if ( ! wp_checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1], $gmt ) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59 ) {
			return $gmt;
		}

		return [
			'year'   => (int) $parts[1],
			'month'  => (int) $parts[2],
			'day'    => (int) $parts[3],
			'hour'   => (int) $parts[4],
			'minute' => (int) $parts[5],
			'second' => (int) $parts[6],
		];
	}

	/**
	 * A coverage's published pinned entries, in the order the live feed
	 * shows them.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return WP_Post[]
	 */
	private static function query_pinned_entries( int $coverage_id ): array {
		$pinned_ids = Post_Type::get_pinned_ids();

		if ( empty( $pinned_ids ) ) {
			return [];
		}

		$query = new WP_Query(
			array_merge(
				self::coverage_entries_args( $coverage_id ),
				[
					'post__in'       => $pinned_ids,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'posts_per_page' => count( $pinned_ids ),
				]
			)
		);

		return $query->posts;
	}

	/**
	 * Runs a date-ordered entries query and leaves pinned entries out of its
	 * result. Pinned entries sit at the top of the feed, so a page that
	 * continues below them leaves them out.
	 *
	 * @param array $args     WP_Query arguments, without posts_per_page.
	 * @param int   $per_page How many entries to return.
	 * @return array {
	 *     @type WP_Post[] $posts    Up to $per_page unpinned entries.
	 *     @type bool      $has_more Whether older unpinned entries remain.
	 * }
	 */
	private static function query_unpinned_entries( array $args, int $per_page ): array {
		$pinned_ids = Post_Type::get_pinned_ids();
		$limit      = $per_page + count( $pinned_ids ) + 1;

		$args['posts_per_page']                = $limit;
		$args[ Post_Type::SKIP_PIN_ORDER_VAR ] = true;

		$query    = new WP_Query( $args );
		$unpinned = array_values(
			array_filter(
				$query->posts,
				static function ( $post ) use ( $pinned_ids ) {
					return ! in_array( $post->ID, $pinned_ids, true );
				}
			)
		);

		return [
			'posts'    => array_slice( $unpinned, 0, $per_page ),
			'has_more' => count( $unpinned ) > $per_page,
		];
	}

	/**
	 * Poll cursor for a whole coverage: its most recently modified published
	 * entry. A feed that starts at a shared entry polls from here, so entries
	 * published before the page was rendered are not reported as new.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string Cursor in "{id}:{modified_gmt}" format.
	 */
	private static function coverage_cursor( int $coverage_id ): string {
		$query = new WP_Query(
			array_merge(
				self::coverage_entries_args( $coverage_id ),
				[
					'orderby'                     => 'modified',
					'order'                       => 'DESC',
					'posts_per_page'              => 1,
					'update_post_meta_cache'      => false,
					'update_post_term_cache'      => false,
					Post_Type::SKIP_PIN_ORDER_VAR => true,
				]
			)
		);

		return self::latest_cursor( $query->posts );
	}

	/**
	 * How many of a coverage's published entries are newer than the shared
	 * entry, up to NEWER_COUNT_CAP. Pinned entries are left out, as the
	 * shared view already shows them.
	 *
	 * @param int     $coverage_id  Coverage term ID.
	 * @param WP_Post $shared_entry The entry the feed opens at.
	 * @return int
	 */
	private static function count_newer_entries( int $coverage_id, WP_Post $shared_entry ): int {
		$pinned_ids = Post_Type::get_pinned_ids();

		$query = new WP_Query(
			array_merge(
				self::coverage_entries_args( $coverage_id ),
				[
					'date_query'                  => [
						[
							'column'    => 'post_date_gmt',
							'after'     => self::gmt_date_bound( self::post_date_gmt( $shared_entry ) ),
							'inclusive' => false,
						],
					],
					'fields'                      => 'ids',
					'posts_per_page'              => self::NEWER_COUNT_CAP + count( $pinned_ids ),
					'update_post_meta_cache'      => false,
					'update_post_term_cache'      => false,
					Post_Type::SKIP_PIN_ORDER_VAR => true,
				]
			)
		);

		return min( self::NEWER_COUNT_CAP, count( array_diff( array_map( 'intval', $query->posts ), $pinned_ids ) ) );
	}

	/**
	 * The label of the control on a feed opened at a shared entry: the number
	 * of newer entries, exact up to ten and from there the round number it
	 * has passed, e.g. "10+ Newer Posts" for 11 to 50. Empty when there are
	 * none, as the control then keeps its own text. The view script builds
	 * the same labels.
	 *
	 * @param int $count How many entries are newer.
	 * @return string
	 */
	public static function newer_posts_label( int $count ): string {
		if ( $count < 1 ) {
			return '';
		}

		if ( $count <= 10 ) {
			/* translators: %d: number of coverage entries newer than the one shown, from 1 to 10. */
			return sprintf( _n( '%d Newer Post', '%d Newer Posts', $count, 'newspack-rolling-coverage' ), $count );
		}

		$floor = 10;

		if ( $count > 100 ) {
			$floor = 100;
		} elseif ( $count > 50 ) {
			$floor = 50;
		}

		/* translators: %d: a round number the count of newer coverage entries has passed: 10, 50 or 100. */
		return sprintf( _n( '%d+ Newer Post', '%d+ Newer Posts', $floor, 'newspack-rolling-coverage' ), $floor );
	}

	/**
	 * The URL of the live feed. On a front-end page request it is the host
	 * post's permalink when that post is the page being viewed, otherwise the
	 * current URL without the shared entry, kept on this site. Anywhere else
	 * (wp-admin, admin-ajax, cron, a REST request, a feed, WP-CLI) the
	 * request's URL is not a page's, so it is the host post's permalink, or
	 * the site's home URL when no host post is known.
	 *
	 * @return string
	 */
	public static function live_feed_url(): string {
		// remove_query_arg() reads the request URI unguarded, and the conditional tags need the main query.
		$is_page_request = did_action( 'wp' ) && ! is_admin() && ! empty( $_SERVER['REQUEST_URI'] ) && ! wp_is_serving_rest_request() && ! is_feed();
		$host_url        = self::$host_post_id ? (string) get_permalink( self::$host_post_id ) : '';

		if ( ! $is_page_request ) {
			return $host_url ? $host_url : home_url( '/' );
		}

		if ( $host_url && is_singular() && get_queried_object_id() === self::$host_post_id ) {
			return $host_url;
		}

		return '/' . ltrim( esc_url_raw( remove_query_arg( Social_Sharing::ENTRY_QUERY_VAR ) ), '/' );
	}

	/**
	 * The control fixed above the feed: the layout's "Jump to Latest" button,
	 * or the default one when the layout has none, or has one that cannot
	 * link to the live feed (its label emptied, or its element switched to a
	 * button). It links to the live feed, so it works without the view
	 * script, and its wrapper carries that URL for the script. In the normal
	 * view it is hidden until the view script reveals it when new entries
	 * wait; when the feed opens at a shared entry it shows, reading how many
	 * entries are newer when any are. A feed has none, and neither has a
	 * block inside an entry's content: a control fixed to the viewport
	 * belongs to the page's own feed.
	 *
	 * @param WP_Block $block          The parent rolling-coverage block instance.
	 * @param bool     $is_shared_view Whether the feed opens at a shared entry.
	 * @param int      $newer_count    How many entries are newer than the shared entry.
	 * @return string Control HTML.
	 */
	private static function render_new_entries_control( WP_Block $block, bool $is_shared_view, int $newer_count = 0 ): string {
		if ( is_feed() || self::is_rendering_entry() ) {
			return '';
		}

		$html = '';

		foreach ( self::layout_items( $block ) as $inner ) {
			if ( Entry_Bindings::is_latest_buttons( $inner ) ) {
				$html = render_block( $inner );
				break;
			}
		}

		if ( ! self::has_latest_link( $html ) ) {
			$html = render_block( self::default_latest_buttons_block() );
		}

		$control = new WP_HTML_Tag_Processor( $html );

		if ( ! $control->next_tag( [ 'class_name' => 'wp-block-buttons' ] ) ) {
			return '';
		}

		$control->add_class( self::MARKUP_PREFIX . '-new-entries' );
		$control->set_attribute( 'data-live-url', esc_url_raw( self::live_feed_url() ) );

		if ( ! $is_shared_view ) {
			$control->set_attribute( 'hidden', true );

			return $control->get_updated_html();
		}

		$control->set_attribute( 'data-newer-count', (string) $newer_count );

		$label = self::newer_posts_label( $newer_count );

		$own_label = '' !== $label ? self::plain_latest_label( $html ) : null;

		// A label holding markup is left for the view script, which reads the same count.
		// A replaced label is kept on the link, for when the script can no longer count.
		if ( null !== $own_label ) {
			while ( $control->next_tag( 'a' ) ) {
				if ( null !== $control->get_attribute( Entry_Bindings::LATEST_ATTRIBUTE ) ) {
					$control->set_attribute( 'data-label', $own_label );
					$control->next_token();
					$control->set_modifiable_text( $label );
					break;
				}
			}
		}

		return $control->get_updated_html();
	}

	/**
	 * The text of the link to the live feed in rendered HTML, when the link
	 * holds text alone, so its label can be replaced without losing markup.
	 *
	 * @param string $html Rendered HTML.
	 * @return string|null The text, or null when the link holds anything else.
	 */
	private static function plain_latest_label( string $html ): ?string {
		$tags = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( 'a' ) ) {
			if ( null === $tags->get_attribute( Entry_Bindings::LATEST_ATTRIBUTE ) ) {
				continue;
			}

			if ( ! $tags->next_token() || '#text' !== $tags->get_token_name() ) {
				return null;
			}

			$text = $tags->get_modifiable_text();

			return $tags->next_token() && 'A' === $tags->get_token_name() && $tags->is_tag_closer() ? $text : null;
		}

		return null;
	}

	/**
	 * Whether rendered HTML holds the link to the live feed, marked for the
	 * view script (see Entry_Bindings::filter_button()).
	 *
	 * @param string $html Rendered HTML.
	 * @return bool
	 */
	private static function has_latest_link( string $html ): bool {
		$tags = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( 'a' ) ) {
			if ( null !== $tags->get_attribute( Entry_Bindings::LATEST_ATTRIBUTE ) && $tags->get_attribute( 'href' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The default "Jump to Latest" button's colors, as palette slugs: the
	 * theme's Contrast and Base where its palette has both, as block themes
	 * do; otherwise Dark Gray and White where it has both, as the Newspack
	 * Theme does; otherwise Contrast and Base. The editor picks the same way
	 * (see latestColors() in template.ts).
	 *
	 * @return array{background: string, text: string}
	 */
	private static function latest_button_colors(): array {
		$slugs = [];

		foreach ( (array) wp_get_global_settings( [ 'color', 'palette' ] ) as $palette ) {
			$slugs = array_merge( $slugs, wp_list_pluck( (array) $palette, 'slug' ) );
		}

		$has_contrast_and_base = in_array( 'contrast', $slugs, true ) && in_array( 'base', $slugs, true );

		if ( ! $has_contrast_and_base && in_array( 'dark-gray', $slugs, true ) && in_array( 'white', $slugs, true ) ) {
			return [
				'background' => 'dark-gray',
				'text'       => 'white',
			];
		}

		return [
			'background' => 'contrast',
			'text'       => 'base',
		];
	}

	/**
	 * The default "Jump to Latest" button, as the editor saves the one in the
	 * default layout: a parsed Buttons block holding a button in the palette's
	 * colors (see latest_button_colors()) with the theme's Elevation 1
	 * shadow, its link bound to the live feed.
	 *
	 * @return array Parsed-block-shaped array.
	 */
	private static function default_latest_buttons_block(): array {
		$name        = __( 'Jump to Latest', 'newspack-rolling-coverage' );
		$lock        = [
			'remove' => true,
			'move'   => true,
		];
		$class       = self::MARKUP_PREFIX . '-new-entries';
		$colors      = self::latest_button_colors();
		$open        = sprintf( '<div class="%s">', esc_attr( 'wp-block-buttons ' . $class ) );
		$button_html = sprintf(
			'<div class="wp-block-button"><a class="%s" style="box-shadow:var(--wp--preset--shadow--elevation-1)">%s</a></div>',
			esc_attr( sprintf( 'wp-block-button__link has-%s-color has-%s-background-color has-text-color has-background wp-element-button', $colors['text'], $colors['background'] ) ),
			esc_html( $name )
		);

		return [
			'blockName'    => 'core/buttons',
			'attrs'        => [
				'lock'      => $lock,
				'metadata'  => [ 'name' => $name ],
				'className' => $class,
				'layout'    => [
					'type'           => 'flex',
					'justifyContent' => 'center',
				],
			],
			'innerBlocks'  => [
				[
					'blockName'    => 'core/button',
					'attrs'        => [
						'backgroundColor' => $colors['background'],
						'textColor'       => $colors['text'],
						'lock'            => $lock,
						'metadata'        => [
							'name'     => $name,
							'bindings' => [
								'url' => [
									'source' => Entry_Bindings::SOURCE_NAME,
									'args'   => [ 'key' => 'latestUrl' ],
								],
							],
						],
						'style'           => [ 'shadow' => 'var:preset|shadow|elevation-1' ],
					],
					'innerBlocks'  => [],
					'innerHTML'    => $button_html,
					'innerContent' => [ $button_html ],
				],
			],
			'innerHTML'    => $open . '</div>',
			'innerContent' => [ $open, null, '</div>' ],
		];
	}

	/**
	 * Enqueues the styles of every block in the layout. Core enqueues them
	 * only for blocks rendered on the page, so without this, a block that no
	 * entry on the page shows, such as the pinned card's, would arrive
	 * unstyled with an entry added by polling.
	 *
	 * @param array[] $blocks Parsed layout blocks.
	 */
	private static function enqueue_template_block_styles( array $blocks ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $block['blockName'] );

			foreach ( $block_type ? array_merge( $block_type->style_handles, $block_type->view_style_handles ) : [] as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}

			self::enqueue_template_block_styles( $block['innerBlocks'] ?? [] );
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
	 * Stores the layout styles of the template's pinned cards and entry
	 * groups, so entries added by polling or load more are spaced even when
	 * no entry of their kind was on the page when it loaded.
	 *
	 * @param array[] $template Parsed template blocks.
	 */
	private static function store_entry_layout_styles( array $template ): void {
		foreach ( $template as $block ) {
			if ( is_array( $block ) && ( self::is_pinned_card( $block ) || self::is_regular_entry( $block ) ) ) {
				self::with_entry_layout( $block );
			}
		}
	}

	/**
	 * Lays an entry group or pinned card out as a core flow layout spaced by
	 * its own Block spacing setting (`spacing-20` when unset), and returns
	 * the container class it carries. Core prints the layout's styles with
	 * the page's other block styles; the class depends only on the spacing,
	 * so entries added by polling or load more share it. On a theme without
	 * theme.json the class goes on the group's inner container, over the
	 * margins such themes give every block in a group.
	 *
	 * @param array $group Parsed entry group or pinned card.
	 * @return string Container class.
	 */
	private static function entry_layout_class( array $group ): string {
		$gap   = wp_sanitize_block_gap_value( $group['attrs']['style']['spacing']['blockGap'] ?? null );
		$gap   = is_array( $gap ) ? ( $gap['top'] ?? null ) : $gap;
		$gap   = is_string( $gap ) && '' !== $gap ? $gap : self::DEFAULT_ENTRY_GAP;
		$class = self::MARKUP_PREFIX . '-entry-layout-' . substr( md5( $gap ), 0, 8 );

		wp_get_layout_style( '.' . $class, [ 'type' => 'default' ], true, $gap );

		if ( ! wp_theme_has_theme_json() ) {
			wp_get_layout_style( '.' . self::PINNED_CARD_CLASS . ' > .wp-block-group__inner-container.' . $class, [ 'type' => 'default' ], true, $gap );
			wp_get_layout_style( '.' . self::REGULAR_ENTRY_CLASS . ' > .wp-block-group__inner-container.' . $class, [ 'type' => 'default' ], true, $gap );
		}

		return $class;
	}

	/**
	 * Renders coverage-level blocks once, with the coverage in their context
	 * so the follow button carries its tag. A follow button that can't render,
	 * e.g. on an archived coverage, leaves nothing behind, and "Jump to
	 * Latest" renders only as its own control, so none renders here.
	 *
	 * @param array[] $blocks          Parsed coverage-level blocks.
	 * @param int     $coverage_id     Coverage term id.
	 * @param string  $status          Coverage status.
	 * @param string  $all_updates_url Where the "See all updates" paragraph links; empty drops it.
	 * @return string Rendered HTML, or an empty string.
	 */
	private static function render_coverage_blocks( array $blocks, int $coverage_id, string $status, string $all_updates_url = '' ): string {
		if ( ! $blocks ) {
			return '';
		}

		// Preload the follow button's view script and the legacy block's
		// styles: the button renders inside this callback, so WordPress
		// doesn't enqueue its assets.
		$follow_block_type = Coverage_Follow_Block::should_render( $status ) && self::holds_follow_button( $blocks ) ? WP_Block_Type_Registry::get_instance()->get_registered( Coverage_Follow_Block::BLOCK_NAME ) : null;

		if ( $follow_block_type ) {
			foreach ( $follow_block_type->style_handles as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}

			foreach ( $follow_block_type->view_script_handles as $script_handle ) {
				wp_enqueue_script( $script_handle );
			}
		}

		$blocks = self::map_template_blocks(
			$blocks,
			static function ( array $block ) use ( $coverage_id, $status, $all_updates_url ) {
				if ( Entry_Bindings::is_latest_buttons( $block ) || ( '' === $all_updates_url && Entry_Bindings::is_all_updates_paragraph( $block ) ) ) {
					return [];
				}

				if ( Coverage_Follow_Block::BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
					$block['attrs'] = array_merge(
						(array) ( $block['attrs'] ?? [] ),
						[
							'coverageId' => $coverage_id,
							'status'     => $status,
						]
					);
				}

				return [ $block ];
			}
		);

		$add_coverage_context = fn( $context ) => array_merge(
			(array) $context,
			[
				Entry_Bindings::COVERAGE_ID_CONTEXT     => $coverage_id,
				Entry_Bindings::COVERAGE_STATUS_CONTEXT => $status,
			]
		);

		$previous_all_updates_url = self::$all_updates_url;
		self::$all_updates_url    = $all_updates_url;

		add_filter( 'render_block_context', $add_coverage_context );
		++self::$coverage_render_depth;

		try {
			return implode( '', array_map( 'render_block', $blocks ) );
		} finally {
			--self::$coverage_render_depth;
			self::$all_updates_url = $previous_all_updates_url;
			remove_filter( 'render_block_context', $add_coverage_context );
		}
	}

	/**
	 * Whether blocks hold a follow button, the core one or the legacy block,
	 * at any depth.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return bool
	 */
	private static function holds_follow_button( array $blocks ): bool {
		return self::holds_block(
			$blocks,
			static fn( array $block ) => Coverage_Follow_Block::BLOCK_NAME === ( $block['blockName'] ?? '' ) || Entry_Bindings::is_follow_buttons( $block )
		);
	}

	/**
	 * Whether blocks hold a block matching a test, at any depth.
	 *
	 * @param array[]  $blocks   Parsed blocks.
	 * @param callable $is_match Tests a parsed block.
	 * @return bool
	 */
	private static function holds_block( array $blocks, callable $is_match ): bool {
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && ( $is_match( $block ) || self::holds_block( $block['innerBlocks'] ?? [], $is_match ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Renders the notice that opens an archived coverage's Feed, unless the block
	 * turns it off: the block's text, or the default naming the coverage when it
	 * has none. Unless the block turns the link off, a link follows to the
	 * block's URL or, without one, to the coverage's latest breakout post.
	 *
	 * @param array $attributes  Block attributes.
	 * @param int   $coverage_id Coverage term ID.
	 * @return string Rendered HTML.
	 */
	private static function render_archived_notice( array $attributes, int $coverage_id ): string {
		if ( ! (bool) ( $attributes['archivedNoticeShow'] ?? true ) ) {
			return '';
		}

		$text      = trim( (string) ( $attributes['archivedNotice'] ?? '' ) );
		$show_link = (bool) ( $attributes['archivedNoticeShowLink'] ?? true );
		$url       = $show_link ? trim( (string) ( $attributes['archivedNoticeLinkUrl'] ?? '' ) ) : '';
		$label     = trim( (string) ( $attributes['archivedNoticeLinkLabel'] ?? '' ) );

		if ( $show_link && '' === $url ) {
			$url = (string) self::latest_breakout_url( $coverage_id );
		}

		$link = '' !== $url ? esc_url( $url ) : '';

		return sprintf(
			'<p class="%s-archived-notice">%s%s</p>',
			self::MARKUP_PREFIX,
			nl2br( esc_html( '' !== $text ? $text : self::default_archived_notice( $coverage_id ) ), false ),
			'' !== $link
				? sprintf(
					' <a class="%s-archived-notice__link" href="%s">%s</a>',
					self::MARKUP_PREFIX,
					$link,
					esc_html( '' !== $label ? $label : __( 'Read more', 'newspack-rolling-coverage' ) )
				)
				: ''
		);
	}

	/**
	 * The notice shown above an archived coverage when the block sets none,
	 * naming the coverage.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string
	 */
	private static function default_archived_notice( int $coverage_id ): string {
		$term = get_term( $coverage_id, Taxonomy::TAXONOMY_SLUG );
		$name = $term instanceof WP_Term ? trim( $term->name ) : '';

		if ( '' === $name ) {
			return __( 'Coverage of this news event has concluded and this feed is now archived.', 'newspack-rolling-coverage' );
		}

		/* translators: %s: Coverage name. */
		return sprintf( __( 'Coverage of “%s” has concluded and this feed is now archived.', 'newspack-rolling-coverage' ), $name );
	}

	/**
	 * The link to the most recently published breakout post among the
	 * coverage's published entries, by the breakout post's own date.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string|null
	 */
	private static function latest_breakout_url( int $coverage_id ): ?string {
		global $wpdb;

		$term = get_term( $coverage_id, Taxonomy::TAXONOMY_SLUG );

		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		$cache_key   = sprintf( 'latest_breakout:%d:%s:%s', $term->term_taxonomy_id, wp_cache_get_last_changed( 'posts' ), wp_cache_get_last_changed( 'terms' ) );
		$breakout_id = wp_cache_get( $cache_key, self::BREAKOUT_CACHE_GROUP );

		if ( false === $breakout_id ) {
			$breakout_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"SELECT breakout.ID FROM {$wpdb->term_relationships} AS tr
					INNER JOIN {$wpdb->posts} AS entry ON entry.ID = tr.object_id
					INNER JOIN {$wpdb->postmeta} AS link ON link.post_id = entry.ID AND link.meta_key = %s
					INNER JOIN {$wpdb->posts} AS breakout ON breakout.ID = CAST( link.meta_value AS UNSIGNED )
					WHERE tr.term_taxonomy_id = %d
						AND entry.post_type = %s
						AND entry.post_status = 'publish'
						AND breakout.post_type = 'post'
						AND breakout.post_status = 'publish'
					ORDER BY breakout.post_date_gmt DESC, breakout.ID DESC
					LIMIT 1",
					Breakout::ENTRY_BREAKOUT_POST_ID_META,
					$term->term_taxonomy_id,
					Post_Type::CPT_SLUG
				)
			);

			wp_cache_set( $cache_key, $breakout_id, self::BREAKOUT_CACHE_GROUP );
		}

		return $breakout_id ? ( get_permalink( (int) $breakout_id ) ?: null ) : null; // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
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
		if ( empty( self::layout_items( $block ) ) ) {
			return self::default_entry_template();
		}

		return self::layout_parts( $block )['template'];
	}

	/**
	 * The hardcoded fallback per-entry template, the Bulletin layout's: the
	 * pinned card, which a pinned entry shows, and the entry group, which
	 * every other entry shows, closed by a separator.
	 *
	 * @return array[] Array of parsed-block-shaped arrays.
	 */
	private static function default_entry_template() {
		$separator_html = '<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/>';
		$separator      = [
			'blockName'    => 'core/separator',
			'attrs'        => [ 'className' => 'is-style-wide' ],
			'innerBlocks'  => [],
			'innerHTML'    => $separator_html,
			'innerContent' => [ $separator_html ],
		];

		return [
			self::pinned_card_block( self::default_entry_blocks( true ) ),
			self::regular_entry_block( array_merge( self::default_entry_blocks( false ), [ $separator ] ) ),
		];
	}

	/**
	 * What an entry shows by default: a row with the time and "Share", or on
	 * the pinned card the pinned row and the relative date, then a large
	 * title, the content and "Read more".
	 *
	 * @param bool $is_pinned Whether the blocks are the pinned card's.
	 * @return array[] Array of parsed-block-shaped arrays.
	 */
	private static function default_entry_blocks( bool $is_pinned ): array {
		$time_format = get_option( 'time_format' );
		$meta_blocks = $is_pinned
			? [
				self::pinned_row_block(),
				self::post_block(
					'core/post-date',
					[
						'format'   => 'human-diff',
						'fontSize' => 'small',
						'style'    => [ 'color' => [ 'text' => self::ACCENT_CONTRAST ] ],
					]
				),
			]
			: [
				self::post_block(
					'core/post-date',
					[
						'format'   => $time_format ? $time_format : 'g:i a',
						'fontSize' => 'small',
					]
				),
				self::link_paragraph_block( Entry_Bindings::SHARE_CLASS, __( 'Share', 'newspack-rolling-coverage' ) ),
			];

		return [
			[
				'blockName'    => 'core/group',
				'attrs'        => [
					'layout'   => [
						'type'              => 'flex',
						'flexWrap'          => 'wrap',
						'verticalAlignment' => 'center',
					],
					'style'    => [ 'spacing' => [ 'blockGap' => 'var:preset|spacing|30' ] ],
					'metadata' => [ 'name' => __( 'Meta', 'newspack-rolling-coverage' ) ],
				],
				'innerBlocks'  => $meta_blocks,
				'innerHTML'    => '<div class="wp-block-group"></div>',
				'innerContent' => [ '<div class="wp-block-group">', null, null, '</div>' ],
			],
			self::post_block(
				'core/post-title',
				[
					'level'    => 3,
					'fontSize' => $is_pinned ? self::theme_font_size( 'x-large', 'huge' ) : 'large',
				]
			),
			self::post_block(
				'core/post-content',
				[
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
				]
			),
			self::link_paragraph_block( Entry_Bindings::READ_MORE_CLASS, __( 'Read more', 'newspack-rolling-coverage' ) ),
		];
	}

	/**
	 * A font size preset the theme defines: the preferred slug where the
	 * theme has it, else its fallback. The Newspack Theme names its sizes
	 * Normal and Huge where block themes have Medium and X-Large.
	 *
	 * @param string $preferred The preferred slug.
	 * @param string $fallback  The slug to use where the theme lacks it.
	 * @return string
	 */
	private static function theme_font_size( string $preferred, string $fallback ): string {
		$origins = wp_get_global_settings( [ 'typography', 'fontSizes' ] );
		$theme   = is_array( $origins ) && is_array( $origins['theme'] ?? null ) ? $origins['theme'] : [];
		$sizes   = array_column( array_filter( $theme, 'is_array' ), 'slug' );

		return ! in_array( $preferred, $sizes, true ) && in_array( $fallback, $sizes, true ) ? $fallback : $preferred;
	}

	/**
	 * A parsed post block, such as Post Date, which renders from its attributes
	 * alone.
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Block attributes.
	 * @return array Parsed-block-shaped array.
	 */
	private static function post_block( string $name, array $attrs ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	/**
	 * A parsed paragraph holding a placeholder link, which the site points at
	 * the entry's link (see Entry_Bindings::link_paragraph()).
	 *
	 * @param string $class_name The paragraph's class: Read more's or Share's.
	 * @param string $text       The link's text.
	 * @return array Parsed-block-shaped array.
	 */
	private static function link_paragraph_block( string $class_name, string $text ): array {
		$html = sprintf(
			'<p class="use-header-font %s has-small-font-size"><a href="#">%s</a></p>',
			esc_attr( $class_name ),
			esc_html( $text )
		);

		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => [
				'className' => 'use-header-font ' . $class_name,
				'fontSize'  => 'small',
			],
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * A parsed entry group: a group holding what an entry that isn't pinned
	 * shows, with none of the pinned card's look.
	 *
	 * @param array[] $inner_blocks Parsed blocks inside the group.
	 * @return array Parsed-block-shaped array.
	 */
	private static function regular_entry_block( array $inner_blocks ): array {
		$style = [
			'spacing' => [ 'blockGap' => 'var:preset|spacing|30' ],
		];
		$open  = '<div class="wp-block-group ' . esc_attr( self::REGULAR_ENTRY_CLASS ) . '">';

		return [
			'blockName'    => 'core/group',
			'attrs'        => [
				'className' => self::REGULAR_ENTRY_CLASS,
				'style'     => $style,
				'metadata'  => [ 'name' => __( 'Entry', 'newspack-rolling-coverage' ) ],
			],
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $open . '</div>',
			'innerContent' => array_merge( [ $open ], array_fill( 0, count( $inner_blocks ), null ), [ '</div>' ] ),
		];
	}

	/**
	 * A parsed pinned card: a group set in the accent color, with its text,
	 * links and headings in the accent's contrast color, holding the given
	 * blocks.
	 *
	 * @param array[] $inner_blocks Parsed blocks inside the card.
	 * @return array Parsed-block-shaped array.
	 */
	private static function pinned_card_block( array $inner_blocks ): array {
		$style  = [
			'color'    => [
				'background' => self::ACCENT,
				'text'       => self::ACCENT_CONTRAST,
			],
			'elements' => [
				'link'    => [ 'color' => [ 'text' => self::ACCENT_CONTRAST ] ],
				'heading' => [ 'color' => [ 'text' => self::ACCENT_CONTRAST ] ],
			],
			'spacing'  => [
				'padding'  => [
					'top'    => 'var:preset|spacing|50',
					'right'  => 'var:preset|spacing|50',
					'bottom' => 'var:preset|spacing|50',
					'left'   => 'var:preset|spacing|50',
				],
				'blockGap' => 'var:preset|spacing|30',
			],
		];
		$styles = wp_style_engine_get_styles( $style );
		$open   = sprintf(
			'<div class="%s" style="%s">',
			esc_attr( trim( 'wp-block-group ' . self::PINNED_CARD_CLASS . ' ' . ( $styles['classnames'] ?? '' ) ) ),
			esc_attr( $styles['css'] ?? '' )
		);

		return [
			'blockName'    => 'core/group',
			'attrs'        => [
				'className' => self::PINNED_CARD_CLASS,
				'style'     => $style,
				'metadata'  => [ 'name' => __( 'Pinned Entry', 'newspack-rolling-coverage' ) ],
			],
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $open . '</div>',
			'innerContent' => array_merge( [ $open ], array_fill( 0, count( $inner_blocks ), null ), [ '</div>' ] ),
		];
	}

	/**
	 * The template as one entry renders it. Where it holds both the pinned
	 * card and the entry group, a pinned entry renders the card and every
	 * other entry the entry group. Without an entry group, only a pinned entry
	 * keeps the card; others render its blocks without it. A pinned entry shown
	 * as a card, and the last entry once no more can load, drop the separator
	 * that closes the template or the entry group. A pinned card with no
	 * breakout link to show also drops any bottom margin set on its last
	 * block, and as the last entry, a card that closes the template drops any
	 * set below it, so the card's padding is even and nothing trails the list.
	 *
	 * @param array[] $template     Parsed template blocks.
	 * @param bool    $is_pinned    Whether the entry is pinned.
	 * @param bool    $has_breakout Whether a pinned entry has a published
	 *                              breakout; only read for pinned entries.
	 * @param bool    $is_last      Whether the entry is the last one to load.
	 * @return array[]
	 */
	public static function shape_entry_template( array $template, bool $is_pinned, bool $has_breakout, bool $is_last ): array {
		$template = self::for_entry_kind( $template, $is_pinned );

		if ( ( $is_pinned && self::has_pinned_card( $template ) ) || $is_last ) {
			$template = self::without_closing_separator( $template );
		}

		$closing        = end( $template );
		$is_last_closer = $is_last && is_array( $closing ) && self::is_pinned_card( $closing );

		return self::map_template_blocks(
			$template,
			static function ( array $block ) use ( $is_pinned, $has_breakout, $is_last_closer ) {
				if ( self::is_regular_entry( $block ) ) {
					return [ self::with_entry_layout( $block ) ];
				}

				if ( ! self::is_pinned_card( $block ) ) {
					return [ $block ];
				}

				if ( ! $is_pinned ) {
					return $block['innerBlocks'] ?? [];
				}

				if ( ! $has_breakout ) {
					$block = self::without_breakout_link( $block );
					$count = count( $block['innerBlocks'] ?? [] );

					if ( $count ) {
						$block['innerBlocks'][ $count - 1 ] = self::without_bottom_margin( $block['innerBlocks'][ $count - 1 ] );
					}
				}

				if ( $is_last_closer ) {
					$block = self::without_bottom_margin( $block );
				}

				return [ self::with_entry_layout( $block ) ];
			}
		);
	}

	/**
	 * The template without the separator that closes it, whether it follows
	 * the entry group or ends it.
	 *
	 * @param array[] $template Parsed template blocks.
	 * @return array[]
	 */
	private static function without_closing_separator( array $template ): array {
		$index = array_key_last( $template );
		$last  = null === $index ? null : $template[ $index ];

		if ( ! is_array( $last ) ) {
			return $template;
		}

		if ( 'core/separator' === ( $last['blockName'] ?? '' ) ) {
			unset( $template[ $index ] );

			return array_values( $template );
		}

		$inner_blocks = $last['innerBlocks'] ?? [];
		$closing      = end( $inner_blocks );

		if ( self::is_regular_entry( $last ) && is_array( $closing ) && 'core/separator' === ( $closing['blockName'] ?? '' ) ) {
			array_pop( $inner_blocks );
			$template[ $index ] = self::sync_inner_content( $last, $inner_blocks );
		}

		return $template;
	}

	/**
	 * The template as an entry without a title renders it: a row holding the
	 * title, such as the header with Share opposite, centers its blocks, as
	 * the date is all that's left beside them.
	 *
	 * @param array[] $template Parsed template blocks.
	 * @return array[]
	 */
	public static function with_centered_title_rows( array $template ): array {
		return self::map_template_blocks(
			$template,
			static function ( array $block ) {
				if ( self::is_title_row( $block ) ) {
					$block['attrs']['layout']['verticalAlignment'] = 'center';
				}

				return [ $block ];
			}
		);
	}

	/**
	 * Whether an entry has a title to show. Slack entries have none; the
	 * message is the entry.
	 *
	 * @param WP_Post $entry Entry post object.
	 * @return bool
	 */
	public static function has_title( WP_Post $entry ): bool {
		return '' !== trim( wp_strip_all_tags( get_the_title( $entry ) ) );
	}

	/**
	 * Whether a parsed block is a row holding the post title: a horizontal
	 * flex group with a Post Title block anywhere inside it.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private static function is_title_row( array $block ): bool {
		$layout = $block['attrs']['layout'] ?? [];

		return 'core/group' === ( $block['blockName'] ?? '' ) &&
			'flex' === ( $layout['type'] ?? '' ) &&
			'vertical' !== ( $layout['orientation'] ?? '' ) &&
			self::holds_post_title( $block['innerBlocks'] ?? [] );
	}

	/**
	 * Whether parsed blocks hold a Post Title block.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return bool
	 */
	private static function holds_post_title( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && ( 'core/post-title' === ( $block['blockName'] ?? '' ) || self::holds_post_title( $block['innerBlocks'] ?? [] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The rows holding the post title among parsed blocks, so the layout
	 * styles of both their forms can be stored for entries with and without
	 * a title that arrive after the page loads.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return array[]
	 */
	private static function title_rows( array $blocks ): array {
		$rows = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$rows = self::is_title_row( $block ) ? array_merge( $rows, [ $block ] ) : array_merge( $rows, self::title_rows( $block['innerBlocks'] ?? [] ) );
		}

		return $rows;
	}

	/**
	 * The pinned cards among parsed blocks, so their layout styles can be
	 * stored for a pinned entry that arrives after the page loads.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return array[]
	 */
	private static function pinned_cards( array $blocks ): array {
		$cards = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$cards = self::is_pinned_card( $block ) ? array_merge( $cards, [ $block ] ) : array_merge( $cards, self::pinned_cards( $block['innerBlocks'] ?? [] ) );
		}

		return $cards;
	}

	/**
	 * Whether parsed blocks hold the pinned card.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return bool
	 */
	private static function has_pinned_card( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && ( self::is_pinned_card( $block ) || self::has_pinned_card( $block['innerBlocks'] ?? [] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A parsed block without its bottom margin. Static blocks render it from
	 * their saved markup, and keep it in their attributes so their layout
	 * container class, which core derives from them, stays the one whose
	 * styles the page printed; dynamic blocks render it from their
	 * attributes.
	 *
	 * @param array $block Parsed block.
	 * @return array
	 */
	private static function without_bottom_margin( array $block ): array {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( (string) ( $block['blockName'] ?? '' ) );

		if ( ! $block_type || $block_type->is_dynamic() ) {
			unset( $block['attrs']['style']['spacing']['margin']['bottom'] );
		}

		foreach ( [ 'innerHTML', 'innerContent' ] as $key ) {
			$markup = 'innerHTML' === $key ? ( $block['innerHTML'] ?? null ) : ( $block['innerContent'][0] ?? null );

			if ( ! is_string( $markup ) ) {
				continue;
			}

			$tag = new WP_HTML_Tag_Processor( $markup );

			if ( ! $tag->next_tag() ) {
				continue;
			}

			$style = $tag->get_attribute( 'style' );

			if ( ! is_string( $style ) ) {
				continue;
			}

			$declarations = array_filter(
				array_map( 'trim', explode( ';', $style ) ),
				static fn( $declaration ) => '' !== $declaration && 'margin-bottom' !== strtolower( trim( strtok( $declaration, ':' ) ) )
			);

			if ( $declarations ) {
				$tag->set_attribute( 'style', implode( ';', $declarations ) );
			} else {
				$tag->remove_attribute( 'style' );
			}

			if ( 'innerHTML' === $key ) {
				$block['innerHTML'] = $tag->get_updated_html();
			} else {
				$block['innerContent'][0] = $tag->get_updated_html();
			}
		}

		return $block;
	}

	/**
	 * The pinned card or entry group spacing its blocks by its own Block
	 * spacing, with its layout class (see entry_layout_class()) on the
	 * element that holds them. On a theme without theme.json that's the
	 * inner container core would otherwise add without it (see
	 * wp_restore_group_inner_container()). A group laid out as a row or grid
	 * keeps core's spacing.
	 *
	 * @param array $block Parsed pinned card or entry group.
	 * @return array
	 */
	private static function with_entry_layout( array $block ): array {
		if ( ! in_array( $block['attrs']['layout']['type'] ?? 'default', [ 'default', 'constrained' ], true ) ) {
			return $block;
		}

		$content = $block['innerContent'] ?? [];
		$first   = array_key_first( $content );
		$last    = array_key_last( $content );

		if ( null === $first || $first === $last || ! is_string( $content[ $first ] ) || ! is_string( $content[ $last ] ) ) {
			return $block;
		}

		$layout_class = self::entry_layout_class( $block );

		if ( wp_theme_has_theme_json() ) {
			$opening = new WP_HTML_Tag_Processor( $content[ $first ] );

			if ( ! $opening->next_tag() ) {
				return $block;
			}

			$opening->add_class( $layout_class );
			$content[ $first ] = $opening->get_updated_html();
		} else {
			$content[ $first ] .= '<div class="wp-block-group__inner-container ' . esc_attr( $layout_class ) . '">';
			$content[ $last ]   = '</div>' . $content[ $last ];
		}

		$block['innerContent'] = $content;

		return $block;
	}

	/**
	 * The template for one kind of entry, where its top-level blocks hold both
	 * the pinned card and the entry group: a pinned entry keeps the card
	 * alone, every other entry the entry group alone. A template missing
	 * either is returned as it is.
	 *
	 * @param array[] $template  Parsed template blocks.
	 * @param bool    $is_pinned Whether the entry is pinned.
	 * @return array[]
	 */
	private static function for_entry_kind( array $template, bool $is_pinned ): array {
		$cards   = array_filter( $template, static fn( $block ) => is_array( $block ) && self::is_pinned_card( $block ) );
		$entries = array_filter( $template, static fn( $block ) => is_array( $block ) && self::is_regular_entry( $block ) );

		if ( ! $cards || ! $entries ) {
			return $template;
		}

		return array_values( array_diff_key( $template, $is_pinned ? $entries : $cards ) );
	}

	/**
	 * Whether a parsed block is the entry group.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private static function is_regular_entry( array $block ): bool {
		return 'core/group' === ( $block['blockName'] ?? '' ) &&
			in_array( self::REGULAR_ENTRY_CLASS, explode( ' ', (string) ( $block['attrs']['className'] ?? '' ) ), true );
	}

	/**
	 * Whether a parsed block is the pinned card or the entry group, which
	 * render per entry.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	public static function is_entry_group( array $block ): bool {
		return self::is_pinned_card( $block ) || self::is_regular_entry( $block );
	}

	/**
	 * Whether a parsed block is the pinned card.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private static function is_pinned_card( array $block ): bool {
		return 'core/group' === ( $block['blockName'] ?? '' ) &&
			in_array( self::PINNED_CARD_CLASS, explode( ' ', (string) ( $block['attrs']['className'] ?? '' ) ), true );
	}

	/**
	 * A parsed block without the breakout links anywhere inside it, dropping
	 * any block they leave empty, as drop_empty_entry_buttons() does for
	 * Buttons once rendered.
	 *
	 * @param array $block Parsed block.
	 * @return array
	 */
	private static function without_breakout_link( array $block ): array {
		$inner_blocks = [];

		foreach ( $block['innerBlocks'] ?? [] as $inner_block ) {
			if ( ! is_array( $inner_block ) || self::is_breakout_link( $inner_block ) ) {
				continue;
			}

			if ( ! empty( $inner_block['innerBlocks'] ) ) {
				$inner_block = self::without_breakout_link( $inner_block );

				if ( empty( $inner_block['innerBlocks'] ) ) {
					continue;
				}
			}

			$inner_blocks[] = $inner_block;
		}

		return self::sync_inner_content( $block, $inner_blocks );
	}

	/**
	 * Whether a parsed block links to the entry's breakout post: a button
	 * whose link is bound to it, the Breakout Post Link block, or the "Read more"
	 * paragraph.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private static function is_breakout_link( array $block ): bool {
		$binding = $block['attrs']['metadata']['bindings']['url'] ?? [];

		return 'newspack-rolling-coverage/breakout-post-link' === ( $block['blockName'] ?? '' ) || Entry_Bindings::is_read_more_paragraph( $block ) || (
			'core/button' === ( $block['blockName'] ?? '' ) &&
			is_array( $binding ) &&
			Entry_Bindings::SOURCE_NAME === ( $binding['source'] ?? '' ) &&
			'breakoutUrl' === ( $binding['args']['key'] ?? '' )
		);
	}

	/**
	 * Maps parsed blocks, each to any number of replacements, keeping every
	 * parent's inner content in step with its inner blocks.
	 *
	 * Inner blocks are mapped before the block holding them.
	 *
	 * @param array[]  $blocks Parsed blocks.
	 * @param callable $map    Returns the blocks that replace the one given.
	 * @return array[]
	 */
	private static function map_template_blocks( array $blocks, callable $map ): array {
		$mapped = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block = self::sync_inner_content( $block, self::map_template_blocks( $block['innerBlocks'], $map ) );
			}

			foreach ( $map( $block ) as $replacement ) {
				$mapped[] = $replacement;
			}
		}

		return $mapped;
	}

	/**
	 * A parsed block with new inner blocks, its inner content keeping one
	 * placeholder per inner block. Placeholders beyond the new count go, and
	 * extra ones are added before the closing markup.
	 *
	 * @param array   $block        Parsed block.
	 * @param array[] $inner_blocks New inner blocks.
	 * @return array
	 */
	private static function sync_inner_content( array $block, array $inner_blocks ): array {
		$content = [];
		$wanted  = count( $inner_blocks );
		$placed  = 0;

		foreach ( $block['innerContent'] ?? [] as $chunk ) {
			if ( null === $chunk ) {
				if ( $placed < $wanted ) {
					$content[] = null;
					++$placed;
				}

				continue;
			}

			$content[] = $chunk;
		}

		if ( $placed < $wanted ) {
			$closing = is_string( end( $content ) ) ? array_pop( $content ) : null;
			$content = array_merge( $content, array_fill( 0, $wanted - $placed, null ) );

			if ( null !== $closing ) {
				$content[] = $closing;
			}
		}

		$block['innerBlocks']  = array_values( $inner_blocks );
		$block['innerContent'] = $content;

		return $block;
	}

	/**
	 * A parsed row of the pin icon and the pinned label, shown only on pinned
	 * entries.
	 *
	 * @return array Parsed-block-shaped array.
	 */
	private static function pinned_row_block(): array {
		$label_html = sprintf(
			'<p class="use-header-font %s has-small-font-size" style="font-weight:700">%s</p>',
			Entry_Bindings::PINNED_LABEL_CLASS,
			esc_html__( 'Pinned', 'newspack-rolling-coverage' )
		);

		return [
			'blockName'    => 'core/group',
			'attrs'        => [
				'layout'   => [
					'type'              => 'flex',
					'flexWrap'          => 'nowrap',
					'verticalAlignment' => 'center',
				],
				'style'    => [ 'spacing' => [ 'blockGap' => '0' ] ],
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
						'className' => 'use-header-font ' . Entry_Bindings::PINNED_LABEL_CLASS,
						'fontSize'  => 'small',
						'style'     => [ 'typography' => [ 'fontWeight' => '700' ] ],
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
	 * Stores the entry template plus the block's ad settings in the options
	 * table and returns a hash key identifying that exact combination. A
	 * capped feed's config also holds how many entries it shows.
	 *
	 * @param int   $coverage_id  Coverage term ID.
	 * @param array $template     Per-entry inner-block template.
	 * @param bool  $ads_enabled  The block's own Enable Ads toggle.
	 * @param int   $ads_interval Show an ad after every N entries.
	 * @param int   $latest_count How many entries a capped feed shows; 0 when not capped.
	 * @return string Hash key identifying this config.
	 */
	private static function persist_block_config( int $coverage_id, array $template, bool $ads_enabled, int $ads_interval, int $latest_count = 0 ): string {
		$config = [
			'template'    => $template,
			'adsEnabled'  => $ads_enabled,
			'adsInterval' => $ads_interval,
		];

		if ( $latest_count ) {
			$config['latestOnly']  = true;
			$config['latestCount'] = $latest_count;
		}

		$hash       = substr( md5( wp_json_encode( $config ) ), 0, 12 );
		$option_key = self::TEMPLATE_OPTION_PREFIX . $coverage_id . '_' . $hash;

		if ( false === get_option( $option_key ) ) {
			update_option( $option_key, $config, false );
		}

		// Pages still in the page cache poll with an older config's key, and
		// one coverage can show several layouts at once. Only the most recent
		// configs are kept so the options table stays bounded. The list is
		// written only when its membership changes or the next config to drop
		// is rendered again, since every term meta write flushes the site's
		// term query caches.
		$meta_key = 'rolling_coverage_template_hashes';
		$hashes   = get_term_meta( $coverage_id, $meta_key, true );
		$hashes   = is_array( $hashes ) ? array_values( $hashes ) : [];

		if ( ! in_array( $hash, $hashes, true ) ) {
			$hashes[] = $hash;
		} elseif ( count( $hashes ) >= self::CONFIGS_KEPT && $hashes[0] === $hash ) {
			array_shift( $hashes );
			$hashes[] = $hash;
		} else {
			return $hash;
		}

		foreach ( array_splice( $hashes, 0, max( 0, count( $hashes ) - self::CONFIGS_KEPT ) ) as $old_hash ) {
			delete_option( self::TEMPLATE_OPTION_PREFIX . $coverage_id . '_' . $old_hash );
		}

		update_term_meta( $coverage_id, $meta_key, $hashes );

		return $hash;
	}

	/**
	 * Loads a persisted block config (entry template + ad settings) by
	 * coverage ID and hash key, falling back to defaults if the option is
	 * missing.
	 *
	 * @param int    $coverage_id  Coverage term ID.
	 * @param string $template_key Hash returned by persist_block_config().
	 * @return array{template: array[], adsEnabled: bool, adsInterval: int, latestOnly?: bool, latestCount?: int}
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
	 * @param WP_Post $entry          Entry post object.
	 * @param array[] $template       Per-entry inner-block template, as returned
	 *                                by get_entry_template().
	 * @param string  $arrival        How the entry first reaches the client:
	 *                                'initial', 'poll', or 'load_more'. Stamped as
	 *                                data-arrival for frontend entry-seen tracking.
	 * @param bool    $is_last        Whether no entry can load after this one.
	 * @param bool    $is_linked      Whether the page's link names this entry.
	 * @param bool    $is_capped      Whether the entry shows in a capped feed:
	 *                                rendered as unpinned whatever its pinned
	 *                                state, and with no anchor id, so links to
	 *                                the entry land on the coverage page.
	 * @return string Rendered HTML for the entry.
	 */
	public static function render_entry( WP_Post $entry, array $template, string $arrival = 'initial', bool $is_last = false, bool $is_linked = false, bool $is_capped = false ): string {
		$is_pinned = ! $is_capped && Post_Type::is_pinned( $entry->ID );
		$template  = self::shape_entry_template(
			self::drop_fixed_template_dates( $template ),
			$is_pinned,
			$is_pinned && null !== Breakout::get_published_breakout_url( $entry->ID ),
			$is_last
		);

		if ( ! self::has_title( $entry ) ) {
			$template = self::with_centered_title_rows( $template );
		}

		global $post;

		$previous_post          = $post;
		$was_ignoring_pinning   = self::$ignoring_pinning;
		$post                   = $entry; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		self::$ignoring_pinning = $is_capped;
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
					]
				) )->render( [ 'dynamic' => false ] )
			);
		} finally {
			if ( $is_archived ) {
				remove_filter( 'render_block_core/post-content', [ __CLASS__, 'render_archived_entry_content' ] );
			}

			$post                   = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			self::$ignoring_pinning = $was_ignoring_pinning;
			setup_postdata( $previous_post );
		}

		if ( $is_pinned && ! Entry_Bindings::has_pinned_label( $template ) ) {
			$entry_content = '<span class="newspack-rolling-coverage-pinned-status">' . esc_html__( 'Pinned', 'newspack-rolling-coverage' ) . '</span>' . $entry_content;
		}

		$post_classes = implode( ' ', get_post_class( [ self::MARKUP_PREFIX . '-entry', 'wp-block-post' ], $entry ) );

		$html = sprintf(
			'<article%1$s class="%3$s" data-entry-id="%2$d" data-entry-slug="%6$s" data-arrival="%5$s"%7$s%8$s>%4$s</article>',
			$is_capped ? '' : sprintf( ' id="%s-entry-%d"', self::MARKUP_PREFIX, $entry->ID ),
			$entry->ID,
			esc_attr( $post_classes ),
			$entry_content,
			esc_attr( $arrival ),
			esc_attr( $entry->post_name ),
			$is_pinned ? ' data-pinned' : '',
			$is_linked ? ' data-linked' : ''
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
					'skip_pinned'  => [
						'type'    => 'boolean',
						'default' => false,
					],
					'polled_count' => [
						'type'    => 'integer',
						'default' => 0,
					],
					'latest'       => [
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
					'term_id'     => [
						'required'          => true,
						'validate_callback' => [ __CLASS__, 'validate_term_id' ],
					],
					'per_page'    => [
						'type' => 'integer',
					],
					'latest_only' => [
						'type'    => 'boolean',
						'default' => false,
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

		$per_page    = min( max( 1, (int) ( $params['per_page'] ?? 20 ) ), self::PER_PAGE_MAX );
		$latest_only = rest_sanitize_boolean( $params['latest_only'] ?? false );

		$query_args = [
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
		];

		if ( $latest_only ) {
			$query_args[ Post_Type::SKIP_PIN_ORDER_VAR ] = true;
		}

		$query = new WP_Query( $query_args );

		update_meta_cache( 'post', $query->posts );
		_prime_post_caches( $query->posts, false, false );
		_prime_post_caches(
			array_filter( array_map( fn( $id ) => (int) get_post_meta( $id, Breakout::ENTRY_BREAKOUT_POST_ID_META, true ), $query->posts ) ),
			true,
			false
		);

		$entries = array_map( fn( $id ) => self::map_entry_preview( $id, $latest_only ), $query->posts );

		return new WP_REST_Response( $entries );
	}

	/**
	 * Array_map() callback for get_entries_preview(): reduces a post ID to
	 * the bare `{ id, type, pinned, hasBreakout, hasTitle }` shape the editor
	 * preview needs.
	 *
	 * @param int  $id          Entry post ID.
	 * @param bool $ignore_pins Whether to report the entry as unpinned, as a capped feed does.
	 * @return array{id: int, type: string, pinned: bool, hasBreakout: bool, hasTitle: bool}
	 */
	private static function map_entry_preview( int $id, bool $ignore_pins = false ): array {
		return [
			'id'          => $id,
			'type'        => Post_Type::CPT_SLUG,
			'pinned'      => ! $ignore_pins && Post_Type::is_pinned( $id ),
			'hasBreakout' => null !== Breakout::get_published_breakout_url( $id ),
			'hasTitle'    => self::has_title( get_post( $id ) ),
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
	 *   Sends a short Cache-Control and the site's minimum poll interval; see
	 *   poll_response().
	 * - `before` (backward/pagination): entries published before the given
	 *   date, DESC order, capped at the request's per_page (entriesPerPage).
	 *   Sends no Cache-Control, so it keeps the page cache's default lifetime,
	 *   the same as the page it extends: rendering a page of entries costs
	 *   more than answering an idle poll. A cached copy can predate an edit
	 *   the reader's poll has already delivered, so the view script keeps
	 *   those edits and applies them when load more brings the entry in.
	 *   With skip_pinned, pinned entries are left out, for a feed that opens at
	 *   a shared entry.
	 *
	 * A capped feed, as its stored config or a positive `latest` count says,
	 * polls entries as unpinned and without ads, and loads no more.
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

		$base_args = self::coverage_entries_args( $term_id );

		$config           = self::load_block_config( $term_id, $template_key );
		$template         = $config['template'];
		$is_capped        = self::latest_count( $config ) > 0 || self::requested_latest_count( $params ) > 0;
		$ads_interval     = max( 1, (int) $config['adsInterval'] );
		$ads_enabled_attr = (bool) $config['adsEnabled'];
		$ads_enabled      = ! $is_capped && $ads_enabled_attr && ! self::is_coverage_ads_disabled( $term_id );

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
					],
					$term_id
				);
			}

			$args = array_merge(
				$base_args,
				[
					'date_query'     => [
						[
							'column'    => 'post_modified_gmt',
							'after'     => self::gmt_date_bound( $cursor_modified ),
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
					],
					$term_id
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
					'html'   => self::render_entry( $entry, $template, $is_new_entry ? 'poll' : '', is_capped: $is_capped ),
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
				],
				$term_id
			);
		}

		if ( $is_capped ) {
			return new WP_REST_Response(
				[
					'html'    => '',
					'before'  => null,
					'hasMore' => false,
					'count'   => 0,
					'adSlots' => [],
				]
			);
		}

		$args = array_merge(
			$base_args,
			[
				'date_query' => [
					[
						'column'    => 'post_date_gmt',
						'before'    => self::gmt_date_bound( (string) $before ),
						'inclusive' => false,
					],
				],
				'orderby'    => 'date',
				'order'      => 'DESC',
			]
		);

		$entry_offset = max( 0, (int) ( $params['entry_offset'] ?? 0 ) );

		if ( rest_sanitize_boolean( $params['skip_pinned'] ?? false ) ) {
			$page     = self::query_unpinned_entries( $args, $per_page );
			$posts    = $page['posts'];
			$has_more = $page['has_more'];
		} else {
			// Prevents duplicate pinned entries on frontend.
			$args[ Post_Type::SKIP_PIN_ORDER_VAR ] = true;
			$args['posts_per_page']                = $per_page;

			$query    = new WP_Query( $args );
			$posts    = $query->posts;
			$has_more = count( $posts ) === $per_page;
		}

		$html        = '';
		$ad_slots    = [];
		$entry_index = 0;

		foreach ( $posts as $entry ) {
			$entry_index++;
			$html .= self::render_entry( $entry, $template, 'load_more', is_last: ! $has_more && count( $posts ) === $entry_index );

			$position = $entry_offset + $entry_index;
			if ( $ads_enabled && Ads::is_capped_ad_position( $position, $ads_interval ) ) {
				$placement = Ads::render_placement();
				$html     .= $placement['html'];
				$ad_slots  = array_merge( $ad_slots, $placement['slots'] );
			}
		}

		wp_reset_postdata();

		$next_before = ! empty( $posts )
			? self::post_date_gmt( $posts[ count( $posts ) - 1 ] )
			: null;

		return new WP_REST_Response(
			[
				'html'    => $html,
				'before'  => $next_before,
				'hasMore' => $has_more,
				'count'   => count( $posts ),
				'adSlots' => $ad_slots,
			]
		);
	}

	/**
	 * The slowest pace the site lets readers' pages poll at, in seconds.
	 *
	 * A safety valve for a site under load. Every block polls at this
	 * interval or its own, whichever is longer, and poll responses are cached
	 * for longer to match. Pages that are already open learn it from their
	 * next poll, so it takes hold, and lifts, without a reload.
	 *
	 * @return int Whole seconds; 0 when the site sets no minimum.
	 */
	public static function get_min_poll_interval(): int {
		/**
		 * Minimum seconds between a reader's polls for new entries, for every
		 * Rolling Coverage block on the site. Poll responses are cached for
		 * half this long.
		 *
		 * @constant NEWSPACK_ROLLING_COVERAGE_MIN_POLL_INTERVAL
		 * @type     int
		 * @default  No minimum; each block polls at its own interval
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_ROLLING_COVERAGE_MIN_POLL_INTERVAL', 60 );
		 */
		$interval = defined( 'NEWSPACK_ROLLING_COVERAGE_MIN_POLL_INTERVAL' ) ? NEWSPACK_ROLLING_COVERAGE_MIN_POLL_INTERVAL : 0;

		/**
		 * Filters the minimum seconds between a reader's polls for new entries.
		 *
		 * @param mixed $interval Seconds. Anything but a positive number means no minimum.
		 */
		$interval = apply_filters( 'newspack_rolling_coverage_min_poll_interval', $interval );

		return is_numeric( $interval ) ? max( 0, (int) $interval ) : 0;
	}

	/**
	 * The coverage's status as readers see it: a status the plugin doesn't
	 * know reads as live.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string 'active', 'paused' or 'archived'.
	 */
	public static function coverage_status( int $coverage_id ): string {
		$status = (string) get_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, true );

		return in_array( $status, [ Taxonomy::STATUS_ACTIVE, Taxonomy::STATUS_PAUSED, Taxonomy::STATUS_ARCHIVED ], true ) ? $status : Taxonomy::STATUS_ACTIVE;
	}

	/**
	 * Builds a poll response: how long caches may keep it, and the minimum
	 * poll interval for the pages that receive it.
	 *
	 * An idle poll's URL only changes once something new is published, so
	 * this response's cache lifetime is how long a new entry can take to
	 * reach open pages. Left unset, Batcache and the edge keep it for five
	 * minutes. Batcache adopts the max-age sent here as its own lifetime, so
	 * this one header sets both. Authenticated requests still get core's
	 * no-cache headers, which replace it.
	 *
	 * The lifetime grows to half the site's minimum poll interval and never
	 * drops below POLL_MAX_AGE.
	 *
	 * It also carries the coverage's status and newest entry date for the
	 * Coverage Status block.
	 *
	 * @param array $data        Poll response body.
	 * @param int   $coverage_id Coverage term ID.
	 * @return WP_REST_Response Response with a short Cache-Control header.
	 */
	private static function poll_response( array $data, int $coverage_id ): WP_REST_Response {
		$data['status']          = self::coverage_status( $coverage_id );
		$data['newestEntry']     = Newest_Entry::get_iso( $coverage_id );
		$min_poll_interval       = self::get_min_poll_interval();
		$data['minPollInterval'] = $min_poll_interval;

		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'public, max-age=' . max( self::POLL_MAX_AGE, intdiv( $min_poll_interval, 2 ) ) );

		return $response;
	}
}
