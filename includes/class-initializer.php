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
		Ads::init();
		Rolling_Coverage_Block::init();
		Breakout_Post_Link_Block::init();
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

		/**
		 * Action to hook into when Rolling Coverage plugin is activated.
		 */
		do_action( 'rolling_coverage_activation' );
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivation_hook() {

		// Clear any pending orphaned entry cleanup cron events.
		$timestamp = wp_next_scheduled( Post_Type::CLEANUP_CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, Post_Type::CLEANUP_CRON_HOOK );
		}

		wp_clear_scheduled_hook( Post_Type::CLEANUP_CRON_HOOK );

		/**
		 * Action to hook into when Rolling Coverage plugin is deactivated.
		 */
		do_action( 'rolling_coverage_deactivation' );
	}
}
