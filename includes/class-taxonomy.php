<?php
/**
 * Register the rolling_coverage taxonomy.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration of the rolling_coverage taxonomy and its termmeta.
 */
class Taxonomy {

	// Config constants.
	const TAXONOMY_SLUG   = 'rolling_coverage';
	const REST_BASE       = 'rolling-coverage';

	// Related constants.
	const STATUS_META_KEY = 'rolling_coverage_status';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
	}

	/**
	 * Register the taxonomy, termmeta and any other taxonomy-related functionality.
	 */
	public static function register() {
		register_taxonomy(
			self::TAXONOMY_SLUG,
			Post_Type::CPT_SLUG,
			[
				'labels'             => [
					'name'          => __( 'Rolling Coverage', 'newspack-rolling-coverage' ),
					'singular_name' => __( 'Rolling Coverage', 'newspack-rolling-coverage' ),
				],
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => true,
				'rest_base'          => self::REST_BASE,
				'hierarchical'       => false,
				'rewrite'            => false,
				'query_var'          => true,
				'meta_box_cb'        => false,
			]
		);

		// Tracks the status of the blogs (active, paused, archived).
		register_term_meta(
			self::TAXONOMY_SLUG,
			self::STATUS_META_KEY,
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => 'active',
			]
		);
	}
}
