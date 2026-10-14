<?php
/**
 * The accent color the feed's labels take on the page background.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the theme color for accents set on the page background, such as the
 * Full story label (see Breakout_Card) and the Pinned row: the site's
 * primary color (a block theme's accent) when it reads well on the page
 * background, else its secondary color (accent-2), else the text color. The
 * choice is published as a custom property naming the theme's own variable
 * rather than a fixed value, so the accents follow the theme.
 */
class Readable_Accent {

	/**
	 * Custom property holding the chosen color.
	 */
	const PROPERTY = '--newspack-rolling-coverage-accent';

	/**
	 * Lowest APCA contrast, as an absolute Lc, a color needs against the
	 * page background: enough for small bold label text.
	 */
	const MIN_LC = 60.0;

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'enqueue_block_assets', [ __CLASS__, 'add_inline_style' ] );
	}

	/**
	 * Publish the chosen color with the feed block's stylesheet, so it
	 * prints only where a feed does, on the site and in the editor. The
	 * editor fires the hook twice into one stylesheet, so it's added once.
	 */
	public static function add_inline_style(): void {
		static $added = false;

		if ( $added ) {
			return;
		}

		$value = self::value();

		if ( '' === $value ) {
			return;
		}

		$added = true;

		wp_add_inline_style(
			generate_block_asset_handle( 'newspack-rolling-coverage/rolling-coverage', 'style' ),
			sprintf( ':root{%s:%s}', self::PROPERTY, $value )
		);
	}

	/**
	 * The chosen color for the active theme, or '' when the theme names no
	 * colors the plugin can weigh, leaving the stylesheet's own fallback.
	 *
	 * @return string
	 */
	public static function value(): string {
		$theme = self::theme_colors();

		return $theme ? self::pick( $theme['candidates'], $theme['background'] ) : '';
	}

	/**
	 * The first candidate with enough contrast against the background, else
	 * `currentcolor`. A color that isn't a hex value can't be weighed, so it
	 * ends the search with '', leaving the stylesheet's own fallback.
	 *
	 * @param array  $candidates CSS value => the color it resolves to, in
	 *                           order of preference.
	 * @param string $background The page background color.
	 * @return string
	 */
	public static function pick( array $candidates, string $background ): string {
		$background = Apca::normalize( $background );

		if ( '' === $background ) {
			return '';
		}

		foreach ( $candidates as $css => $color ) {
			$color = Apca::normalize( (string) $color );

			if ( '' === $color ) {
				return '';
			}

			if ( abs( Apca::lc( $color, $background ) ) >= self::MIN_LC ) {
				return (string) $css;
			}
		}

		return 'currentcolor';
	}

	/**
	 * The active theme's candidate colors and page background: a block
	 * theme's accent and accent-2 palette colors, as merged with its style
	 * variation and Global Styles, on its page background, or the classic
	 * Newspack Theme's primary and secondary colors on its white page.
	 *
	 * @return array{candidates: array<string, string>, background: string}|array{}
	 */
	private static function theme_colors(): array {
		if ( wp_is_block_theme() ) {
			$palette = [];

			foreach ( (array) wp_get_global_settings( [ 'color', 'palette', 'theme' ] ) as $entry ) {
				if ( isset( $entry['slug'], $entry['color'] ) ) {
					$palette[ $entry['slug'] ] = $entry['color'];
				}
			}

			if ( ! isset( $palette['accent'], $palette['base'] ) ) {
				return [];
			}

			$candidates = [ 'var(--wp--preset--color--accent)' => $palette['accent'] ];

			if ( isset( $palette['accent-2'] ) ) {
				$candidates['var(--wp--preset--color--accent-2)'] = $palette['accent-2'];
			}

			return [
				'candidates' => $candidates,
				'background' => self::block_theme_background( $palette ),
			];
		}

		if ( ! function_exists( 'newspack_get_colors' ) ) {
			return [];
		}

		$colors = newspack_get_colors();

		return [
			'candidates' => [
				'var(--newspack-theme-color-primary)'   => $colors['primary'] ?? '',
				'var(--newspack-theme-color-secondary)' => $colors['secondary'] ?? '',
			],
			'background' => '#ffffff',
		];
	}

	/**
	 * A block theme's page background: the color its global styles give the
	 * page, a palette preset or a hex value, else its base palette color.
	 *
	 * @param array $palette Palette colors by slug.
	 * @return string
	 */
	private static function block_theme_background( array $palette ): string {
		$background = (string) wp_get_global_styles( [ 'color', 'background' ] );

		if ( preg_match( '/^var(?::preset\|color\||\(--wp--preset--color--)([a-z0-9-]+)\)?$/', $background, $matches ) ) {
			return $palette[ $matches[1] ] ?? $palette['base'];
		}

		return '' !== Apca::normalize( $background ) ? $background : $palette['base'];
	}
}
