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
	const SCREEN_BODY_CLASS    = 'newspack-rolling-coverage-admin-screen';

	/**
	 * Menu icon: the `activity` glyph from newspack-icons.
	 */
	const MENU_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="#a7aaad" aria-hidden="true" focusable="false"><path d="M10.2656 4.00001C10.6062 4.0071 10.8996 4.24313 10.9795 4.57423L13.7861 16.2022L15.0254 11.5567L15.0674 11.4385C15.1876 11.1748 15.4527 11 15.75 11H19.25C19.6642 11 20 11.3358 20 11.75C20 12.1642 19.6642 12.5 19.25 12.5H16.3262L14.4746 19.4434C14.3862 19.7748 14.0842 20.004 13.7412 20C13.3981 19.9959 13.101 19.7593 13.0205 19.4258L10.1885 7.69337L8.9707 11.9561C8.87867 12.278 8.58482 12.5 8.25 12.5H4.75C4.33579 12.5 4 12.1642 4 11.75C4 11.3358 4.33579 11 4.75 11H7.68457L9.5293 4.54396L9.57324 4.42579C9.69857 4.16391 9.96776 3.99384 10.2656 4.00001Z"/></svg>';

	/**
	 * Top-level menu item this plugin's menu sits directly below.
	 */
	const MENU_ANCHOR = 'edit.php?post_type=newspack_nl_cpt';

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
	 * What WordPress appends to the page title in the admin tab, e.g.
	 * " ‹ Site — WordPress". The admin script prefixes it with the current
	 * view's label, so the tab follows the hash route.
	 *
	 * @var string
	 */
	private static $admin_title_suffix = '';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'add_body_class' ) );
		add_filter( 'admin_title', array( __CLASS__, 'capture_admin_title_suffix' ), PHP_INT_MAX, 2 );
		add_filter( 'custom_menu_order', '__return_true' );
		// After Newspack's own wizard ordering, which runs at 11.
		add_filter( 'menu_order', array( __CLASS__, 'menu_order' ), 12 );
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
			'data:image/svg+xml;base64,' . base64_encode( self::MENU_ICON_SVG ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Coverages', 'newspack-rolling-coverage' ),
			__( 'All Coverages', 'newspack-rolling-coverage' ),
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
	 * Place the menu directly below Newsletters when it is present.
	 *
	 * @param string[] $menu_order Ordered top-level menu slugs.
	 * @return string[]
	 */
	public static function menu_order( $menu_order ): array {
		$anchor = array_search( self::MENU_ANCHOR, $menu_order, true );
		$own    = array_search( self::MENU_SLUG, $menu_order, true );
		if ( false === $anchor || false === $own ) {
			return $menu_order;
		}
		array_splice( $menu_order, $own, 1 );
		$anchor = array_search( self::MENU_ANCHOR, $menu_order, true );
		array_splice( $menu_order, $anchor + 1, 0, self::MENU_SLUG );
		return $menu_order;
	}

	/**
	 * Record the admin title's suffix, which follows the screen title.
	 *
	 * @param string $admin_title Full admin title.
	 * @param string $title       Screen title.
	 * @return string Unchanged admin title.
	 */
	public static function capture_admin_title_suffix( $admin_title, $title ) {
		$screen_title = wp_strip_all_tags( (string) $title );
		if ( '' !== $screen_title && str_starts_with( (string) $admin_title, $screen_title ) ) {
			self::$admin_title_suffix = html_entity_decode( substr( $admin_title, strlen( $screen_title ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		}
		return $admin_title;
	}

	/**
	 * Flag the plugin's admin screens, and whether they run alongside
	 * newspack-plugin, which brings the Newspack branding with it.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public static function add_body_class( string $classes ): string {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, self::$page_hooks, true ) ) {
			$classes .= ' ' . self::SCREEN_BODY_CLASS;
			if ( class_exists( '\Newspack\Newspack' ) ) {
				$classes .= ' ' . self::SCREEN_BODY_CLASS . '--bundled';
			}
		}
		return $classes;
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

		wp_set_script_translations(
			'newspack-rolling-coverage-admin',
			'newspack-rolling-coverage',
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'languages'
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
			'adminTitleSuffix'    => self::$admin_title_suffix,
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
				'entriesView'   => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/coverages' ) ),
				'aiSettings'    => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . AI_Settings::REST_ROUTE ) ),
				'restNamespace' => esc_url_raw( rest_url( NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/' ) ),
				'posts'         => esc_url_raw( rest_url( 'wp/v2/posts' ) ),
			),
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'capabilities'        => array(
				'canEditPosts'        => current_user_can( 'edit_posts' ),
				'canEditEntries'      => current_user_can( Post_Type::EDIT_ENTRIES_CAP ),
				'canManageTerms'      => current_user_can( 'manage_categories' ),
				'canManageOptions'    => current_user_can( 'manage_options' ),
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
				'statusKey'       => Taxonomy::STATUS_META_KEY,
				'lastModifiedKey' => Rolling_Coverage_Block::LAST_MODIFIED_META_KEY,
				'canonicalUrlKey' => Taxonomy::CANONICAL_URL_META_KEY,
				'adsDisabledKey'  => Taxonomy::ADS_DISABLED_META_KEY,
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
