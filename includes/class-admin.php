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
	const AI_MENU_SLUG         = 'rolling-coverage-ai';
	const CONNECTION_MENU_SLUG = 'rolling-coverage-connection';

	/**
	 * Hook suffixes for plugin admin pages, keyed by SPA route.
	 *
	 * Captured from add_menu_page()/add_submenu_page() return values
	 * so comparisons use the actual runtime value, not a reconstructed
	 * (language-sensitive) string.
	 *
	 * @var string[]
	 */
	private static $page_hooks = [];

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter(
			'should_load_block_editor_scripts_and_styles',
			array( __CLASS__, 'filter_should_load_block_editor_scripts' )
		);
	}

	/**
	 * Register admin menu pages, capturing each hook suffix.
	 */
	public static function add_menu_page(): void {
		self::$page_hooks['coverages'] = add_menu_page(
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
			__( 'All Rolling Coverages', 'newspack-rolling-coverage' ),
			__( 'All Rolling Coverages', 'newspack-rolling-coverage' ),
			'edit_posts',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);

		self::$page_hooks['connection'] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Slack Connection', 'newspack-rolling-coverage' ),
			__( 'Slack Connection', 'newspack-rolling-coverage' ),
			'manage_options',
			self::CONNECTION_MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);

		self::$page_hooks['ai'] = add_submenu_page(
			self::MENU_SLUG,
			__( 'AI', 'newspack-rolling-coverage' ),
			__( 'AI', 'newspack-rolling-coverage' ),
			'edit_others_posts',
			self::AI_MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Mark our admin page as a block editor screen.
	 *
	 * @param bool $is_block_editor Whether the current screen is a block editor.
	 * @return bool
	 */
	public static function filter_should_load_block_editor_scripts( $is_block_editor ) {
		$screen = get_current_screen();

		if ( $screen && isset( self::$page_hooks['coverages'] ) && self::$page_hooks['coverages'] === $screen->id ) {
			return true;
		}

		return $is_block_editor;
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
		if ( ! in_array( $hook_suffix, self::$page_hooks, true ) ) {
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

		wp_enqueue_script( 'wp-edit-post' );
		wp_enqueue_style( 'wp-edit-post' );

		// Load editor scripts for all registered blocks for third-party blocks.
		wp_enqueue_registered_block_scripts_and_styles();

		// Bootstrap server-side block definitions into the JS block store.
		wp_add_inline_script(
			'wp-blocks',
			'wp.blocks.unstable__bootstrapServerSideBlockDefinitions('
			. wp_json_encode( get_block_editor_server_block_settings() )
			. ');'
		);

		// Prevent newspack-plugin's editor UI scripts from loading in our
		// custom EditorProvider context, where they cause a forwardRef crash.
		remove_action(
			'enqueue_block_editor_assets',
			[ 'Newspack\Blocks', 'enqueue_block_editor_assets' ]
		);

		do_action( 'enqueue_block_editor_assets' );

		// Re-add newspack-plugin's editor UI scripts.
		add_action(
			'enqueue_block_editor_assets',
			[ 'Newspack\Blocks', 'enqueue_block_editor_assets' ]
		);

		// Media library for Image/Gallery blocks.
		wp_enqueue_media();

		// Classic editor (TinyMCE) for the Freeform/Classic block.
		wp_enqueue_editor();
		wp_tinymce_inline_scripts();

		wp_localize_script(
			'newspack-rolling-coverage-admin',
			'newspackRollingCoverageAdmin',
			self::get_script_data( $hook_suffix )
		);
	}

	/**
	 * Get localized script data.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return array<string, mixed> Script data array.
	 */
	private static function get_script_data( string $hook_suffix = '' ): array {
		$route = array_search( $hook_suffix, self::$page_hooks, true );
		$page  = $route ? '/' . $route : '/coverages';

		$block_editor_settings = get_block_editor_settings(
			array(),
			new \WP_Block_Editor_Context()
		);

		return array(
			'page'                => $page,
			'restBase'            => array(
				'coverages' => Taxonomy::REST_BASE,
				'entries'   => Post_Type::REST_BASE,
				'slack'     => Slack::REST_NAMESPACE,
			),
			'restBaseUrls'        => array(
				'coverages'     => esc_url_raw( rest_url( 'wp/v2/' . Taxonomy::REST_BASE ) ),
				'entries'       => esc_url_raw( rest_url( 'wp/v2/' . Post_Type::REST_BASE ) ),
				'slack'         => esc_url_raw( rest_url( Slack::REST_NAMESPACE . '/' ) ),
				'breakout'      => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/entries' ) ),
				'aiSettings'    => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . AI_Settings::REST_ROUTE ) ),
				'restNamespace' => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/' ) ),
				'posts'         => esc_url_raw( rest_url( 'wp/v2/posts' ) ),
			),
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'capabilities'        => array(
				'canEditPosts'        => current_user_can( 'edit_posts' ),
				'canManageTerms'      => current_user_can( 'manage_categories' ),
				'canManageAiSettings' => current_user_can( 'edit_others_posts' ),
			),
			'adminUrls'           => array(
				'editEntry' => admin_url( 'post.php?action=edit' ),
				'newEntry'  => admin_url( 'post-new.php?post_type=' . Post_Type::CPT_SLUG ),
				'editTerm'  => admin_url( 'term.php?taxonomy=' . Taxonomy::TAXONOMY_SLUG ),
				'editUser'  => admin_url( 'user-edit.php' ),
			),
			'postType'            => Post_Type::CPT_SLUG,
			'taxonomy'            => Taxonomy::TAXONOMY_SLUG,
			'taxMeta'             => array(
				'statusKey' => Taxonomy::STATUS_META_KEY,
			),
			'slack'               => array(
				'isConfigured' => Slack_Config::is_configured(),
			),
			'availableAdapters'   => array(
				'slack' => __( 'Slack', 'newspack-rolling-coverage' ),
			),
			'blockEditorSettings' => $block_editor_settings,
			'aiSettings'          => AI_Settings::get_all(),
			'aiDefaultSettings'   => AI_Settings::get_defaults(),
			'aiAvailable'         => AI_Service::is_available(),
			'aiMaxPromptLength'   => AI_Service::MAX_PROMPT_LENGTH,
		);
	}
}
