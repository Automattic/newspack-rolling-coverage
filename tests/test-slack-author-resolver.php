<?php
/**
 * Tests for crediting Slack messages to the WordPress user with the
 * matching Slack handle.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Slack_Author_Resolver;
use Newspack_Rolling_Coverage\Slack_Config;

/**
 * A user's Slack handle or member ID is set on their profile. A message from
 * that person is credited to them, and anything else to the Slack bot user.
 */
class Test_Slack_Author_Resolver extends Rolling_Coverage_TestCase {

	/**
	 * The Slack member ID of the message author in these tests.
	 */
	const MEMBER_ID = 'U0REPORTER1';

	/**
	 * Clear the submitted profile form.
	 */
	public function tear_down() {
		unset( $_POST[ Slack_Author_Resolver::FIELD_SLACK_HANDLE ], $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * A Slack `users.info` profile.
	 *
	 * @param string $display_name Display name.
	 * @param string $name         Username.
	 * @param string $real_name    Full name.
	 * @return array
	 */
	private static function slack_user( string $display_name, string $name = 'someone', string $real_name = '' ): array {
		return [
			'name'    => $name,
			'profile' => [
				'display_name' => $display_name,
				'real_name'    => $real_name,
			],
		];
	}

	/**
	 * The user credited with a message from the test member ID.
	 *
	 * @param array|WP_Error $user_info The author's `users.info` profile.
	 * @return int User ID.
	 */
	private static function resolve( $user_info ): int {
		return Slack_Author_Resolver::resolve_author( self::MEMBER_ID, $user_info )['user_id'];
	}

	/**
	 * Create an author mapped to a Slack handle.
	 *
	 * @param string $handle Slack handle.
	 * @param string $role   Role.
	 * @return int User ID.
	 */
	private static function mapped_user( string $handle, string $role = 'author' ): int {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );
		update_user_meta( $user_id, Slack_Author_Resolver::META_SLACK_HANDLE, $handle );
		return $user_id;
	}

	/**
	 * Submit the profile form for a user, as someone allowed to edit them.
	 *
	 * @param int    $user_id User being saved.
	 * @param string $handle  Submitted handle.
	 */
	private static function save_profile( int $user_id, string $handle ): void {
		$_POST[ Slack_Author_Resolver::FIELD_SLACK_HANDLE ] = wp_slash( $handle );
		$_REQUEST['_wpnonce']                               = wp_create_nonce( 'update-user_' . $user_id );
		Slack_Author_Resolver::save_profile_section( $user_id );
	}

	/**
	 * A message from a mapped display name is credited to that user.
	 */
	public function test_credits_the_user_with_the_matching_display_name() {
		$user_id = self::mapped_user( 'Riley Sample' );

		$this->assertSame( $user_id, self::resolve( self::slack_user( 'Riley Sample' ) ) );
	}

	/**
	 * Case and a typed `@` don't stop a match.
	 */
	public function test_match_ignores_case_and_the_at_sign() {
		$user_id = self::mapped_user( 'riley.sample' );

		$this->assertSame( $user_id, self::resolve( self::slack_user( '@Riley.Sample' ) ) );
	}

	/**
	 * With no display name, Slack shows the full name, so it is tried next,
	 * and the legacy username after it.
	 */
	public function test_falls_back_to_the_full_name_then_the_username() {
		$by_username  = self::mapped_user( 'rsample' );
		$by_full_name = self::mapped_user( 'Riley Sample' );

		$this->assertSame(
			[
				'user_id'    => $by_full_name,
				'matched_by' => 'real_name',
			],
			Slack_Author_Resolver::resolve_author( self::MEMBER_ID, self::slack_user( '', 'rsample', 'Riley Sample' ) ),
			'The full name should win over the username.'
		);
		$this->assertSame(
			[
				'user_id'    => $by_username,
				'matched_by' => 'name',
			],
			Slack_Author_Resolver::resolve_author( self::MEMBER_ID, self::slack_user( '', 'rsample' ) ),
			'The username should match when nothing else does.'
		);
	}

	/**
	 * The display name wins over the username when both are mapped.
	 */
	public function test_display_name_wins_over_the_username() {
		self::mapped_user( 'rsample' );
		$by_display_name = self::mapped_user( 'Riley Sample' );

		$this->assertSame( $by_display_name, self::resolve( self::slack_user( 'Riley Sample', 'rsample' ) ) );
	}

	/**
	 * A message from a mapped member ID is credited to that user, even when
	 * Slack can't say who the author is.
	 */
	public function test_credits_the_user_with_the_matching_member_id() {
		$user_id = self::mapped_user( self::MEMBER_ID );

		$this->assertSame( $user_id, self::resolve( new WP_Error( 'http_request_failed' ) ) );
	}

	/**
	 * The member ID wins over a handle, which anyone can take.
	 */
	public function test_member_id_wins_over_a_handle() {
		self::mapped_user( 'Riley Sample' );
		$by_member_id = self::mapped_user( self::MEMBER_ID );

		$this->assertSame( $by_member_id, self::resolve( self::slack_user( 'Riley Sample' ) ) );
	}

	/**
	 * A Slack name is free text, so one set to a colleague's member ID must
	 * not credit the message to that colleague.
	 *
	 * @dataProvider data_names_shaped_like_a_member_id
	 *
	 * @param array $user_info The impersonator's `users.info` profile.
	 */
	public function test_name_shaped_like_a_member_id_does_not_match_it( array $user_info ) {
		self::mapped_user( 'U0COLLEAGUE1' );

		$this->assertSame( Slack_Config::get_or_create_bot_user_id(), self::resolve( $user_info ) );
	}

	/**
	 * Profiles whose names copy a colleague's member ID.
	 *
	 * @return array
	 */
	public function data_names_shaped_like_a_member_id() {
		return [
			'display name' => [ self::slack_user( 'U0COLLEAGUE1' ) ],
			'full name'    => [ self::slack_user( '', 'someone', 'U0COLLEAGUE1' ) ],
			'username'     => [ self::slack_user( '', 'U0COLLEAGUE1' ) ],
		];
	}

	/**
	 * A handle that differs from a member ID only in case is a handle, and
	 * doesn't claim that member's messages.
	 */
	public function test_member_id_matches_only_exactly() {
		self::mapped_user( strtolower( self::MEMBER_ID ) );

		$this->assertSame( Slack_Config::get_or_create_bot_user_id(), self::resolve( self::slack_user( 'Riley Sample' ) ) );
	}

	/**
	 * Member IDs are told from handles by their prefix and form.
	 *
	 * @dataProvider data_member_ids
	 *
	 * @param string $value     Stored value.
	 * @param bool   $is_member Whether it is a member ID.
	 */
	public function test_tells_member_ids_from_handles( string $value, bool $is_member ) {
		$this->assertSame( $is_member, Slack_Author_Resolver::is_member_id( $value ) );
	}

	/**
	 * Values and whether each is a member ID.
	 *
	 * @return array
	 */
	public function data_member_ids() {
		return [
			'user ID'            => [ 'U012AB3CDE', true ],
			'Enterprise Grid ID' => [ 'W012AB3CDE', true ],
			'lowercase'          => [ 'u012ab3cde', false ],
			'other prefix'       => [ 'C012AB3CDE', false ],
			'too short'          => [ 'U012', false ],
			'no digits'          => [ 'WALTERWHITE', false ],
			'handle'             => [ 'Umberto', false ],
		];
	}

	/**
	 * Nobody mapped means the bot user.
	 */
	public function test_unmapped_handle_goes_to_the_bot_user() {
		self::mapped_user( 'Someone Else' );

		$this->assertSame( Slack_Config::get_or_create_bot_user_id(), self::resolve( self::slack_user( 'Riley Sample' ) ) );
	}

	/**
	 * A failed Slack lookup means the bot user.
	 */
	public function test_failed_lookup_goes_to_the_bot_user() {
		self::mapped_user( 'Riley Sample' );

		$this->assertSame( Slack_Config::get_or_create_bot_user_id(), self::resolve( new WP_Error( 'http_request_failed' ) ) );
		$this->assertSame( Slack_Config::get_or_create_bot_user_id(), self::resolve( [] ) );
	}

	/**
	 * Someone who can't write entries is never credited with one.
	 */
	public function test_user_who_cannot_write_entries_is_not_credited() {
		self::mapped_user( 'Riley Sample', 'subscriber' );

		$this->assertSame( Slack_Config::get_or_create_bot_user_id(), self::resolve( self::slack_user( 'Riley Sample' ) ) );
	}

	/**
	 * The profile form stores the handle without a typed `@`.
	 */
	public function test_profile_saves_the_handle() {
		$user_id = self::log_in_as( 'author' );

		self::save_profile( $user_id, '  @Riley Sample ' );

		$this->assertSame( 'Riley Sample', get_user_meta( $user_id, Slack_Author_Resolver::META_SLACK_HANDLE, true ) );
	}

	/**
	 * Emptying the field removes the mapping.
	 */
	public function test_profile_clears_the_handle() {
		$user_id = self::log_in_as( 'author' );
		update_user_meta( $user_id, Slack_Author_Resolver::META_SLACK_HANDLE, 'Riley Sample' );

		self::save_profile( $user_id, '' );

		$this->assertSame( '', get_user_meta( $user_id, Slack_Author_Resolver::META_SLACK_HANDLE, true ) );
	}

	/**
	 * A handle another user has is refused, with an error on the profile.
	 */
	public function test_profile_refuses_a_handle_another_user_has() {
		$owner   = self::mapped_user( 'Riley Sample' );
		$user_id = self::log_in_as( 'author' );

		self::save_profile( $user_id, 'riley sample' );

		$errors = new WP_Error();
		do_action_ref_array( 'user_profile_update_errors', [ &$errors, true, get_userdata( $user_id ) ] );

		$this->assertSame( '', get_user_meta( $user_id, Slack_Author_Resolver::META_SLACK_HANDLE, true ), 'The handle should not be saved.' );
		$this->assertContains( 'rolling_coverage_slack_handle_taken', $errors->get_error_codes(), 'The profile should show an error.' );
		$this->assertSame( 'Riley Sample', get_user_meta( $owner, Slack_Author_Resolver::META_SLACK_HANDLE, true ), 'The other user should keep the handle.' );
	}

	/**
	 * A member ID another user has is refused, like a handle.
	 */
	public function test_profile_refuses_a_member_id_another_user_has() {
		$owner   = self::mapped_user( self::MEMBER_ID );
		$user_id = self::log_in_as( 'author' );

		self::save_profile( $user_id, self::MEMBER_ID );

		$errors = new WP_Error();
		do_action_ref_array( 'user_profile_update_errors', [ &$errors, true, get_userdata( $user_id ) ] );

		$this->assertSame( '', get_user_meta( $user_id, Slack_Author_Resolver::META_SLACK_HANDLE, true ), 'The member ID should not be saved.' );
		$this->assertContains( 'rolling_coverage_slack_handle_taken', $errors->get_error_codes(), 'The profile should show an error.' );
		$this->assertSame( self::MEMBER_ID, get_user_meta( $owner, Slack_Author_Resolver::META_SLACK_HANDLE, true ), 'The other user should keep the member ID.' );
	}

	/**
	 * A value held by someone who can't be credited doesn't block anyone.
	 */
	public function test_profile_ignores_a_value_held_by_someone_who_cannot_be_credited() {
		self::mapped_user( 'Riley Sample', 'subscriber' );
		$user_id = self::log_in_as( 'author' );

		self::save_profile( $user_id, 'Riley Sample' );

		$this->assertSame( 'Riley Sample', get_user_meta( $user_id, Slack_Author_Resolver::META_SLACK_HANDLE, true ) );
	}

	/**
	 * Saving a profile again with its own handle is not a conflict.
	 */
	public function test_profile_keeps_its_own_handle() {
		$user_id = self::log_in_as( 'author' );
		update_user_meta( $user_id, Slack_Author_Resolver::META_SLACK_HANDLE, 'Riley Sample' );

		self::save_profile( $user_id, 'Riley Sample' );

		$errors = new WP_Error();
		do_action_ref_array( 'user_profile_update_errors', [ &$errors, true, get_userdata( $user_id ) ] );

		$this->assertFalse( $errors->has_errors() );
		$this->assertSame( 'Riley Sample', get_user_meta( $user_id, Slack_Author_Resolver::META_SLACK_HANDLE, true ) );
	}

	/**
	 * Someone who can't edit the user can't set their handle.
	 */
	public function test_profile_ignores_users_who_cannot_edit_the_user() {
		$other = self::factory()->user->create( [ 'role' => 'author' ] );
		self::log_in_as( 'author' );

		self::save_profile( $other, 'Riley Sample' );

		$this->assertSame( '', get_user_meta( $other, Slack_Author_Resolver::META_SLACK_HANDLE, true ) );
	}

	/**
	 * The profile screens show the section with the stored handle.
	 */
	public function test_profile_shows_the_section() {
		$user_id = self::mapped_user( 'Riley Sample' );

		ob_start();
		Slack_Author_Resolver::render_profile_section( get_userdata( $user_id ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( '<h2>Rolling Coverage</h2>', $html );
		$this->assertStringContainsString( 'Slack handle', $html );
		$this->assertStringContainsString( 'value="Riley Sample"', $html );
		$this->assertStringContainsString( 'Copy member ID', $html, 'The section should say how to find a member ID.' );
	}

	/**
	 * Users who can't write entries can't be credited, so they don't get the
	 * section, and a submitted value is ignored.
	 */
	public function test_profile_leaves_out_users_who_cannot_write_entries() {
		$user_id = self::log_in_as( 'subscriber' );

		ob_start();
		Slack_Author_Resolver::render_profile_section( get_userdata( $user_id ) );
		$html = ob_get_clean();
		self::save_profile( $user_id, 'Riley Sample' );

		$this->assertSame( '', $html, 'The section should not show.' );
		$this->assertSame( '', get_user_meta( $user_id, Slack_Author_Resolver::META_SLACK_HANDLE, true ), 'The value should not be saved.' );
	}
}
