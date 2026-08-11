<?php
/**
 * Newspack Rolling Coverage plugin initialization.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

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
		Slack::init();
		Breakout::init();
		Social_Sharing::init();
		Rolling_Coverage_Block::init();
		Breakout_Post_Link_Block::init();
		Share_Block::init();
		Deep_Link_CTA_Block::init();
		Ads::init();
		AI_Service::init();
		AI_Settings::init();
		Abilities::init();

		// Admin interface (only load in admin context).
		if ( is_admin() ) {
			Admin::init();
		}
	}

	/**
	 * Runs on plugin activation.
	 */
	public static function activation_hook() {
		// Register the post type and taxonomy so rewrite rules are available.
		Post_Type::register();
		Taxonomy::register();

		// Flush rewrite rules to ensure the new post type and taxonomy are available.
		flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules

		/**
		 * Action to hook into when Rolling Coverage plugin is activated.
		 */
		do_action( 'rolling_coverage_activation' );
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivation_hook() {
		// Flush rewrite rules to ensure the new post type and taxonomy are removed.
		flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules

		/**
		 * Action to hook into when Rolling Coverage plugin is deactivated.
		 */
		do_action( 'rolling_coverage_deactivation' );
	}
}
