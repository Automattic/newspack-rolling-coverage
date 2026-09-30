<?php
/**
 * Inserter category for the plugin's blocks.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the blocks in the Newspack category on Newspack sites and in their
 * own category on standalone ones.
 *
 * The other Newspack plugins add the Newspack category from scripts that
 * load after the block scripts, so it is registered here, server-side, where
 * the editor has it before any block registers.
 */
class Block_Category {

	const NEWSPACK_SLUG = 'newspack';

	const FALLBACK_SLUG = 'rolling-coverage';

	/**
	 * Constants defined by the Newspack plugins that show the Newspack category.
	 */
	const NEWSPACK_PLUGIN_CONSTANTS = [
		'NEWSPACK_PLUGIN_FILE',
		'NEWSPACK_BLOCKS__PLUGIN_FILE',
		'NEWSPACK_ADS_PLUGIN_FILE',
		'NEWSPACK_LISTINGS_PLUGIN_FILE',
	];

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_filter( 'block_categories_all', [ __CLASS__, 'add_category' ] );
	}

	/**
	 * The category slug the blocks register under.
	 *
	 * @return string
	 */
	public static function get_slug(): string {
		foreach ( self::NEWSPACK_PLUGIN_CONSTANTS as $constant ) {
			if ( defined( $constant ) ) {
				return self::NEWSPACK_SLUG;
			}
		}

		return self::FALLBACK_SLUG;
	}

	/**
	 * Add the blocks' category unless it's already registered.
	 *
	 * @param array $categories Registered block categories.
	 * @return array
	 */
	public static function add_category( $categories ): array {
		$categories = is_array( $categories ) ? $categories : [];
		$slug       = self::get_slug();

		if ( in_array( $slug, wp_list_pluck( $categories, 'slug' ), true ) ) {
			return $categories;
		}

		$categories[] = [
			'slug'  => $slug,
			'title' => self::NEWSPACK_SLUG === $slug ? 'Newspack' : 'Rolling Coverage',
			'icon'  => null,
		];

		return $categories;
	}
}
