<?php
/**
 * Tests for the Settings admin page: who sees it, its URLs, and the old AI
 * page's redirect.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Admin;

/**
 * The Settings page holds the General, Slack and AI tabs, which need different
 * capabilities, and replaces the AI page.
 */
class Test_Settings_Page extends Rolling_Coverage_TestCase {

	/**
	 * A tab's URL ends at its hash route.
	 */
	public function test_settings_url_ends_at_the_tab_route() {
		$base = admin_url( 'admin.php?page=' . Admin::SETTINGS_MENU_SLUG );

		$this->assertSame( $base . '#/settings/general', Admin::get_settings_url() );
		$this->assertSame( $base . '#/settings/ai', Admin::get_settings_url( 'ai' ) );
	}

	/**
	 * An editor can open the General tab, so the page uses its capability.
	 */
	public function test_editor_gets_the_general_tab_capability() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 'edit_others_posts', Admin::get_settings_capability() );
	}

	/**
	 * A user who can manage options but not edit others' posts can still
	 * open the Slack and AI tabs, so the page uses their capability.
	 */
	public function test_options_only_user_gets_the_ai_tab_capability() {
		$user = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$user->add_cap( 'manage_options' );
		wp_set_current_user( $user->ID );

		$this->assertSame( 'manage_options', Admin::get_settings_capability() );
	}

	/**
	 * A user who can't edit others' posts gets the Slack and AI tabs'
	 * capability, which an author doesn't have either, so the page stays
	 * hidden.
	 */
	public function test_user_without_either_capability_cannot_open_the_page() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$this->assertFalse( current_user_can( Admin::get_settings_capability() ) );
	}

	/**
	 * The old AI page goes to the AI tab; other pages are left alone.
	 */
	public function test_legacy_ai_page_redirects_to_the_ai_tab() {
		$this->assertSame( Admin::get_settings_url( 'ai' ), Admin::get_legacy_ai_redirect( [ 'page' => Admin::LEGACY_AI_MENU_SLUG ] ) );
		$this->assertNull( Admin::get_legacy_ai_redirect( [ 'page' => Admin::SETTINGS_MENU_SLUG ] ) );
		$this->assertNull( Admin::get_legacy_ai_redirect( [] ) );
	}

	/**
	 * The redirect runs where WordPress denies access to the unregistered
	 * old page.
	 */
	public function test_legacy_redirect_is_hooked_to_access_denied() {
		Admin::init();

		$this->assertNotFalse( has_action( 'admin_page_access_denied', [ Admin::class, 'redirect_legacy_ai_page' ] ) );
	}
}
