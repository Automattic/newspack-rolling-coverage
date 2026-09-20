<?php
/**
 * Tests for keeping the entry templates' post blocks available in the editor.
 *
 * @package Newspack_Rolling_Coverage
 */

/**
 * The Newspack Theme unregisters the post blocks in the editor. The entry
 * templates are built from them, so the plugin takes them off the theme's list.
 */
class Test_Theme_FSE_Blocks extends WP_UnitTestCase {

	/**
	 * Handle of the Newspack Theme script that unregisters the blocks.
	 */
	const THEME_SCRIPT_HANDLE = 'newspack-hide-fse-blocks';

	/**
	 * Load the stand-in for the theme's block list.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once __DIR__ . '/mocks/newspack-theme.php';
	}

	/**
	 * Remove the stand-in theme script and hook.
	 */
	public function tear_down() {
		remove_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_theme_script' ] );
		wp_dequeue_script( self::THEME_SCRIPT_HANDLE );
		wp_deregister_script( self::THEME_SCRIPT_HANDLE );
		parent::tear_down();
	}

	/**
	 * Enqueue the block removal script the way the Newspack Theme does.
	 */
	public static function enqueue_theme_script() {
		wp_register_script( self::THEME_SCRIPT_HANDLE, 'https://example.test/editor-remove-blocks.js', [], '1.0', true );
		wp_localize_script( self::THEME_SCRIPT_HANDLE, 'updateAllowedBlocks', newspack_fse_blocks_to_remove() );
		wp_enqueue_script( self::THEME_SCRIPT_HANDLE );
	}

	/**
	 * Only the blocks the entry templates do not use are left for the theme
	 * to unregister.
	 */
	public function test_theme_only_unregisters_blocks_the_entry_templates_do_not_use() {
		// A theme hooks in after plugins do, at the default priority.
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_theme_script' ] );

		do_action( 'enqueue_block_editor_assets' );

		// The theme script reads the global, so the last assignment is the one that counts.
		$localized_data = (string) wp_scripts()->get_data( self::THEME_SCRIPT_HANDLE, 'data' );
		preg_match_all( '/var updateAllowedBlocks = (\{.*?\});/', $localized_data, $assignments );
		$last_assignment = json_decode( (string) end( $assignments[1] ), true );

		$this->assertSame( 'core/query,core/avatar', $last_assignment['removeblocks'] ?? null );
	}
}
