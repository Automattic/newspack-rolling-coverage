<?php
/**
 * Tests for the Slack webhook: request gating, message filtering and the
 * path from a message event to an entry.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Archive_Mode;
use Newspack_Rolling_Coverage\Entry_Ingestion_Service;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Push_Notifications;
use Newspack_Rolling_Coverage\Slack;
use Newspack_Rolling_Coverage\Slack_API_Client;
use Newspack_Rolling_Coverage\Slack_Author_Resolver;
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
	const REPLY_TS       = '1767225700.000200';

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
	 * Reactions the code under test asked Slack to add or remove: the
	 * method (`reactions.add` or `reactions.remove`) and the request body.
	 *
	 * @var array[]
	 */
	private $reaction_requests = [];

	/**
	 * Error code Slack answers reaction requests with, or '' when they work.
	 *
	 * @var string
	 */
	private $reaction_failure = '';

	/**
	 * Texts of the messages the bot posted to channels.
	 *
	 * @var string[]
	 */
	private $posted_messages = [];

	/**
	 * Runs when a reacted message is read, to stand in for what another
	 * request does meanwhile.
	 *
	 * @var callable|null
	 */
	private $during_message_read = null;

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
		$this->reaction_requests        = [];
		$this->reaction_failure         = '';
		$this->posted_messages          = [];
		$this->during_message_read      = null;
		$this->file_response            = [
			'type' => 'image/png',
			'body' => base64_decode( self::PNG ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.
		];
		add_filter( 'pre_http_request', [ $this, 'mock_slack_api' ], 10, 3 );
	}

	/**
	 * Drop the REST server so routes a test registered do not outlive it, and
	 * the route a test said WordPress is serving. The core test case restores
	 * hooks between tests, but keeps both.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		unset( $GLOBALS['wp']->query_vars['rest_route'] );
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * Stand in for the Slack API: a file download answers with the file
	 * response, `conversations.history` answers from the channel's messages,
	 * `conversations.replies` answers with a message and, for a reply, its
	 * thread's first message, `conversations.info` describes a channel the
	 * bot is in, reaction requests and posted messages are recorded, and
	 * every other method answers with a `users.info` payload.
	 *
	 * @param false|array $response    Short-circuit value.
	 * @param array       $parsed_args Request arguments.
	 * @param string      $url         Request URL.
	 * @return array|WP_Error Mocked response.
	 */
	public function mock_slack_api( $response, $parsed_args, $url ) {
		$this->outbound_requests[] = $url;

		// A notification due now spawns cron; that request goes nowhere.
		if ( false !== strpos( $url, 'wp-cron.php' ) ) {
			return [
				'headers'  => [],
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'body'     => '',
			];
		}

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

		if ( false !== strpos( $url, '/reactions.' ) ) {
			$this->reaction_requests[] = [
				'method' => basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
				'body'   => json_decode( (string) $parsed_args['body'], true ),
			];

			if ( '' !== $this->reaction_failure ) {
				return $this->slack_api_response(
					[
						'ok'    => false,
						'error' => $this->reaction_failure,
					]
				);
			}

			return $this->slack_api_response( [] );
		}

		if ( false !== strpos( $url, 'chat.postMessage' ) ) {
			$this->posted_messages[] = (string) ( json_decode( (string) $parsed_args['body'], true )['text'] ?? '' );
			return $this->slack_api_response( [] );
		}

		if ( false !== strpos( $url, 'conversations.info' ) ) {
			return $this->slack_api_response(
				[
					'channel' => [
						'id'        => (string) ( $query['channel'] ?? '' ),
						'name'      => 'election-desk',
						'is_member' => true,
					],
				]
			);
		}

		if ( false !== strpos( $url, 'conversations.replies' ) ) {
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

			if ( null !== $this->during_message_read ) {
				( $this->during_message_read )();
			}

			$ts = (string) ( $query['ts'] ?? '' );

			if ( ! isset( $this->channel_messages[ $ts ] ) ) {
				return $this->slack_api_response(
					[
						'ok'    => false,
						'error' => 'thread_not_found',
					]
				);
			}

			$as_message = static fn( $message_ts, $message ) => [ 'ts' => (string) $message_ts ] + ( is_array( $message ) ? $message : [ 'text' => $message ] );
			$message    = $as_message( $ts, $this->channel_messages[ $ts ] );
			$messages   = [ $message ];
			$thread_ts  = (string) ( $message['thread_ts'] ?? '' );

			// A reply comes after the message its thread starts from, so the
			// code under test has to pick the one it asked for.
			if ( '' !== $thread_ts && $thread_ts !== $ts && isset( $this->channel_messages[ $thread_ts ] ) ) {
				array_unshift( $messages, $as_message( $thread_ts, $this->channel_messages[ $thread_ts ] ) );
			}

			return $this->slack_api_response( [ 'messages' => $messages ] );
		}

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
				'numberposts' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_numberposts -- Tests count every copy the code under test added.
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
			'channel description set'      => [ self::channel_description_message() ],
			'channel topic set'            => [
				[
					'subtype' => 'channel_topic',
					'text'    => '<@U0REPORTER> set the channel topic: Results',
				],
			],
			'message pinned'               => [
				[
					'subtype' => 'pinned_item',
					'text'    => '<@U0REPORTER> pinned a message to this channel.',
				],
			],
			'subtype Slack adds later'     => [
				[
					'subtype' => 'channel_something_new',
					'text'    => '<@U0REPORTER> did something new.',
				],
			],
		];
	}

	/**
	 * The message Slack posts when someone sets the channel's description.
	 * It has an author and text, as a reporter's message does.
	 *
	 * @return array
	 */
	private static function channel_description_message() {
		return [
			'subtype' => 'channel_purpose',
			'user'    => 'U0REPORTER',
			'text'    => '<@U0REPORTER> set the channel description: Live updates from election night.',
			'purpose' => 'Live updates from election night.',
		];
	}

	/**
	 * Bot traffic, edits, Slack's notices about the channel and opted-out
	 * messages are skipped.
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
	 * skip prefix somewhere after its first character, one that shares a
	 * file, a thread reply also sent to the channel, and a `/me` message.
	 */
	public function test_keeps_reporter_messages() {
		$this->assertFalse( Slack_Ingestion_Service::should_filter_message( [ 'text' => 'Polls have closed.' ] ), 'A plain message should be kept.' );
		$this->assertFalse( Slack_Ingestion_Service::should_filter_message( [ 'text' => 'Turnout was ~~60%~~ 62%.' ] ), 'The skip prefix only counts at the start of the message.' );
		$this->assertFalse(
			Slack_Ingestion_Service::should_filter_message(
				[
					'subtype' => 'file_share',
					'text'    => 'The line outside Hall A.',
					'files'   => [ self::slack_file() ],
				]
			),
			'A message sharing a file should be kept.'
		);
		$this->assertFalse(
			Slack_Ingestion_Service::should_filter_message(
				[
					'subtype'   => 'thread_broadcast',
					'text'      => 'The clerk confirmed it.',
					'thread_ts' => self::MESSAGE_TS,
				]
			),
			'A thread reply also sent to the channel should be kept.'
		);
		$this->assertFalse(
			Slack_Ingestion_Service::should_filter_message(
				[
					'subtype' => 'me_message',
					'text'    => 'is outside the county clerk\'s office.',
				]
			),
			'A /me message should be kept.'
		);
	}

	/**
	 * Slack's notice that someone set the channel's description is not an
	 * entry, even in a channel that publishes its messages straight away.
	 */
	public function test_channel_description_notice_creates_no_entry() {
		$coverage_id = self::link_channel( Slack_Config::PUBLISH_MODE_PUBLISH );

		$response = self::controller()->handle_event( self::webhook_request( self::message_event_body( self::channel_description_message() ) ) );

		$this->assertSame( 200, $response->get_status(), 'Slack should still get a 200.' );
		$this->assertSame( [], self::get_coverage_entries( $coverage_id ), 'No entry should be created.' );
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
	 * Turn on the Password Protected plugin with Slack configured, and have
	 * WordPress serve the given route.
	 *
	 * @param string $route REST route, or '' for a request that isn't a REST one.
	 */
	private static function serve_on_a_password_protected_site( $route ) {
		require_once __DIR__ . '/mocks/class-password-protected.php';
		new Password_Protected();
		update_option( 'password_protected_status', 1 );
		self::configure_slack();
		Slack::register_conditional_hooks();
		$GLOBALS['wp']->query_vars['rest_route'] = $route;
	}

	/**
	 * Webhook routes as WordPress parses them from the request URL.
	 *
	 * @return array[]
	 */
	public function webhook_rest_route_provider() {
		return [
			'events'                     => [ '/rolling-coverage/v1/slack/events' ],
			'commands'                   => [ '/rolling-coverage/v1/slack/commands' ],
			'interactions'               => [ '/rolling-coverage/v1/slack/interactions' ],
			'other case, trailing slash' => [ '/rolling-coverage/v1/Slack/Events/' ],
		];
	}

	/**
	 * On a site the Password Protected plugin protects, Slack's requests
	 * reach the webhook routes, which then check their signature.
	 *
	 * @dataProvider webhook_rest_route_provider
	 *
	 * @param string $route REST route.
	 */
	public function test_password_protected_site_lets_slack_reach_the_webhook_routes( $route ) {
		self::serve_on_a_password_protected_site( $route );

		$this->assertNotWPError( rest_get_server()->check_authentication() );
	}

	/**
	 * A filter at the default priority that turns protection on doesn't lock
	 * Slack out of the webhook routes.
	 */
	public function test_password_protected_site_lets_slack_in_past_other_filters() {
		self::serve_on_a_password_protected_site( '/rolling-coverage/v1/slack/events' );
		add_filter( 'password_protected_is_active', '__return_true' );

		$this->assertNotWPError( rest_get_server()->check_authentication() );
	}

	/**
	 * Requests that aren't for a webhook route.
	 *
	 * @return array[]
	 */
	public function other_request_provider() {
		return [
			'core route'           => [ '/wp/v2/posts' ],
			'Slack admin route'    => [ '/rolling-coverage/v1/slack/settings' ],
			'path below a webhook' => [ '/rolling-coverage/v1/slack/events/extra' ],
			'not a REST request'   => [ '' ],
		];
	}

	/**
	 * The Password Protected plugin keeps protecting everything else.
	 *
	 * @dataProvider other_request_provider
	 *
	 * @param string $route REST route, or ''.
	 */
	public function test_password_protected_site_keeps_other_requests_protected( $route ) {
		self::serve_on_a_password_protected_site( $route );

		$refusal = rest_get_server()->check_authentication();

		$this->assertWPError( $refusal, 'Password Protected should refuse the request.' );
		$this->assertSame( 'rest_cannot_access', $refusal->get_error_code() );
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
	 * A message from a Slack handle mapped to a WordPress user is credited
	 * to that user, images included, instead of the bot user.
	 */
	public function test_message_from_a_mapped_handle_is_credited_to_that_user() {
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		update_user_meta( $author_id, Slack_Author_Resolver::META_SLACK_HANDLE, 'Riley Sample' );

		self::controller()->handle_event( self::webhook_request( self::message_event_body( [ 'files' => [ self::slack_file() ] ] ) ) );
		$entries = self::get_coverage_entries( $coverage_id );

		$this->assertCount( 1, $entries, 'The message should create one entry.' );
		$this->assertSame( $author_id, (int) $entries[0]->post_author, 'The mapped user should own the entry.' );

		$images = get_posts(
			[
				'post_type'   => 'attachment',
				'post_parent' => $entries[0]->ID,
				'post_status' => 'inherit',
			]
		);

		$this->assertNotEmpty( $images, 'The image should be attached to the entry.' );
		$this->assertSame( $author_id, (int) $images[0]->post_author, 'The mapped user should own the image.' );
	}

	/**
	 * A message from a member ID mapped to a WordPress user is credited to
	 * that user.
	 */
	public function test_message_from_a_mapped_member_id_is_credited_to_that_user() {
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel( self::CHANNEL_ID, [ 'term_id' => $coverage_id ] );
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		update_user_meta( $author_id, Slack_Author_Resolver::META_SLACK_HANDLE, 'U0REPORTER' );

		self::controller()->handle_event( self::webhook_request( self::message_event_body() ) );
		$entries = self::get_coverage_entries( $coverage_id );

		$this->assertCount( 1, $entries, 'The message should create one entry.' );
		$this->assertSame( $author_id, (int) $entries[0]->post_author, 'The mapped user should own the entry.' );
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
	 * A linked channel's settings report its publish mode and when it last
	 * ingested a message, so the connection drawer can show them.
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

		$this->assertSame( 'publish', $data['publish_mode'], 'A channel linked before publish modes should report its auto-publish setting as a mode.' );
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
	 * Photos posted together show as one gallery below the text, in the order
	 * they were uploaded, each still an image of its own.
	 */
	public function test_several_images_become_one_gallery_in_upload_order() {
		$coverage_id = self::deliver_to_linked_channel(
			[
				'files' => [
					self::slack_file( [ 'name' => 'first.png' ] ),
					self::slack_file( [ 'name' => 'second.png' ] ),
				],
			]
		);
		$content     = self::get_coverage_entries( $coverage_id )[0]->post_content;
		$blocks      = array_values( array_filter( parse_blocks( $content ), static fn( $block ) => null !== $block['blockName'] ) );

		$this->assertSame( [ 'core/paragraph', 'core/gallery' ], wp_list_pluck( $blocks, 'blockName' ), 'The text should be followed by one gallery.' );
		$this->assertSame( [ 'core/image', 'core/image' ], wp_list_pluck( $blocks[1]['innerBlocks'], 'blockName' ), 'Each photo should be an image in the gallery.' );
		$this->assertStringContainsString( "<!-- wp:gallery {\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">", $content, 'The gallery should be saved as the editor saves one.' );
		$this->assertMatchesRegularExpression( '#first\.png.*second\.png#s', $content, 'The photos should keep their upload order.' );
	}

	/**
	 * When only one of a message's images can be imported, it is shown as an
	 * image, not as a gallery of one.
	 */
	public function test_single_imported_image_is_not_put_in_a_gallery() {
		$this->silence_error_log();

		$coverage_id = self::deliver_to_linked_channel(
			[
				'files' => [
					self::slack_file(),
					self::slack_file(
						[
							'name'        => 'second.png',
							'url_private' => '',
						]
					),
				],
			]
		);
		$content     = self::get_coverage_entries( $coverage_id )[0]->post_content;

		$this->assertStringContainsString( '<!-- wp:image ', $content, 'The imported image should be in the entry.' );
		$this->assertStringNotContainsString( 'wp:gallery', $content, 'It should not be wrapped in a gallery.' );
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

	/**
	 * Link the test channel to a new coverage with the given publish mode.
	 *
	 * @param string $publish_mode One of Slack_Config::PUBLISH_MODES.
	 * @return int Coverage term ID.
	 */
	private static function link_channel( $publish_mode ) {
		self::configure_slack();
		$coverage_id = self::create_coverage();
		Slack_Config::update_channel(
			self::CHANNEL_ID,
			[
				'term_id'      => $coverage_id,
				'publish_mode' => $publish_mode,
			]
		);

		return $coverage_id;
	}

	/**
	 * Messages and replies whose entries wait for a reaction.
	 *
	 * @return array[]
	 */
	public function waiting_message_provider() {
		return [
			'message'      => [ self::message_event_body() ],
			'thread reply' => [ self::thread_reply_event_body() ],
		];
	}

	/**
	 * In an Emoji trigger channel a message waits for its reaction. Nothing
	 * about it is looked up, not even the thread a reply is in.
	 *
	 * @dataProvider waiting_message_provider
	 *
	 * @param string $body Event body.
	 */
	public function test_message_in_an_emoji_trigger_channel_waits_for_a_reaction( $body ) {
		$coverage_id = self::link_channel( Slack_Config::PUBLISH_MODE_REACTION );

		$response = self::controller()->handle_event( self::webhook_request( $body ) );

		$this->assertSame( 200, $response->get_status(), 'Slack should get a 200.' );
		$this->assertSame( [], self::get_coverage_entries( $coverage_id ), 'No entry should be created.' );
		$this->assertSame( [], $this->outbound_requests, 'Slack should not be called.' );
	}

	/**
	 * Publish modes and the status their channel's messages get.
	 *
	 * @return array[]
	 */
	public function message_publish_mode_provider() {
		return [
			'Auto-publish' => [ Slack_Config::PUBLISH_MODE_PUBLISH, 'publish', 'large_green_circle' ],
			'Draft'        => [ Slack_Config::PUBLISH_MODE_DRAFT, 'draft', 'large_yellow_circle' ],
		];
	}

	/**
	 * A message gets the status its channel's publish mode sets, and the bot
	 * reacts to it once its entry is saved, so the reporter sees it made it in.
	 *
	 * @dataProvider message_publish_mode_provider
	 *
	 * @param string $publish_mode    Channel publish mode.
	 * @param string $expected_status Entry status.
	 * @param string $expected_emoji  Reaction the bot adds.
	 */
	public function test_message_follows_its_channels_publish_mode( $publish_mode, $expected_status, $expected_emoji ) {
		$coverage_id = self::link_channel( $publish_mode );

		self::controller()->handle_event( self::webhook_request( self::message_event_body() ) );

		$this->assertSame( [ $expected_status ], wp_list_pluck( self::get_coverage_entries( $coverage_id ), 'post_status' ), 'The entry should have the status the mode sets.' );
		$this->assertSame(
			[
				[
					'method' => 'reactions.add',
					'body'   => [
						'channel'   => self::CHANNEL_ID,
						'timestamp' => self::MESSAGE_TS,
						'name'      => $expected_emoji,
					],
				],
			],
			$this->reaction_requests,
			'The bot should react once to the message.'
		);
	}

	/**
	 * A redelivered message gets no second reaction, since no second entry
	 * is saved.
	 */
	public function test_bot_reacts_once_to_a_redelivered_message() {
		$this->silence_error_log();
		self::link_channel( Slack_Config::PUBLISH_MODE_DRAFT );

		self::controller()->handle_event( self::webhook_request( self::message_event_body() ) );
		self::controller()->handle_event( self::webhook_request( self::message_event_body() ) );

		$this->assertCount( 1, $this->reaction_requests );
	}

	/**
	 * The entry matters more than the reaction: when Slack refuses the
	 * reaction, as for an app without the reactions:write scope, the entry
	 * is kept and Slack still gets a 200.
	 */
	public function test_entry_is_kept_when_the_bot_cannot_react() {
		$coverage_id            = self::link_channel( Slack_Config::PUBLISH_MODE_DRAFT );
		$this->reaction_failure = 'missing_scope';

		$response = self::controller()->handle_event( self::webhook_request( self::message_event_body() ) );

		$this->assertSame( 200, $response->get_status(), 'Slack should get a 200.' );
		$this->assertCount( 1, self::get_coverage_entries( $coverage_id ), 'The entry should be saved.' );
		$this->assertCount( 1, $this->reaction_requests, 'The bot should have tried to react.' );
	}

	/**
	 * Reacting with an emoji the bot already added counts as done.
	 */
	public function test_an_existing_reaction_counts_as_added() {
		self::configure_slack();
		$this->reaction_failure = 'already_reacted';

		$this->assertTrue( ( new Slack_API_Client() )->add_reaction( self::CHANNEL_ID, self::MESSAGE_TS, 'large_green_circle' ) );
	}

	/**
	 * Build the body of a `reaction_added` event on the default message.
	 *
	 * @param array $event_overrides Event fields to override.
	 * @return string JSON body.
	 */
	private static function reaction_event_body( array $event_overrides = [] ) {
		return wp_json_encode(
			[
				'type'  => 'event_callback',
				'event' => array_merge(
					[
						'type'      => 'reaction_added',
						'user'      => 'U0EDITOR',
						'reaction'  => 'mega',
						'item_user' => 'U0REPORTER',
						'item'      => [
							'type'    => 'message',
							'channel' => self::CHANNEL_ID,
							'ts'      => self::MESSAGE_TS,
						],
						'event_ts'  => '1767225660.000300',
					],
					$event_overrides
				),
			]
		);
	}

	/**
	 * Link the test channel in Emoji trigger mode, with the default message
	 * in its history.
	 *
	 * @return int Coverage term ID.
	 */
	private function link_emoji_trigger_channel() {
		$coverage_id            = self::link_channel( Slack_Config::PUBLISH_MODE_REACTION );
		$this->channel_messages = [
			self::MESSAGE_TS => [
				'user' => 'U0REPORTER',
				'text' => 'Polls have closed across the county.',
			],
		];

		return $coverage_id;
	}

	/**
	 * Deliver a reaction event.
	 *
	 * @param array $event_overrides Event fields to override.
	 * @return WP_REST_Response
	 */
	private static function react_to_message( array $event_overrides = [] ) {
		return self::controller()->handle_event( self::webhook_request( self::reaction_event_body( $event_overrides ) ) );
	}

	/**
	 * The publish emoji publishes the message as it reads when the reaction
	 * arrives, and the bot confirms with its own reaction.
	 */
	public function test_publish_emoji_publishes_the_message() {
		$coverage_id = $this->link_emoji_trigger_channel();

		$response = self::react_to_message();
		$entries  = self::get_coverage_entries( $coverage_id );

		$this->assertSame( 200, $response->get_status(), 'Slack should get a 200.' );
		$this->assertSame( [ 'publish' ], wp_list_pluck( $entries, 'post_status' ), 'One published entry should be created.' );
		$this->assertStringContainsString( 'Polls have closed across the county.', $entries[0]->post_content, 'The message text should be the entry content.' );
		$this->assertSame( 'Riley Sample', get_post_meta( $entries[0]->ID, Post_Type::META_SLACK_AUTHOR_NAME, true ), 'The message author, not the person reacting, should be credited.' );
		$this->assertSame( [ 'large_green_circle' ], array_column( array_column( $this->reaction_requests, 'body' ), 'name' ), 'The bot should react green.' );
	}

	/**
	 * The draft emoji saves the message as a draft.
	 */
	public function test_draft_emoji_saves_the_message_as_a_draft() {
		$coverage_id = $this->link_emoji_trigger_channel();

		self::react_to_message( [ 'reaction' => 'memo' ] );

		$this->assertSame( [ 'draft' ], wp_list_pluck( self::get_coverage_entries( $coverage_id ), 'post_status' ) );
		$this->assertSame( [ 'large_yellow_circle' ], array_column( array_column( $this->reaction_requests, 'body' ), 'name' ) );
	}

	/**
	 * The newsroom's own emoji trigger, in any skin tone.
	 */
	public function test_newsrooms_own_emoji_trigger_in_any_skin_tone() {
		$coverage_id = $this->link_emoji_trigger_channel();
		Slack_Config::set_reactions( 'thumbsup', 'pencil2' );

		self::react_to_message( [ 'reaction' => 'thumbsup::skin-tone-3' ] );

		$this->assertSame( [ 'publish' ], wp_list_pluck( self::get_coverage_entries( $coverage_id ), 'post_status' ) );
	}

	/**
	 * Reactions that never create an entry.
	 *
	 * @return array[]
	 */
	public function ignored_reaction_provider() {
		return [
			'another emoji'           => [ [ 'reaction' => 'eyes' ], Slack_Config::PUBLISH_MODE_REACTION ],
			'the bot\'s own reaction' => [ [ 'user' => 'U0BOT' ], Slack_Config::PUBLISH_MODE_REACTION ],
			'reaction on a file'      => [
				[
					'item' => [
						'type' => 'file',
						'file' => 'F0PHOTO',
					],
				],
				Slack_Config::PUBLISH_MODE_REACTION,
			],
			'channel set to Draft'    => [ [], Slack_Config::PUBLISH_MODE_DRAFT ],
			'unlinked channel'        => [
				[
					'item' => [
						'type'    => 'message',
						'channel' => 'C0ELSEWHERE',
						'ts'      => self::MESSAGE_TS,
					],
				],
				Slack_Config::PUBLISH_MODE_REACTION,
			],
		];
	}

	/**
	 * Slack sends every reaction in the bot's channels; anything that is not
	 * a trigger emoji on a message in an Emoji trigger channel is dropped
	 * without calling Slack.
	 *
	 * @dataProvider ignored_reaction_provider
	 *
	 * @param array  $event_overrides Event fields to override.
	 * @param string $publish_mode    Publish mode of the linked channel.
	 */
	public function test_other_reactions_are_dropped_without_calling_slack( array $event_overrides, $publish_mode ) {
		$coverage_id            = self::link_channel( $publish_mode );
		$this->channel_messages = [
			self::MESSAGE_TS => [
				'user' => 'U0REPORTER',
				'text' => 'Polls have closed.',
			],
		];
		Slack_Config::update_settings( [ 'slack_bot_user_id' => 'U0BOT' ] );

		$response = self::react_to_message( $event_overrides );

		$this->assertSame( 200, $response->get_status(), 'Slack should get a 200.' );
		$this->assertSame( [], self::get_coverage_entries( $coverage_id ), 'No entry should be created.' );
		$this->assertSame( [], $this->outbound_requests, 'Slack should not be called.' );
	}

	/**
	 * The reaction is the opt-in, so the ignore prefix doesn't apply.
	 */
	public function test_reacted_message_is_published_despite_the_ignore_prefix() {
		$coverage_id = $this->link_emoji_trigger_channel();
		$this->channel_messages[ self::MESSAGE_TS ]['text'] = '~~ Polls have closed.';

		self::react_to_message();

		$this->assertCount( 1, self::get_coverage_entries( $coverage_id ) );
	}

	/**
	 * A reacted message posted by a bot is not an entry.
	 */
	public function test_reacted_bot_message_creates_no_entry() {
		$coverage_id = $this->link_emoji_trigger_channel();
		$this->channel_messages[ self::MESSAGE_TS ]['bot_id'] = 'B0OTHER';

		self::react_to_message();

		$this->assertSame( [], self::get_coverage_entries( $coverage_id ) );
	}

	/**
	 * A reaction on Slack's notice that someone set the channel's description
	 * doesn't make the notice an entry.
	 */
	public function test_reacted_channel_description_notice_creates_no_entry() {
		$coverage_id = $this->link_emoji_trigger_channel();
		$this->channel_messages[ self::MESSAGE_TS ] = self::channel_description_message();

		self::react_to_message();

		$this->assertSame( [], self::get_coverage_entries( $coverage_id ) );
	}

	/**
	 * The publish emoji publishes the draft the draft emoji saved, keeping
	 * its content and date as publishing from the editor would, and the bot
	 * swaps its yellow reaction for green.
	 */
	public function test_publish_emoji_publishes_a_draft_saved_from_the_same_message() {
		global $wpdb;

		$coverage_id = $this->link_emoji_trigger_channel();
		self::react_to_message( [ 'reaction' => 'memo' ] );
		$entry_id = self::get_coverage_entries( $coverage_id )[0]->ID;

		// An editor added an embed to the draft, and it was saved an hour ago.
		$an_hour_ago = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
			$wpdb->posts,
			[
				'post_date'     => get_date_from_gmt( $an_hour_ago ),
				'post_date_gmt' => $an_hour_ago,
				'post_content'  => '<p>Polls have closed.</p><iframe src="https://example.test/embed"></iframe>',
			],
			[ 'ID' => $entry_id ]
		);
		clean_post_cache( $entry_id );

		self::react_to_message();
		$entries = self::get_coverage_entries( $coverage_id );

		$this->assertSame( [ $entry_id ], wp_list_pluck( $entries, 'ID' ), 'The draft should be the only entry.' );
		$this->assertSame( 'publish', $entries[0]->post_status, 'The draft should be published.' );
		$this->assertSame( $an_hour_ago, $entries[0]->post_date_gmt, 'The entry should keep the date the draft was created.' );
		$this->assertStringContainsString( '<iframe src="https://example.test/embed"></iframe>', $entries[0]->post_content, 'Markup an editor added should be kept.' );
		$this->assertSame(
			[ 'reactions.add:large_yellow_circle', 'reactions.add:large_green_circle', 'reactions.remove:large_yellow_circle' ],
			array_map( fn( $request ) => $request['method'] . ':' . $request['body']['name'], $this->reaction_requests ),
			'The bot should swap yellow for green.'
		);
	}

	/**
	 * A reaction never unpublishes: the draft emoji on a published entry
	 * changes nothing.
	 */
	public function test_draft_emoji_leaves_a_published_entry_alone() {
		$coverage_id = $this->link_emoji_trigger_channel();
		self::react_to_message();

		self::react_to_message( [ 'reaction' => 'memo' ] );

		$this->assertSame( [ 'publish' ], wp_list_pluck( self::get_coverage_entries( $coverage_id ), 'post_status' ) );
		$this->assertCount( 1, $this->reaction_requests, 'The bot should not react again.' );
	}

	/**
	 * Slack redelivering the reaction, or a second person adding it, makes
	 * no second entry and no second bot reaction.
	 */
	public function test_repeated_publish_emoji_makes_one_entry() {
		$this->silence_error_log();
		$coverage_id = $this->link_emoji_trigger_channel();

		self::react_to_message();
		self::react_to_message( [ 'user' => 'U0SECONDEDITOR' ] );

		$this->assertCount( 1, self::get_coverage_entries( $coverage_id ), 'One entry should be created.' );
		$this->assertCount( 1, $this->reaction_requests, 'The bot should react once.' );
	}

	/**
	 * A coverage that has ended takes no new entries, and its drafts are not
	 * published from Slack.
	 */
	public function test_reactions_change_nothing_once_the_coverage_has_ended() {
		$this->silence_error_log();
		$coverage_id = $this->link_emoji_trigger_channel();
		self::react_to_message( [ 'reaction' => 'memo' ] );
		$this->channel_messages[ self::REPLY_TS ] = [
			'user' => 'U0REPORTER',
			'text' => 'Turnout was 62%.',
		];
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		self::react_to_message();
		self::react_to_message(
			[
				'item' => [
					'type'    => 'message',
					'channel' => self::CHANNEL_ID,
					'ts'      => self::REPLY_TS,
				],
			]
		);

		$this->assertSame( [ 'draft' ], wp_list_pluck( self::get_coverage_entries( $coverage_id ), 'post_status' ), 'The draft should stay a draft, and no entry should be added.' );
	}

	/**
	 * A reacted thread reply becomes a child of the entry for the message
	 * its thread starts from, even though Slack lists that message first.
	 */
	public function test_reacted_thread_reply_is_a_child_of_its_threads_entry() {
		$coverage_id            = $this->link_emoji_trigger_channel();
		$this->channel_messages = [
			self::MESSAGE_TS => [
				'user'        => 'U0REPORTER',
				'text'        => 'Polls have closed across the county.',
				'thread_ts'   => self::MESSAGE_TS,
				'reply_count' => 1,
			],
			self::REPLY_TS   => [
				'user'      => 'U0REPORTER',
				'text'      => 'Turnout was 62%.',
				'thread_ts' => self::MESSAGE_TS,
			],
		];

		self::react_to_message();
		$parent_id = self::get_coverage_entries( $coverage_id )[0]->ID;
		self::react_to_message(
			[
				'item' => [
					'type'    => 'message',
					'channel' => self::CHANNEL_ID,
					'ts'      => self::REPLY_TS,
				],
			]
		);
		$entries = wp_list_pluck( self::get_coverage_entries( $coverage_id ), 'post_parent', 'ID' );
		unset( $entries[ $parent_id ] );
		$reply_id = (int) array_key_first( $entries );

		$this->assertSame( [ $parent_id ], array_values( $entries ), 'The reply should be a child of the thread entry.' );
		$this->assertStringContainsString( 'Turnout was 62%.', get_post( $reply_id )->post_content, 'The reply, not the thread\'s first message, should be the content.' );
		$this->assertSame( '', get_post_meta( $parent_id, Post_Type::META_SLACK_THREAD_TS, true ), 'The thread\'s first message should not record itself as its thread.' );
		$this->assertSame( self::MESSAGE_TS, get_post_meta( $reply_id, Post_Type::META_SLACK_THREAD_TS, true ), 'The reply should record its thread.' );
	}

	/**
	 * Ways reading the reacted message can fail, and whether Slack should
	 * send the reaction again.
	 *
	 * @return array[]
	 */
	public function reacted_message_failure_provider() {
		return [
			'Slack timed out'      => [ 'timeout', 503 ],
			'Slack rate limited'   => [ 'ratelimited', 503 ],
			'channel not readable' => [ 'channel_not_found', 200 ],
		];
	}

	/**
	 * When the reacted message can't be read, a temporary failure asks Slack
	 * to send the reaction again, and any other failure gives up on it.
	 *
	 * @dataProvider reacted_message_failure_provider
	 *
	 * @param string $failure         How reading fails.
	 * @param int    $expected_status Status Slack gets.
	 */
	public function test_reaction_when_the_message_cannot_be_read( $failure, $expected_status ) {
		$this->silence_error_log();
		$coverage_id           = $this->link_emoji_trigger_channel();
		$this->history_failure = $failure;

		$response = self::react_to_message();

		$this->assertSame( $expected_status, $response->get_status() );
		$this->assertSame( [], self::get_coverage_entries( $coverage_id ) );
	}

	/**
	 * A message deleted before its reaction arrives creates nothing.
	 */
	public function test_reaction_on_a_deleted_message_creates_no_entry() {
		$coverage_id            = $this->link_emoji_trigger_channel();
		$this->channel_messages = [];

		$response = self::react_to_message();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], self::get_coverage_entries( $coverage_id ) );
	}

	/**
	 * Build a request to a channel's settings route.
	 *
	 * @param string $method HTTP method.
	 * @param array  $params Request parameters.
	 * @return WP_REST_Request
	 */
	private static function channel_settings_request( $method, array $params = [] ) {
		$request = new WP_REST_Request( $method, '/rolling-coverage/v1/slack/channel/' . self::CHANNEL_ID );
		$request->set_param( 'id', self::CHANNEL_ID );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * Changing a channel's publish mode stores it and tells the channel what
	 * now happens to its messages, naming the emoji in Emoji trigger mode.
	 */
	public function test_channel_publish_mode_can_be_changed() {
		self::link_channel( Slack_Config::PUBLISH_MODE_DRAFT );

		$response = self::controller()->update_channel_settings( self::channel_settings_request( 'POST', [ 'publish_mode' => 'reaction' ] ) );

		$this->assertSame( 'reaction', $response->get_data()['publish_mode'], 'The new mode should be returned.' );
		$this->assertSame( 'reaction', Slack_Config::get_channel_settings( self::CHANNEL_ID )['publish_mode'], 'The new mode should be stored.' );
		$this->assertSame( [ 'Publish mode is now Emoji trigger. React to a message with :mega: to publish it, or :memo: to save it as a draft.' ], $this->posted_messages, 'The channel should be told.' );
	}

	/**
	 * An unknown publish mode is refused and the stored one kept.
	 */
	public function test_unknown_publish_mode_is_refused() {
		self::link_channel( Slack_Config::PUBLISH_MODE_DRAFT );

		$response = self::controller()->update_channel_settings( self::channel_settings_request( 'POST', [ 'publish_mode' => 'sometimes' ] ) );

		$this->assertSame( 400, $response->get_status(), 'The request should be refused.' );
		$this->assertSame( 'draft', Slack_Config::get_channel_settings( self::CHANNEL_ID )['publish_mode'], 'The stored mode should be kept.' );
		$this->assertSame( [], $this->posted_messages, 'The channel should not be told anything.' );
	}

	/**
	 * The channel list reports each channel's publish mode, including for
	 * channels linked before publish modes.
	 */
	public function test_channel_list_reports_each_channels_publish_mode() {
		Slack_Config::update_channel(
			self::CHANNEL_ID,
			[
				'term_id'     => self::create_coverage(),
				'autopublish' => true,
			]
		);
		Slack_Config::update_channel(
			'C0EMOJI',
			[
				'term_id'      => self::create_coverage(),
				'publish_mode' => 'reaction',
			]
		);

		$rows = array_column( self::controller()->list_channels( new WP_REST_Request( 'GET', '/rolling-coverage/v1/slack/channels' ) )->get_data(), null, 'channel_id' );

		$this->assertSame( 'publish', $rows[ self::CHANNEL_ID ]['publish_mode'] );
		$this->assertArrayNotHasKey( 'autopublish', $rows[ self::CHANNEL_ID ], 'The legacy flag should not be reported.' );
		$this->assertSame( 'reaction', $rows['C0EMOJI']['publish_mode'] );
	}

	/**
	 * Connecting a channel from the admin stores the chosen publish mode and
	 * says in the channel what happens to its messages.
	 */
	public function test_connecting_a_channel_stores_its_publish_mode() {
		self::configure_slack();
		$coverage_id = self::create_coverage( '', [ 'name' => 'Election Night' ] );
		$request     = new WP_REST_Request( 'POST', '/rolling-coverage/v1/slack/connect' );
		$request->set_param( 'term_id', $coverage_id );
		$request->set_param( 'channel', self::CHANNEL_ID );
		$request->set_param( 'publish_mode', 'publish' );

		$response = self::controller()->connect_channel( $request );

		$this->assertSame( 200, $response->get_status(), 'The channel should connect.' );
		$this->assertSame( 'publish', Slack_Config::get_channel_settings( self::CHANNEL_ID )['publish_mode'], 'The mode should be stored.' );
		$this->assertSame( [ '✅ This channel is now connected to the "Election Night" coverage. New messages are published right away.' ], $this->posted_messages );
	}

	/**
	 * Connecting with an unknown publish mode is refused.
	 */
	public function test_connecting_with_an_unknown_publish_mode_is_refused() {
		self::configure_slack();
		$request = new WP_REST_Request( 'POST', '/rolling-coverage/v1/slack/connect' );
		$request->set_param( 'term_id', self::create_coverage() );
		$request->set_param( 'channel', self::CHANNEL_ID );
		$request->set_param( 'publish_mode', 'sometimes' );

		$this->assertSame( 400, self::controller()->connect_channel( $request )->get_status() );
		$this->assertNull( Slack_Config::get_channel_settings( self::CHANNEL_ID ) );
	}

	/**
	 * /rolling-coverage-status says what happens to the channel's messages.
	 */
	public function test_status_command_describes_the_publish_mode() {
		self::link_channel( Slack_Config::PUBLISH_MODE_DRAFT );
		$body = http_build_query(
			[
				'command'    => '/rolling-coverage-status',
				'channel_id' => self::CHANNEL_ID,
			]
		);

		$text = self::controller()->handle_command( self::webhook_request( $body, true, 'commands' ) )->get_data()['text'];

		$this->assertStringEndsWith( 'New messages are saved as drafts.', $text );
	}

	/**
	 * Scopes Slack can report for the bot token, and whether the settings
	 * should say the app can react to messages.
	 *
	 * @return array[]
	 */
	public function reaction_scopes_provider() {
		return [
			'app installed before reactions'        => [ 'channels:history,files:read,chat:write', false ],
			'app that can only read them'           => [ 'channels:history,reactions:read', false ],
			'app with both reaction scopes'         => [ 'reactions:read,files:read,reactions:write', true ],
			'scopes unknown, as when Slack is down' => [ null, true ],
		];
	}

	/**
	 * The settings say when the Slack app lacks the scopes reactions need,
	 * so the admin can ask for the app to be updated.
	 *
	 * @dataProvider reaction_scopes_provider
	 *
	 * @param string|null $scopes            Scopes Slack reports for the token.
	 * @param bool        $can_use_reactions Whether the settings should say the app can react.
	 */
	public function test_settings_say_whether_the_app_can_use_reactions( $scopes, $can_use_reactions ) {
		self::configure_slack();
		$this->token_scopes = $scopes;

		$settings = self::controller()->get_settings( new WP_REST_Request( 'GET', '/rolling-coverage/v1/slack/settings' ) )->get_data();

		$this->assertSame( $can_use_reactions, $settings['can_use_reactions'] );
		$this->assertSame( 'mega', $settings['publish_reaction'], 'The publish emoji should be reported.' );
		$this->assertSame( 'memo', $settings['draft_reaction'], 'The draft emoji should be reported.' );
	}

	/**
	 * Build a request that saves the Slack settings.
	 *
	 * @param array $params Request parameters.
	 * @return WP_REST_Request
	 */
	private static function save_settings_request( array $params ) {
		$request = new WP_REST_Request( 'POST', '/rolling-coverage/v1/slack/settings' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * The trigger emoji are saved with the ignore prefix, and saving again
	 * with only the emoji changed works.
	 */
	public function test_settings_save_the_trigger_emoji() {
		self::controller()->save_settings(
			self::save_settings_request(
				[
					'ignore_prefix'    => '~~',
					'publish_reaction' => 'mega',
					'draft_reaction'   => 'memo',
				]
			)
		);

		$response = self::controller()->save_settings(
			self::save_settings_request(
				[
					'ignore_prefix'    => '~~',
					'publish_reaction' => ':Rocket:',
					'draft_reaction'   => 'pencil2',
				]
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Saving with the same prefix should work.' );
		$this->assertSame( 'rocket', $response->get_data()['publish_reaction'], 'The stored name should be returned.' );
		$this->assertSame( 'rocket', Slack_Config::get_publish_reaction() );
		$this->assertSame( 'pencil2', Slack_Config::get_draft_reaction() );
	}

	/**
	 * Unusable emoji are refused before anything is stored, the new prefix
	 * included.
	 */
	public function test_settings_refuse_unusable_trigger_emoji() {
		$response = self::controller()->save_settings(
			self::save_settings_request(
				[
					'ignore_prefix'    => '##',
					'publish_reaction' => 'mega',
					'draft_reaction'   => ':mega:',
				]
			)
		);

		$this->assertSame( 400, $response->get_status(), 'The request should be refused.' );
		$this->assertSame( '~~', Slack_Config::get_ignore_prefix(), 'The prefix should be unchanged.' );
		$this->assertSame( 'memo', Slack_Config::get_draft_reaction(), 'The draft emoji should be unchanged.' );
	}

	/**
	 * The settings report the highest heading level, reading an unusable
	 * stored one as the default.
	 */
	public function test_settings_report_the_highest_heading_level() {
		Slack_Config::set_top_heading_level( 3 );

		$settings = self::controller()->get_settings( new WP_REST_Request( 'GET', '/rolling-coverage/v1/slack/settings' ) )->get_data();

		$this->assertSame( 3, $settings['top_heading_level'], 'The stored level should be reported.' );

		Slack_Config::update_settings( [ 'top_heading_level' => 9 ] );

		$settings = self::controller()->get_settings( new WP_REST_Request( 'GET', '/rolling-coverage/v1/slack/settings' ) )->get_data();

		$this->assertSame( 0, $settings['top_heading_level'], 'An unusable level should be reported as the default, off.' );
	}

	/**
	 * The highest heading level is saved with the other settings, and a
	 * request without it leaves it as it was.
	 */
	public function test_settings_save_the_highest_heading_level() {
		$response = self::controller()->save_settings(
			self::save_settings_request(
				[
					'ignore_prefix'     => '~~',
					'top_heading_level' => 2,
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['top_heading_level'], 'The stored level should be returned.' );
		$this->assertSame( 2, Slack_Config::get_top_heading_level() );

		self::controller()->save_settings( self::save_settings_request( [ 'ignore_prefix' => '##' ] ) );

		$this->assertSame( 2, Slack_Config::get_top_heading_level(), 'Saving without a level should keep it.' );

		self::controller()->save_settings(
			self::save_settings_request(
				[
					'ignore_prefix'     => '##',
					'top_heading_level' => 0,
				]
			)
		);

		$this->assertSame( 0, Slack_Config::get_top_heading_level(), 'Headings should turn off again.' );
	}

	/**
	 * Values the highest heading level cannot take.
	 *
	 * @return array[]
	 */
	public function invalid_heading_level_param_provider() {
		return [
			'Heading 1'      => [ 1 ],
			'Heading 7'      => [ 7 ],
			'a fraction'     => [ '3.5' ],
			'a tag name'     => [ 'h3' ],
			'an empty value' => [ '' ],
		];
	}

	/**
	 * An unusable level is refused before anything is stored, the new
	 * prefix included.
	 *
	 * @dataProvider invalid_heading_level_param_provider
	 *
	 * @param mixed $level Requested level.
	 */
	public function test_settings_refuse_an_unusable_heading_level( $level ) {
		$response = self::controller()->save_settings(
			self::save_settings_request(
				[
					'ignore_prefix'     => '##',
					'top_heading_level' => $level,
				]
			)
		);

		$this->assertSame( 400, $response->get_status(), 'The request should be refused.' );
		$this->assertSame( '~~', Slack_Config::get_ignore_prefix(), 'The prefix should be unchanged.' );
		$this->assertSame( 0, Slack_Config::get_top_heading_level(), 'The level should be unchanged.' );
	}

	/**
	 * A line starting with # in a message becomes a heading at the level
	 * chosen in the settings.
	 */
	public function test_message_headings_use_the_highest_heading_level() {
		Slack_Config::set_top_heading_level( 3 );

		$coverage_id = self::deliver_to_linked_channel( [ 'text' => "Polls have closed.\n# Results\nWard 1 is counted." ] );
		$content     = self::get_coverage_entries( $coverage_id )[0]->post_content;

		$this->assertStringContainsString( '<h3 class="wp-block-heading">Results</h3>', $content );
		$this->assertStringContainsString( '<p>Ward 1 is counted.</p>', $content );
	}

	/**
	 * Until a level is chosen, a message's # lines stay as typed and the
	 * entry has no title.
	 */
	public function test_message_headings_are_off_by_default() {
		$coverage_id = self::deliver_to_linked_channel( [ 'text' => '# of precincts reporting: 45' ] );
		$entry       = self::get_coverage_entries( $coverage_id )[0];

		$this->assertSame( '', $entry->post_title );
		$this->assertStringContainsString( '<p># of precincts reporting: 45</p>', $entry->post_content );
	}

	/**
	 * A message's opening heading is the entry's title, and a message that
	 * is only that line becomes a titled entry with no content.
	 */
	public function test_opening_heading_becomes_the_entry_title() {
		Slack_Config::set_top_heading_level( 3 );

		$coverage_id = self::deliver_to_linked_channel( [ 'text' => "# Results are in\nWard 1 is counted." ] );
		$entry       = self::get_coverage_entries( $coverage_id )[0];

		$this->assertSame( 'Results are in', $entry->post_title );
		$this->assertStringContainsString( '<p>Ward 1 is counted.</p>', $entry->post_content );
		$this->assertStringNotContainsString( 'wp:heading', $entry->post_content );

		$coverage_id = self::deliver_to_linked_channel( [ 'text' => '# Polls closed' ] );
		$entry       = self::get_coverage_entries( $coverage_id )[0];

		$this->assertSame( 'Polls closed', $entry->post_title, 'A title-only message should still become an entry.' );
		$this->assertSame( '', $entry->post_content );
	}

	/**
	 * A draft published with the publish emoji gets a slug, as one saved as
	 * published does, so its share and push notification links work.
	 */
	public function test_draft_published_by_the_publish_emoji_gets_a_slug() {
		$coverage_id = $this->link_emoji_trigger_channel();
		self::react_to_message( [ 'reaction' => 'memo' ] );
		$entry_id = self::get_coverage_entries( $coverage_id )[0]->ID;

		self::react_to_message();

		$this->assertSame( (string) $entry_id, get_post( $entry_id )->post_name, 'An untitled entry should be named after its ID, as core names it.' );
	}

	/**
	 * The publish emoji that arrives while the draft emoji's entry is still
	 * being saved asks Slack to send it again, so it publishes that draft
	 * once it exists instead of being lost.
	 */
	public function test_publish_emoji_waits_for_a_draft_still_being_saved() {
		$coverage_id = $this->link_emoji_trigger_channel();
		add_option( Entry_Ingestion_Service::MUTEX_PREFIX . md5( 'slack:' . self::MESSAGE_TS ), time(), '', false );

		$response = self::react_to_message();

		$this->assertSame( 503, $response->get_status(), 'Slack should send the reaction again.' );
		$this->assertSame( [], self::get_coverage_entries( $coverage_id ), 'No second entry should be started.' );
	}

	/**
	 * Whether the bot's reaction shows on the message by the time it is read:
	 * the draft emoji's request saves the entry before it reacts.
	 *
	 * @return array[]
	 */
	public function draft_saved_meanwhile_provider() {
		return [
			'bot has not reacted yet' => [ false ],
			'bot has reacted'         => [ true ],
		];
	}

	/**
	 * The publish emoji publishes a draft another request saved while this
	 * one read the message, whether or not the bot's reaction to it shows yet.
	 *
	 * @dataProvider draft_saved_meanwhile_provider
	 *
	 * @param bool $has_bot_reaction Whether the bot's reaction is on the message.
	 */
	public function test_publish_emoji_publishes_a_draft_saved_meanwhile( $has_bot_reaction ) {
		$coverage_id = $this->link_emoji_trigger_channel();
		Slack_Config::update_settings( [ 'slack_bot_user_id' => 'U0BOT' ] );
		$this->during_message_read = function () use ( $coverage_id, $has_bot_reaction ) {
			self::create_entry(
				$coverage_id,
				[
					'post_status' => 'draft',
					'meta_input'  => [ Post_Type::META_SOURCE_REF => self::MESSAGE_TS ],
				]
			);

			if ( $has_bot_reaction ) {
				$this->channel_messages[ self::MESSAGE_TS ]['reactions'] = [
					[
						'name'  => 'large_yellow_circle',
						'users' => [ 'U0BOT' ],
						'count' => 1,
					],
				];
			}
		};

		self::react_to_message();

		$this->assertSame( [ 'publish' ], wp_list_pluck( self::get_coverage_entries( $coverage_id ), 'post_status' ) );
	}

	/**
	 * An entry an editor trashed stays gone: a later reaction on its
	 * message, as when a colleague adds the same emoji to agree, doesn't
	 * bring it back.
	 */
	public function test_reaction_does_not_bring_back_a_trashed_entry() {
		$coverage_id = $this->link_emoji_trigger_channel();
		self::react_to_message();
		$entry_id = self::get_coverage_entries( $coverage_id )[0]->ID;
		wp_trash_post( $entry_id );

		self::react_to_message( [ 'user' => 'U0SECONDEDITOR' ] );

		$this->assertSame( [], self::get_coverage_entries( $coverage_id ), 'No entry should be created.' );
		$this->assertSame( 'trash', get_post_status( $entry_id ), 'The entry should stay in the trash.' );
	}

	/**
	 * A draft archived on its own is not published from Slack.
	 */
	public function test_publish_emoji_leaves_an_archived_draft_alone() {
		$coverage_id = $this->link_emoji_trigger_channel();
		self::react_to_message( [ 'reaction' => 'memo' ] );
		$entry_id = self::get_coverage_entries( $coverage_id )[0]->ID;
		update_post_meta( $entry_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );

		self::react_to_message();

		$this->assertSame( 'draft', get_post_status( $entry_id ) );
	}

	/**
	 * An entry an editor took down to draft stays down: a later publish
	 * emoji on its message, as from a colleague agreeing, doesn't put it
	 * back.
	 */
	public function test_publish_emoji_leaves_an_entry_taken_down_to_draft_alone() {
		$coverage_id = $this->link_emoji_trigger_channel();
		self::react_to_message();
		$entry_id = self::get_coverage_entries( $coverage_id )[0]->ID;
		wp_update_post(
			[
				'ID'          => $entry_id,
				'post_status' => 'draft',
			]
		);

		self::react_to_message( [ 'user' => 'U0SECONDEDITOR' ] );

		$this->assertSame( 'draft', get_post_status( $entry_id ), 'The entry should stay a draft.' );
		$this->assertCount( 1, $this->reaction_requests, 'The bot should not react again.' );
	}

	/**
	 * A draft dated ahead isn't published from Slack, since it would go live
	 * with a date in the future.
	 */
	public function test_publish_emoji_leaves_a_draft_dated_ahead_alone() {
		global $wpdb;

		$coverage_id = $this->link_emoji_trigger_channel();
		self::react_to_message( [ 'reaction' => 'memo' ] );
		$entry_id    = self::get_coverage_entries( $coverage_id )[0]->ID;
		$an_hour_out = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
			$wpdb->posts,
			[
				'post_date'     => get_date_from_gmt( $an_hour_out ),
				'post_date_gmt' => $an_hour_out,
			],
			[ 'ID' => $entry_id ]
		);
		clean_post_cache( $entry_id );

		self::react_to_message();

		$this->assertSame( 'draft', get_post_status( $entry_id ) );
	}

	/**
	 * A channel linked before publish modes keeps its legacy auto-publish
	 * flag in step with its new mode, so a release that reads only the flag
	 * doesn't publish every message in a channel now set to Emoji trigger.
	 */
	public function test_changing_a_legacy_channels_mode_keeps_its_autopublish_flag_in_step() {
		Slack_Config::update_channel(
			self::CHANNEL_ID,
			[
				'term_id'     => self::create_coverage(),
				'autopublish' => true,
			]
		);

		self::controller()->update_channel_settings( self::channel_settings_request( 'POST', [ 'publish_mode' => 'reaction' ] ) );

		$this->assertFalse( Slack_Config::get_channel_settings( self::CHANNEL_ID )['autopublish'] );
	}

	/**
	 * A draft published with the publish emoji notifies followers on the
	 * next cron run, as an entry published straight from Slack does, rather
	 * than a minute later.
	 */
	public function test_draft_published_by_emoji_notifies_followers_without_waiting() {
		require_once __DIR__ . '/mocks/onesignal.php';
		update_option(
			'OneSignalWPSetting',
			[
				'app_id'           => 'test-app-id',
				'app_rest_api_key' => 'test-rest-api-key',
			]
		);
		// The webhook is a REST request, which defers notifications.
		add_filter( 'wp_is_rest_endpoint', '__return_true' );
		$coverage_id = $this->link_emoji_trigger_channel();
		self::react_to_message( [ 'reaction' => 'memo' ] );
		$entry_id = self::get_coverage_entries( $coverage_id )[0]->ID;

		self::react_to_message();

		$this->assertNotFalse( wp_next_scheduled( Push_Notifications::SEND_HOOK, [ $entry_id ] ), 'The send should be scheduled.' );
		$this->assertLessThanOrEqual( time(), wp_next_scheduled( Push_Notifications::SEND_HOOK, [ $entry_id ] ), 'The send should not wait.' );
	}

	/**
	 * Emoji trigger is refused while Slack reports the app can't read
	 * reactions, so the channel isn't told to react to messages the site
	 * would never hear about.
	 */
	public function test_emoji_trigger_is_refused_while_the_app_cannot_read_reactions() {
		self::link_channel( Slack_Config::PUBLISH_MODE_DRAFT );
		$this->token_scopes = 'channels:history,chat:write,files:read';

		$response = self::controller()->update_channel_settings( self::channel_settings_request( 'POST', [ 'publish_mode' => 'reaction' ] ) );

		$this->assertSame( 400, $response->get_status(), 'The change should be refused.' );
		$this->assertSame( 'draft', Slack_Config::get_channel_settings( self::CHANNEL_ID )['publish_mode'], 'The stored mode should be kept.' );
		$this->assertSame( [], $this->posted_messages, 'The channel should not be told to react.' );
	}

	/**
	 * Connecting a channel in Emoji trigger mode is refused the same way.
	 */
	public function test_connecting_in_emoji_trigger_mode_is_refused_while_the_app_cannot_read_reactions() {
		self::configure_slack();
		$this->token_scopes = 'channels:history,chat:write,files:read';
		$request            = new WP_REST_Request( 'POST', '/rolling-coverage/v1/slack/connect' );
		$request->set_param( 'term_id', self::create_coverage() );
		$request->set_param( 'channel', self::CHANNEL_ID );
		$request->set_param( 'publish_mode', 'reaction' );

		$this->assertSame( 400, self::controller()->connect_channel( $request )->get_status() );
		$this->assertNull( Slack_Config::get_channel_settings( self::CHANNEL_ID ) );
	}

	/**
	 * A message the bot already reacted to had an entry, so finding none now
	 * means an editor deleted it for good: a later reaction doesn't add the
	 * message again.
	 */
	public function test_message_the_bot_already_reacted_to_is_not_added_again() {
		$coverage_id = $this->link_emoji_trigger_channel();
		Slack_Config::update_settings( [ 'slack_bot_user_id' => 'U0BOT' ] );
		$this->channel_messages[ self::MESSAGE_TS ]['reactions'] = [
			[
				'name'  => 'large_green_circle',
				'users' => [ 'U0BOT' ],
				'count' => 1,
			],
			[
				'name'  => 'mega',
				'users' => [ 'U0EDITOR' ],
				'count' => 1,
			],
		];

		self::react_to_message();

		$this->assertSame( [], self::get_coverage_entries( $coverage_id ) );
	}
}
