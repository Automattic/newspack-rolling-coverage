<?php
/**
 * Ready-made pages built around the Rolling Coverage blocks.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Rolling Coverage pages" block pattern category and the page
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
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ], 11 );
	}

	/**
	 * Registers the category and the patterns.
	 */
	public static function register() {
		register_block_pattern_category(
			self::CATEGORY,
			[
				'label'       => __( 'Rolling Coverage pages', 'newspack-rolling-coverage' ),
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
	 * A Follow Coverage block with its button, as the block builds it. It has
	 * no `coverageId`, so it follows the liveblog of the page, or the one it
	 * is set to once someone picks Custom.
	 *
	 * @return string Block markup.
	 */
	public static function follow(): string {
		$text = __( 'Follow', 'newspack-rolling-coverage' );
		$name  = trim( (string) wp_json_encode( $text ), '"' );
		$label = esc_html( $text );

		return '<!-- wp:newspack-rolling-coverage/coverage-follow -->' . "\n"
			. '<!-- wp:buttons {"metadata":{"name":"' . $name . '"}} -->' . "\n"
			. '<div class="wp-block-buttons"><!-- wp:button {"tagName":"button","metadata":{"name":"' . $name . '","bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"followTag"}}}}} -->' . "\n"
			. '<div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">' . $label . '</button></div>' . "\n"
			. '<!-- /wp:button --></div>' . "\n"
			. '<!-- /wp:buttons -->' . "\n"
			. '<!-- /wp:newspack-rolling-coverage/coverage-follow -->';
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
				'description'   => __( 'The newest entry large at the top, with every entry in the Stream layout below it.', 'newspack-rolling-coverage' ),
				'keywords'      => [ 'liveblog', 'live', 'latest', 'newest', 'stream' ],
				'viewportWidth' => 1200,
			],
			'live-page-side-column'  => [
				'title'         => __( 'Live page with side column', 'newspack-rolling-coverage' ),
				'description'   => __( 'Entries beside a column with the status badge, a Follow button and the update timer.', 'newspack-rolling-coverage' ),
				'keywords'      => [ 'liveblog', 'live', 'sidebar', 'columns', 'follow', 'timer' ],
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
