<?php
/**
 * Slack webhook REST controller.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Slack admin and webhook REST routes.
 */
class Slack_Webhook_Controller {

	const CHANNEL_ID_PATTERN = '[CG][A-Z0-9]+';

	/**
	 * API client.
	 *
	 * @var Slack_API_Client
	 */
	private $api_client;

	/**
	 * Signature verifier.
	 *
	 * @var Slack_Signature_Verifier
	 */
	private $signature_verifier;

	/**
	 * Constructor.
	 *
	 * @param Slack_API_Client         $api_client         API client.
	 * @param Slack_Signature_Verifier $signature_verifier Signature verifier.
	 */
	public function __construct(
		Slack_API_Client $api_client,
		Slack_Signature_Verifier $signature_verifier
	) {
		$this->api_client         = $api_client;
		$this->signature_verifier = $signature_verifier;
	}

	/**
	 * Central map of Slack error codes to localized, user-facing messages.
	 *
	 * This is the single source of truth for error text shown in the admin UI.
	 * REST routes return both the machine `error` code and the `message` from
	 * this map; the frontend renders `message` directly, so wording changes
	 * only need to happen here.
	 *
	 * @param string $code The error code.
	 * @return string The localized message, or the code itself if unmapped.
	 */
	protected static function get_error_message( string $code ): string {
		$messages = [
			// Connect (coverage ↔ channel) errors.
			'channel_not_found'      => __( 'Channel not found. Make sure the bot is invited to the channel, or use the channel ID (e.g., C12345678) instead of the name.', 'newspack-rolling-coverage' ),
			'bot_not_in_channel'     => __( 'The bot is not a member of this channel. Please invite the bot first using /invite @Rolling Coverage in Slack.', 'newspack-rolling-coverage' ),
			'channel_already_linked' => __( 'This channel is already linked to another coverage.', 'newspack-rolling-coverage' ),
			'term_already_connected' => __( 'This coverage is already connected to another channel.', 'newspack-rolling-coverage' ),
			'invalid_term'           => __( 'This coverage could not be found. Please refresh the page and try again.', 'newspack-rolling-coverage' ),
			'invalid_channel'        => __( 'That channel could not be used. Try a channel ID like C12345678 or a channel name like #general.', 'newspack-rolling-coverage' ),
			'term_select_required'   => __( 'Please select a coverage.', 'newspack-rolling-coverage' ),
			'term_not_found'         => __( 'Selected coverage no longer exists.', 'newspack-rolling-coverage' ),
			// Credentials errors.
			'missing_credentials'    => __( 'A bot token and signing secret are both required.', 'newspack-rolling-coverage' ),
			'invalid_token'          => __( 'The bot token is invalid. It must start with xoxb-.', 'newspack-rolling-coverage' ),
			'invalid_signing_secret' => __( 'The signing secret is invalid. It must be a 32-character hex string.', 'newspack-rolling-coverage' ),
			// Slack auth.test error codes (returned verbatim by Slack).
			'invalid_auth'           => __( 'The bot token was rejected by Slack. Check that it starts with xoxb- and is still valid.', 'newspack-rolling-coverage' ),
			'token_revoked'          => __( 'The bot token has been revoked. Generate a new one in your Slack app settings.', 'newspack-rolling-coverage' ),
			'not_authed'             => __( 'No valid token was provided to Slack.', 'newspack-rolling-coverage' ),
			'account_inactive'       => __( 'The Slack workspace account is inactive.', 'newspack-rolling-coverage' ),
			// Settings errors.
			'invalid_prefix'         => __( 'The ignore prefix must be 1-10 printable characters.', 'newspack-rolling-coverage' ),
			// Configuration / transport.
			'slack_not_configured'   => __( 'Slack integration is not configured. Please set up the bot token in Slack settings.', 'newspack-rolling-coverage' ),
			'slack_transport_error'  => __( 'Could not reach Slack. Please try again.', 'newspack-rolling-coverage' ),
		];

		return $messages[ $code ] ?? $code;
	}

	/**
	 * Build a JSON error response carrying both the machine code and a
	 * localized message. The frontend renders the `message` field directly.
	 *
	 * @param string $code    The error code (looked up in get_error_message()).
	 * @param int    $status  HTTP status code. Defaults to 400.
	 * @param string $message Optional explicit message overriding the mapped one.
	 * @return \WP_REST_Response The error response.
	 */
	protected static function rest_error( string $code, int $status = 400, string $message = '' ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'ok'      => false,
				'error'   => $code,
				'message' => '' !== $message ? $message : self::get_error_message( $code ),
			],
			$status
		);
	}

	/**
	 * Build a Slack Block Kit view_submission error response that shows a
	 * localized message inline on a specific modal block. Reuses the central
	 * get_error_message() map so wording lives in one place; the modal stays
	 * open and the user sees an actionable message instead of Slack's generic
	 * "We had some trouble connecting" fallback.
	 *
	 * @param string $code     The error code (looked up in get_error_message()).
	 * @param string $block_id Block ID to attach the error to.
	 * @return \WP_REST_Response The view_submission error response.
	 */
	protected static function view_error( string $code, string $block_id = 'term_select_block' ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'response_action' => 'errors',
				'errors'          => [
					$block_id => self::get_error_message( $code ),
				],
			],
			200
		);
	}

	/**
	 * Register admin REST routes (always available to manage_options users).
	 *
	 * @return void
	 */
	public function register_admin_routes(): void {
		$namespace = Slack::REST_NAMESPACE;

		register_rest_route(
			$namespace,
			'/slack/verify',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'verify_credentials' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			$namespace,
			'/slack/disconnect',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'disconnect' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			$namespace,
			'/slack/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'save_settings' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/slack/channels',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_channels' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			$namespace,
			'/slack/channel/(?P<id>' . self::CHANNEL_ID_PATTERN . ')',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_channel_settings' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'unlink_channel' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'update_channel_settings' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/slack/connect',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'connect_channel' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			$namespace,
			'/slack/disconnect-term',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'disconnect_term' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		register_rest_route(
			$namespace,
			'/slack/search-terms',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'search_terms' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);
	}

	/**
	 * Register webhook REST routes (signature-verified).
	 *
	 * @return void
	 */
	public function register_webhook_routes(): void {
		$namespace = Slack::REST_NAMESPACE;

		register_rest_route(
			$namespace,
			'/slack/events',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_event' ],
				'permission_callback' => [ $this, 'verify_webhook_signature' ],
			]
		);

		register_rest_route(
			$namespace,
			'/slack/commands',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_command' ],
				'permission_callback' => [ $this, 'verify_webhook_signature' ],
			]
		);

		register_rest_route(
			$namespace,
			'/slack/interactions',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_interaction' ],
				'permission_callback' => [ $this, 'verify_webhook_signature' ],
			]
		);
	}

	/**
	 * Permission callback for admin routes.
	 *
	 * @return bool True if the current user can manage options.
	 */
	public function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Verify Slack webhook signature for webhook routes.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error True if valid, \WP_Error otherwise.
	 */
	public function verify_webhook_signature( \WP_REST_Request $request ) {
		if ( ! Slack_Config::is_configured() ) {
			return new \WP_Error(
				'slack_not_configured',
				__( 'Slack is not configured.', 'newspack-rolling-coverage' ),
				[ 'status' => 503 ]
			);
		}

		$signature = $request->get_header( 'X-Slack-Signature' );
		$timestamp = $request->get_header( 'X-Slack-Request-Timestamp' );
		$raw_body  = $request->get_body();

		$result = $this->signature_verifier->verify( $raw_body, $signature, $timestamp ? (int) $timestamp : null );

		if ( ! $result['valid'] ) {
			Slack_Monitor::log( 'error', 'Signature verification failed', [ 'reason' => (string) ( $result['reason'] ?? 'unknown' ) ] );

			return new \WP_Error(
				'slack_invalid_signature',
				__( 'Invalid Slack signature.', 'newspack-rolling-coverage' ),
				[ 'status' => 401 ]
			);
		}

		Slack_Monitor::log( 'info', 'Signature verification passed' );

		return true;
	}

	/**
	 * POST /slack/verify — verify Slack credentials and store them.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function verify_credentials( \WP_REST_Request $request ): \WP_REST_Response {
		Slack_Monitor::log( 'info', 'Credential verification requested' );
		$bot_token      = sanitize_text_field( (string) $request->get_param( 'bot_token' ) );
		$signing_secret = sanitize_text_field( (string) $request->get_param( 'signing_secret' ) );

		if ( '' === $bot_token || '' === $signing_secret ) {
			return self::rest_error( 'missing_credentials' );
		}

		// Validate credential formats WITHOUT persisting, so a bad re-verify
		// can never overwrite previously-good stored credentials. These checks
		// mirror Slack_Config::set_bot_token() / set_signing_secret().
		if ( ! preg_match( '/^xoxb-[a-zA-Z0-9-]+$/', $bot_token ) ) {
			return self::rest_error( 'invalid_token' );
		}
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $signing_secret ) ) {
			return self::rest_error( 'invalid_signing_secret' );
		}

		// The API client reads the token from Slack_Config, so the candidate
		// credentials must be stored temporarily to run auth_test. Capture the
		// prior values first; on failure they are restored instead of calling
		// clear_all(), so a failed re-verify leaves the existing integration
		// (channel map, settings, prior credentials) intact.
		$old_token  = Slack_Config::get_bot_token();
		$old_secret = Slack_Config::get_signing_secret();

		Slack_Config::set_bot_token( $bot_token );
		Slack_Config::set_signing_secret( $signing_secret );

		$test = $this->api_client->auth_test();

		if ( is_wp_error( $test ) ) {
			// Restore the previously-stored credentials; do NOT wipe the
			// channel map or settings. An empty prior value clears the option
			// since set_bot_token()/set_signing_secret() reject empty strings.
			if ( '' === $old_token ) {
				delete_option( Slack_Config::OPTION_BOT_TOKEN );
			} else {
				Slack_Config::set_bot_token( $old_token );
			}
			if ( '' === $old_secret ) {
				delete_option( Slack_Config::OPTION_SIGNING_SECRET );
			} else {
				Slack_Config::set_signing_secret( $old_secret );
			}

			$error_code = $test->get_error_code();

			// Slack returned an error code (e.g. invalid_auth); map it if known.
			if ( 'slack_api_error' === $error_code ) {
				return self::rest_error( (string) $test->get_error_message(), 401 );
			}

			return self::rest_error( 'slack_transport_error', 500 );
		}

		// Success: candidate credentials are already persisted above. Update
		// settings with the workspace/bot identity returned by auth.test.
		$bot_user_id = Slack_Config::get_or_create_bot_user_id();

		Slack_Config::update_settings(
			[
				'connected'         => true,
				'workspace_name'    => (string) ( $test['team'] ?? '' ),
				'workspace_id'      => (string) ( $test['team_id'] ?? '' ),
				'bot_user_id'       => $bot_user_id,
				'slack_bot_user_id' => (string) ( $test['user_id'] ?? '' ),
			]
		);

		Slack_Monitor::log( 'success', 'Credentials verified', [ 'workspace' => (string) ( $test['team'] ?? '' ) ] );

		return new \WP_REST_Response(
			[
				'ok'   => true,
				'team' => (string) ( $test['team'] ?? '' ),
				'user' => (string) ( $test['user'] ?? '' ),
			],
			200
		);
	}

	/**
	 * POST /slack/disconnect — disconnect Slack integration.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function disconnect( \WP_REST_Request $request ): \WP_REST_Response {
		Slack_Monitor::log( 'info', 'Slack integration disconnected' );
		Slack_Config::clear_all();
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * GET /slack/settings — get merged settings with masked token.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = Slack_Config::get_settings();
		$settings['masked_token'] = Slack_Config::get_masked_bot_token();

		$bot_user_id = (int) ( $settings['bot_user_id'] ?? 0 );

		if ( $bot_user_id > 0 ) {
			$user = get_user_by( 'id', $bot_user_id );

			if ( $user ) {
				$settings['bot_user'] = [
					'id'           => (int) $user->ID,
					'login'        => (string) $user->user_login,
					'display_name' => (string) $user->display_name,
					'email'        => (string) $user->user_email,
					'roles'        => (array) $user->roles,
					'edit_url'     => (string) admin_url( 'user-edit.php?user_id=' . $user->ID ),
				];
			}
		}

		return new \WP_REST_Response( $settings, 200 );
	}

	/**
	 * POST /slack/settings — save settings (ignore_prefix).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$ignore_prefix = sanitize_text_field( (string) $request->get_param( 'ignore_prefix' ) );

		if ( ! Slack_Config::set_ignore_prefix( $ignore_prefix ) ) {
			Slack_Monitor::log( 'warning', 'Invalid ignore prefix rejected', [ 'prefix' => $ignore_prefix ] );
			return self::rest_error( 'invalid_prefix' );
		}

		Slack_Monitor::log( 'info', 'Settings saved', [ 'ignore_prefix' => $ignore_prefix ] );

		return new \WP_REST_Response(
			[
				'ok'            => true,
				'ignore_prefix' => Slack_Config::get_ignore_prefix(),
			],
			200
		);
	}

	/**
	 * GET /slack/channels — list the channel map.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function list_channels( \WP_REST_Request $request ): \WP_REST_Response {
		$map        = Slack_Config::get_channel_map();
		$channels   = [];
		$term_ids   = [];
		$term_names = [];

		// Collect linked term IDs and resolve their names in a single batched
		// get_terms() call, avoiding an N+1 get_term() per channel entry.
		foreach ( $map as $data ) {
			$term_id = (int) ( $data['term_id'] ?? 0 );
			if ( $term_id > 0 ) {
				$term_ids[] = $term_id;
			}
		}

		if ( ! empty( $term_ids ) ) {
			$terms = get_terms(
				[
					'taxonomy'   => Taxonomy::TAXONOMY_SLUG,
					'include'    => $term_ids,
					'hide_empty' => false,
				]
			);

			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_names[ (int) $term->term_id ] = $term->name;
				}
			}
		}

		foreach ( $map as $channel_id => $data ) {
			$entry = array_merge( [ 'channel_id' => $channel_id ], $data );

			// Resolve the linked coverage term name from the batched lookup.
			$term_id           = (int) ( $data['term_id'] ?? 0 );
			$entry['term_name'] = ( $term_id > 0 && isset( $term_names[ $term_id ] ) )
				? $term_names[ $term_id ]
				: '';

			$channels[] = $entry;
		}

		return new \WP_REST_Response( $channels, 200 );
	}

	/**
	 * DELETE /slack/channel/(?P<id>[CG][A-Z0-9]+) — unlink a channel.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function unlink_channel( \WP_REST_Request $request ): \WP_REST_Response {
		$channel_id = sanitize_text_field( (string) $request->get_param( 'id' ) );

		// Fetch the linked term ID from channel settings before removing, so
		// the shared helper can clean up the corresponding term meta.
		$settings = Slack_Config::get_channel_settings( $channel_id );
		$term_id  = ( null !== $settings ) ? (int) ( $settings['term_id'] ?? 0 ) : 0;

		$this->unlink_channel_from_term( $channel_id, $term_id );

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * POST /slack/connect — connect a Slack channel to a rolling coverage term.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function connect_channel( \WP_REST_Request $request ): \WP_REST_Response {
		Slack_Monitor::log( 'info', 'Admin channel connect requested' );
		$term_id     = (int) $request->get_param( 'term_id' );
		$channel     = sanitize_text_field( (string) $request->get_param( 'channel' ) );
		$autopublish = (bool) $request->get_param( 'autopublish' );

		if ( $term_id <= 0 || ! term_exists( $term_id, Taxonomy::TAXONOMY_SLUG ) ) {
			return self::rest_error( 'invalid_term' );
		}

		$channel_id   = '';
		$channel_name = '';

		if ( preg_match( '/^' . self::CHANNEL_ID_PATTERN . '$/', $channel ) ) {
			$channel_id = $channel;
		} else {
			$resolved = $this->api_client->resolve_channel_name( $channel );

			if ( null === $resolved ) {
				return self::rest_error( 'channel_not_found' );
			}

			$channel_id   = $resolved['id'];
			$channel_name = $resolved['name'];
		}

		if ( '' === $channel_id || ! preg_match( '/^' . self::CHANNEL_ID_PATTERN . '$/', $channel_id ) ) {
			return self::rest_error( 'invalid_channel' );
		}

		// A single conversations.info call supplies both the channel name and
		// bot membership, avoiding a duplicate HTTP roundtrip when the channel
		// input is an ID (previously get_channel_name + is_bot_in_channel).
		$info = $this->api_client->get_channel_info( $channel_id );

		if ( is_wp_error( $info ) ) {
			return self::rest_error( 'channel_not_found' );
		}

		if ( '' === $channel_name ) {
			$channel_name = (string) ( $info['channel']['name'] ?? '' );
		}

		if ( ! (bool) ( $info['channel']['is_member'] ?? false ) ) {
			return self::rest_error( 'bot_not_in_channel' );
		}

		$existing_channel = (string) get_term_meta( $term_id, Slack_Config::TERM_META_CHANNEL_ID, true );

		if ( '' !== $existing_channel ) {
			return self::rest_error( 'term_already_connected' );
		}

		$linked_term = Slack_Config::find_linked_term_id( $channel_id );

		if ( null !== $linked_term && $linked_term !== $term_id ) {
			return self::rest_error( 'channel_already_linked' );
		}

		$this->link_channel_to_term( $term_id, $channel_id, $channel_name, $autopublish );

		return new \WP_REST_Response(
			[
				'ok'           => true,
				'channel_id'   => $channel_id,
				'channel_name' => $channel_name,
			],
			200
		);
	}

	/**
	 * POST /slack/disconnect-term — disconnect a channel from a term (idempotent).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function disconnect_term( \WP_REST_Request $request ): \WP_REST_Response {
		$term_id = (int) $request->get_param( 'term_id' );

		$channel_id = (string) get_term_meta( $term_id, Slack_Config::TERM_META_CHANNEL_ID, true );
		if ( '' === $channel_id ) {
			return new \WP_REST_Response( [ 'ok' => true ], 200 );
		}

		$this->unlink_channel_from_term( $channel_id, $term_id );

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * GET /slack/channel/(?P<id>[CG][A-Z0-9]+) — get a single channel's settings.
	 *
	 * Returns the channel map entry for the given channel ID, including the
	 * current autopublish state. Used by the Slack connection modal to seed
	 * the autopublish toggle when a channel is already linked.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function get_channel_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$channel_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$settings   = Slack_Config::get_channel_settings( $channel_id );

		if ( null === $settings ) {
			return self::rest_error( 'invalid_channel' );
		}

		return new \WP_REST_Response(
			[
				'ok'          => true,
				'channel_id'  => $channel_id,
				'autopublish' => (bool) ( $settings['autopublish'] ?? false ),
			],
			200
		);
	}

	/**
	 * POST /slack/channel/(?P<id>[CG][A-Z0-9]+) — update channel settings.
	 *
	 * Currently supports toggling `autopublish` for an already-linked channel.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function update_channel_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$channel_id  = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$autopublish = (bool) $request->get_param( 'autopublish' );
		$settings    = Slack_Config::get_channel_settings( $channel_id );

		if ( null === $settings ) {
			return self::rest_error( 'invalid_channel' );
		}

		Slack_Config::update_channel( $channel_id, [ 'autopublish' => $autopublish ] );

		// Announce the auto-publish change in the channel so editors see the new ingestion behaviour.
		$this->api_client->post_message(
			$channel_id,
			$autopublish
				? __( '🔔 Auto-publish is now enabled for this channel — new entries publish immediately.', 'newspack-rolling-coverage' )
				: __( '🔕 Auto-publish is now disabled for this channel — new entries are saved as drafts.', 'newspack-rolling-coverage' )
		);

		return new \WP_REST_Response(
			[
				'ok'          => true,
				'autopublish' => $autopublish,
			],
			200
		);
	}

	/**
	 * GET /slack/search-terms — search rolling coverage terms.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function search_terms( \WP_REST_Request $request ): \WP_REST_Response {
		$q = sanitize_text_field( (string) $request->get_param( 'q' ) );

		$terms = get_terms(
			[
				'taxonomy'   => Taxonomy::TAXONOMY_SLUG,
				'number'     => 20,
				'search'     => $q,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) ) {
			return new \WP_REST_Response( [ 'terms' => [] ], 200 );
		}

		$result = [];

		foreach ( $terms as $term ) {
			$result[] = [
				'id'    => (int) $term->term_id,
				'name'  => $term->name,
				'count' => (int) $term->count,
			];
		}

		return new \WP_REST_Response( [ 'terms' => $result ], 200 );
	}

	/**
	 * POST /slack/events — handle Slack Events API events.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function handle_event( \WP_REST_Request $request ): \WP_REST_Response {
		$raw_body = $request->get_body();
		$payload  = json_decode( $raw_body, true );

		if ( ! is_array( $payload ) ) {
			return new \WP_REST_Response( [ 'error' => 'invalid_request' ], 400 );
		}

		$type = (string) ( $payload['type'] ?? '' );

		Slack_Monitor::log( 'info', "Webhook event received: {$type}" );

		if ( 'url_verification' === $type ) {
			return new \WP_REST_Response(
				[ 'challenge' => (string) ( $payload['challenge'] ?? '' ) ],
				200
			);
		}

		$event = $payload['event'] ?? [];

		if ( ! is_array( $event ) ) {
			return new \WP_REST_Response( [ 'ok' => true ], 200 );
		}

		$event_type = (string) ( $event['type'] ?? '' );

		if ( 'message' === $event_type ) {
			// 1. Filter — Slack-specific rules from Slack_Ingestion_Service.
			if ( Slack_Ingestion_Service::should_filter_message( $event ) ) {
				Slack_Monitor::log( 'info', 'Message filtered (bot/edit/delete/join-leave/ignore prefix)', [ 'channel' => $event['channel'] ?? '' ] );
				return new \WP_REST_Response( [ 'ok' => true ], 200 );
			}

			// 2. Slack-specific config lookups (synchronous — fast, no outbound calls).
			$channel_id = (string) ( $event['channel'] ?? '' );
			$ts         = (string) ( $event['ts'] ?? '' );
			$user_id    = (string) ( $event['user'] ?? '' );

			if ( '' === $channel_id || '' === $ts || '' === $user_id ) {
				Slack_Monitor::log( 'warning', 'Missing channel/ts/user in message event' );
				return new \WP_REST_Response( [ 'ok' => true ], 200 );
			}

			$channel_settings = Slack_Config::get_channel_settings( $channel_id );

			if ( null === $channel_settings ) {
				Slack_Monitor::log( 'info', 'Channel not linked to a coverage, skipping', [ 'channel' => $channel_id ] );
				return new \WP_REST_Response( [ 'ok' => true ], 200 );
			}

			$term_id = (int) ( $channel_settings['term_id'] ?? 0 );

			if ( $term_id <= 0 ) {
				Slack_Monitor::log( 'warning', 'Channel mapping has no term_id', [ 'channel' => $channel_id ] );
				return new \WP_REST_Response( [ 'ok' => true ], 200 );
			}

			// 3. Process this message inline. The 1s API timeout for the
			// outbound users.info call keeps the total webhook response well
			// under Slack's 3-second limit.
			Slack_Monitor::log(
				'info',
				'Dispatching message to ingestion pipeline',
				[
					'channel' => $channel_id,
					'ts'      => $ts,
				] 
			);

			self::process_ingest_payload(
				[
					'event'        => $event,
					'term_id'      => $term_id,
					'channel_id'   => $channel_id,
					'ts'           => $ts,
					'user_id'      => $user_id,
					'auto_publish' => Slack_Config::is_autopublish_enabled( $channel_id ),
				]
			);

			return new \WP_REST_Response( [ 'ok' => true ], 200 );
		}

		if ( 'member_joined_channel' === $event_type ) {
			$settings     = Slack_Config::get_settings();
			$slack_bot_id = (string) ( $settings['slack_bot_user_id'] ?? '' );
			$joining_user = (string) ( $event['user'] ?? '' );
			$channel_id   = (string) ( $event['channel'] ?? '' );

			if ( '' !== $slack_bot_id && $slack_bot_id === $joining_user && '' !== $channel_id ) {
				$this->api_client->post_message(
					$channel_id,
					__( '👋 I\'m now connected and will ingest messages for rolling coverage.', 'newspack-rolling-coverage' )
				);
			}
		}

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * POST /slack/commands — handle Slack slash commands.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function handle_command( \WP_REST_Request $request ): \WP_REST_Response {
		$raw_body = $request->get_body();
		$params   = [];
		// Parse the raw body in $params.
		wp_parse_str( $raw_body, $params );

		$command      = (string) ( $params['command'] ?? '' );
		$channel_id   = (string) ( $params['channel_id'] ?? '' );
		$channel_name = (string) ( $params['channel_name'] ?? '' );
		$trigger_id   = (string) ( $params['trigger_id'] ?? '' );

		// Secondary defense-in-depth (signature verifier is the primary trust
		// boundary): confirm the command's team matches the workspace bound to
		// this Slack app on first connect. A signed payload from a different
		// workspace must not be able to unlink or inspect channel-term linkages
		// even if signing material leaked.
		$payload_team = (string) ( $params['team_id'] ?? '' );
		$stored_team  = (string) ( Slack_Config::get_settings()['workspace_id'] ?? '' );

		if ( '' !== $stored_team && '' !== $payload_team && $payload_team !== $stored_team ) {
			Slack_Monitor::log(
				'warning',
				'Slash command rejected: team_id mismatch',
				[
					'payload_team' => $payload_team,
					'stored_team'  => $stored_team,
				] 
			);
			return $this->ephemeral( __( 'This command is not available for your workspace.', 'newspack-rolling-coverage' ) );
		}

		Slack_Monitor::log( 'info', "Slash command received: {$command}", [ 'channel' => $channel_id ] );

		switch ( $command ) {
			case '/rolling-coverage-connect':
				return $this->handle_connect_command( $trigger_id, $channel_id, $channel_name );
			case '/rolling-coverage-unlink':
				return $this->handle_unlink_command( $channel_id );
			case '/rolling-coverage-status':
				return $this->handle_status_command( $channel_id );
			default:
				return $this->ephemeral( __( 'Unknown command.', 'newspack-rolling-coverage' ) );
		}
	}

	/**
	 * Handle /rolling-coverage-connect slash command — opens a modal.
	 *
	 * @param string $trigger_id   Slack trigger ID.
	 * @param string $channel_id    Slack channel ID.
	 * @param string $channel_name  Slack channel name.
	 * @return \WP_REST_Response Response.
	 */
	private function handle_connect_command( string $trigger_id, string $channel_id, string $channel_name ): \WP_REST_Response {
		$callback_id = 'rolling_coverage_connect_' . $channel_id;

		$blocks = [
			[
				'type'     => 'input',
				'block_id' => 'term_select_block',
				'label'    => [
					'type' => 'plain_text',
					'text' => __( 'Select a Rolling Coverage', 'newspack-rolling-coverage' ),
				],
				'element'  => [
					'type'             => 'external_select',
					'action_id'        => 'rolling_coverage_search',
					'min_query_length' => 0,
					'placeholder'      => [
						'type' => 'plain_text',
						'text' => __( 'Type to search...', 'newspack-rolling-coverage' ),
					],
				],
			],
		];

		$view = [
			'type'             => 'modal',
			'callback_id'      => $callback_id,
			'private_metadata' => $channel_id,
			'title'            => [
				'type' => 'plain_text',
				'text' => __( 'Connect Channel', 'newspack-rolling-coverage' ),
			],
			'submit'           => [
				'type' => 'plain_text',
				'text' => __( 'Connect', 'newspack-rolling-coverage' ),
			],
			'close'            => [
				'type' => 'plain_text',
				'text' => __( 'Cancel', 'newspack-rolling-coverage' ),
			],
			'blocks'           => $blocks,
		];

		$result = $this->api_client->open_view( $trigger_id, $view );

		if ( null === $result ) {
			return $this->ephemeral( __( 'Failed to open the connect modal. Please try again.', 'newspack-rolling-coverage' ) );
		}

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Handle /rolling-coverage-unlink slash command.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @return \WP_REST_Response Response.
	 */
	private function handle_unlink_command( string $channel_id ): \WP_REST_Response {
		$term_id = Slack_Config::find_linked_term_id( $channel_id );

		if ( null === $term_id ) {
			return $this->ephemeral( __( 'This channel is not connected to any coverage.', 'newspack-rolling-coverage' ) );
		}

		$term_name = $this->get_term_name( $term_id );

		$this->unlink_channel_from_term( $channel_id, $term_id );

		return $this->ephemeral(
			'' !== $term_name
				? sprintf(
					/* translators: 1: coverage name */
					__( 'Disconnected from "%1$s".', 'newspack-rolling-coverage' ),
					$term_name
				)
				: __( 'Channel unlinked successfully.', 'newspack-rolling-coverage' )
		);
	}

	/**
	 * Handle /rolling-coverage-status slash command.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @return \WP_REST_Response Response.
	 */
	private function handle_status_command( string $channel_id ): \WP_REST_Response {
		$settings = Slack_Config::get_channel_settings( $channel_id );

		if ( null === $settings ) {
			return $this->ephemeral( __( 'This channel is not connected to any coverage.', 'newspack-rolling-coverage' ) );
		}

		$term_id     = (int) ( $settings['term_id'] ?? 0 );
		$term        = $term_id > 0 ? get_term( $term_id, Taxonomy::TAXONOMY_SLUG ) : null;
		$term_name   = ( $term && ! is_wp_error( $term ) ) ? $term->name : __( '(unknown)', 'newspack-rolling-coverage' );
		$autopublish = (bool) ( $settings['autopublish'] ?? false );

		$status_text = $autopublish
			? __( 'autopublish enabled', 'newspack-rolling-coverage' )
			: __( 'autopublish disabled', 'newspack-rolling-coverage' );

		return $this->ephemeral(
			sprintf(
				/* translators: 1: term name, 2: autopublish status */
				__( 'Connected to "%1$s" — %2$s.', 'newspack-rolling-coverage' ),
				$term_name,
				$status_text
			)
		);
	}

	/**
	 * POST /slack/interactions — handle Slack interactive callbacks.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function handle_interaction( \WP_REST_Request $request ): \WP_REST_Response {
		$raw_body = $request->get_body();
		$params   = [];
		// Parse the raw body in $params.
		wp_parse_str( $raw_body, $params );

		$payload_str = (string) ( $params['payload'] ?? '' );
		$payload     = json_decode( $payload_str, true );

		if ( ! is_array( $payload ) ) {
			return new \WP_REST_Response( [ 'ok' => true ], 200 );
		}

		$type = (string) ( $payload['type'] ?? '' );

		Slack_Monitor::log( 'info', "Interaction received: {$type}" );

		if ( 'view_submission' === $type ) {
			return $this->handle_view_submission( $payload );
		}

		if ( 'block_suggestion' === $type ) {
			$action_id = (string) ( $payload['action_id'] ?? '' );

			if ( 'rolling_coverage_search' === $action_id ) {
				return $this->handle_term_suggestion( $payload );
			}
		}

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Dispatch a view_submission interaction.
	 *
	 * Slack nests the callback_id inside payload.view (it is NOT a top-level
	 * field on view_submission payloads), so it must be read from
	 * $view['callback_id']. Reading it from the top level always yields an
	 * empty string, causing the submission to never route to its handler —
	 * Slack then surfaces the modal as "We had some trouble connecting."
	 *
	 * @param array $payload Interaction payload.
	 * @return \WP_REST_Response Response.
	 */
	private function handle_view_submission( array $payload ): \WP_REST_Response {
		$view        = $payload['view'] ?? [];
		$callback_id = (string) ( $view['callback_id'] ?? '' );

		if ( 0 === strpos( $callback_id, 'rolling_coverage_connect_' ) ) {
			return $this->handle_connect_submission( $payload );
		}

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Handle a view_submission from the connect modal.
	 *
	 * @param array $payload Full view_submission payload (needed for the
	 *                       top-level `team` / `user.team_id` secondary check).
	 * @return \WP_REST_Response Response.
	 */
	private function handle_connect_submission( array $payload ): \WP_REST_Response {
		$view       = $payload['view'] ?? [];
		$channel_id = (string) ( $view['private_metadata'] ?? '' );
		$state      = $view['state']['values'] ?? [];

		// Secondary defense-in-depth (signature verifier is the primary trust
		// boundary): confirm the submission's team matches the workspace bound
		// to this Slack app on first connect. A signed payload from a different
		// workspace must not be able to mutate channel-term linkages even if
		// signing material leaked. Use the generic `invalid_term` error so we
		// don't leak workspace identity to a probing attacker.
		$payload_team = (string) ( $payload['team']['id'] ?? $payload['user']['team_id'] ?? '' );
		$stored_team  = (string) ( Slack_Config::get_settings()['workspace_id'] ?? '' );

		if ( '' !== $stored_team && '' !== $payload_team && $payload_team !== $stored_team ) {
			return self::view_error( 'invalid_term' );
		}

		$selected = '';

		foreach ( $state as $block ) {
			foreach ( $block as $element ) {
				if ( isset( $element['selected_option']['value'] ) ) {
					$selected = (string) $element['selected_option']['value'];
				} elseif ( isset( $element['value'] ) ) {
					$selected = (string) $element['value'];
				}
			}
		}

		$term_id = (int) $selected;

		if ( $term_id <= 0 || '' === $channel_id ) {
			return self::view_error( 'term_select_required' );
		}

		if ( ! term_exists( $term_id, Taxonomy::TAXONOMY_SLUG ) ) {
			return self::view_error( 'term_not_found' );
		}

		$linked_term = Slack_Config::find_linked_term_id( $channel_id );

		if ( null !== $linked_term && $linked_term !== $term_id ) {
			return self::view_error( 'channel_already_linked' );
		}

		$existing_channel = (string) get_term_meta( $term_id, Slack_Config::TERM_META_CHANNEL_ID, true );

		if ( '' !== $existing_channel && $existing_channel !== $channel_id ) {
			return self::view_error( 'term_already_connected' );
		}

		// Verify the bot is a member of the channel before linking. Without
		// this, the follow-up chat.postMessage fails and Slack surfaces a
		// generic "We had some trouble connecting" modal error instead of an
		// actionable message. conversations.info returning a \WP_Error means the
		// bot cannot see the channel at all (not invited / not found).
		$membership = $this->api_client->is_bot_in_channel( $channel_id );

		if ( ! empty( $membership['error'] ) ) {
			return self::view_error( 'channel_not_found' );
		}
		if ( empty( $membership['is_member'] ) ) {
			return self::view_error( 'bot_not_in_channel' );
		}

		$channel_name = $this->api_client->get_channel_name( $channel_id );

		// link_channel_to_term posts the connection announcement to the channel
		// (with the coverage name + auto-publish state), so no separate
		// chat.postMessage is needed here.
		$this->link_channel_to_term( $term_id, $channel_id, $channel_name, false );

		return new \WP_REST_Response( [ 'response_action' => 'clear' ], 200 );
	}

	/**
	 * Handle a block_suggestion for the external term select.
	 *
	 * @param array $payload Interaction payload.
	 * @return \WP_REST_Response Response with options array.
	 */
	private function handle_term_suggestion( array $payload ): \WP_REST_Response {
		// Secondary defense-in-depth (signature verifier is the primary trust
		// boundary): only reveal coverage term names to callers whose team_id
		// matches the workspace bound to this Slack app. An empty/missing
		// payload team, or a mismatch, returns an empty option list so an
		// attacker from a different workspace (or probing blindly) cannot
		// enumerate coverage terms via this endpoint.
		$payload_team = (string) ( $payload['team']['id'] ?? $payload['user']['team_id'] ?? '' );
		$stored_team  = (string) ( Slack_Config::get_settings()['workspace_id'] ?? '' );

		if ( '' === $payload_team || ( '' !== $stored_team && $payload_team !== $stored_team ) ) {
			return new \WP_REST_Response( [ 'options' => [] ], 200 );
		}

		$query = (string) ( $payload['value'] ?? '' );

		$terms = get_terms(
			[
				'taxonomy'   => Taxonomy::TAXONOMY_SLUG,
				'number'     => 20,
				'search'     => $query,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return new \WP_REST_Response( [ 'options' => [] ], 200 );
		}

		$options = [];

		foreach ( $terms as $term ) {
			$options[] = [
				'text'  => [
					'type' => 'plain_text',
					'text' => $term->name,
				],
				'value' => (string) $term->term_id,
			];
		}

		return new \WP_REST_Response( [ 'options' => $options ], 200 );
	}

	/**
	 * Build an ephemeral Slack command response.
	 *
	 * @param string $text Message text.
	 * @return \WP_REST_Response Response.
	 */
	private function ephemeral( string $text ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'response_type' => 'ephemeral',
				'text'          => $text,
			],
			200
		);
	}

	/**
	 * Resolve a rolling coverage term's name from its ID.
	 *
	 * Used by the connect/disconnect channel announcements and the unlink slash
	 * command so the messages reference the linked coverage by name (mirroring
	 * the /rolling-coverage-status output).
	 *
	 * @param int $term_id Term ID.
	 * @return string Term name, or '' if the term no longer exists.
	 */
	private function get_term_name( int $term_id ): string {
		if ( $term_id <= 0 ) {
			return '';
		}

		$term = get_term( $term_id, Taxonomy::TAXONOMY_SLUG );

		return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
	}

	/**
	 * Link a Slack channel to a rolling coverage term.
	 *
	 * Centralizes the channel-map update, term-meta writes, and linked action
	 * so every connect/link code path keeps the same linkage invariants.
	 *
	 * Called from both admin REST routes (manage_options gated via
	 * check_admin_permission) and signature-verified webhook routes. The
	 * webhook has no WP user, so this helper deliberately does not run a
	 * `current_user_can` check. Webhook call sites MUST verify the payload's
	 * `team.id` matches the stored `workspace_id` before invoking this helper
	 * — see handle_connect_submission for the canonical secondary check. Admin
	 * routes are already gated on `manage_options` at the permission_callback
	 * level and therefore do not need an additional team_id check.
	 *
	 * @param int    $term_id      Term ID.
	 * @param string $channel_id   Slack channel ID.
	 * @param string $channel_name Slack channel name.
	 * @param bool   $autopublish  Whether autopublish is enabled for the channel.
	 * @return void
	 */
	private function link_channel_to_term( int $term_id, string $channel_id, string $channel_name, bool $autopublish ): void {
		Slack_Config::update_channel(
			$channel_id,
			[
				'channel_name' => $channel_name,
				'term_id'      => $term_id,
				'autopublish'  => $autopublish,
				'last_sync_ts' => '',
			]
		);
		update_term_meta( $term_id, Slack_Config::TERM_META_CHANNEL_ID, $channel_id );
		update_term_meta( $term_id, Slack_Config::TERM_META_CHANNEL_NAME, $channel_name );

		// Dual-write the generic source term-meta keys alongside the Slack-specific
		// ones. A future cross-platform 'list all linked sources' query can read
		// these directly without enumerating per-source keys.
		update_term_meta( $term_id, Taxonomy::META_SOURCE, 'slack' );
		update_term_meta( $term_id, Taxonomy::META_SOURCE_REF, $channel_id );

		/**
		 * Fires after a Slack channel is linked to a rolling coverage term.
		 *
		 * @param string $channel_id The Slack channel ID.
		 * @param int    $term_id    The linked rolling coverage term ID.
		 */
		do_action( 'rolling_coverage_slack_channel_linked', $channel_id, $term_id );

		// Announce the connection in the channel, referencing the linked coverage
		// by name and the auto-publish state (mirrors /rolling-coverage-status).
		$term_name   = $this->get_term_name( $term_id );
		$status_text = $autopublish
			? __( 'auto-publish is enabled', 'newspack-rolling-coverage' )
			: __( 'auto-publish is disabled', 'newspack-rolling-coverage' );

		$this->api_client->post_message(
			$channel_id,
			'' !== $term_name
				? sprintf(
					/* translators: 1: coverage name, 2: auto-publish status */
					__( '✅ This channel is now connected to the "%1$s" coverage. %2$s.', 'newspack-rolling-coverage' ),
					$term_name,
					$status_text
				)
				: __( '✅ This channel is now connected to rolling coverage.', 'newspack-rolling-coverage' )
		);
	}

	/**
	 * Unlink a Slack channel from a rolling coverage term.
	 *
	 * Centralizes the channel-map removal, term-meta cleanup, and unlinked
	 * action so every unlink/disconnect code path keeps the same invariants.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @param int    $term_id    Term ID.
	 * @return void
	 */
	private function unlink_channel_from_term( string $channel_id, int $term_id ): void {
		// Resolve the coverage name before removing the linkage so the
		// disconnection announcement can still reference it.
		$term_name = $this->get_term_name( $term_id );

		Slack_Config::remove_channel( $channel_id );
		delete_term_meta( $term_id, Slack_Config::TERM_META_CHANNEL_ID );
		delete_term_meta( $term_id, Slack_Config::TERM_META_CHANNEL_NAME );

		// Mirror the dual-write from link_channel_to_term: clear the generic
		// source term-meta keys when the Slack-specific linkage is removed.
		delete_term_meta( $term_id, Taxonomy::META_SOURCE );
		delete_term_meta( $term_id, Taxonomy::META_SOURCE_REF );

		/**
		 * Fires after a Slack channel is unlinked from its rolling coverage term.
		 *
		 * @param string $channel_id The Slack channel ID that was unlinked.
		 */
		do_action( 'rolling_coverage_slack_channel_unlinked', $channel_id );

		// Announce the disconnection in the channel, referencing the coverage it
		// was linked to.
		$this->api_client->post_message(
			$channel_id,
			'' !== $term_name
				? sprintf(
					/* translators: 1: coverage name */
					__( '🔌 This channel is no longer connected to the "%1$s" coverage.', 'newspack-rolling-coverage' ),
					$term_name
				)
				: __( '🔌 This channel is no longer connected to rolling coverage.', 'newspack-rolling-coverage' )
		);
	}

	/**
	 * Core ingestion pipeline. Performs author resolution (with a 1s API
	 * timeout to stay under Slack's 3s webhook limit), content processing,
	 * and DB writes.
	 *
	 * @param array $payload Pre-validated payload with event + resolved IDs.
	 * @return void
	 */
	protected static function process_ingest_payload( array $payload ): void {
		$event        = $payload['event'] ?? [];
		$term_id      = (int) ( $payload['term_id'] ?? 0 );
		$channel_id   = (string) ( $payload['channel_id'] ?? '' );
		$ts           = (string) ( $payload['ts'] ?? '' );
		$user_id      = (string) ( $payload['user_id'] ?? '' );
		$auto_publish = (bool) ( $payload['auto_publish'] ?? false );

		if ( $term_id <= 0 || '' === $channel_id || '' === $ts || '' === $user_id ) {
			Slack_Monitor::log( 'warning', 'Ingestion: invalid payload' );
			return;
		}

		$text      = (string) ( $event['text'] ?? '' );
		$thread_ts = (string) ( $event['thread_ts'] ?? '' );

		// Fresh instances — this runs in the webhook request without the
		// controller's injected dependencies.
		$api_client        = new Slack_API_Client();
		$content_processor = new Slack_Content_Processor();

		// 1. Author resolution via outbound Slack API call. Use the short
		// webhook timeout (1s) to stay under Slack's 3s webhook limit.
		$user_info   = $api_client->get_user_info( $user_id, Slack_API_Client::WEBHOOK_TIMEOUT );
		$author_name = ( is_wp_error( $user_info ) || empty( $user_info ) )
			? 'User ' . $user_id
			: (string) ( $user_info['profile']['display_name'] ?? $user_info['profile']['real_name'] ?? $user_info['name'] ?? 'User ' . $user_id );

		// 2. Content processing.
		$content       = $content_processor->process( $text );
		$content_plain = $content_processor->to_plain_text_sanitized( $text );

		// 3. Bot user resolution.
		$bot_user_id = Slack_Author_Resolver::get_slack_bot_user_id();

		// 4. Build the normalized payload.
		$source_payload = new Source_Event_Payload(
			source: 'slack',
			source_ref: $ts,
			conversation_ref: $channel_id,
			author_external_id: $user_id,
			author_display_name: $author_name,
			content_html: $content,
			content_plain: $content_plain,
			thread_ref: '' !== $thread_ts ? $thread_ts : null,
			external_timestamp: (string) ( is_numeric( $ts ) ? gmdate( 'c', (int) ( (float) $ts ) ) : '' ),
			raw_payload: $event
		);

		// 5. Slack-specific provenance meta.
		$provenance_meta = [
			Post_Type::META_SLACK_TS          => $ts,
			Post_Type::META_SLACK_CHANNEL_ID  => $channel_id,
			Post_Type::META_SLACK_USER_ID     => $user_id,
			Post_Type::META_SLACK_THREAD_TS   => $thread_ts,
			Post_Type::META_SLACK_AUTHOR_NAME => $author_name,
		];

		// 6. Call the generic ingestion service.
		$post_id = Entry_Ingestion_Service::ingest(
			$source_payload,
			$term_id,
			$auto_publish,
			$bot_user_id,
			$provenance_meta
		);

		if ( is_wp_error( $post_id ) || $post_id <= 0 ) {
			if ( is_wp_error( $post_id ) ) {
				Slack_Monitor::log(
					'error',
					'Ingestion: entry creation failed',
					[
						'error'   => $post_id->get_error_code(),
						'message' => $post_id->get_error_message(),
					] 
				);
			} else {
				Slack_Monitor::log( 'info', 'Ingestion: entry not created (duplicate, empty content, or bot user unavailable)', [ 'ts' => $ts ] );
			}
			return;
		}

		// 7. Adapter-specific side effects: last_sync_ts update.
		Slack_Config::update_channel( $channel_id, [ 'last_sync_ts' => $ts ] );

		Slack_Monitor::log(
			'success',
			'Ingestion: entry created',
			[
				'post_id' => (int) $post_id,
				'channel' => $channel_id,
				'status'  => $auto_publish ? 'publish' : 'draft',
			] 
		);
	}
}
