<?php
/**
 * Ready-made pages built around the Rolling Coverage blocks.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Rolling Coverage - Pages" block pattern category and the page
 * patterns in it, which the inserter's Patterns tab offers.
 *
 * Their markup lives in `includes/patterns/<slug>.php`, one file per pattern,
 * which core includes the first time the pattern is read. The patterns have
 * no `blockTypes` or `templateTypes`, so the new-page "Choose a pattern"
 * window never offers them.
 *
 * A pattern can't name a built-in layout: `layoutId` is a site's own pattern
 * ID. Each Rolling Coverage block in a pattern holds its layout as inner
 * blocks instead (feed()), which the block renders as a detached layout.
 */
class Page_Patterns {

	const CATEGORY = 'rolling-coverage-pages';

	const NAME_PREFIX = 'newspack-rolling-coverage/';

	/**
	 * Newspack Blocks' Columns style that shows the second column first from
	 * tablet width up.
	 */
	const FIRST_COLUMN_TO_SECOND = 'is-style-first-col-to-second';

	/**
	 * The theme's accent, as the layouts set their Full story label in it.
	 */
	const ACCENT = 'var(--newspack-rolling-coverage-accent, var(--wp--preset--color--accent, var(--newspack-theme-color-primary)))';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 11 );
	}

	/**
	 * Registers the category and the patterns.
	 */
	public static function register(): void {
		register_block_pattern_category(
			self::CATEGORY,
			[
				/* translators: %s: the plugin's name, Rolling Coverage. */
				'label'       => sprintf( __( '%s - Pages', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
				'description' => __( 'Ready-made pages for a liveblog.', 'newspack-rolling-coverage' ),
			]
		);

		foreach ( self::definitions() as $slug => $definition ) {
			register_block_pattern(
				self::NAME_PREFIX . $slug,
				$definition + [
					'categories' => [ self::CATEGORY ],
					'filePath'   => __DIR__ . '/patterns/' . $slug . '.php',
				]
			);
		}
	}

	/**
	 * A Rolling Coverage block holding a built-in layout, as a pattern's
	 * markup. It has no `coverageId`, so the editor asks which liveblog to
	 * show once the pattern is inserted.
	 *
	 * @param string $layout     Built-in layout slug.
	 * @param array  $attributes The block's own settings: what picking the layout in the editor sets, such as `latestOnly`.
	 * @return string Block markup, empty for a slug without a layout.
	 */
	public static function feed( string $layout, array $attributes = [] ): string {
		$blocks = Layout_Templates::get_blocks( $layout );

		if ( null === $blocks ) {
			return '';
		}

		return serialize_block(
			[
				'blockName'    => Rolling_Coverage_Block::BLOCK_NAME,
				'attrs'        => $attributes,
				'innerBlocks'  => $blocks,
				'innerHTML'    => '',
				'innerContent' => array_fill( 0, count( $blocks ), null ),
			]
		);
	}

	/**
	 * The theme's font size slugs.
	 *
	 * @return string[]
	 */
	private static function font_size_slugs(): array {
		$sizes = wp_get_global_settings( [ 'typography', 'fontSizes' ] );

		return array_column( is_array( $sizes ) ? ( $sizes['theme'] ?? [] ) : [], 'slug' );
	}

	/**
	 * The opening of a wide row of two columns whose first column is the
	 * narrow side column, so it stacks first on phones. With Newspack Blocks
	 * active, the row takes its "Move first column to second" style, which
	 * shows the side column on the right from tablet width up.
	 *
	 * @param bool|null $reorder Whether to use the style; null uses it when Newspack Blocks is active.
	 * @return string Block markup up to the first column.
	 */
	public static function side_columns_open( ?bool $reorder = null ): string {
		$reorder    = $reorder ?? class_exists( 'Newspack_Blocks' );
		$attributes = [ 'align' => 'wide' ] + ( $reorder ? [ 'className' => self::FIRST_COLUMN_TO_SECOND ] : [] );

		return '<!-- wp:columns ' . serialize_block_attributes( $attributes ) . ' -->' . "\n"
			. '<div class="wp-block-columns alignwide' . ( $reorder ? ' ' . self::FIRST_COLUMN_TO_SECOND : '' ) . '">';
	}

	/**
	 * A Follow Coverage block with its button, as the block builds it. It has
	 * no `coverageId`, so it follows the liveblog of the page, or the one it
	 * is set to once someone picks Custom.
	 *
	 * @param bool $full_width Whether the button fills the width of its column.
	 * @return string Block markup.
	 */
	public static function follow( bool $full_width = false ): string {
		$text        = __( 'Follow', 'newspack-rolling-coverage' );
		$label       = esc_html__( 'Get alerts', 'newspack-rolling-coverage' );
		$classes     = 'wp-block-button' . ( $full_width ? ' has-custom-width wp-block-button__width-100' : '' );
		$button_html = "\n" . '<div class="' . $classes . '"><button type="button" class="wp-block-button__link wp-element-button">' . $label . '</button></div>' . "\n";
		$button      = [
			'blockName'    => 'core/button',
			'attrs'        => [
				'tagName'  => 'button',
				'metadata' => [
					'name'     => $text,
					'bindings' => [
						'url' => [
							'source' => 'newspack-rolling-coverage/entry',
							'args'   => [ 'key' => 'followTag' ],
						],
					],
				],
			],
			'innerBlocks'  => [],
			'innerHTML'    => $button_html,
			'innerContent' => [ $button_html ],
		];
		if ( $full_width ) {
			$button['attrs']['width'] = 100;
		}
		$buttons = [
			'blockName'    => 'core/buttons',
			'attrs'        => [ 'metadata' => [ 'name' => $text ] ],
			'innerBlocks'  => [ $button ],
			'innerHTML'    => "\n" . '<div class="wp-block-buttons"></div>' . "\n",
			'innerContent' => [ "\n" . '<div class="wp-block-buttons">', null, '</div>' . "\n" ],
		];

		return serialize_block(
			[
				'blockName'    => 'newspack-rolling-coverage/coverage-follow',
				'attrs'        => [],
				'innerBlocks'  => [ $buttons ],
				'innerHTML'    => "\n\n",
				'innerContent' => [ "\n", null, "\n" ],
			]
		);
	}

	/**
	 * A Coverage Status block with the time of the last update. It has no
	 * `coverageId`, so it shows the liveblog of the page.
	 *
	 * @param string[] $sizes Font size slugs: the first one the theme has.
	 * @return string Block markup.
	 */
	public static function status( array $sizes = [] ): string {
		$attributes = array_filter(
			[
				'showLastUpdated' => true,
				'fontSize'        => self::first_size( $sizes ),
			]
		);

		return '<!-- wp:newspack-rolling-coverage/coverage-status ' . serialize_block_attributes( $attributes ) . ' /-->';
	}

	/**
	 * An Update Timer block. It has no `coverageId`, so it counts down for
	 * the liveblog of the page.
	 *
	 * @param string[] $sizes Font size slugs: the first one the theme has.
	 * @return string Block markup.
	 */
	public static function update_timer( array $sizes = [] ): string {
		$size = self::first_size( $sizes );

		return '<!-- wp:newspack-rolling-coverage/update-timer ' . ( $size ? serialize_block_attributes( [ 'fontSize' => $size ] ) . ' ' : '' ) . '/-->';
	}

	/**
	 * A heading at a font size preset, with the markup core saves for it.
	 *
	 * @param string   $text       Heading text, already escaped.
	 * @param string[] $sizes      Font size slugs: the first one the theme has.
	 * @param int      $level      Heading level.
	 * @param string   $margin_top Spacing preset slug for the top margin, if any.
	 * @return string Block markup.
	 */
	public static function heading( string $text, array $sizes, int $level = 2, string $margin_top = '' ): string {
		$size       = self::first_size( $sizes );
		$classes    = 'wp-block-heading' . ( $size ? ' has-' . _wp_to_kebab_case( $size ) . '-font-size' : '' );
		$style      = $margin_top ? ' style="margin-top:var(--wp--preset--spacing--' . $margin_top . ')"' : '';
		$attributes = array_filter(
			[
				'level'    => 2 === $level ? null : $level,
				'style'    => $margin_top ? [ 'spacing' => [ 'margin' => [ 'top' => 'var:preset|spacing|' . $margin_top ] ] ] : null,
				'fontSize' => $size,
			]
		);
		$html       = "\n" . '<h' . $level . ' class="' . esc_attr( $classes ) . '"' . $style . '>' . $text . '</h' . $level . '>' . "\n";

		return serialize_block(
			[
				'blockName'    => 'core/heading',
				'attrs'        => $attributes,
				'innerBlocks'  => [],
				'innerHTML'    => $html,
				'innerContent' => [ $html ],
			]
		);
	}

	/**
	 * The first of some font size slugs that the theme has.
	 *
	 * @param string[] $slugs Font size slugs, in order of preference.
	 * @return string The slug, or empty when the theme has none of them.
	 */
	private static function first_size( array $slugs ): string {
		return (string) current( array_intersect( $slugs, self::font_size_slugs() ) );
	}

	/**
	 * A Scoreboard block with its parts bound to the score, set as the
	 * block's own template sets them (`src/blocks/scoreboard/template.ts`):
	 * team names and scores bold in the headings' font, a dash between the
	 * scores on one line, the status line small and muted. It has no
	 * `coverageId`, so it shows the page's liveblog.
	 *
	 * @param array $options {
	 *     How the scoreboard is set out.
	 *
	 *     @type bool     $stacked     One row per team, its name then its score, instead of one line with the scores in the middle.
	 *     @type string[] $name_size   Font size slugs for the team names: the first one the theme has. Defaults to Large on a theme with a block scale (it has Extra Extra Large), else Normal, as the block's own template does.
	 *     @type string[] $score_size  Font size slugs for the scores.
	 *     @type string[] $dash_size   Font size slugs for the dash between the scores, a size down from them. Not used when stacked.
	 *     @type string[] $status_size Font size slugs for the status line.
	 *     @type string   $gap         Spacing preset slug between a row's parts.
	 *     @type bool     $accent      Set the scores in the theme's accent color.
	 *     @type array    $attributes  The Scoreboard block's own settings, such as a background.
	 * }
	 * @return string Block markup.
	 */
	public static function scoreboard( array $options ): string {
		$options += [
			'stacked'     => false,
			'score_size'  => [ 'xx-large', 'huge' ],
			'dash_size'   => [ 'x-large', 'large' ],
			'status_size' => [ 'x-small', 'small' ],
			'gap'         => '40',
			'accent'      => false,
			'attributes'  => [],
		];

		$sizes  = self::font_size_slugs();
		$size   = static fn( array $slugs ) => current( array_intersect( $slugs, $sizes ) );
		$names  = $options['name_size'] ?? ( in_array( 'xx-large', $sizes, true ) ? [ 'large' ] : [ 'normal' ] );
		$font   = array_filter(
			[
				'className'  => 'use-header-font',
				'fontFamily' => self::heading_font(),
			]
		);
		$name   = static fn( string $align ) => $font + [
			'fontSize' => $size( $names ),
			'style'    => [
				'layout'     => [
					'selfStretch' => 'fill',
					'flexSize'    => null,
				],
				'typography' => [
					'fontWeight' => '700',
					'lineHeight' => '1.2',
					'textAlign'  => $align,
				],
			],
		];
		$score  = $font + [
			'fontSize' => $size( $options['score_size'] ),
			'style'    => array_filter(
				[
					'color'      => $options['accent'] ? [ 'text' => self::ACCENT ] : null,
					'typography' => [
						'fontWeight' => '700',
						'lineHeight' => '1',
					],
				]
			),
		];
		$muted  = current( array_intersect( Layout_Templates::MUTED_TEXT_COLORS, self::color_slugs() ) );
		$status = self::score_part(
			'status',
			__( 'Status Line', 'newspack-rolling-coverage' ),
			[
				'fontSize'  => $size( $options['status_size'] ),
				'textColor' => $muted,
				'style'     => [ 'typography' => [ 'textAlign' => $options['stacked'] ? 'left' : 'center' ] ],
			]
		);
		$row    = static fn( string $label, array $parts ) => self::group(
			[
				'metadata' => [ 'name' => $label ],
				'style'    => [ 'spacing' => [ 'blockGap' => 'var:preset|spacing|' . $options['gap'] ] ],
				'layout'   => [
					'type'              => 'flex',
					'flexWrap'          => 'nowrap',
					'justifyContent'    => $options['stacked'] ? 'space-between' : 'center',
					'verticalAlignment' => 'center',
				],
			],
			$parts
		);

		$team1_name  = self::score_part( 'team1Name', __( 'First Team', 'newspack-rolling-coverage' ), $name( $options['stacked'] ? 'left' : 'right' ) );
		$team1_score = self::score_part( 'team1Score', __( 'First Team Score', 'newspack-rolling-coverage' ), $score );
		$team2_score = self::score_part( 'team2Score', __( 'Second Team Score', 'newspack-rolling-coverage' ), $score );
		$team2_name  = self::score_part( 'team2Name', __( 'Second Team', 'newspack-rolling-coverage' ), $name( 'left' ) );
		$dash        = self::paragraph(
			[
				'metadata'   => [ 'name' => _x( 'Dash', 'the punctuation mark between two scores', 'newspack-rolling-coverage' ) ],
				'className'  => $font['className'] . ' ' . Scoreboard_Block::DASH_CLASS,
				'fontFamily' => $font['fontFamily'] ?? '',
				'fontSize'   => $size( $options['dash_size'] ),
				'style'      => [ 'typography' => [ 'lineHeight' => '1' ] ],
			],
			'–'
		);

		$blocks = $options['stacked']
			? [
				$row( __( 'First Team Row', 'newspack-rolling-coverage' ), [ $team1_name, $team1_score ] ),
				$row( __( 'Second Team Row', 'newspack-rolling-coverage' ), [ $team2_name, $team2_score ] ),
				$status,
			]
			: [
				$row( __( 'Score', 'newspack-rolling-coverage' ), [ $team1_name, $team1_score, $dash, $team2_score, $team2_name ] ),
				$status,
			];

		return serialize_block(
			[
				'blockName'    => Scoreboard_Block::BLOCK_NAME,
				'attrs'        => $options['attributes'],
				'innerBlocks'  => $blocks,
				'innerHTML'    => '',
				'innerContent' => array_fill( 0, count( $blocks ), null ),
			]
		);
	}

	/**
	 * A paragraph bound to one part of the score, with the markup core saves
	 * for its settings.
	 *
	 * @param string $key        One of Scoreboard_Block::KEYS.
	 * @param string $label      Its name in List View.
	 * @param array  $attributes Its settings; empty ones are left out.
	 * @return array Parsed block.
	 */
	private static function score_part( string $key, string $label, array $attributes ): array {
		return self::paragraph(
			[
				'metadata' => [
					'name'     => $label,
					'bindings' => [
						'content' => [
							'source' => Scoreboard_Block::SOURCE_NAME,
							'args'   => [ 'key' => $key ],
						],
					],
				],
			] + $attributes
		);
	}

	/**
	 * A paragraph with the markup core saves for its settings.
	 *
	 * @param array  $attributes Its settings; empty ones are left out.
	 * @param string $content    Its text.
	 * @return array Parsed block.
	 */
	private static function paragraph( array $attributes, string $content = '' ): array {
		$attributes = array_filter( $attributes );
		$typography = $attributes['style']['typography'] ?? [];
		$classes    = array_filter(
			[
				isset( $typography['textAlign'] ) ? 'has-text-align-' . $typography['textAlign'] : '',
				$attributes['className'] ?? '',
				isset( $attributes['textColor'] ) ? 'has-' . _wp_to_kebab_case( $attributes['textColor'] ) . '-color has-text-color' : '',
				! isset( $attributes['textColor'] ) && isset( $attributes['style']['color']['text'] ) ? 'has-text-color' : '',
				isset( $attributes['fontFamily'] ) ? 'has-' . _wp_to_kebab_case( $attributes['fontFamily'] ) . '-font-family' : '',
				isset( $attributes['fontSize'] ) ? 'has-' . _wp_to_kebab_case( $attributes['fontSize'] ) . '-font-size' : '',
			]
		);
		$styles     = array_filter(
			[
				isset( $attributes['style']['color']['text'] ) ? 'color:' . $attributes['style']['color']['text'] : '',
				isset( $typography['fontWeight'] ) ? 'font-weight:' . $typography['fontWeight'] : '',
				isset( $typography['lineHeight'] ) ? 'line-height:' . $typography['lineHeight'] : '',
			]
		);
		$html       = '<p' . ( $classes ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '' )
			. ( $styles ? ' style="' . esc_attr( implode( ';', $styles ) ) . '"' : '' ) . '>' . esc_html( $content ) . '</p>';

		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => $attributes,
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * A group around blocks, with the markup core saves for it.
	 *
	 * @param array   $attributes The group's settings.
	 * @param array[] $blocks     Parsed blocks.
	 * @return array Parsed block.
	 */
	private static function group( array $attributes, array $blocks ): array {
		$between = array_fill( 0, count( $blocks ), null );

		return [
			'blockName'    => 'core/group',
			'attrs'        => $attributes,
			'innerBlocks'  => $blocks,
			'innerHTML'    => '<div class="wp-block-group"></div>',
			'innerContent' => array_merge( [ '<div class="wp-block-group">' ], $between, [ '</div>' ] ),
		];
	}

	/**
	 * The font family preset headings use: the one the site's styles give
	 * headings, else body text, when it is a preset, as `headingFontFamily()`
	 * picks it in the editor.
	 *
	 * @return string The preset's slug, or empty without one.
	 */
	private static function heading_font(): string {
		$families = [];

		foreach ( (array) wp_get_global_settings( [ 'typography', 'fontFamilies' ] ) as $origin ) {
			foreach ( array_column( is_array( $origin ) ? $origin : [], 'slug' ) as $slug ) {
				$families[ _wp_to_kebab_case( $slug ) ] = $slug;
			}
		}

		foreach ( [ [ 'elements', 'heading', 'typography', 'fontFamily' ], [ 'typography', 'fontFamily' ] ] as $path ) {
			$value = wp_get_global_styles( $path );

			if ( is_string( $value ) && preg_match( '/font-family(?:--|\|)([a-z0-9-]+)/', $value, $match ) && isset( $families[ $match[1] ] ) ) {
				return $families[ $match[1] ];
			}
		}

		return '';
	}

	/**
	 * The slugs of the site's palette colors.
	 *
	 * @return string[]
	 */
	private static function color_slugs(): array {
		$slugs = [];

		foreach ( (array) wp_get_global_settings( [ 'color', 'palette' ] ) as $origin ) {
			$slugs = array_merge( $slugs, array_column( is_array( $origin ) ? $origin : [], 'slug' ) );
		}

		return $slugs;
	}

	/**
	 * What the inserter shows for each pattern.
	 *
	 * @return array<string, array>
	 */
	private static function definitions(): array {
		return [
			'live-page'              => [
				'title'         => __( 'Live page', 'newspack-rolling-coverage' ),
				'description'   => __( 'A status badge with the time of the last update, an introduction and the Clock layout.', 'newspack-rolling-coverage' ),
				'keywords'      => [ 'liveblog', 'live', 'clock', 'timeline', 'status' ],
				'viewportWidth' => 1200,
			],
			'live-page-latest-first' => [
				'title'         => __( 'Live page, latest first', 'newspack-rolling-coverage' ),
				'description'   => __( 'A status badge with the time of the last update and a Follow button on one line, then every entry in the Stream layout.', 'newspack-rolling-coverage' ),
				'keywords'      => [ 'liveblog', 'live', 'latest', 'newest', 'stream' ],
				'viewportWidth' => 1200,
			],
			'live-page-side-column'  => [
				'title'         => __( 'Live page with side column', 'newspack-rolling-coverage' ),
				'description'   => __( 'Entries under the update timer, beside a column with the status badge and a Follow button.', 'newspack-rolling-coverage' ),
				'keywords'      => [ 'liveblog', 'live', 'sidebar', 'columns', 'follow', 'timer' ],
				'viewportWidth' => 1200,
			],
			'match-side'             => [
				'title'         => __( 'Match: scoreboard beside the feed', 'newspack-rolling-coverage' ),
				'description'   => __( 'The score in a column beside the Minute layout.', 'newspack-rolling-coverage' ),
				'keywords'      => [ 'liveblog', 'match', 'score', 'scoreboard', 'sport' ],
				'viewportWidth' => 1200,
			],
			'match-centered'         => [
				'title'         => __( 'Match: centered scoreline', 'newspack-rolling-coverage' ),
				'description'   => __( 'A large score centered above the Minute layout.', 'newspack-rolling-coverage' ),
				'keywords'      => [ 'liveblog', 'match', 'score', 'scoreboard', 'sport', 'centered' ],
				'viewportWidth' => 1200,
			],
			'breaking-news'          => [
				'title'         => __( 'Breaking news', 'newspack-rolling-coverage' ),
				'description'   => __( 'An article with the latest update in a bar above the text, which goes away when the liveblog ends.', 'newspack-rolling-coverage' ),
				'keywords'      => [ 'liveblog', 'breaking', 'article', 'flash', 'latest' ],
				'viewportWidth' => 1200,
			],
			'follow-page'            => [
				'title'         => __( 'Follow page', 'newspack-rolling-coverage' ),
				'description'   => __( 'Asks readers to follow a liveblog, explains how alerts work, and shows its latest updates.', 'newspack-rolling-coverage' ),
				'keywords'      => [ 'liveblog', 'follow', 'alerts', 'notifications', 'push', 'digest' ],
				'viewportWidth' => 1200,
			],
			'follow-band'            => [
				'title'         => __( 'Follow band', 'newspack-rolling-coverage' ),
				'description'   => __( 'A full-width band with a Follow button, above the five latest updates.', 'newspack-rolling-coverage' ),
				'keywords'      => [ 'liveblog', 'follow', 'banner', 'band', 'wire', 'latest' ],
				'viewportWidth' => 1200,
			],
			'every-live-story'       => [
				'title'         => __( 'Every live story', 'newspack-rolling-coverage' ),
				'description'   => __( 'A list of liveblogs, each with its status and a Follow button.', 'newspack-rolling-coverage' ),
				'keywords'      => [ 'liveblog', 'follow', 'list', 'hub', 'status', 'all' ],
				'viewportWidth' => 1200,
			],
		];
	}
}
