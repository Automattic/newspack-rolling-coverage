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
	 * Webhook routes, relative to the REST namespace, and the methods that
	 * handle them. Slack sends every request to one of these.
	 *
	 * @var array<string, string>
	 */
	const WEBHOOK_ROUTES = [
		'/slack/events'       => 'handle_event',
		'/slack/commands'     => 'handle_command',
		'/slack/interactions' => 'handle_interaction',
	];

	/**
	 * Seconds into handling a message after which mentioned users are no
	 * longer looked up from the Slack API. The lookups run inside the webhook
	 * request, which Slack retries when it takes longer than three seconds.
	 * Reading the thread a reply belongs to counts toward it.
	 *
	 * @var float
	 */
	const MENTION_LOOKUP_BUDGET = 1.0;

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
			'invalid_publish_mode'   => __( 'Choose a publish mode: Auto-publish, Draft or Emoji trigger.', 'newspack-rolling-coverage' ),
			'reactions_not_granted'  => __( "The Slack app isn't allowed to read reactions yet. Update its manifest and reinstall it, as the warning on the Slack Connection page explains, then choose Emoji trigger again.", 'newspack-rolling-coverage' ),
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
			'invalid_reactions'      => __( 'Use two different emoji names for publishing and drafts, such as mega and memo.', 'newspack-rolling-coverage' ),
			'invalid_heading_level'  => __( 'Choose a highest heading from Heading 2 to Heading 6.', 'newspack-rolling-coverage' ),
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
		foreach ( self::WEBHOOK_ROUTES as $route => $handler ) {
			register_rest_route(
				Slack::REST_NAMESPACE,
				$route,
				[
					'methods'             => 'POST',
					'callback'            => [ $this, $handler ],
					'permission_callback' => [ $this, 'verify_webhook_signature' ],
				]
			);
		}
	}

	/**
	 * Whether WordPress is serving one of the webhook routes.
	 *
	 * Reads the route WordPress parsed from the request URL, so it answers
	 * false until the request is parsed. The REST API checks authentication
	 * after that. Routes compare without regard to case or a trailing slash,
	 * as the REST API matches them.
	 *
	 * @return bool
	 */
	public static function is_webhook_request(): bool {
		global $wp;

		$route = $wp instanceof \WP ? ( $wp->query_vars['rest_route'] ?? '' ) : '';

		if ( ! is_string( $route ) || '' === $route ) {
			return false;
		}

		$route = untrailingslashit( $route );

		foreach ( array_keys( self::WEBHOOK_ROUTES ) as $webhook_route ) {
			if ( 0 === strcasecmp( '/' . Slack::REST_NAMESPACE . $webhook_route, $route ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lift the Password Protected plugin's protection from the webhook routes.
	 *
	 * Password Protected refuses REST requests from visitors who haven't
	 * entered the site password, unless its "Allow REST API" setting is on.
	 * Slack can't enter the password, so every webhook request would be
	 * refused with a 401 before its signature is checked. The webhook routes
	 * check that signature on every request, so lifting protection from them
	 * lets Slack in and still refuses anyone else. The rest of the site stays
	 * protected.
	 *
	 * @param bool $is_active Whether Password Protected protects the request.
	 * @return bool
	 */
	public static function filter_password_protected_is_active( $is_active ) {
		return self::is_webhook_request() ? false : $is_active;
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
			$reason = (string) ( $result['reason'] ?? 'unknown' );
			error_log( 'Slack ingestion: signature verification failed (' . $reason . ') — webhook rejected with 401, no entry will be created.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			Slack_Monitor::log( 'error', 'Signature verification failed', [ 'reason' => $reason ] );

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

		$settings['publish_reaction']  = Slack_Config::get_publish_reaction();
		$settings['draft_reaction']    = Slack_Config::get_draft_reaction();
		$settings['top_heading_level'] = Slack_Config::get_top_heading_level();

		// Only a definite "no" is reported: unknown scopes are not a problem to act on.
		$scopes                        = $this->api_client->get_granted_scopes();
		$settings['can_read_files']    = null === $scopes || in_array( 'files:read', $scopes, true );
		$settings['can_use_reactions'] = null === $scopes || [] === array_diff( Slack_Config::REACTION_SCOPES, $scopes );

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
	 * POST /slack/settings — save settings (ignore_prefix, publish_reaction,
	 * draft_reaction, top_heading_level).
	 *
	 * Everything is checked before anything is stored, so a refused request
	 * changes nothing.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$ignore_prefix    = sanitize_text_field( (string) $request->get_param( 'ignore_prefix' ) );
		$publish_reaction = sanitize_text_field( (string) ( $request->get_param( 'publish_reaction' ) ?? Slack_Config::get_publish_reaction() ) );
		$draft_reaction   = sanitize_text_field( (string) ( $request->get_param( 'draft_reaction' ) ?? Slack_Config::get_draft_reaction() ) );
		$heading_level    = filter_var( $request->get_param( 'top_heading_level' ) ?? Slack_Config::get_top_heading_level(), FILTER_VALIDATE_INT );

		if ( ! Slack_Config::is_top_heading_level( $heading_level ) ) {
			Slack_Monitor::log( 'warning', 'Invalid highest heading rejected', [ 'top_heading_level' => $request->get_param( 'top_heading_level' ) ] );
			return self::rest_error( 'invalid_heading_level' );
		}

		if ( ! Slack_Config::are_valid_reactions( $publish_reaction, $draft_reaction ) ) {
			Slack_Monitor::log(
				'warning',
				'Invalid trigger emoji rejected',
				[
					'publish_reaction' => $publish_reaction,
					'draft_reaction'   => $draft_reaction,
				]
			);
			return self::rest_error( 'invalid_reactions' );
		}

		if ( ! Slack_Config::set_ignore_prefix( $ignore_prefix ) ) {
			Slack_Monitor::log( 'warning', 'Invalid ignore prefix rejected', [ 'prefix' => $ignore_prefix ] );
			return self::rest_error( 'invalid_prefix' );
		}

		Slack_Config::set_reactions( $publish_reaction, $draft_reaction );
		Slack_Config::set_top_heading_level( $heading_level );

		$saved = [
			'ignore_prefix'     => Slack_Config::get_ignore_prefix(),
			'publish_reaction'  => Slack_Config::get_publish_reaction(),
			'draft_reaction'    => Slack_Config::get_draft_reaction(),
			'top_heading_level' => Slack_Config::get_top_heading_level(),
		];

		Slack_Monitor::log( 'info', 'Settings saved', $saved );

		return new \WP_REST_Response( [ 'ok' => true ] + $saved, 200 );
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

			// Channels linked before publish modes stored only an auto-publish flag.
			$entry['publish_mode'] = Slack_Config::resolve_publish_mode( $data );
			unset( $entry['autopublish'] );

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
		$term_id      = (int) $request->get_param( 'term_id' );
		$channel      = sanitize_text_field( (string) $request->get_param( 'channel' ) );
		$publish_mode = (string) ( $request->get_param( 'publish_mode' ) ?? Slack_Config::PUBLISH_MODE_DRAFT );

		if ( ! Slack_Config::is_publish_mode( $publish_mode ) ) {
			return self::rest_error( 'invalid_publish_mode' );
		}

		if ( Slack_Config::PUBLISH_MODE_REACTION === $publish_mode && $this->app_cannot_read_reactions() ) {
			return self::rest_error( 'reactions_not_granted' );
		}

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

		$this->link_channel_to_term( $term_id, $channel_id, $channel_name, $publish_mode );

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
	 * Returns the channel's publish mode and the timestamp of the last
	 * message it ingested. The Slack connection drawer shows both when a
	 * channel is already linked.
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
				'ok'           => true,
				'channel_id'   => $channel_id,
				'publish_mode' => Slack_Config::resolve_publish_mode( $settings ),
				'last_sync_ts' => (string) ( $settings['last_sync_ts'] ?? '' ),
			],
			200
		);
	}

	/**
	 * POST /slack/channel/(?P<id>[CG][A-Z0-9]+) — update channel settings.
	 *
	 * Sets the publish mode of an already-linked channel, and tells the
	 * channel what now happens to its messages.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function update_channel_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$channel_id   = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$publish_mode = (string) $request->get_param( 'publish_mode' );
		$settings     = Slack_Config::get_channel_settings( $channel_id );

		if ( null === $settings ) {
			return self::rest_error( 'invalid_channel' );
		}

		if ( ! Slack_Config::is_publish_mode( $publish_mode ) ) {
			return self::rest_error( 'invalid_publish_mode' );
		}

		// A channel told to react while its reactions can't reach the site
		// would publish nothing, so Emoji trigger waits for the permissions.
		if ( Slack_Config::PUBLISH_MODE_REACTION === $publish_mode && $this->app_cannot_read_reactions() ) {
			return self::rest_error( 'reactions_not_granted' );
		}

		Slack_Config::update_channel( $channel_id, Slack_Config::publish_mode_fields( $publish_mode ) );

		$this->api_client->post_message(
			$channel_id,
			sprintf(
				/* translators: 1: publish mode name, 2: what happens to messages posted in the channel */
				__( 'Publish mode is now %1$s. %2$s', 'newspack-rolling-coverage' ),
				self::publish_mode_label( $publish_mode ),
				self::describe_publish_mode( $publish_mode )
			)
		);

		return new \WP_REST_Response(
			[
				'ok'           => true,
				'publish_mode' => $publish_mode,
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
			$started = microtime( true );

			// 1. Filter — Slack-specific rules from Slack_Ingestion_Service.
			if ( Slack_Ingestion_Service::should_filter_message( $event ) ) {
				Slack_Monitor::log(
					'info',
					'Message filtered (bot, edit, delete, Slack notice or ignore prefix)',
					[
						'channel' => $event['channel'] ?? '',
						'subtype' => (string) ( $event['subtype'] ?? '' ),
					]
				);
				return new \WP_REST_Response( [ 'ok' => true ], 200 );
			}

			// 2. Slack-specific config lookups (synchronous — fast, no outbound calls).
			$channel_id = (string) ( $event['channel'] ?? '' );
			$ts         = (string) ( $event['ts'] ?? '' );
			$user_id    = (string) ( $event['user'] ?? '' );

			if ( '' === $channel_id || '' === $ts || '' === $user_id ) {
				Slack_Monitor::log( 'warning', 'Missing channel/ts/user in message event' );
				error_log( 'Slack ingestion: missing channel/ts/user, skipping.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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
				error_log( 'Slack ingestion: channel ' . $channel_id . ' mapping has no term_id, skipping.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return new \WP_REST_Response( [ 'ok' => true ], 200 );
			}

			$publish_mode = Slack_Config::resolve_publish_mode( $channel_settings );

			// 3. In an Emoji trigger channel a message waits for its reaction,
			// which reads the message again, so nothing is looked up now.
			if ( Slack_Config::PUBLISH_MODE_REACTION === $publish_mode ) {
				Slack_Monitor::log(
					'info',
					'Message left for a reaction (Emoji trigger channel)',
					[
						'channel' => $channel_id,
						'ts'      => $ts,
					]
				);
				return new \WP_REST_Response( [ 'ok' => true ], 200 );
			}

			// 4. A message with the ignore prefix opts its whole thread out,
			// so a reply under it is skipped too. The reply event does not
			// carry the message its thread starts from, so that is read from
			// Slack.
			$thread_ts = (string) ( $event['thread_ts'] ?? '' );

			if ( '' !== $thread_ts && $thread_ts !== $ts ) {
				$thread_message = $this->api_client->get_message( $channel_id, $thread_ts, Slack_API_Client::WEBHOOK_TIMEOUT );

				// A thread that cannot be read may be an opted-out one, and
				// its reply would be published on an auto-publish channel, so
				// the reply is not ingested. When a later attempt could read
				// the thread, the error status makes Slack send the event
				// again; otherwise the reply is given up on.
				if ( is_wp_error( $thread_message ) ) {
					$is_resend_wanted = Slack_API_Client::is_temporary_error( $thread_message );
					$outcome          = $is_resend_wanted ? 'left for Slack to resend' : 'skipped';

					Slack_Monitor::log(
						'warning',
						'Thread reply ' . $outcome . ' (the first message of its thread could not be read)',
						[
							'channel' => $channel_id,
							'ts'      => $ts,
							'error'   => $thread_message->get_error_message(),
						]
					);
					error_log( 'Slack ingestion: thread reply ' . $ts . ' ' . $outcome . ', the first message of its thread could not be read (' . $thread_message->get_error_message() . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

					return $is_resend_wanted
						? new \WP_REST_Response( [ 'ok' => false ], 503 )
						: new \WP_REST_Response( [ 'ok' => true ], 200 );
				}

				if ( Slack_Ingestion_Service::has_ignore_prefix( (string) ( $thread_message['text'] ?? '' ) ) ) {
					Slack_Monitor::log(
						'info',
						'Thread reply filtered (its thread starts with the ignore prefix)',
						[
							'channel' => $channel_id,
							'ts'      => $ts,
						]
					);
					return new \WP_REST_Response( [ 'ok' => true ], 200 );
				}
			}

			// 5. Process this message inline. Its lookups use a 1s timeout, so
			// a text message is answered inside Slack's 3-second limit. One
			// with images can take longer: Slack then redelivers it, and the
			// ingestion service skips the copy.
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
					'auto_publish' => Slack_Config::PUBLISH_MODE_PUBLISH === $publish_mode,
					'started'      => $started,
				]
			);

			return new \WP_REST_Response( [ 'ok' => true ], 200 );
		}

		if ( 'reaction_added' === $event_type ) {
			return $this->handle_reaction( $event );
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
	 * Turn a reaction into an entry in a channel set to Emoji trigger: the
	 * publish emoji publishes the message, the draft emoji saves it as a
	 * draft, and the publish emoji on a message saved as a draft publishes
	 * that draft. Anything else changes nothing; a reaction never
	 * unpublishes, and removing one does nothing.
	 *
	 * Slack sends every reaction in the bot's channels, so anything that is
	 * not a trigger emoji in such a channel is dropped before Slack is
	 * called. The message is read when the reaction arrives, so edits made
	 * before it are included.
	 *
	 * @param array $event Slack `reaction_added` event.
	 * @return \WP_REST_Response Response.
	 */
	private function handle_reaction( array $event ): \WP_REST_Response {
		$started    = microtime( true );
		$item       = is_array( $event['item'] ?? null ) ? $event['item'] : [];
		$channel_id = (string) ( $item['channel'] ?? '' );
		$ts         = (string) ( $item['ts'] ?? '' );
		$status     = self::reaction_status( (string) ( $event['reaction'] ?? '' ) );
		$bot_id     = (string) ( Slack_Config::get_settings()['slack_bot_user_id'] ?? '' );
		$ok         = new \WP_REST_Response( [ 'ok' => true ], 200 );

		if ( 'message' !== ( $item['type'] ?? '' ) || '' === $channel_id || '' === $ts || null === $status ) {
			return $ok;
		}

		// The bot's own reactions confirm entries and must never make one.
		if ( '' !== $bot_id && (string) ( $event['user'] ?? '' ) === $bot_id ) {
			return $ok;
		}

		$channel_settings = Slack_Config::get_channel_settings( $channel_id );
		$term_id          = (int) ( $channel_settings['term_id'] ?? 0 );

		if ( null === $channel_settings || $term_id <= 0 || Slack_Config::PUBLISH_MODE_REACTION !== Slack_Config::resolve_publish_mode( $channel_settings ) ) {
			return $ok;
		}

		$context = [
			'channel' => $channel_id,
			'ts'      => $ts,
			'status'  => $status,
		];

		Slack_Monitor::log( 'info', 'Trigger reaction received', $context );

		$entry_id = Entry_Ingestion_Service::find_entry_id( $ts, $term_id, true );

		if ( $entry_id > 0 ) {
			if ( 'publish' === $status ) {
				$this->publish_draft_entry( $entry_id, $channel_id, $ts );
			}

			return $ok;
		}

		$message = $this->api_client->get_message_or_reply( $channel_id, $ts, Slack_API_Client::WEBHOOK_TIMEOUT );

		if ( is_wp_error( $message ) ) {
			$is_resend_wanted = Slack_API_Client::is_temporary_error( $message );

			Slack_Monitor::log(
				'warning',
				'Reacted message ' . ( $is_resend_wanted ? 'left for Slack to resend' : 'skipped' ) . ' (it could not be read)',
				$context + [ 'error' => $message->get_error_message() ]
			);

			return $is_resend_wanted ? new \WP_REST_Response( [ 'ok' => false ], 503 ) : $ok;
		}

		// The bot reacts to every message it makes an entry from. The draft
		// emoji's request may have saved one, and reacted, since the lookup
		// above; otherwise that entry was deleted for good, or made for a
		// coverage the channel was linked to before, so it isn't made again.
		if ( self::carries_bot_reaction( $message, $bot_id ) ) {
			$entry_id = Entry_Ingestion_Service::find_entry_id( $ts, $term_id, true );

			if ( $entry_id > 0 && 'publish' === $status ) {
				$this->publish_draft_entry( $entry_id, $channel_id, $ts );
			} elseif ( 0 === $entry_id ) {
				Slack_Monitor::log( 'info', 'Reacted message skipped (it had an entry before)', $context );
			}

			return $ok;
		}

		$user_id = (string) ( $message['user'] ?? '' );

		if ( '' === $user_id || Slack_Ingestion_Service::should_filter_message( $message, false ) ) {
			Slack_Monitor::log( 'info', 'Reacted message filtered (bot, Slack notice or no author)', $context + [ 'subtype' => (string) ( $message['subtype'] ?? '' ) ] );
			return $ok;
		}

		// The message that starts a thread carries its own timestamp as its
		// thread; a message event doesn't, and the entry should match one.
		if ( (string) ( $message['thread_ts'] ?? '' ) === $ts ) {
			unset( $message['thread_ts'] );
		}

		$result = self::process_ingest_payload(
			[
				'event'        => $message,
				'term_id'      => $term_id,
				'channel_id'   => $channel_id,
				'ts'           => $ts,
				'user_id'      => $user_id,
				'auto_publish' => 'publish' === $status,
				'started'      => $started,
			]
		);

		// The draft emoji's entry may still be being saved by another
		// request, or have been saved since the lookup above. The publish
		// emoji must not be lost either way: Slack sends the reaction again
		// in the first case, and the draft is published now in the second.
		if ( 'publish' === $status && Entry_Ingestion_Service::SKIP_IN_PROGRESS === $result ) {
			return new \WP_REST_Response( [ 'ok' => false ], 503 );
		}

		if ( 'publish' === $status && 0 === $result ) {
			$entry_id = Entry_Ingestion_Service::find_entry_id( $ts, $term_id );

			if ( $entry_id > 0 ) {
				$this->publish_draft_entry( $entry_id, $channel_id, $ts );
			}
		}

		return $ok;
	}

	/**
	 * Whether the bot has already reacted to a message, which it does once
	 * an entry is made from it.
	 *
	 * @param array  $message Message read from Slack.
	 * @param string $bot_id  The bot's Slack user ID, or '' when unknown.
	 * @return bool
	 */
	private static function carries_bot_reaction( array $message, string $bot_id ): bool {
		if ( '' === $bot_id ) {
			return false;
		}

		foreach ( (array) ( $message['reactions'] ?? [] ) as $reaction ) {
			if (
				is_array( $reaction )
				&& in_array( (string) ( $reaction['name'] ?? '' ), [ Slack_Config::BOT_PUBLISHED_REACTION, Slack_Config::BOT_DRAFT_REACTION ], true )
				&& in_array( $bot_id, (array) ( $reaction['users'] ?? [] ), true )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether Slack says the app lacks a scope reactions need. Unknown scopes
	 * don't count, as when Slack can't be reached.
	 *
	 * @return bool
	 */
	private function app_cannot_read_reactions(): bool {
		$scopes = $this->api_client->get_granted_scopes();

		return null !== $scopes && [] !== array_diff( Slack_Config::REACTION_SCOPES, $scopes );
	}

	/**
	 * The entry status a reaction asks for in a channel set to Emoji trigger.
	 *
	 * @param string $reaction Emoji name as Slack sends it, with any skin tone.
	 * @return string|null 'publish', 'draft', or null for any other emoji.
	 */
	private static function reaction_status( string $reaction ): ?string {
		// Slack sends a skin tone as a suffix, as in `thumbsup::skin-tone-2`.
		$name = (string) preg_replace( '/::skin-tone-\d+$/', '', $reaction );

		if ( Slack_Config::get_publish_reaction() === $name ) {
			return 'publish';
		}

		if ( Slack_Config::get_draft_reaction() === $name ) {
			return 'draft';
		}

		return null;
	}

	/**
	 * Publish a message's draft entry after someone reacts to it with the
	 * publish emoji, and swap the bot's draft reaction for the published one.
	 * Only a draft that never went out is published: an entry an editor took
	 * down, an archived one, or one dated ahead stays as it is.
	 *
	 * Publishing through wp_publish_post() doesn't save the content again. A
	 * save through wp_update_post() would run it through kses, since the
	 * webhook has no user, and strip markup an editor added to the draft. The entry
	 * keeps the date the draft was created, as when it is published from
	 * the editor.
	 *
	 * @param int    $entry_id   Entry post ID.
	 * @param string $channel_id Slack channel ID.
	 * @param string $ts         Message timestamp.
	 * @return void
	 */
	private function publish_draft_entry( int $entry_id, string $channel_id, string $ts ): void {
		global $wpdb;

		$post = get_post( $entry_id );

		if ( ! $post || 'draft' !== $post->post_status ) {
			return;
		}

		// An archived entry, or one in a coverage that has ended, stays as it is.
		if ( Archive_Mode::is_entry_locked( $entry_id ) ) {
			Slack_Monitor::log( 'info', 'Draft not published (archived)', [ 'post_id' => $entry_id ] );
			return;
		}

		// An entry an editor took down stays down, however many people add
		// the publish emoji afterwards.
		if ( metadata_exists( 'post', $entry_id, Post_Type::META_UNPUBLISHED_GMT ) ) {
			Slack_Monitor::log( 'info', 'Draft not published (it was taken down)', [ 'post_id' => $entry_id ] );
			return;
		}

		// Core schedules a post dated ahead rather than publishing it, which
		// wp_publish_post() can't do, so such a draft waits for an editor.
		if ( strtotime( $post->post_date_gmt . ' UTC' ) - time() >= MINUTE_IN_SECONDS ) {
			Slack_Monitor::log( 'info', 'Draft not published (dated ahead)', [ 'post_id' => $entry_id ] );
			return;
		}

		// A draft has no slug yet. Core gives a post one when it is saved as
		// published, but wp_publish_post() changes only the status, and the
		// entry's share and notification links need the slug.
		if ( '' === $post->post_name ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->posts,
				[ 'post_name' => wp_unique_post_slug( sanitize_title( $post->post_title, (string) $entry_id ), $entry_id, 'publish', $post->post_type, $post->post_parent ) ],
				[ 'ID' => $entry_id ]
			);
			clean_post_cache( $entry_id );
		}

		wp_publish_post( $entry_id );

		if ( 'publish' !== get_post_status( $entry_id ) ) {
			Slack_Monitor::log( 'error', 'Draft could not be published', [ 'post_id' => $entry_id ] );
			return;
		}

		// The webhook is a REST request, so the publish scheduled the
		// notification a minute out, for routes that write the opt-in after
		// the status. Ingestion wrote this one already, so the send moves to
		// the next cron run, as the entries route's does.
		Push_Notifications::settle_rest_publish( get_post( $entry_id ) );

		Slack_Monitor::log( 'success', 'Draft published from a reaction', [ 'post_id' => $entry_id ] );
		self::react( $this->api_client, $channel_id, $ts, Slack_Config::BOT_PUBLISHED_REACTION );

		$removed = $this->api_client->remove_reaction( $channel_id, $ts, Slack_Config::BOT_DRAFT_REACTION );

		if ( is_wp_error( $removed ) ) {
			Slack_Monitor::log(
				'warning',
				'Could not remove the bot\'s draft reaction',
				[
					'channel' => $channel_id,
					'ts'      => $ts,
					'error'   => $removed->get_error_message(),
				]
			);
		}
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

		$term_id   = (int) ( $settings['term_id'] ?? 0 );
		$term      = $term_id > 0 ? get_term( $term_id, Taxonomy::TAXONOMY_SLUG ) : null;
		$term_name = ( $term && ! is_wp_error( $term ) ) ? $term->name : __( '(unknown)', 'newspack-rolling-coverage' );

		return $this->ephemeral(
			sprintf(
				/* translators: 1: coverage name, 2: what happens to messages posted in the channel */
				__( 'Connected to "%1$s". %2$s', 'newspack-rolling-coverage' ),
				$term_name,
				self::describe_publish_mode( Slack_Config::resolve_publish_mode( $settings ) )
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
		// (with the coverage name and what happens to its messages), so no
		// separate chat.postMessage is needed here. A channel connected from
		// Slack starts in Draft.
		$this->link_channel_to_term( $term_id, $channel_id, $channel_name, Slack_Config::PUBLISH_MODE_DRAFT );

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
	 * A publish mode's name, as the admin shows it.
	 *
	 * @param string $publish_mode One of Slack_Config::PUBLISH_MODES.
	 * @return string
	 */
	private static function publish_mode_label( string $publish_mode ): string {
		switch ( $publish_mode ) {
			case Slack_Config::PUBLISH_MODE_PUBLISH:
				return __( 'Auto-publish', 'newspack-rolling-coverage' );
			case Slack_Config::PUBLISH_MODE_REACTION:
				return __( 'Emoji trigger', 'newspack-rolling-coverage' );
			default:
				return __( 'Draft', 'newspack-rolling-coverage' );
		}
	}

	/**
	 * What happens to a channel's messages in a publish mode, for messages
	 * the bot posts in Slack.
	 *
	 * @param string $publish_mode One of Slack_Config::PUBLISH_MODES.
	 * @return string
	 */
	private static function describe_publish_mode( string $publish_mode ): string {
		switch ( $publish_mode ) {
			case Slack_Config::PUBLISH_MODE_PUBLISH:
				return __( 'New messages are published right away.', 'newspack-rolling-coverage' );
			case Slack_Config::PUBLISH_MODE_REACTION:
				return sprintf(
					/* translators: 1: emoji name that publishes a message, 2: emoji name that saves it as a draft */
					__( 'React to a message with :%1$s: to publish it, or :%2$s: to save it as a draft.', 'newspack-rolling-coverage' ),
					Slack_Config::get_publish_reaction(),
					Slack_Config::get_draft_reaction()
				);
			default:
				return __( 'New messages are saved as drafts.', 'newspack-rolling-coverage' );
		}
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
	 * @param string $publish_mode One of Slack_Config::PUBLISH_MODES.
	 * @return void
	 */
	private function link_channel_to_term( int $term_id, string $channel_id, string $channel_name, string $publish_mode ): void {
		Slack_Config::update_channel(
			$channel_id,
			[
				'channel_name' => $channel_name,
				'term_id'      => $term_id,
				'last_sync_ts' => '',
			] + Slack_Config::publish_mode_fields( $publish_mode )
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
		// by name and what happens to its messages (mirrors /rolling-coverage-status).
		$term_name = $this->get_term_name( $term_id );
		$mode_text = self::describe_publish_mode( $publish_mode );

		$this->api_client->post_message(
			$channel_id,
			'' !== $term_name
				? sprintf(
					/* translators: 1: coverage name, 2: what happens to messages posted in the channel */
					__( '✅ This channel is now connected to the "%1$s" coverage. %2$s', 'newspack-rolling-coverage' ),
					$term_name,
					$mode_text
				)
				: sprintf(
					/* translators: %s: what happens to messages posted in the channel */
					__( '✅ This channel is now connected to rolling coverage. %s', 'newspack-rolling-coverage' ),
					$mode_text
				)
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
	 * Display name from a Slack `users.info` profile.
	 *
	 * @param array|\WP_Error $user_info Slack user profile.
	 * @return string Display name, or '' when there is none.
	 */
	private static function slack_display_name( $user_info ): string {
		if ( is_wp_error( $user_info ) || empty( $user_info ) ) {
			return '';
		}

		foreach ( [ $user_info['profile']['display_name'] ?? '', $user_info['profile']['real_name'] ?? '', $user_info['name'] ?? '' ] as $name ) {
			if ( is_string( $name ) && '' !== trim( $name ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Core ingestion pipeline. Performs author resolution (with a 1s API
	 * timeout, so a text message stays under Slack's 3s webhook limit),
	 * content processing, image import, and DB writes.
	 *
	 * @param array $payload Pre-validated payload with event + resolved IDs.
	 * @return int|\WP_Error What Entry_Ingestion_Service::ingest() returned: the entry ID, 0 or
	 *                       a skip code when no entry was saved, or an error.
	 */
	protected static function process_ingest_payload( array $payload ): int|\WP_Error {
		$started      = (float) ( $payload['started'] ?? microtime( true ) );
		$event        = $payload['event'] ?? [];
		$term_id      = (int) ( $payload['term_id'] ?? 0 );
		$channel_id   = (string) ( $payload['channel_id'] ?? '' );
		$ts           = (string) ( $payload['ts'] ?? '' );
		$user_id      = (string) ( $payload['user_id'] ?? '' );
		$auto_publish = (bool) ( $payload['auto_publish'] ?? false );

		if ( $term_id <= 0 || '' === $channel_id || '' === $ts || '' === $user_id ) {
			Slack_Monitor::log( 'warning', 'Ingestion: invalid payload' );
			error_log( 'Slack ingestion: invalid payload, skipping.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return 0;
		}

		$text      = (string) ( $event['text'] ?? '' );
		$thread_ts = (string) ( $event['thread_ts'] ?? '' );

		// Fresh instances — this runs in the webhook request without the
		// controller's injected dependencies.
		$api_client = new Slack_API_Client();

		// 1. Author resolution via outbound Slack API call. Use the short
		// webhook timeout (1s) to stay under Slack's 3s webhook limit.
		$user_info   = $api_client->get_user_info( $user_id, Slack_API_Client::WEBHOOK_TIMEOUT );
		$author_name = self::slack_display_name( $user_info );
		if ( '' === $author_name ) {
			$author_name = 'User ' . $user_id;
		}

		// Mentions use cached names freely, but only call the API while the
		// request is well inside Slack's limit, and not at all when the author
		// lookup already failed, which usually means Slack is slow or down.
		$can_call_api      = ! is_wp_error( $user_info ) && ! empty( $user_info );
		$content_processor = new Slack_Content_Processor(
			static function ( string $mentioned_user_id ) use ( $api_client, $can_call_api, $started ): string {
				$cached = $api_client->get_cached_user_info( $mentioned_user_id );

				if ( null !== $cached ) {
					return self::slack_display_name( $cached );
				}

				if ( ! $can_call_api || microtime( true ) - $started > self::MENTION_LOOKUP_BUDGET ) {
					return '';
				}

				return self::slack_display_name( $api_client->get_user_info( $mentioned_user_id, Slack_API_Client::WEBHOOK_TIMEOUT ) );
			},
			Slack_Config::get_top_heading_level()
		);

		// 2. Content processing.
		$content = $content_processor->process( $text, is_array( $event['blocks'] ?? null ) ? $event['blocks'] : [] );

		// 3. The entry's author: the WordPress user mapped to the message
		// author's Slack member ID or handle, or the bot user.
		$author    = Slack_Author_Resolver::resolve_author( $user_id, $user_info );
		$author_id = $author['user_id'];

		// 4. Build the normalized payload.
		$source_payload = new Source_Event_Payload(
			source: 'slack',
			source_ref: $ts,
			conversation_ref: $channel_id,
			author_external_id: $user_id,
			author_display_name: $author_name,
			content_html: $content,
			thread_ref: '' !== $thread_ts ? $thread_ts : null,
			external_timestamp: (string) ( is_numeric( $ts ) ? gmdate( 'c', (int) ( (float) $ts ) ) : '' ),
			raw_payload: $event,
			title: $content_processor->get_title()
		);

		// 5. Slack-specific provenance meta.
		$provenance_meta = [
			Post_Type::META_SLACK_TS          => $ts,
			Post_Type::META_SLACK_CHANNEL_ID  => $channel_id,
			Post_Type::META_SLACK_USER_ID     => $user_id,
			Post_Type::META_SLACK_THREAD_TS   => $thread_ts,
			Post_Type::META_SLACK_AUTHOR_NAME => $author_name,
		];

		// 6. Call the generic ingestion service. Uploaded images are imported
		// from inside it, once the message is known not to be a redelivery.
		$files          = is_array( $event['files'] ?? null ) ? $event['files'] : [];
		$media_importer = new Slack_Media_Importer( $api_client, $author_id );

		$post_id = Entry_Ingestion_Service::ingest(
			$source_payload,
			$term_id,
			$auto_publish,
			$author_id,
			$provenance_meta,
			static fn( callable $keep_lock ): string => $media_importer->import( $files, $keep_lock )
		);

		if ( is_wp_error( $post_id ) || $post_id <= 0 ) {
			$media_importer->discard();

			if ( is_wp_error( $post_id ) ) {
				error_log( 'Slack ingestion: entry creation failed — ' . $post_id->get_error_code() . ': ' . $post_id->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				Slack_Monitor::log(
					'error',
					'Ingestion: entry creation failed',
					[
						'error'   => $post_id->get_error_code(),
						'message' => $post_id->get_error_message(),
					] 
				);
			} elseif ( Entry_Ingestion_Service::SKIP_IN_PROGRESS === $post_id ) {
				// Expected for a message with images, which Slack redelivers while the first delivery imports them.
				Slack_Monitor::log( 'info', 'Ingestion: entry not created (another delivery of this message is still being processed)', [ 'ts' => $ts ] );
			} elseif ( Entry_Ingestion_Service::SKIP_ARCHIVED_COVERAGE === $post_id ) {
				Slack_Monitor::log( 'info', 'Ingestion: entry not created (coverage archived)', [ 'ts' => $ts ] );
				error_log( 'Slack ingestion: entry not created for ts ' . $ts . ' (coverage archived).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				Slack_Monitor::log( 'info', 'Ingestion: entry not created (duplicate, empty content, or bot user unavailable)', [ 'ts' => $ts ] );
				error_log( 'Slack ingestion: entry not created for ts ' . $ts . ' (skipped by ingest service: duplicate, empty content, or bot user unavailable). Check preceding log entries for the reason.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return $post_id;
		}

		// 7. Adapter-specific side effects: image attachment, last_sync_ts
		// update, and the bot's reaction, so the reporter sees the message
		// made it in. The reaction is set once and not kept in step with
		// later changes in WordPress.
		$media_importer->attach_to( (int) $post_id );
		Slack_Config::update_channel( $channel_id, [ 'last_sync_ts' => $ts ] );
		self::react( $api_client, $channel_id, $ts, $auto_publish ? Slack_Config::BOT_PUBLISHED_REACTION : Slack_Config::BOT_DRAFT_REACTION );

		Slack_Monitor::log(
			'success',
			'Ingestion: entry created',
			[
				'post_id' => (int) $post_id,
				'channel' => $channel_id,
				'status'  => $auto_publish ? 'publish' : 'draft',
				'author'  => $author['matched_by'],
			] 
		);

		return (int) $post_id;
	}

	/**
	 * React to a message as the bot. A failure, such as an app installed
	 * without the reactions:write scope, is logged and goes no further: the
	 * entry matters more than the reaction.
	 *
	 * @param Slack_API_Client $api_client Slack API client.
	 * @param string           $channel_id Slack channel ID.
	 * @param string           $ts         Message timestamp.
	 * @param string           $name       Emoji name.
	 * @return void
	 */
	private static function react( Slack_API_Client $api_client, string $channel_id, string $ts, string $name ): void {
		$result = $api_client->add_reaction( $channel_id, $ts, $name );

		if ( is_wp_error( $result ) ) {
			Slack_Monitor::log(
				'warning',
				'Could not react to the message',
				[
					'channel'  => $channel_id,
					'ts'       => $ts,
					'reaction' => $name,
					'error'    => $result->get_error_message(),
				]
			);
		}
	}
}
