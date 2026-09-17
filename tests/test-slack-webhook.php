<?php
/**
 * Tests for the Slack webhook: request gating, message filtering and the
 * path from a message event to an entry.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Slack_API_Client;
use Newspack_Rolling_Coverage\Slack_Config;
use Newspack_Rolling_Coverage\Slack_Ingestion_Service;
use Newspack_Rolling_Coverage\Slack_Signature_Verifier;
use Newspack_Rolling_Coverage\Slack_Webhook_Controller;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * The webhook routes are only registered once Slack is configured at load
 * time, so these tests call the controller directly. Outbound Slack API calls
 * are answered by a `pre_http_request` filter; nothing leaves the process.
 */
class Test_Slack_Webhook extends Rolling_Coverage_TestCase {

	const SIGNING_SECRET = '0123456789abcdef0123456789abcdef';
	const BOT_TOKEN      = 'xoxb-000000-test';
	const CHANNEL_ID     = 'C0TESTCHAN';

	/**
	 * URLs of the outbound requests the code under test attempted.
	 *
	 * @var string[]
	 */
	private $outbound_requests = [];

	/**
	 * Answer every outbound HTTP request with a Slack `users.info` payload.
	 */
	public function set_up() {
		parent::set_up();
		$this->outbound_requests = [];
		add_filter( 'pre_http_request', [ $this, 'mock_slack_api' ], 10, 3 );
	}

	/**
	 * Stand in for the Slack API.
	 *
	 * @param false|array $response    Short-circuit value.
	 * @param array       $parsed_args Request arguments.
	 * @param string      $url         Request URL.
	 * @return array Mocked response.
	 */
	public function mock_slack_api( $response, $parsed_args, $url ) {
		$this->outbound_requests[] = $url;

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => wp_json_encode(
				[
					'ok'   => true,
					'user' => [
						'name'    => 'rsample',
						'profile' => [ 'display_name' => 'Riley Sample' ],
					],
				]
			),
		];
	}

	/**
	 * Build a controller with the test signing secret.
	 *
	 * @return Slack_Webhook_Controller
	 */
	private static function controller() {
		return new Slack_Webhook_Controller( new Slack_API_Client(), new Slack_Signature_Verifier( self::SIGNING_SECRET ) );
	}

	/**
	 * Store credentials so the integration counts as configured.
	 */
	private static function configure_slack() {
		Slack_Config::set_bot_token( self::BOT_TOKEN );
		Slack_Config::set_signing_secret( self::SIGNING_SECRET );
	}

	/**
	 * Build a webhook request carrying the given body.
	 *
	 * @param string $body      Raw request body.
	 * @param bool   $is_signed Whether to add valid Slack signature headers.
	 * @return WP_REST_Request
	 */
	private static function webhook_request( $body, $is_signed = true ) {
		$request = new WP_REST_Request( 'POST', '/rolling-coverage/v1/slack/events' );
		$request->set_body( $body );

		if ( $is_signed ) {
			$timestamp = time();
			$request->set_header( 'X-Slack-Request-Timestamp', (string) $timestamp );
			$request->set_header( 'X-Slack-Signature', 'v0=' . hash_hmac( 'sha256', "v0:{$timestamp}:{$body}", self::SIGNING_SECRET ) );
		}

		return $request;
	}

	/**
	 * Build the body of a Slack message event.
	 *
	 * @param array $event_overrides Event fields to override.
	 * @return string JSON body.
	 */
	private static function message_event_body( array $event_overrides = [] ) {
		return wp_json_encode(
			[
				'type'  => 'event_callback',
				'event' => array_merge(
					[
						'type'    => 'message',
						'channel' => self::CHANNEL_ID,
						'user'    => 'U0REPORTER',
						'ts'      => '1767225600.000100',
						'text'    => 'Polls have closed across the county.',
					],
					$event_overrides
				),
			]
		);
	}

	/**
	 * All entries, of any status, in a coverage.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return WP_Post[]
	 */
	private static function get_coverage_entries( $coverage_id ) {
		return get_posts(
			[
				'post_type'   => Post_Type::CPT_SLUG,
				'post_status' => 'any',
				'tax_query'   => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => Taxonomy::TAXONOMY_SLUG,
						'terms'    => $coverage_id,
					],
				],
			]
		);
	}

	/**
	 * Slack events that must never become entries.
	 *
	 * @return array[]
	 */
	public function filtered_event_provider() {
		return [
			'message posted by a bot'      => [ [ 'subtype' => 'bot_message' ] ],
			'message carrying a bot id'    => [ [ 'bot_id' => 'B0BOT' ] ],
			'edit of an earlier message'   => [ [ 'subtype' => 'message_changed' ] ],
			'deletion of a message'        => [ [ 'subtype' => 'message_deleted' ] ],
			'member joining the channel'   => [ [ 'subtype' => 'channel_join' ] ],
			'member leaving the channel'   => [ [ 'subtype' => 'channel_leave' ] ],
			'member joining a private one' => [ [ 'subtype' => 'group_join' ] ],
			'member leaving a private one' => [ [ 'subtype' => 'group_leave' ] ],
			'message with the skip prefix' => [ [ 'text' => '~~ not for publication' ] ],
		];
	}

	/**
	 * Bot traffic, edits, membership noise and opted-out messages are skipped.
	 *
	 * @dataProvider filtered_event_provider
	 *
	 * @param array $event Slack event fields.
	 */
	public function test_skips_events_that_are_not_reporter_messages( array $event ) {
		$this->assertTrue( Slack_Ingestion_Service::should_filter_message( $event ) );
	}

	/**
	 * An ordinary message is ingested, including one that merely contains the
	 * skip prefix somewhere after its first character.
	 */
	public function test_keeps_reporter_messages() {
		$this->assertFalse( Slack_Ingestion_Service::should_filter_message( [ 'text' => 'Polls have closed.' ] ), 'A plain message should be kept.' );
		$this->assertFalse( Slack_Ingestion_Service::should_filter_message( [ 'text' => 'Turnout was ~~60%~~ 62%.' ] ), 'The skip prefix only counts at the start of the message.' );
	}

	/**
	 * A newsroom's own skip prefix replaces the default one.
	 */
	public function test_honors_a_custom_skip_prefix() {
		Slack_Config::set_ignore_prefix( '//' );

		$this->assertTrue( Slack_Ingestion_Service::should_filter_message( [ 'text' => '// internal note' ] ), 'The custom prefix should skip the message.' );
		$this->assertFalse( Slack_Ingestion_Service::should_filter_message( [ 'text' => '~~ struck through' ] ), 'The default prefix should stop applying.' );
	}

	/**
	 * Webhook requests are refused outright until Slack is configured.
	 */
	public function test_refuses_webhook_requests_until_slack_is_configured() {
		$body = self::message_event_body();

		$verification = self::controller()->verify_webhook_signature( self::webhook_request( $body ) );

		$this->assertWPError( $verification, 'An unconfigured site should not accept webhook requests.' );
		$this->assertSame( 503, $verification->get_error_data()['status'], 'The refusal should be a 503.' );
	}

	/**
	 * An unsigned webhook request is answered with a 401.
	 */
	public function test_refuses_an_unsigned_webhook_request() {
		$this->silence_error_log();
		self::configure_slack();

		$verification = self::controller()->verify_webhook_signature( self::webhook_request( self::message_event_body(), false ) );

		$this->assertWPError( $verification, 'An unsigned request should be refused.' );
		$this->assertSame( 401, $verification->get_error_data()['status'], 'The refusal should be a 401.' );
	}

	/**
	 * A request signed with the configured secret passes the gate.
	 */
	public function test_admits_a_signed_webhook_request() {
		self::configure_slack();

		$this->assertTrue( self::controller()->verify_webhook_signature( self::webhook_request( self::message_event_body() ) ) );
	}

	/**
	 * Slack's endpoint verification handshake echoes the challenge back.
	 */
	public function test_answers_the_url_verification_challenge() {
		$body = wp_json_encode(
			[
				'type'      => 'url_verification',
				'challenge' => 'challenge-token-123',
			]
		);

		$response = self::controller()->handle_event( self::webhook_request( $body ) );

		$this->assertSame( [ 'challenge' => 'challenge-token-123' ], $response->get_data() );
	}

	/**
	 * A message in a linked channel becomes a draft entry in that coverage,
	 * authored by the bot user and carrying its Slack provenance.
	 */
	public function test_message_in_a_linked_channel_becomes_a_draft_entry() {
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );

		$response = self::controller()->handle_event( self::webhook_request( self::message_event_body() ) );
		$entries  = self::get_coverage_entries( $coverage_id );

		$this->assertSame( 200, $response->get_status(), 'Slack should get a 200 so it does not retry.' );
		$this->assertCount( 1, $entries, 'The message should create one entry in the linked coverage.' );

		$entry = $entries[0];

		$this->assertSame( 'draft', $entry->post_status, 'Entries are drafts unless the channel opts in to autopublish.' );
		$this->assertStringContainsString( '<p>Polls have closed across the county.</p>', $entry->post_content, 'The message text should be the entry content.' );
		$this->assertSame( Slack_Config::get_or_create_bot_user_id(), (int) $entry->post_author, 'The bot user should own the entry.' );
		$this->assertSame( 'slack', get_post_meta( $entry->ID, Post_Type::META_ENTRY_SOURCE, true ), 'The entry should be marked as coming from Slack.' );
		$this->assertSame( 'Riley Sample', get_post_meta( $entry->ID, Post_Type::META_SLACK_AUTHOR_NAME, true ), 'The Slack author name should be recorded.' );
		$this->assertSame( '1767225600.000100', Slack_Config::get_channel_settings( self::CHANNEL_ID )['last_sync_ts'], 'The channel should remember the last message it ingested.' );
	}

	/**
	 * Autopublish is a per-channel opt-in.
	 */
	public function test_message_is_published_when_the_channel_autopublishes() {
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel(
			self::CHANNEL_ID,
			[
				'term_id'     => $coverage_id,
				'autopublish' => true,
			]
		);

		self::controller()->handle_event( self::webhook_request( self::message_event_body() ) );

		$this->assertSame( [ 'publish' ], wp_list_pluck( self::get_coverage_entries( $coverage_id ), 'post_status' ) );
	}

	/**
	 * Slack retries deliveries it thinks failed; a retry must not duplicate
	 * the entry.
	 */
	public function test_redelivered_message_does_not_create_a_second_entry() {
		$this->silence_error_log();
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );

		self::controller()->handle_event( self::webhook_request( self::message_event_body() ) );
		self::controller()->handle_event( self::webhook_request( self::message_event_body() ) );

		$this->assertCount( 1, self::get_coverage_entries( $coverage_id ) );
	}

	/**
	 * A message in a channel with no coverage is dropped before any Slack API
	 * call is made on its behalf.
	 */
	public function test_message_in_an_unlinked_channel_is_dropped() {
		self::configure_slack();

		$response = self::controller()->handle_event( self::webhook_request( self::message_event_body() ) );

		$this->assertSame( 200, $response->get_status(), 'Slack should still get a 200.' );
		$this->assertSame(
			[],
			get_posts(
				[
					'post_type'   => Post_Type::CPT_SLUG,
					'post_status' => 'any',
				]
			),
			'No entry should be created.'
		);
		$this->assertSame( [], $this->outbound_requests, 'No Slack API call should be made for a dropped message.' );
	}
}
