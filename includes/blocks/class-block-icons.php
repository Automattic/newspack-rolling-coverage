<?php
/**
 * Icons for core's Icon block.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's icons so core's Icon block can show them in the
 * entry template, e.g. the pin on pinned entries, and so the share button
 * can show the link icon.
 */
class Block_Icons {

	const COLLECTION = 'newspack-rolling-coverage';

	const PIN = self::COLLECTION . '/pin-small';

	const LINK = self::COLLECTION . '/link';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_icons' ] );
	}

	/**
	 * Register the icon collection and its icons.
	 */
	public static function register_icons(): void {
		if ( ! function_exists( 'wp_register_icon_collection' ) || ! function_exists( 'wp_register_icon' ) ) {
			return;
		}

		if ( ! wp_register_icon_collection( self::COLLECTION, [ 'label' => 'Rolling Coverage' ] ) ) {
			return;
		}

		wp_register_icon(
			self::PIN,
			[
				'label'     => __( 'Pin', 'newspack-rolling-coverage' ),
				'file_path' => NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'assets/icons/pin-small.svg',
			]
		);

		wp_register_icon(
			self::LINK,
			[
				'label'     => __( 'Link', 'newspack-rolling-coverage' ),
				'file_path' => NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'assets/icons/link.svg',
			]
		);
	}
}
