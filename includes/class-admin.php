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

	const MENU_SLUG     = 'rolling-coverage';
	const AI_MENU_SLUG  = 'rolling-coverage-ai';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
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
			[ __CLASS__, 'render_page' ],
			'dashicons-megaphone',
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Views', 'newspack-rolling-coverage' ),
			__( 'Views', 'newspack-rolling-coverage' ),
			'edit_posts',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'AI', 'newspack-rolling-coverage' ),
			__( 'AI', 'newspack-rolling-coverage' ),
			'manage_options',
			self::AI_MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Render the admin page container.
	 */
	public static function render_page(): void {
		printf(
			'<div id="%s"></div>',
			esc_attr( 'newspack-rolling-coverage-admin' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		$valid_hooks = [
			'toplevel_page_' . self::MENU_SLUG,
			'rolling-coverage_page_' . self::AI_MENU_SLUG,
		];

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
			$asset['dependencies'] ?? [],
			$asset['version'],
			[ 'in_footer' => true ]
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
	 * @return array Script data array.
	 */
	private static function get_script_data( string $hook_suffix = '' ): array {
		$page_hook_map = [
			'toplevel_page_' . self::MENU_SLUG            => '/coverages',
			'rolling-coverage_page_' . self::AI_MENU_SLUG => '/ai',
		];

		return [
			'page'              => $page_hook_map[ $hook_suffix ] ?? '/coverages',
			'restBase'          => [
				'coverages' => Taxonomy::REST_BASE,
				'entries'   => Post_Type::REST_BASE,
			],
			'restBaseUrls'      => [
				'coverages'     => esc_url_raw( rest_url( 'wp/v2/' . Taxonomy::REST_BASE ) ),
				'entries'       => esc_url_raw( rest_url( 'wp/v2/' . Post_Type::REST_BASE ) ),
				'breakout'      => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/entries' ) ),
				'restNamespace' => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/' ) ),
				'aiSettings'    => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . AI_Settings::REST_ROUTE ) ),
				'posts'         => esc_url_raw( rest_url( 'wp/v2/posts' ) ),
			],
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'capabilities'      => [
				'canEditPosts'     => current_user_can( 'edit_posts' ),
				'canManageTerms'   => current_user_can( 'manage_categories' ),
				'canManageOptions' => current_user_can( 'manage_options' ),
			],
			'adminUrls'         => [
				'editEntry' => admin_url( 'post.php?action=edit' ),
				'newEntry'  => admin_url( 'post-new.php?post_type=' . Post_Type::CPT_SLUG ),
				'editTerm'  => admin_url( 'term.php?taxonomy=' . Taxonomy::TAXONOMY_SLUG ),
			],
			'postType'          => Post_Type::CPT_SLUG,
			'taxonomy'          => Taxonomy::TAXONOMY_SLUG,
			'taxMeta'           => [
				'statusKey' => Taxonomy::STATUS_META_KEY,
			],
			'aiSettings'        => AI_Settings::get_all(),
			'aiDefaultSettings' => AI_Settings::get_defaults(),
			'aiAvailable'       => AI_Service::is_available(),
		];
	}
}
