<?php
/**
 * Update Timer Gutenberg block: registration and SSR.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;
use WP_Block_Type;
use WP_Block_Type_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `newspack-rolling-coverage/update-timer` block, which counts
 * down to a Rolling Coverage feed's next check for new entries and shows
 * what the last one found. The server picks the coverage, as the Coverage
 * Status block does, and renders the block hidden; its view script shows it
 * while a feed for that coverage on the page is counting down.
 */
class Update_Timer_Block {

	// Block name, as registered in block.json.
	const BLOCK_NAME = 'newspack-rolling-coverage/update-timer';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'localize_editor_config' ] );
	}

	/**
	 * Registers the block type.
	 */
	public static function register_block(): void {
		register_block_type(
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/update-timer',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Gives the editor script what its Coverage panel needs.
	 */
	public static function localize_editor_config(): void {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK_NAME );

		if ( ! $block_type instanceof WP_Block_Type ) {
			return;
		}

		foreach ( $block_type->editor_script_handles as $handle ) {
			wp_localize_script(
				$handle,
				'newspackUpdateTimerBlock',
				[
					'minPollInterval'  => Rolling_Coverage_Block::get_min_poll_interval(),
					'sourceEntryField' => Breakout::BREAKOUT_SOURCE_ENTRY_FIELD,
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
	 * @return string Rendered HTML, or '' where it could never count down.
	 */
	public static function render_block( array $attributes, string $content, WP_Block $block ): string {
		if ( is_feed() || Lite_Feed::is_lite_render() ) {
			return '';
		}

		$coverage_id = Page_Coverages::coverage_for_block( $block, (int) ( $attributes['coverageId'] ?? 0 ) );

		if ( ! $coverage_id || Taxonomy::STATUS_ARCHIVED === Rolling_Coverage_Block::coverage_status( $coverage_id ) ) {
			return '';
		}

		return sprintf(
			'<div %s hidden><svg class="newspack-rolling-coverage-update-timer__ring" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><circle cx="9" cy="9" r="8.25" pathLength="100" stroke-width="1.5"></circle></svg><span class="newspack-rolling-coverage-update-timer__text"></span></div>',
			get_block_wrapper_attributes( [ 'data-coverage-id' => $coverage_id ] )
		);
	}
}
