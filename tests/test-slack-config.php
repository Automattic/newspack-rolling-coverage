<?php
/**
 * Tests for the stored Slack configuration.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Slack_Config;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Covers the rules on what may be stored as credentials, the bot user that
 * owns ingested entries, and how channel links are torn down.
 */
class Test_Slack_Config extends Rolling_Coverage_TestCase {

	const VALID_BOT_TOKEN      = 'xoxb-000000-test';
	const VALID_SIGNING_SECRET = '0123456789abcdef0123456789abcdef';

	/**
	 * Values that are not a bot token.
	 *
	 * @return array[]
	 */
	public function invalid_bot_token_provider() {
		return [
			'user token'                 => [ 'xoxp-000000-test' ],
			'no prefix'                  => [ '000000-test' ],
			'prefix only'                => [ 'xoxb-' ],
			'token with trailing markup' => [ 'xoxb-000000-test<script>' ],
			'token with a space'         => [ 'xoxb-000000 test' ],
			'empty string'               => [ '' ],
		];
	}

	/**
	 * Only a bot token is stored; anything else leaves the option untouched.
	 *
	 * @dataProvider invalid_bot_token_provider
	 *
	 * @param string $invalid_token Value to reject.
	 */
	public function test_refuses_to_store_anything_but_a_bot_token( $invalid_token ) {
		Slack_Config::set_bot_token( self::VALID_BOT_TOKEN );

		$this->assertFalse( Slack_Config::set_bot_token( $invalid_token ), 'The value should be rejected.' );
		$this->assertSame( self::VALID_BOT_TOKEN, Slack_Config::get_bot_token(), 'The stored token should be unchanged.' );
	}

	/**
	 * Values that are not a signing secret.
	 *
	 * @return array[]
	 */
	public function invalid_signing_secret_provider() {
		return [
			'too short'         => [ '0123456789abcdef' ],
			'too long'          => [ '0123456789abcdef0123456789abcdef0' ],
			'uppercase hex'     => [ '0123456789ABCDEF0123456789ABCDEF' ],
			'non-hex character' => [ '0123456789abcdef0123456789abcdeg' ],
			'empty string'      => [ '' ],
		];
	}

	/**
	 * Only a 32-character lowercase hex secret is stored.
	 *
	 * @dataProvider invalid_signing_secret_provider
	 *
	 * @param string $invalid_secret Value to reject.
	 */
	public function test_refuses_to_store_a_malformed_signing_secret( $invalid_secret ) {
		$this->assertFalse( Slack_Config::set_signing_secret( $invalid_secret ), 'The value should be rejected.' );
		$this->assertSame( '', Slack_Config::get_signing_secret(), 'Nothing should be stored.' );
	}

	/**
	 * The integration counts as configured only with both credentials.
	 */
	public function test_is_configured_only_with_both_credentials() {
		Slack_Config::set_bot_token( self::VALID_BOT_TOKEN );

		$this->assertFalse( Slack_Config::is_configured(), 'A token alone is not enough.' );

		Slack_Config::set_signing_secret( self::VALID_SIGNING_SECRET );

		$this->assertTrue( Slack_Config::is_configured(), 'Token and secret together are.' );
	}

	/**
	 * The settings screen shows the token's last four characters and nothing
	 * else of it.
	 */
	public function test_masked_token_reveals_only_the_last_four_characters() {
		Slack_Config::set_bot_token( self::VALID_BOT_TOKEN );

		$this->assertSame( '...test', Slack_Config::get_masked_bot_token() );
	}

	/**
	 * A stored prefix that breaks the format rules is ignored in favor of the
	 * default. Otherwise an empty prefix would turn skipping off and a
	 * malformed one would stop matching the messages marked to be skipped, so
	 * those messages would become entries.
	 */
	public function test_unusable_stored_skip_prefix_falls_back_to_the_default() {
		Slack_Config::update_settings( [ 'ignore_prefix' => '' ] );

		$this->assertSame( Slack_Config::DEFAULT_IGNORE_PREFIX, Slack_Config::get_ignore_prefix(), 'An empty prefix should fall back.' );

		Slack_Config::update_settings( [ 'ignore_prefix' => 'has space' ] );

		$this->assertSame( Slack_Config::DEFAULT_IGNORE_PREFIX, Slack_Config::get_ignore_prefix(), 'A prefix with whitespace should fall back.' );
	}

	/**
	 * Saving one setting keeps the others.
	 */
	public function test_saving_one_setting_keeps_the_others() {
		Slack_Config::update_settings( [ 'workspace_id' => 'T0WORKSPACE' ] );
		Slack_Config::set_ignore_prefix( '//' );

		$this->assertSame( 'T0WORKSPACE', Slack_Config::get_settings()['workspace_id'] );
	}

	/**
	 * Ingested entries need an owner. The bot user is created once, as an
	 * author, and reused afterwards.
	 */
	public function test_bot_user_is_created_once_as_an_author() {
		$bot_user_id = Slack_Config::get_or_create_bot_user_id();

		$this->assertSame( [ 'author' ], get_userdata( $bot_user_id )->roles, 'The bot user should be an author.' );
		$this->assertSame( $bot_user_id, Slack_Config::get_or_create_bot_user_id(), 'A second call should return the same user.' );
	}

	/**
	 * Slack entries show the bundled bot avatar; other authors keep theirs.
	 */
	public function test_bot_user_gets_the_bundled_avatar() {
		$bot_user_id   = Slack_Config::get_or_create_bot_user_id();
		$other_user_id = self::factory()->user->create();

		$this->assertSame( NEWSPACK_ROLLING_COVERAGE_URL . Slack_Config::BOT_AVATAR_PATH, get_avatar_url( $bot_user_id ) );
		$this->assertSame( NEWSPACK_ROLLING_COVERAGE_URL . Slack_Config::BOT_AVATAR_PATH, get_avatar_url( get_userdata( $bot_user_id ) ) );
		$this->assertSame( NEWSPACK_ROLLING_COVERAGE_URL . Slack_Config::BOT_AVATAR_PATH, get_avatar_url( self::factory()->comment->create_and_get( [ 'user_id' => $bot_user_id ] ) ) );
		$this->assertStringNotContainsString( Slack_Config::BOT_AVATAR_PATH, get_avatar_url( $other_user_id ) );
	}

	/**
	 * Disconnecting keeps the bot user and its entries, so the avatar stays.
	 */
	public function test_bot_avatar_survives_a_disconnect() {
		$bot_user_id = Slack_Config::get_or_create_bot_user_id();

		Slack_Config::clear_all();

		$this->assertSame( NEWSPACK_ROLLING_COVERAGE_URL . Slack_Config::BOT_AVATAR_PATH, get_avatar_url( $bot_user_id ) );
	}

	/**
	 * If the remembered bot user was deleted, a working one is resolved again
	 * instead of handing out an id that no longer exists.
	 */
	public function test_bot_user_is_recreated_after_being_deleted() {
		$deleted_bot_user_id = Slack_Config::get_or_create_bot_user_id();
		self::delete_user( $deleted_bot_user_id );

		$new_bot_user_id = Slack_Config::get_or_create_bot_user_id();

		$this->assertNotSame( $deleted_bot_user_id, $new_bot_user_id, 'The deleted user id should not be returned.' );
		$this->assertInstanceOf( WP_User::class, get_userdata( $new_bot_user_id ), 'The returned id should belong to a user.' );
	}

	/**
	 * Disconnecting removes the credentials and every trace of the channel
	 * links, and announces each unlink so listeners can clean up too.
	 */
	public function test_disconnecting_removes_credentials_and_channel_links() {
		$coverage_id = self::create_coverage();
		Slack_Config::set_bot_token( self::VALID_BOT_TOKEN );
		Slack_Config::set_signing_secret( self::VALID_SIGNING_SECRET );
		Slack_Config::update_channel( 'C0TESTCHAN', [ 'term_id' => $coverage_id ] );
		update_term_meta( $coverage_id, Taxonomy::META_SLACK_CHANNEL_ID, 'C0TESTCHAN' );
		update_term_meta( $coverage_id, Taxonomy::META_SOURCE, 'slack' );

		$unlinked_channel_ids = [];
		add_action(
			'rolling_coverage_slack_channel_unlinked',
			function ( $channel_id ) use ( &$unlinked_channel_ids ) {
				$unlinked_channel_ids[] = $channel_id;
			}
		);

		Slack_Config::clear_all();

		$this->assertFalse( Slack_Config::is_configured(), 'The credentials should be gone.' );
		$this->assertSame( [], Slack_Config::get_channel_map(), 'The channel map should be empty.' );
		$this->assertSame( '', get_term_meta( $coverage_id, Taxonomy::META_SLACK_CHANNEL_ID, true ), 'The coverage should no longer name the channel.' );
		$this->assertSame( '', get_term_meta( $coverage_id, Taxonomy::META_SOURCE, true ), 'The coverage should no longer name a source.' );
		$this->assertSame( [ 'C0TESTCHAN' ], $unlinked_channel_ids, 'The unlink should be announced.' );
	}

	/**
	 * Deleting a coverage unlinks its channel and leaves other links alone,
	 * so messages in that channel stop looking for a coverage that is gone.
	 */
	public function test_deleting_a_coverage_unlinks_only_its_own_channel() {
		$deleted_coverage_id = self::create_coverage();
		$other_coverage_id   = self::create_coverage();
		Slack_Config::update_channel( 'C0DELETED', [ 'term_id' => $deleted_coverage_id ] );
		Slack_Config::update_channel( 'C0OTHER', [ 'term_id' => $other_coverage_id ] );

		Slack_Config::on_term_deleted( $deleted_coverage_id );

		$this->assertNull( Slack_Config::find_linked_term_id( 'C0DELETED' ), "The deleted coverage's channel should be unlinked." );
		$this->assertSame( $other_coverage_id, Slack_Config::find_linked_term_id( 'C0OTHER' ), 'The other link should be kept.' );
	}
}
