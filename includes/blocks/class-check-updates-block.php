<?php
/**
 * Check for Updates Gutenberg block: registration and SSR.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `newspack-rolling-coverage/check-updates` block, which wraps
 * a core button readers press to load a coverage's new entries. A Rolling
 * Coverage block whose layout holds it checks for new entries only then
 * (see Rolling_Coverage_Block::checks_on_request()); the feed's view script
 * runs the check. Anywhere else it renders nothing.
 */
class Check_Updates_Block {

	// Block name, as registered in block.json.
	const BLOCK_NAME = 'newspack-rolling-coverage/check-updates';

	// The class the feed's view script finds the block by, whatever its supports add.
	const CONTROL_CLASS = 'newspack-rolling-coverage-check-updates';

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
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/check-updates',
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
	 * view script to show, so a page without the script never shows a button
	 * that does nothing. Outside a Rolling Coverage block, where no coverage
	 * reaches it, nothing.
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

		return sprintf(
			'<div %s hidden>%s</div>',
			get_block_wrapper_attributes( [ 'class' => self::CONTROL_CLASS ] ),
			$content
		);
	}
}
