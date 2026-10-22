<?php
/**
 * The built-in layouts as this version of the plugin ships them.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a built-in layout from `includes/blocks/layouts/`, where
 * `npm run build:layouts` writes the editor's templates, and fills in what
 * the editor takes from the site: its translations, its name for entries,
 * its time format, and the theme's muted color and font sizes.
 *
 * `tests/js/layout-tokens.test.ts` checks that filling the tokens in as
 * fill_in() does gives the layout the editor builds on the same site.
 */
class Layout_Templates {

	/**
	 * A token in a template: its name, then what follows a colon, if anything.
	 */
	const TOKEN = '/\{\{([a-z_0-9]+)(?::([^}]*))?\}\}/';

	/**
	 * An attribute value that is one token and nothing else.
	 */
	const WHOLE_TOKEN = '/^\{\{([a-z_0-9]+)(?::([^}]*))?\}\}$/';

	/**
	 * The muted text colors, the block theme's then the classic theme's,
	 * mirroring MUTED_TEXT_COLORS in `src/blocks/shared/muted-color.ts`.
	 */
	const MUTED_TEXT_COLORS = [ 'contrast-3', 'medium-gray' ];

	/**
	 * Parsed templates by slug, before they are filled in.
	 *
	 * @var array<string, array[]>
	 */
	private static $parsed = [];

	/**
	 * A built-in layout's inner blocks, filled in for this site.
	 *
	 * @param string $slug Built-in layout slug.
	 * @return array[]|null Parsed blocks, or null for a slug without a template.
	 */
	public static function get_blocks( string $slug ): ?array {
		if ( ! in_array( $slug, Layout::BUILT_IN_SLUGS, true ) ) {
			return null;
		}

		if ( ! isset( self::$parsed[ $slug ] ) ) {
			$file = __DIR__ . '/layouts/' . $slug . '.html';

			if ( ! is_readable( $file ) ) {
				return null;
			}

			self::$parsed[ $slug ] = array_values(
				array_filter(
					parse_blocks( (string) file_get_contents( $file ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					static fn( $block ) => ! empty( $block['blockName'] )
				)
			);
		}

		return self::fill_in( self::$parsed[ $slug ], self::site_values() );
	}

	/**
	 * A built-in layout as its pattern holds it: one Rolling Coverage block
	 * around the layout's inner blocks, as the editor creates it.
	 *
	 * @param string $slug Built-in layout slug.
	 * @return string|null Block markup, or null for a slug without a template.
	 */
	public static function get_pattern_content( string $slug ): ?string {
		$blocks = self::get_blocks( $slug );

		if ( null === $blocks ) {
			return null;
		}

		return serialize_block(
			[
				'blockName'    => Rolling_Coverage_Block::BLOCK_NAME,
				'attrs'        => [],
				'innerBlocks'  => $blocks,
				'innerHTML'    => '',
				'innerContent' => array_fill( 0, count( $blocks ), null ),
			]
		);
	}

	/**
	 * What the tokens other than translations stand for on this site.
	 *
	 * @return array{time_format: string, muted_color: ?string, font_sizes: string[], all_updates: string}
	 */
	private static function site_values(): array {
		$palette = wp_get_global_settings( [ 'color', 'palette' ] );
		$colors  = [];

		foreach ( is_array( $palette ) ? $palette : [] as $origin ) {
			foreach ( is_array( $origin ) ? $origin : [] as $color ) {
				$colors[] = $color['slug'] ?? '';
			}
		}

		$muted       = array_values( array_intersect( self::MUTED_TEXT_COLORS, $colors ) );
		$font_sizes  = wp_get_global_settings( [ 'typography', 'fontSizes' ] );
		$time_format = (string) get_option( 'time_format' );
		$plural      = Entry_Name::word( 2 );

		return [
			'time_format' => '' !== $time_format ? $time_format : 'g:i a',
			'muted_color' => $muted[0] ?? null,
			'font_sizes'  => array_column( $font_sizes['theme'] ?? [], 'slug' ),
			'all_updates' => '' === $plural
				? __( 'See all entries', 'newspack-rolling-coverage' )
				/* translators: %s: the site's name for several coverage entries, as it reads mid-sentence, e.g. "updates". */
				: sprintf( __( 'See all %s', 'newspack-rolling-coverage' ), $plural ),
		];
	}

	/**
	 * A token's value, or null where the attribute holding it goes.
	 *
	 * @param string $name     Token name.
	 * @param string $argument What follows the token's colon.
	 * @param array  $site     The site's values, from site_values().
	 * @return string|null
	 */
	private static function token_value( string $name, string $argument, array $site ): ?string {
		switch ( $name ) {
			case 'i18n':
				// The template's words, which the editor's script translates too.
				return __( $argument, 'newspack-rolling-coverage' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			case 'all_updates':
			case 'time_format':
			case 'muted_color':
				return $site[ $name ];
			case 'font_size':
				// The preferred size where the theme has it, else its fallback,
				// as themeFontSize() in `src/blocks/shared/theme-font-size.ts`.
				$sizes     = array_pad( explode( '|', $argument, 2 ), 2, '' );
				$preferred = $sizes[0];
				$fallback  = $sizes[1];

				return ! in_array( $preferred, $site['font_sizes'], true ) && in_array( $fallback, $site['font_sizes'], true ) ? $fallback : $preferred;
		}

		return '';
	}

	/**
	 * The blocks with their tokens filled in: as they are in attributes,
	 * escaped in markup. An attribute that is only a token without a value,
	 * such as the muted color on a theme without one, is dropped.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @param array   $site   The site's values, from site_values().
	 * @return array[]
	 */
	private static function fill_in( array $blocks, array $site ): array {
		$in_markup = static fn( $chunk ) => is_string( $chunk )
			? preg_replace_callback( self::TOKEN, static fn( $match ) => esc_html( (string) self::token_value( $match[1], $match[2] ?? '', $site ) ), $chunk )
			: $chunk;

		foreach ( $blocks as $index => $block ) {
			$blocks[ $index ]['attrs']        = self::fill_in_attributes( $block['attrs'] ?? [], $site );
			$blocks[ $index ]['innerHTML']    = $in_markup( $block['innerHTML'] ?? '' );
			$blocks[ $index ]['innerContent'] = array_map( $in_markup, $block['innerContent'] ?? [] );
			$blocks[ $index ]['innerBlocks']  = self::fill_in( $block['innerBlocks'] ?? [], $site );
		}

		return $blocks;
	}

	/**
	 * Block attributes with their tokens filled in.
	 *
	 * @param array $attributes Block attributes.
	 * @param array $site       The site's values, from site_values().
	 * @return array
	 */
	private static function fill_in_attributes( array $attributes, array $site ): array {
		foreach ( $attributes as $key => $value ) {
			if ( is_array( $value ) ) {
				$attributes[ $key ] = self::fill_in_attributes( $value, $site );
				continue;
			}

			if ( ! is_string( $value ) ) {
				continue;
			}

			if ( preg_match( self::WHOLE_TOKEN, $value, $match ) && null === self::token_value( $match[1], $match[2] ?? '', $site ) ) {
				unset( $attributes[ $key ] );
				continue;
			}

			$attributes[ $key ] = preg_replace_callback( self::TOKEN, static fn( $match ) => (string) self::token_value( $match[1], $match[2] ?? '', $site ), $value );
		}

		return $attributes;
	}
}
