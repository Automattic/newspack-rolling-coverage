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
 * a coverage's status wherever it is placed: in post content, next to the
 * title in a template, or in a header. Placed inside a Rolling Coverage
 * block, it shows that block's status; elsewhere, its chosen coverage's, or
 * else that of the coverage the page being viewed belongs to.
 */
class Coverage_Status_Block {

	// Block name, as registered in block.json.
	const BLOCK_NAME = 'newspack-rolling-coverage/coverage-status';

	// REST field on the coverage term holding when its newest entry was published.
	const NEWEST_ENTRY_REST_FIELD = 'newestEntry';

	// The badge's modifier classes for each status.
	const BADGE_CLASSES = [
		Taxonomy::STATUS_ACTIVE   => 'newspack-ui__badge--success newspack-ui__badge--dot newspack-ui__badge--pulse',
		Taxonomy::STATUS_PAUSED   => 'newspack-ui__badge--secondary',
		Taxonomy::STATUS_ARCHIVED => 'newspack-ui__badge--error',
	];

	/**
	 * Theme colors a badge background can name instead of a hex color, keyed
	 * by the block theme's palette slug: the block theme's preset, then the
	 * classic Newspack Theme's custom property, then a plain value. They
	 * follow the theme's style variations, which a stored hex can't. `pair`
	 * is the palette slug of the text color the theme designed for it.
	 */
	const THEME_COLORS = [
		'accent' => [
			'background' => 'var(--wp--preset--color--accent, var(--newspack-theme-color-primary, #003da5))',
			'pair'       => 'accent-contrast',
			'text'       => 'var(--wp--preset--color--accent-contrast, var(--wp--preset--color--base, var(--newspack-theme-color-against-primary, #fff)))',
		],
		'base'   => [
			'background' => 'var(--wp--preset--color--base, var(--newspack-theme-color-bg-body, #fff))',
			'pair'       => 'contrast',
			'text'       => 'var(--wp--preset--color--contrast, var(--newspack-theme-color-text-main, #111))',
		],
	];

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'localize_editor_config' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_fields' ] );
	}

	/**
	 * Registers the block type.
	 */
	public static function register_block(): void {
		register_block_type(
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/coverage-status',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Adds the newest entry's publish moment to the coverage REST record, so
	 * the editor preview shows the same time as the front end.
	 */
	public static function register_rest_fields(): void {
		register_rest_field(
			Taxonomy::TAXONOMY_SLUG,
			self::NEWEST_ENTRY_REST_FIELD,
			[
				'get_callback' => [ __CLASS__, 'get_newest_entry_rest_field' ],
				'schema'       => [
					'description' => __( 'When the newest published entry went out, as ISO 8601.', 'newspack-rolling-coverage' ),
					'type'        => [ 'string', 'null' ],
					'format'      => 'date-time',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
			]
		);
	}

	/**
	 * The newest entry's publish moment for the coverage REST record, shown
	 * to people who can edit only.
	 *
	 * @param array $term Coverage term REST data.
	 * @return string|null ISO 8601 date, or null without entries or access.
	 */
	public static function get_newest_entry_rest_field( array $term ): ?string {
		if ( ! isset( $term['id'] ) || ! current_user_can( 'edit_posts' ) ) {
			return null;
		}

		return Newest_Entry::get_iso( (int) $term['id'] );
	}

	/**
	 * Gives the editor script what its preview needs.
	 */
	public static function localize_editor_config(): void {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK_NAME );

		if ( ! $block_type instanceof WP_Block_Type ) {
			return;
		}

		foreach ( $block_type->editor_script_handles as $handle ) {
			wp_localize_script(
				$handle,
				'newspackCoverageStatusBlock',
				[
					'sourceEntryField' => Breakout::BREAKOUT_SOURCE_ENTRY_FIELD,
					'statusLabels'     => Status_Labels::get_all(),
					'statusMetaKey'    => Taxonomy::STATUS_META_KEY,
					'taxonomySlug'     => Taxonomy::TAXONOMY_SLUG,
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
	 * @return string Rendered HTML, or '' when there's no coverage to show.
	 */
	public static function render_block( array $attributes, string $content, WP_Block $block ): string {
		$coverage_id = self::followed_coverage_id( $attributes, $block );

		if ( ! $coverage_id ) {
			return '';
		}

		$status = Rolling_Coverage_Block::coverage_status( $coverage_id );

		if ( Taxonomy::STATUS_ARCHIVED === $status && ! empty( $attributes['hideWhenEnded'] ) ) {
			return '';
		}

		$show_dot = false !== ( $attributes['showDot'] ?? true );
		$labels   = self::labels( $attributes );
		$styles   = self::badge_styles( $attributes );
		$wrapper  = [
			'data-coverage-id' => $coverage_id,
			'data-status'      => $status,
		];

		foreach ( $labels as $key => $label ) {
			$wrapper[ 'data-label-' . $key ] = $label;
		}

		foreach ( array_filter( $styles ) as $key => $style ) {
			$wrapper[ 'data-style-' . $key ] = $style;
		}

		if ( ! $show_dot ) {
			$wrapper['data-hide-dot'] = 'true';
		}

		if ( ! empty( $attributes['hideWhenEnded'] ) ) {
			$wrapper['data-hide-when-ended'] = 'true';
		}

		$classes = self::BADGE_CLASSES[ $status ];

		if ( ! $show_dot && Taxonomy::STATUS_ACTIVE === $status ) {
			$classes = 'newspack-ui__badge--success';
		}

		$html = sprintf(
			'<span class="%s"%s>%s</span>',
			esc_attr( 'newspack-ui__badge ' . $classes ),
			'' !== $styles[ $status ] ? ' style="' . esc_attr( $styles[ $status ] ) . '"' : '',
			esc_html( $labels[ $status ] )
		);

		// A lite page strips the span that hides "Updated" and never runs the
		// script that keeps it current, so there the badge stands alone, as a
		// snapshot like the rest of the page.
		if ( ! empty( $attributes['showLastUpdated'] ) && ! Lite_Feed::is_lite_render() ) {
			$html .= ' ' . self::render_last_updated( $coverage_id, $status );
		}

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( $wrapper ), $html );
	}

	/**
	 * Inline badge style for a custom background: the color, its text color,
	 * and a dot color that stays visible on it. A THEME_COLORS name keeps the
	 * theme's paired text when the palette has it; otherwise APCA picks the
	 * text against the palette's hex, so a light accent with no pair still
	 * gets dark text. With neither, the paired variable's fallbacks apply.
	 *
	 * @param string $color Background color: a THEME_COLORS name, or any form Apca::normalize() accepts.
	 * @return string The style, or '' when the color is unset or invalid.
	 */
	private static function badge_style( string $color ): string {
		if ( isset( self::THEME_COLORS[ $color ] ) ) {
			$background = self::THEME_COLORS[ $color ]['background'];
			$text       = self::THEME_COLORS[ $color ]['text'];
			$preset     = Apca::normalize( self::palette_color( $color ) );

			if ( '' !== $preset && '' === self::palette_color( self::THEME_COLORS[ $color ]['pair'] ) ) {
				$text = Apca::text_color( $preset );
			}
		} else {
			$background = Apca::normalize( $color );

			if ( '' === $background ) {
				return '';
			}

			$text = Apca::text_color( $background );
		}

		return sprintf( 'background:%1$s;color:%2$s;--newspack-ui-badge-dot-color:color-mix(in srgb, %2$s 60%%, %1$s)', $background, $text );
	}

	/**
	 * A palette color's current value, the site's own before the theme's,
	 * as the preset variable resolves. It reflects the active style variation.
	 *
	 * @param string $slug Palette slug.
	 * @return string The color, or '' when the palette has no such slug.
	 */
	private static function palette_color( string $slug ): string {
		$palette = wp_get_global_settings( [ 'color', 'palette' ] );

		foreach ( [ 'custom', 'theme' ] as $origin ) {
			foreach ( (array) ( $palette[ $origin ] ?? [] ) as $entry ) {
				if ( is_array( $entry ) && $slug === ( $entry['slug'] ?? null ) && is_string( $entry['color'] ?? null ) && '' !== $entry['color'] ) {
					return $entry['color'];
				}
			}
		}

		return '';
	}

	/**
	 * The badge style for each status from the block's custom colors.
	 *
	 * @param array $attributes Block attributes.
	 * @return array<string, string>
	 */
	private static function badge_styles( array $attributes ): array {
		$own    = is_array( $attributes['backgroundColors'] ?? null ) ? $attributes['backgroundColors'] : [];
		$styles = [];

		foreach ( array_keys( self::BADGE_CLASSES ) as $status ) {
			$styles[ $status ] = is_string( $own[ $status ] ?? null ) ? self::badge_style( $own[ $status ] ) : '';
		}

		return $styles;
	}

	/**
	 * The coverage the block shows, as Page_Coverages::coverage_for_block()
	 * decides it.
	 *
	 * @param array    $attributes Block attributes.
	 * @param WP_Block $block      Block instance.
	 * @return int Coverage term ID, or 0.
	 */
	private static function followed_coverage_id( array $attributes, WP_Block $block ): int {
		return Page_Coverages::coverage_for_block( $block, (int) ( $attributes['coverageId'] ?? 0 ) );
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
