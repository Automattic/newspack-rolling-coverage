<?php
/**
 * Admin interface for Rolling Coverage management.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the admin menu page and asset enqueuing.
 */
class Admin {

	const MENU_SLUG            = 'rolling-coverage';
	const CONNECTION_MENU_SLUG = 'rolling-coverage-connection';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register the admin menu page.
	 */
	public static function add_menu_page(): void {
		add_menu_page(
			__( 'Rolling Coverage', 'newspack-rolling-coverage' ),
			__( 'Rolling Coverage', 'newspack-rolling-coverage' ),
			'edit_posts',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-megaphone',
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Views', 'newspack-rolling-coverage' ),
			__( 'Views', 'newspack-rolling-coverage' ),
			'edit_posts',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Connection', 'newspack-rolling-coverage' ),
			__( 'Connection', 'newspack-rolling-coverage' ),
			'manage_options',
			self::CONNECTION_MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the admin page container.
	 */
	public static function render_page(): void {
		printf(
			'<div id="%s"></div>',
			esc_attr( 'newspack-rolling-coverage-root' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		$valid_hooks = array(
			'toplevel_page_' . self::MENU_SLUG,
			'rolling-coverage_page_' . self::CONNECTION_MENU_SLUG,
		);

		if ( ! in_array( $hook_suffix, $valid_hooks, true ) ) {
			return;
		}

		$asset_file = NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/admin.asset.php';
		
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'newspack-rolling-coverage-admin',
			NEWSPACK_ROLLING_COVERAGE_URL . 'dist/admin.js',
			$asset['dependencies'] ?? array(),
			$asset['version'],
			array( 'in_footer' => true )
		);

		wp_enqueue_style(
			'newspack-rolling-coverage-admin',
			NEWSPACK_ROLLING_COVERAGE_URL . 'dist/admin.css',
			[ 'wp-components' ],
			$asset['version']
		);

		wp_localize_script(
			'newspack-rolling-coverage-admin',
			'newspackRollingCoverageAdmin',
			self::get_script_data( $hook_suffix )
		);
	}

	/**
	 * Get localized script data - configuration constants only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return array<string, mixed> Script data array.
	 */
	private static function get_script_data( string $hook_suffix = '' ): array {
		// Extensible multi-page react entry point setup.
		$page_hook_map = [
			'toplevel_page_' . self::MENU_SLUG => '/coverages',
			'rolling-coverage_page_' . self::CONNECTION_MENU_SLUG => '/connection',
		];

		return array(
			'page'              => $page_hook_map[ $hook_suffix ] ?? '/coverages',
			'restBase'          => array(
				'coverages' => Taxonomy::REST_BASE,
				'entries'   => Post_Type::REST_BASE,
				'slack'     => Slack::REST_NAMESPACE,
			),
			'restBaseUrls'      => array(
				'coverages' => esc_url_raw( rest_url( 'wp/v2/' . Taxonomy::REST_BASE ) ),
				'entries'   => esc_url_raw( rest_url( 'wp/v2/' . Post_Type::REST_BASE ) ),
				'slack'     => esc_url_raw( rest_url( Slack::REST_NAMESPACE . '/' ) ),
				'breakout'  => esc_url_raw( rest_url( Breakout::REST_NAMESPACE . '/entries' ) ),
			),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'capabilities'      => array(
				'canEditPosts'   => current_user_can( 'edit_posts' ),
				'canManageTerms' => current_user_can( 'manage_categories' ),
			),
			'adminUrls'         => array(
				'editEntry' => admin_url( 'post.php?action=edit' ),
				'newEntry'  => admin_url( 'post-new.php?post_type=' . Post_Type::CPT_SLUG ),
				'editTerm'  => admin_url( 'term.php?taxonomy=' . Taxonomy::TAXONOMY_SLUG ),
				'editUser'  => admin_url( 'user-edit.php' ),
			),
			'postType'          => Post_Type::CPT_SLUG,
			'taxonomy'          => Taxonomy::TAXONOMY_SLUG,
			'taxMeta'           => array(
				'statusKey' => Taxonomy::STATUS_META_KEY,
			),
			'slack'             => array(
				'isConfigured' => Slack_Config::is_configured(),
			),
			'availableAdapters' => array(
				'slack' => __( 'Slack', 'newspack-rolling-coverage' ),
			),
		);
	}
}
