<?php
/**
 * Load More Gutenberg block: registration and SSR.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;
use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `newspack-rolling-coverage/load-more` block, which wraps a
 * core button readers press to load a coverage's older entries. A Rolling
 * Coverage block set to load older entries with a button shows it where its
 * layout places it, rather than its own button after the entries (see
 * Rolling_Coverage_Block::render_coverage_blocks()); the feed's view script
 * loads the entries. Anywhere else it renders nothing.
 */
class Load_More_Block {

	// Block name, as registered in block.json.
	const BLOCK_NAME = 'newspack-rolling-coverage/load-more';

	// The class the feed's view script finds the button by, whatever the block's supports add.
	const CONTROL_CLASS = 'newspack-rolling-coverage-load-more';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Registers the block type.
	 */
	public static function register_block(): void {
		register_block_type(
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/load-more',
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
			'render_callback' => [ __CLASS__, 'render_block' ],
		];
	}

	/**
	 * Server-side render callback: the block's button, hidden for the feed's
	 * view script to show while older entries remain, so a page without the
	 * script never shows a button that does nothing. Where the inner blocks
	 * leave no <button> to press, as when the label is left empty (core then
	 * renders nothing for it), one reading "Load more" goes inside the Buttons
	 * block where it rendered, keeping its justification and margin, or on its
	 * own, or readers would have no way to load older entries. Outside a Rolling Coverage block, where no
	 * coverage reaches it, nothing.
	 *
	 * @param array    $attributes Block attributes (unused).
	 * @param string   $content    Rendered inner blocks.
	 * @param WP_Block $block      Block instance.
	 * @return string
	 */
	public static function render_block( array $attributes, string $content, WP_Block $block ): string {
		if ( is_feed() || empty( $block->context[ Entry_Bindings::COVERAGE_ID_CONTEXT ] ) ) {
			return '';
		}

		$processor = new WP_HTML_Tag_Processor( $content );

		if ( ! $processor->next_tag( 'button' ) ) {
			$button  = sprintf(
				'<button type="button" class="wp-element-button wp-block-button__link">%s</button>',
				esc_html__( 'Load more', 'newspack-rolling-coverage' )
			);
			$wrapped = preg_replace( '/<div\b[^>]*\bwp-block-buttons\b[^>]*>/', '$0' . $button, $content, 1, $count );
			$content = $count ? $wrapped : $button;
		}

		return sprintf(
			'<div %s hidden>%s</div>',
			get_block_wrapper_attributes( [ 'class' => self::CONTROL_CLASS ] ),
			$content
		);
	}
}
