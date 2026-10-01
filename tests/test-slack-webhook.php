<?php
/**
 * Tests for the Slack webhook: request gating, message filtering and the
 * path from a message event to an entry.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Entry_Ingestion_Service;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Slack;
use Newspack_Rolling_Coverage\Slack_API_Client;
use Newspack_Rolling_Coverage\Slack_Config;
use Newspack_Rolling_Coverage\Slack_Ingestion_Service;
use Newspack_Rolling_Coverage\Slack_Media_Importer;
use Newspack_Rolling_Coverage\Slack_Signature_Verifier;
use Newspack_Rolling_Coverage\Slack_Webhook_Controller;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * The webhook routes are only registered once Slack is configured at load
 * time, so these tests call the controller directly, apart from the one that
 * registers the routes itself to check each is gated. Outbound Slack API
 * calls are answered by a `pre_http_request` filter; nothing leaves the
 * process.
 */
class Test_Slack_Webhook extends Rolling_Coverage_TestCase {

	const SIGNING_SECRET = '0123456789abcdef0123456789abcdef';
	const BOT_TOKEN      = 'xoxb-000000-test';
	const CHANNEL_ID     = 'C0TESTCHAN';
	const MESSAGE_TS     = '1767225600.000100';

	/**
	 * A 1x1 PNG, base64-encoded: the smallest file WordPress accepts as an image.
	 */
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	/**
	 * URLs of the outbound requests the code under test attempted.
	 *
	 * @var string[]
	 */
	private $outbound_requests = [];

	/**
	 * Slack user IDs whose `users.info` lookup times out.
	 *
	 * @var string[]
	 */
	private $failing_users = [];

	/**
	 * The messages the channel's history holds, keyed by timestamp: the text,
	 * or the message's fields when it needs more than text.
	 *
	 * @var array<string, string|array>
	 */
	private $channel_messages = [];

	/**
	 * How reading the channel's history fails: '' when it works, 'timeout'
	 * when Slack does not answer, or the error code Slack answers with.
	 *
	 * @var string
	 */
	private $history_failure = '';

	/**
	 * Seconds Slack takes to answer when the channel's history is read.
	 *
	 * @var float
	 */
	private $history_response_seconds = 0.0;

	/**
	 * Requests made for Slack files: their `url`, `headers` and `redirection`.
	 *
	 * @var array[]
	 */
	private $file_requests = [];

	/**
	 * What a Slack file download answers with: a `type` and `body`, with a
	 * `code` when it is not 200, or a WP_Error for a failed request.
	 *
	 * @var array|WP_Error
	 */
	private $file_response = [];

	/**
	 * Scopes Slack reports for the bot token, or null to report none, as
	 * when Slack cannot be reached.
	 *
	 * @var string|null
	 */
	private $token_scopes = null;

	/**
	 * Runs when a Slack file is requested, to stand in for what another
	 * request does while this one downloads.
	 *
	 * @var callable|null
	 */
	private $during_file_request = null;

	/**
	 * Answer every outbound HTTP request as the Slack API would, and every
	 * file download with a PNG.
	 */
	public function set_up() {
		parent::set_up();
		$this->outbound_requests        = [];
		$this->failing_users            = [];
		$this->channel_messages         = [];
		$this->history_failure          = '';
		$this->history_response_seconds = 0.0;
		$this->file_requests            = [];
		$this->token_scopes             = null;
		$this->file_response            = [
			'type' => 'image/png',
			'body' => base64_decode( self::PNG ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.
		];
		add_filter( 'pre_http_request', [ $this, 'mock_slack_api' ], 10, 3 );
	}

	/**
	 * Drop the REST server so routes a test registered do not outlive it. The
	 * core test case restores hooks between tests, but keeps the server.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * Stand in for the Slack API: a file download answers with the file
	 * response, `conversations.history` answers from the channel's messages,
	 * and every other method with a `users.info` payload.
	 *
	 * @param false|array $response    Short-circuit value.
	 * @param array       $parsed_args Request arguments.
	 * @param string      $url         Request URL.
	 * @return array|WP_Error Mocked response.
	 */
	public function mock_slack_api( $response, $parsed_args, $url ) {
		$this->outbound_requests[] = $url;

		if ( 'slack.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			$this->file_requests[] = [
				'url'         => $url,
				'headers'     => $parsed_args['headers'],
				'redirection' => $parsed_args['redirection'],
			];

			if ( is_wp_error( $this->file_response ) ) {
				return $this->file_response;
			}

			if ( null !== $this->during_file_request ) {
				( $this->during_file_request )();
			}

			// A streamed download is written to the file the caller named.
			file_put_contents( $parsed_args['filename'], $this->file_response['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- The path is the temporary file the code under test created.

			return [
				'headers'  => [ 'content-type' => $this->file_response['type'] ],
				'response' => [
					'code'    => $this->file_response['code'] ?? 200,
					'message' => '',
				],
				'body'     => '',
			];
		}

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		if ( false !== strpos( $url, 'conversations.history' ) ) {
			if ( 'timeout' === $this->history_failure ) {
				return new WP_Error( 'http_request_failed', 'Operation timed out' );
			}

			if ( '' !== $this->history_failure ) {
				return $this->slack_api_response(
					[
						'ok'    => false,
						'error' => $this->history_failure,
					]
				);
			}

			usleep( (int) ( $this->history_response_seconds * 1000000 ) );

			// Like Slack, answer with the newest message up to `latest`.
			$timestamps = array_filter( array_keys( $this->channel_messages ), fn( $ts ) => (float) $ts <= (float) ( $query['latest'] ?? 0 ) );
			$messages   = [];

			if ( ! empty( $timestamps ) ) {
				$newest_ts  = (string) max( $timestamps );
				$message    = $this->channel_messages[ $newest_ts ];
				$messages[] = [ 'ts' => $newest_ts ] + ( is_array( $message ) ? $message : [ 'text' => $message ] );
			}

			return $this->slack_api_response( [ 'messages' => $messages ] );
		}

		if ( in_array( $query['user'] ?? '', $this->failing_users, true ) ) {
			return new WP_Error( 'http_request_failed', 'Operation timed out' );
		}

		return $this->slack_api_response(
			[
				'user' => [
					'name'    => 'rsample',
					'profile' => [ 'display_name' => 'Riley Sample' ],
				],
			]
		);
	}

	/**
	 * Build a Slack API response, successful unless the payload says otherwise.
	 *
	 * @param array $payload Method-specific fields.
	 * @return array HTTP response.
	 */
	private function slack_api_response( array $payload ) {
		return [
			'headers'  => null === $this->token_scopes ? [] : [ 'x-oauth-scopes' => $this->token_scopes ],
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => wp_json_encode( $payload + [ 'ok' => true ] ),
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
	 * @param string $route     Webhook route, relative to `/slack/`.
	 * @return WP_REST_Request
	 */
	private static function webhook_request( $body, $is_signed = true, $route = 'events' ) {
		$request = new WP_REST_Request( 'POST', '/rolling-coverage/v1/slack/' . $route );
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
						'ts'      => self::MESSAGE_TS,
						'text'    => 'Polls have closed across the county.',
					],
					$event_overrides
				),
			]
		);
	}

	/**
	 * A file as Slack describes it in a message event.
	 *
	 * @param array $overrides File fields to override.
	 * @return array
	 */
	private static function slack_file( array $overrides = [] ) {
		return array_merge(
			[
				'id'          => 'F0PHOTO',
				'name'        => 'polling-place.png',
				'mimetype'    => 'image/png',
				'filetype'    => 'png',
				'size'        => 68,
				'url_private' => 'https://files.slack.com/files-pri/T0TEAM-F0PHOTO/polling-place.png',
			],
			$overrides
		);
	}

	/**
	 * Deliver a message to a channel linked to a new coverage.
	 *
	 * @param array $event_overrides Event fields to override.
	 * @return int Coverage term ID.
	 */
	private static function deliver_to_linked_channel( array $event_overrides ) {
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );

		self::controller()->handle_event( self::webhook_request( self::message_event_body( $event_overrides ) ) );

		return $coverage_id;
	}

	/**
	 * Give every image a `large` copy in its metadata, as WordPress would for
	 * a photo wider than that size, without needing an image editor to make it.
	 */
	private static function pretend_images_have_a_large_copy() {
		add_filter(
			'wp_get_attachment_metadata',
			static function ( $metadata ) {
				if ( is_array( $metadata ) && ! empty( $metadata['file'] ) ) {
					$metadata['sizes']['large'] = [
						'file'      => pathinfo( $metadata['file'], PATHINFO_FILENAME ) . '-1024x768.' . pathinfo( $metadata['file'], PATHINFO_EXTENSION ),
						'width'     => 1024,
						'height'    => 768,
						'mime-type' => 'image/png',
					];
				}

				return $metadata;
			}
		);
	}

	/**
	 * Every image in the media library.
	 *
	 * @return WP_Post[]
	 */
	private static function get_media() {
		return get_posts(
			[
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => -1,
			]
		);
	}

	/**
	 * Build the body of a reply in the thread of the default message.
	 *
	 * @param array $event_overrides Event fields to override.
	 * @return string JSON body.
	 */
	private static function thread_reply_event_body( array $event_overrides = [] ) {
		return self::message_event_body(
			array_merge(
				[
					'ts'        => '1767225700.000200',
					'thread_ts' => self::MESSAGE_TS,
					'text'      => 'Is that confirmed by the clerk?',
				],
				$event_overrides
			)
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
	 * The webhook routes, relative to `/slack/`.
	 *
	 * @return array[]
	 */
	public function webhook_route_provider() {
		return [
			'events'       => [ 'events' ],
			'commands'     => [ 'commands' ],
			'interactions' => [ 'interactions' ],
		];
	}

	/**
	 * Every webhook route runs the signature check first, so an unsigned
	 * request never reaches its handler.
	 *
	 * @dataProvider webhook_route_provider
	 *
	 * @param string $route Webhook route, relative to `/slack/`.
	 */
	public function test_webhook_routes_refuse_unsigned_requests( $route ) {
		$this->silence_error_log();
		self::configure_slack();
		add_action( 'rest_api_init', [ self::controller(), 'register_webhook_routes' ] );

		// The server is built once per run; rebuild it so the routes register.
		$GLOBALS['wp_rest_server'] = null;

		$unsigned_request = self::webhook_request( self::message_event_body(), false, $route );

		$this->assertSame( 401, rest_get_server()->dispatch( $unsigned_request )->get_status() );
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
	 * A formatted message keeps its formatting in the entry, mentions show
	 * the person's name, and the entry has no generated title.
	 */
	public function test_formatted_message_keeps_its_formatting() {
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );

		$body = self::message_event_body(
			[
				'text'   => '*Polls closed.* Thanks <@U0COLLEAGUE>',
				'blocks' => [
					[
						'type'     => 'rich_text',
						'elements' => [
							[
								'type'     => 'rich_text_section',
								'elements' => [
									[
										'type'  => 'text',
										'text'  => 'Polls closed.',
										'style' => [ 'bold' => true ],
									],
									[
										'type' => 'text',
										'text' => ' Thanks ',
									],
									[
										'type'    => 'user',
										'user_id' => 'U0COLLEAGUE',
									],
								],
							],
							[
								'type'     => 'rich_text_list',
								'style'    => 'bullet',
								'elements' => [
									[
										'type'     => 'rich_text_section',
										'elements' => [
											[
												'type' => 'link',
												'url'  => 'https://example.test/results',
												'text' => 'Results',
											],
										],
									],
								],
							],
						],
					],
				],
			]
		);

		self::controller()->handle_event( self::webhook_request( $body ) );
		$entries = self::get_coverage_entries( $coverage_id );

		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( '<p><strong>Polls closed.</strong> Thanks @Riley Sample</p>', $entries[0]->post_content, 'Inline styles and mentions should be kept.' );
		$this->assertStringContainsString( '<li><a href="https://example.test/results">Results</a></li>', $entries[0]->post_content, 'Lists and links should be kept.' );
		$this->assertSame( '', $entries[0]->post_title, 'The entry should have no generated title.' );
	}

	/**
	 * When the author lookup fails, Slack is likely slow, so mentions are not
	 * looked up and show their Slack ID rather than add more waiting to the
	 * webhook request.
	 */
	public function test_mentions_are_not_looked_up_when_the_author_lookup_fails() {
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );
		$this->failing_users = [ 'U0REPORTER' ];

		$body = self::message_event_body(
			[
				'text'   => 'Thanks <@U0COLLEAGUE>',
				'blocks' => [
					[
						'type'     => 'rich_text',
						'elements' => [
							[
								'type'     => 'rich_text_section',
								'elements' => [
									[
										'type' => 'text',
										'text' => 'Thanks ',
									],
									[
										'type'    => 'user',
										'user_id' => 'U0COLLEAGUE',
									],
								],
							],
						],
					],
				],
			]
		);

		self::controller()->handle_event( self::webhook_request( $body ) );
		$entries = self::get_coverage_entries( $coverage_id );

		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( '<p>Thanks @U0COLLEAGUE</p>', $entries[0]->post_content );
		$this->assertCount( 1, array_filter( $this->outbound_requests, fn( $url ) => false !== strpos( $url, 'users.info' ) ), 'Only the author should be looked up.' );
	}

	/**
	 * Names already cached are used even when the author lookup fails, since
	 * they cost no request.
	 */
	public function test_cached_mention_names_are_used_when_the_author_lookup_fails() {
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );
		$this->failing_users = [ 'U0REPORTER' ];
		set_transient( Slack_API_Client::TRANSIENT_USER_CACHE . 'U0COLLEAGUE', [ 'profile' => [ 'display_name' => 'Sam Rivera' ] ] );

		$body = self::message_event_body(
			[
				'text'   => 'Thanks <@U0COLLEAGUE>',
				'blocks' => [
					[
						'type'     => 'rich_text',
						'elements' => [
							[
								'type'     => 'rich_text_section',
								'elements' => [
									[
										'type' => 'text',
										'text' => 'Thanks ',
									],
									[
										'type'    => 'user',
										'user_id' => 'U0COLLEAGUE',
									],
								],
							],
						],
					],
				],
			]
		);

		self::controller()->handle_event( self::webhook_request( $body ) );
		$entries = self::get_coverage_entries( $coverage_id );

		$this->assertStringContainsString( '<p>Thanks @Sam Rivera</p>', $entries[0]->post_content );
	}

	/**
	 * A linked channel's settings report when it last ingested a message, so
	 * the connection drawer can show it.
	 */
	public function test_channel_settings_include_the_last_sync() {
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel(
			self::CHANNEL_ID,
			[
				'term_id'      => $coverage_id,
				'autopublish'  => true,
				'last_sync_ts' => '1767225600.000100',
			]
		);
		$request = new WP_REST_Request( 'GET', '/rolling-coverage/v1/slack/channel/' . self::CHANNEL_ID );
		$request->set_param( 'id', self::CHANNEL_ID );

		$data = self::controller()->get_channel_settings( $request )->get_data();

		$this->assertTrue( $data['autopublish'], 'The stored autopublish setting should be returned.' );
		$this->assertSame( '1767225600.000100', $data['last_sync_ts'], 'The last ingested message timestamp should be returned.' );
	}

	/**
	 * Channel settings, including the last sync, are for administrators only.
	 */
	public function test_channel_settings_are_admin_only() {
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => self::create_coverage() ] );
		$GLOBALS['wp_rest_server'] = null;
		$request                   = new WP_REST_Request( 'GET', '/' . Slack::REST_NAMESPACE . '/slack/channel/' . self::CHANNEL_ID );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status(), 'Editors should not read channel settings.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status(), 'Administrators should read channel settings.' );
	}

	/**
	 * A channel that has never ingested a message reports an empty last sync.
	 */
	public function test_channel_settings_report_no_sync_yet() {
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => self::create_coverage() ] );
		$request = new WP_REST_Request( 'GET', '/rolling-coverage/v1/slack/channel/' . self::CHANNEL_ID );
		$request->set_param( 'id', self::CHANNEL_ID );

		$this->assertSame( '', self::controller()->get_channel_settings( $request )->get_data()['last_sync_ts'] );
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
	 * A message with the skip prefix opts its whole thread out. The replies
	 * under it are the newsroom's discussion, so none becomes an entry, even
	 * in a channel that publishes its messages straight away.
	 */
	public function test_reply_in_the_thread_of_a_skipped_message_creates_no_entry() {
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel(
			self::CHANNEL_ID,
			[
				'term_id'     => $coverage_id,
				'autopublish' => true,
			]
		);
		$this->channel_messages = [ self::MESSAGE_TS => '~~ not for publication' ];

		$response = self::controller()->handle_event( self::webhook_request( self::thread_reply_event_body() ) );

		$this->assertSame( 200, $response->get_status(), 'Slack should still get a 200.' );
		$this->assertSame( [], self::get_coverage_entries( $coverage_id ), 'No entry should be created.' );
	}

	/**
	 * A Slack reply's entry is a child of the entry for the message it was
	 * posted under.
	 */
	public function test_reply_entry_is_a_child_of_the_entry_for_its_thread() {
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );
		$this->channel_messages = [ self::MESSAGE_TS => 'Polls have closed across the county.' ];

		self::controller()->handle_event( self::webhook_request( self::message_event_body() ) );
		$message_entry_id = self::get_coverage_entries( $coverage_id )[0]->ID;
		self::controller()->handle_event( self::webhook_request( self::thread_reply_event_body() ) );
		$parents_by_entry = wp_list_pluck( self::get_coverage_entries( $coverage_id ), 'post_parent', 'ID' );

		$this->assertCount( 2, $parents_by_entry, 'The message and its reply should each have an entry.' );
		unset( $parents_by_entry[ $message_entry_id ] );
		$this->assertSame( [ $message_entry_id ], array_values( $parents_by_entry ), 'The reply should be a child of the message entry.' );
	}

	/**
	 * Reading a reply's thread counts toward the time the webhook may spend on
	 * Slack lookups, so after a slow read the mentions are not looked up and
	 * Slack still gets its answer in time.
	 */
	public function test_mentions_are_not_looked_up_after_a_slow_thread_lookup() {
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );
		$this->channel_messages         = [ self::MESSAGE_TS => 'Polls have closed across the county.' ];
		$this->history_response_seconds = Slack_Webhook_Controller::MENTION_LOOKUP_BUDGET + 0.05;

		$body = self::thread_reply_event_body(
			[
				'text'   => 'Thanks <@U0COLLEAGUE>',
				'blocks' => [
					[
						'type'     => 'rich_text',
						'elements' => [
							[
								'type'     => 'rich_text_section',
								'elements' => [
									[
										'type' => 'text',
										'text' => 'Thanks ',
									],
									[
										'type'    => 'user',
										'user_id' => 'U0COLLEAGUE',
									],
								],
							],
						],
					],
				],
			]
		);

		self::controller()->handle_event( self::webhook_request( $body ) );
		$entries = self::get_coverage_entries( $coverage_id );

		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( '<p>Thanks @U0COLLEAGUE</p>', $entries[0]->post_content, 'The mention should show its Slack ID.' );
		$this->assertCount( 1, array_filter( $this->outbound_requests, fn( $url ) => false !== strpos( $url, 'users.info' ) ), 'Only the author should be looked up.' );
	}

	/**
	 * Failures of the thread read that a later attempt can get past.
	 *
	 * @return array[]
	 */
	public function passing_history_failure_provider() {
		return [
			'Slack does not answer in time' => [ 'timeout' ],
			'Slack is rate limiting'        => [ 'ratelimited' ],
		];
	}

	/**
	 * When the thread of a reply cannot be read for a passing reason, the
	 * reply is neither ingested nor given up on: the webhook answers with an
	 * error, which is what makes Slack deliver the event again.
	 *
	 * @dataProvider passing_history_failure_provider
	 *
	 * @param string $history_failure How reading the channel's history fails.
	 */
	public function test_reply_is_left_for_slack_to_resend_when_its_thread_read_fails( $history_failure ) {
		$this->silence_error_log();
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );
		$this->history_failure = $history_failure;

		$response = self::controller()->handle_event( self::webhook_request( self::thread_reply_event_body() ) );

		$this->assertSame( 503, $response->get_status(), 'Slack should be told the delivery failed.' );
		$this->assertSame( [], self::get_coverage_entries( $coverage_id ), 'No entry should be created.' );
	}

	/**
	 * What the channel's history holds once the first message of a thread has
	 * been deleted.
	 *
	 * @return array[]
	 */
	public function deleted_thread_message_provider() {
		return [
			'only earlier messages are left'    => [ [ '1767225500.000050' => 'An earlier message.' ] ],
			'a placeholder stands in its place' => [
				[
					self::MESSAGE_TS => [
						'subtype' => 'tombstone',
						'text'    => 'This message was deleted.',
					],
				],
			],
		];
	}

	/**
	 * A reply whose thread lost its first message might belong to a skipped
	 * message, and no later attempt can tell. It is left out for good rather
	 * than risk publishing it.
	 *
	 * @dataProvider deleted_thread_message_provider
	 *
	 * @param array $channel_messages Messages the channel's history holds, keyed by timestamp.
	 */
	public function test_reply_creates_no_entry_when_the_first_message_of_its_thread_is_gone( array $channel_messages ) {
		$this->silence_error_log();
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );
		$this->channel_messages = $channel_messages;

		$response = self::controller()->handle_event( self::webhook_request( self::thread_reply_event_body() ) );

		$this->assertSame( 200, $response->get_status(), 'Slack should not be asked to resend the reply.' );
		$this->assertSame( [], self::get_coverage_entries( $coverage_id ), 'No entry should be created.' );
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

	/**
	 * An image uploaded with a message lands in the media library, owned by
	 * the bot user and attached to the entry, and shows below the text.
	 */
	public function test_uploaded_image_is_added_to_the_entry() {
		$coverage_id = self::deliver_to_linked_channel(
			[
				'subtype' => 'file_share',
				'files'   => [ self::slack_file() ],
			]
		);
		$entries     = self::get_coverage_entries( $coverage_id );
		$media       = self::get_media();

		$this->assertCount( 1, $entries, 'The message should create one entry.' );
		$this->assertCount( 1, $media, 'The image should be added to the media library.' );

		$entry = $entries[0];
		$image = $media[0];

		$this->assertSame( 'image/png', $image->post_mime_type, 'The image should keep its type.' );
		$this->assertSame( $entry->ID, $image->post_parent, 'The image should be attached to the entry.' );
		$this->assertSame( Slack_Config::get_or_create_bot_user_id(), (int) $image->post_author, 'The bot user should own the image.' );
		$this->assertMatchesRegularExpression(
			'#<p>Polls have closed across the county\.</p>.*<!-- wp:image \{"id":' . $image->ID . ',"sizeSlug":"large","linkDestination":"none"\} -->\s*<figure class="wp-block-image size-large"><img src="' . preg_quote( wp_get_attachment_url( $image->ID ), '#' ) . '" alt="" class="wp-image-' . $image->ID . '" ?/></figure>\s*<!-- /wp:image -->#s',
			$entry->post_content,
			'The image block should follow the message text.'
		);
		$this->assertSame(
			[
				[
					'url'         => 'https://files.slack.com/files-pri/T0TEAM-F0PHOTO/polling-place.png',
					'headers'     => [ 'Authorization' => 'Bearer ' . self::BOT_TOKEN ],
					'redirection' => 0,
				],
			],
			$this->file_requests,
			'The file should be downloaded once, as the bot, without following a redirect that would carry the token elsewhere.'
		);
	}

	/**
	 * A photo posted without a caption is an entry of its own.
	 */
	public function test_message_with_only_an_image_becomes_an_entry() {
		$coverage_id = self::deliver_to_linked_channel(
			[
				'subtype' => 'file_share',
				'text'    => '',
				'files'   => [ self::slack_file() ],
			]
		);
		$entries     = self::get_coverage_entries( $coverage_id );

		$this->assertCount( 1, $entries, 'The image should create an entry.' );
		$this->assertStringStartsWith( '<!-- wp:image ', $entries[0]->post_content, 'The entry should hold just the image.' );
	}

	/**
	 * Several images keep the order they were uploaded in.
	 */
	public function test_several_images_keep_their_order() {
		$coverage_id = self::deliver_to_linked_channel(
			[
				'files' => [
					self::slack_file( [ 'name' => 'first.png' ] ),
					self::slack_file( [ 'name' => 'second.png' ] ),
				],
			]
		);

		$this->assertMatchesRegularExpression( '#first\.png.*second\.png#s', self::get_coverage_entries( $coverage_id )[0]->post_content );
	}

	/**
	 * The entry stores the image's own URL, as the editor would, even when an
	 * image CDN rewrites image URLs for the request.
	 */
	public function test_entry_stores_the_image_url_from_the_media_library() {
		add_filter( 'image_downsize', static fn() => [ 'https://cdn.example.test/polling-place.png?w=1024', 1024, 768, true ] );

		$coverage_id = self::deliver_to_linked_channel( [ 'files' => [ self::slack_file() ] ] );
		$content     = self::get_coverage_entries( $coverage_id )[0]->post_content;

		$this->assertStringContainsString( 'src="' . wp_get_attachment_url( self::get_media()[0]->ID ) . '"', $content );
		$this->assertStringNotContainsString( 'cdn.example.test', $content );
	}

	/**
	 * A photo wider than the `large` size is shown from its `large` copy, also
	 * by that copy's own URL.
	 */
	public function test_entry_stores_the_url_of_the_large_copy_of_a_big_image() {
		self::pretend_images_have_a_large_copy();
		add_filter( 'image_downsize', static fn() => [ 'https://cdn.example.test/polling-place.png?w=1024', 1024, 768, true ] );

		$coverage_id = self::deliver_to_linked_channel( [ 'files' => [ self::slack_file() ] ] );

		$this->assertStringContainsString(
			'src="' . dirname( wp_get_attachment_url( self::get_media()[0]->ID ) ) . '/polling-place-1024x768.png"',
			self::get_coverage_entries( $coverage_id )[0]->post_content
		);
	}

	/**
	 * WordPress resizes a GIF to a single frame, so a GIF is shown from the
	 * file that was uploaded and keeps its animation.
	 */
	public function test_gif_is_shown_from_its_original_file() {
		self::pretend_images_have_a_large_copy();
		$this->file_response = [
			'type' => 'image/gif',
			'body' => base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture: a 1x1 GIF.
		];

		$coverage_id = self::deliver_to_linked_channel(
			[
				'files' => [
					self::slack_file(
						[
							'name'     => 'count.gif',
							'mimetype' => 'image/gif',
						]
					),
				],
			]
		);
		$image       = self::get_media()[0];
		$content     = self::get_coverage_entries( $coverage_id )[0]->post_content;

		$this->assertStringContainsString( 'src="' . wp_get_attachment_url( $image->ID ) . '"', $content, 'The image should come from the uploaded file.' );
		$this->assertStringContainsString( '{"id":' . $image->ID . ',"sizeSlug":"full","linkDestination":"none"}', $content, 'The block should say it shows the full size.' );
		$this->assertStringContainsString( 'class="wp-block-image size-full"', $content, 'The figure should be styled as full size.' );
	}

	/**
	 * The description a reporter gave the image in Slack becomes its alt text,
	 * as plain text, in the entry and in the media library alike.
	 */
	public function test_image_keeps_its_slack_description_as_alt_text() {
		$coverage_id = self::deliver_to_linked_channel(
			[ 'files' => [ self::slack_file( [ 'alt_txt' => ' Voters <b>queue</b> outside "Hall A" ' ] ) ] ]
		);

		$this->assertStringContainsString(
			'alt="Voters queue outside &quot;Hall A&quot;"',
			self::get_coverage_entries( $coverage_id )[0]->post_content,
			'The entry should carry the description, escaped.'
		);
		$this->assertSame(
			'Voters queue outside "Hall A"',
			get_post_meta( self::get_media()[0]->ID, '_wp_attachment_image_alt', true ),
			'The media library should carry the description.'
		);
	}

	/**
	 * Uploads that are not images Slack can be asked for.
	 *
	 * @return array[]
	 */
	public function skipped_file_provider() {
		return [
			'a document'                    => [
				[
					'name'     => 'results.pdf',
					'mimetype' => 'application/pdf',
				],
			],
			'a video'                       => [
				[
					'name'     => 'clip.mp4',
					'mimetype' => 'video/mp4',
				],
			],
			'an image format browsers lack' => [
				[
					'name'     => 'photo.heic',
					'mimetype' => 'image/heic',
				],
			],
			'a file Slack no longer has'    => [ [ 'url_private' => '' ] ],
			'a file hosted outside Slack'   => [ [ 'url_private' => 'https://files.example.test/polling-place.png' ] ],
			'a file not served over https'  => [ [ 'url_private' => 'http://files.slack.com/files-pri/T0TEAM-F0PHOTO/polling-place.png' ] ],
			'a file above the upload limit' => [ [ 'size' => PHP_INT_MAX ] ],
		];
	}

	/**
	 * Only images are imported, and the bot token is only ever sent to Slack's
	 * file host. The message text still becomes an entry.
	 *
	 * @dataProvider skipped_file_provider
	 *
	 * @param array $file Slack file fields.
	 */
	public function test_leaves_out_uploads_that_are_not_slack_images( array $file ) {
		$this->silence_error_log();
		$coverage_id = self::deliver_to_linked_channel( [ 'files' => [ self::slack_file( $file ) ] ] );
		$entries     = self::get_coverage_entries( $coverage_id );

		$this->assertSame( [], $this->file_requests, 'The file should not be requested.' );
		$this->assertSame( [], self::get_media(), 'Nothing should be added to the media library.' );
		$this->assertCount( 1, $entries, 'The message text should still become an entry.' );
		$this->assertStringNotContainsString( 'wp:image', $entries[0]->post_content, 'The entry should have no image.' );
	}

	/**
	 * What Slack can answer a file request with in place of the image.
	 *
	 * @return array[]
	 */
	public function failed_download_provider() {
		return [
			'the request fails'               => [ new WP_Error( 'http_request_failed', 'Operation timed out' ) ],
			// A page in place of the image, as an app without the files:read scope could get.
			'a sign-in page comes back'       => [
				[
					'type' => 'text/html; charset=utf-8',
					'body' => '<html><body>Sign in to Slack</body></html>',
				],
			],
			'Slack redirects the request'     => [
				[
					'code' => 302,
					'type' => 'image/png',
					'body' => base64_decode( self::PNG ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.
				],
			],
			'the content is not what it says' => [
				[
					'type' => 'image/png',
					'body' => '<html><body>Not a PNG</body></html>',
				],
			],
		];
	}

	/**
	 * An image that cannot be downloaded does not cost the newsroom the text
	 * that came with it, and leaves nothing behind.
	 *
	 * @dataProvider failed_download_provider
	 *
	 * @param array|WP_Error $file_response What the file request answers with.
	 */
	public function test_failed_image_download_keeps_the_message_text( $file_response ) {
		$this->silence_error_log();
		$this->file_response = $file_response;

		$coverage_id = self::deliver_to_linked_channel( [ 'files' => [ self::slack_file() ] ] );
		$entries     = self::get_coverage_entries( $coverage_id );

		$this->assertCount( 1, $entries, 'The message text should still become an entry.' );
		$this->assertStringContainsString( '<p>Polls have closed across the county.</p>', $entries[0]->post_content, 'The text should be kept.' );
		$this->assertStringNotContainsString( 'wp:image', $entries[0]->post_content, 'The entry should have no image.' );
		$this->assertSame( [], self::get_media(), 'Nothing should be added to the media library.' );
		$this->assertSame( [], glob( get_temp_dir() . 'polling-place*' ), 'The partial download should be removed.' );
	}

	/**
	 * With no text and no image there is nothing to publish.
	 */
	public function test_image_only_message_is_dropped_when_its_download_fails() {
		$this->silence_error_log();
		$this->file_response = new WP_Error( 'http_request_failed', 'Operation timed out' );

		$coverage_id = self::deliver_to_linked_channel(
			[
				'text'  => '',
				'files' => [ self::slack_file() ],
			]
		);

		$this->assertSame( [], self::get_coverage_entries( $coverage_id ) );
	}

	/**
	 * Slack redelivers a message when the first delivery takes longer than
	 * three seconds, which a large photo can. The redelivery must not import
	 * the photo again.
	 */
	public function test_redelivered_message_does_not_import_its_image_again() {
		$this->silence_error_log();
		$event       = [ 'files' => [ self::slack_file() ] ];
		$coverage_id = self::deliver_to_linked_channel( $event );

		self::controller()->handle_event( self::webhook_request( self::message_event_body( $event ) ) );

		$this->assertCount( 1, self::get_coverage_entries( $coverage_id ), 'There should still be one entry.' );
		$this->assertCount( 1, $this->file_requests, 'The image should be downloaded once.' );
		$this->assertCount( 1, self::get_media(), 'The media library should hold one copy.' );
	}

	/**
	 * An import that runs longer than the lock's lifetime keeps the lock while
	 * WordPress processes the image, so a redelivery arriving then backs off.
	 */
	public function test_slow_image_import_keeps_the_message_locked() {
		$lock_key = Entry_Ingestion_Service::MUTEX_PREFIX . md5( 'slack:1767225600.000100' );
		$lock_age = null;

		// The download takes longer than the lock's lifetime.
		$this->during_file_request = static fn() => update_option( $lock_key, time() - Entry_Ingestion_Service::MUTEX_TTL - 1, false );

		add_action(
			'newspack_rolling_coverage_entry_ingested',
			static function () use ( $lock_key, &$lock_age ) {
				$lock_age = time() - (int) get_option( $lock_key );
			}
		);

		self::deliver_to_linked_channel( [ 'files' => [ self::slack_file() ] ] );

		$this->assertLessThan( Entry_Ingestion_Service::MUTEX_TTL, $lock_age, 'Processing the image should have refreshed the lock.' );
	}

	/**
	 * When another delivery of the message saves its entry while this one is
	 * still downloading, this one stands down and takes its copy of the image
	 * with it.
	 */
	public function test_image_is_removed_when_another_delivery_saved_the_entry_first() {
		$this->silence_error_log();
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );

		$this->during_file_request = static function () use ( $coverage_id ) {
			$other_entry_id = self::factory()->post->create( [ 'post_type' => Post_Type::CPT_SLUG ] );
			wp_set_object_terms( $other_entry_id, [ $coverage_id ], Taxonomy::TAXONOMY_SLUG );
			add_post_meta( $other_entry_id, Post_Type::META_SOURCE_REF, '1767225600.000100' );
		};

		self::controller()->handle_event( self::webhook_request( self::message_event_body( [ 'files' => [ self::slack_file() ] ] ) ) );

		$this->assertCount( 1, self::get_coverage_entries( $coverage_id ), 'Only the other delivery should have an entry.' );
		$this->assertCount( 1, $this->file_requests, 'The image should have been downloaded.' );
		$this->assertSame( [], self::get_media(), 'The image should be removed again.' );
	}

	/**
	 * An image imported for an entry that then fails to save is not left
	 * orphaned in the media library.
	 */
	public function test_image_is_removed_when_the_entry_cannot_be_saved() {
		$this->silence_error_log();
		add_filter(
			'wp_insert_post_empty_content',
			static fn( $is_empty, $postarr ) => Post_Type::CPT_SLUG === $postarr['post_type'],
			10,
			2
		);

		$coverage_id = self::deliver_to_linked_channel( [ 'files' => [ self::slack_file() ] ] );

		$this->assertSame( [], self::get_coverage_entries( $coverage_id ), 'The entry should not be saved.' );
		$this->assertCount( 1, $this->file_requests, 'The image should have been downloaded.' );
		$this->assertSame( [], self::get_media(), 'The image should be removed again.' );
	}

	/**
	 * Images still waiting when the time allowed for a message runs out are
	 * left out, so a slow download cannot hold the webhook open indefinitely.
	 */
	public function test_images_are_left_out_once_the_time_allowed_runs_out() {
		$this->silence_error_log();
		self::configure_slack();
		$importer = new Slack_Media_Importer( new Slack_API_Client(), self::factory()->user->create(), microtime( true ) - 1 );

		$this->assertSame( '', $importer->import( [ self::slack_file() ] ), 'No image block should be rendered.' );
		$this->assertSame( [], $this->file_requests, 'The file should not be requested.' );
	}

	/**
	 * A page in place of the image, as an app without the `files:read` scope
	 * could get, is reported as a file Slack did not serve.
	 */
	public function test_sign_in_page_is_reported_as_an_unreadable_file() {
		self::configure_slack();
		$this->file_response = [
			'type' => 'text/html; charset=utf-8',
			'body' => '<html><body>Sign in to Slack</body></html>',
		];

		$download = ( new Slack_API_Client() )->download_image( self::slack_file()['url_private'], 1 );

		$this->assertWPError( $download );
		$this->assertSame( 'slack_file_not_served', $download->get_error_code() );
	}

	/**
	 * Scopes Slack can report for the bot token, and whether the settings
	 * should say the app can read uploaded files.
	 *
	 * @return array[]
	 */
	public function token_scopes_provider() {
		return [
			'app installed before images were imported' => [ 'channels:history,chat:write,users:read', false ],
			'app with the files:read scope'             => [ 'channels:history,files:read,chat:write', true ],
			'scopes unknown, as when Slack is down'     => [ null, true ],
		];
	}

	/**
	 * The settings say when the Slack app was installed without the scope
	 * that images need, so the admin can ask for the app to be updated. When
	 * the scopes are unknown nothing is reported.
	 *
	 * @dataProvider token_scopes_provider
	 *
	 * @param string|null $scopes         Scopes Slack reports for the token.
	 * @param bool        $can_read_files Whether the settings should say the app can read files.
	 */
	public function test_settings_say_whether_the_app_can_read_uploaded_files( $scopes, $can_read_files ) {
		self::configure_slack();
		$this->token_scopes = $scopes;

		$settings = self::controller()->get_settings( new WP_REST_Request( 'GET', '/rolling-coverage/v1/slack/settings' ) )->get_data();

		$this->assertSame( $can_read_files, $settings['can_read_files'] );
	}

	/**
	 * A site that is not connected to Slack makes no request to it.
	 */
	public function test_settings_do_not_ask_slack_for_scopes_until_connected() {
		self::controller()->get_settings( new WP_REST_Request( 'GET', '/rolling-coverage/v1/slack/settings' ) );

		$this->assertSame( [], $this->outbound_requests );
	}
}
