<?php
/**
 * Newspack Rolling Coverage plugin initialization.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Class to handle the plugin initialization.
 */
class Initializer {

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		// Trigger activation/deactivation functionalities.
		register_activation_hook( NEWSPACK_ROLLING_COVERAGE_PLUGIN_FILE, [ __CLASS__, 'activation_hook' ] );
		register_deactivation_hook( NEWSPACK_ROLLING_COVERAGE_PLUGIN_FILE, [ __CLASS__, 'deactivation_hook' ] );

		self::includes();
	}

	/**
	 * Load feature classes into the WordPress lifecycle.
	 */
	private static function includes() {
		Post_Type::init();
		Taxonomy::init();
	}

	/**
	 * Runs on plugin activation.
	 */
	public static function activation_hook() {

		/**
		 * Fires
		 */
		do_action( 'rolling_coverage_activation' );
	}

	/**
	 * Runs on plugin deactivation to clean up redundant rewrite rules.
	 */
	public static function deactivation_hook() {
	
		/**
		 * Fires when Rolling Coverage plugin is deactivated
		 */
		do_action( 'rolling_coverage_deactivation' );
	}
}
