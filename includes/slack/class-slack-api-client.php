<?php
/**
 * Slack API client for outbound calls to https://slack.com/api/*.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Outbound Slack API calls via wp_remote_get/wp_remote_post.
 */
class Slack_API_Client {

	const API_BASE_URL = 'https://slack.com/api/';
	const TIMEOUT      = 3;

	/**
	 * Short timeout for the lookups a message needs before it becomes an
	 * entry. They run inside the Slack webhook request, which Slack redelivers
	 * after three seconds; image downloads take their own, longer timeout.
	 */
	const WEBHOOK_TIMEOUT = 1;

	const TRANSIENT_USER_CACHE = 'rolling_coverage_slack_user_';

	/**
	 * Host Slack serves uploaded files from. File downloads carry the bot
	 * token, so they are made to this host only and follow no redirect.
	 */
	const FILES_HOST = 'files.slack.com';

	/**
	 * Slack error codes for failures on Slack's side that a later attempt can
	 * get past.
	 */
	const TEMPORARY_API_ERRORS = [ 'ratelimited', 'request_timeout', 'service_unavailable', 'internal_error', 'fatal_error' ];

	/**
	 * Post a message to a Slack channel.
	 *
	 * @param string     $channel_id Slack channel ID.
	 * @param string     $text       Message text.
	 * @param array|null $blocks   Optional Slack blocks payload.
	 * @return bool True on success.
	 */
	public function post_message( string $channel_id, string $text, ?array $blocks = null ): bool {
		$body = [
			'channel' => $channel_id,
			'text'    => $text,
		];

		if ( null !== $blocks ) {
			$body['blocks'] = $blocks;
		}

		$result = $this->request( 'chat.postMessage', $body, 'POST' );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get user info from Slack, with a 5-minute transient cache.
	 *
	 * @param string $user_id Slack user ID.
	 * @param int    $timeout Optional request timeout in seconds. Defaults to self::TIMEOUT.
	 * @return array|\WP_Error User profile array or \WP_Error.
	 */
	public function get_user_info( string $user_id, int $timeout = self::TIMEOUT ): array|\WP_Error {
		$cached = $this->get_cached_user_info( $user_id );

		if ( null !== $cached ) {
			return $cached;
		}

		$result = $this->request( 'users.info', [ 'user' => $user_id ], 'GET', $timeout );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$profile = $result['user'] ?? [];
		set_transient( self::TRANSIENT_USER_CACHE . $user_id, $profile, HOUR_IN_SECONDS / 12 );

		return $profile;
	}

	/**
	 * A user's profile from the cache, without calling the Slack API.
	 *
	 * @param string $user_id Slack user ID.
	 * @return array|null User profile array, or null when it is not cached.
	 */
	public function get_cached_user_info( string $user_id ): ?array {
		$cached = get_transient( self::TRANSIENT_USER_CACHE . $user_id );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Download an image uploaded to Slack to a temporary file.
	 *
	 * Slack serves an uploaded file only to a bot token whose app has the
	 * `files:read` scope. Whatever it answers a token without the scope is not
	 * the image, so any other response, a redirect included, is a failure.
	 *
	 * @param string $url     The file's `url_private`.
	 * @param int    $timeout Request timeout in seconds.
	 * @return string|\WP_Error Path of the temporary file, or \WP_Error.
	 */
	public function download_image( string $url, int $timeout ): string|\WP_Error {
		$token = Slack_Config::get_bot_token();

		if ( '' === $token ) {
			return new \WP_Error( 'slack_not_configured', __( 'Slack is not configured.', 'newspack-rolling-coverage' ) );
		}

		// The URL comes from the message event, and the request carries the bot token.
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || self::FILES_HOST !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return new \WP_Error( 'slack_file_url_rejected', __( 'The file is not hosted by Slack.', 'newspack-rolling-coverage' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$path     = wp_tempnam( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$response = wp_safe_remote_get(
			$url,
			[
				'headers'     => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout'     => $timeout,
				'redirection' => 0,
				'stream'      => true,
				'filename'    => $path,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $path );
			return new \WP_Error( 'slack_transport_error', $response->get_error_message() );
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );

		if ( 200 !== $status || 0 !== strpos( $content_type, 'image/' ) ) {
			wp_delete_file( $path );
			return new \WP_Error(
				'slack_file_not_served',
				/* translators: %d: HTTP status code of Slack's response. */
				sprintf( __( 'Slack did not return the image (HTTP %d). The Slack app may be missing the files:read scope.', 'newspack-rolling-coverage' ), $status )
			);
		}

		return $path;
	}

	/**
	 * Get one channel message from Slack by its timestamp.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @param string $ts         Message timestamp.
	 * @param int    $timeout    Optional request timeout in seconds. Defaults to self::TIMEOUT.
	 * @return array|\WP_Error Message array, or \WP_Error when it cannot be read or no longer exists.
	 */
	public function get_message( string $channel_id, string $ts, int $timeout = self::TIMEOUT ): array|\WP_Error {
		$result = $this->request(
			'conversations.history',
			[
				'channel'   => $channel_id,
				'latest'    => $ts,
				'inclusive' => 'true',
				'limit'     => 1,
			],
			'GET',
			$timeout
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$message = $result['messages'][0] ?? null;

		// Slack answers with the newest message up to the timestamp. Once the
		// message asked for has been deleted, that is an earlier message, or
		// a placeholder in its place when it still has replies.
		if (
			! is_array( $message )
			|| (string) ( $message['ts'] ?? '' ) !== $ts
			|| 'tombstone' === ( $message['subtype'] ?? '' )
		) {
			return new \WP_Error( 'slack_api_error', 'message_not_found' );
		}

		return $message;
	}

	/**
	 * Whether a failed call may succeed when tried again: Slack gave no
	 * usable answer, or answered that it is rate limiting or failing.
	 *
	 * @param \WP_Error $error Error returned by one of this client's calls.
	 * @return bool True if a later attempt can get past the failure.
	 */
	public static function is_temporary_error( \WP_Error $error ): bool {
		if ( 'slack_transport_error' === $error->get_error_code() ) {
			return true;
		}

		return 'slack_api_error' === $error->get_error_code()
			&& in_array( $error->get_error_message(), self::TEMPORARY_API_ERRORS, true );
	}

	/**
	 * Get channel info from Slack.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @return array|\WP_Error Channel info array or \WP_Error.
	 */
	public function get_channel_info( string $channel_id ): array|\WP_Error {
		return $this->request( 'conversations.info', [ 'channel' => $channel_id ], 'GET' );
	}

	/**
	 * Resolve a channel's display name from its ID.
	 *
	 * Tries conversations.info first. If that fails or returns no name (which
	 * can happen for channels the bot scopes cannot directly inspect), falls
	 * back to paginating conversations.list and matching by ID. Returns an
	 * empty string when the name cannot be resolved.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @return string Channel name, or '' if it could not be resolved.
	 */
	public function get_channel_name( string $channel_id ): string {
		$info = $this->get_channel_info( $channel_id );

		if ( ! is_wp_error( $info ) ) {
			$name = (string) ( $info['channel']['name'] ?? '' );
			if ( '' !== $name ) {
				return $name;
			}
		}

		// Fallback: scan conversations.list for a channel matching the ID.
		$cursor = '';

		while ( true ) {
			$params = [
				'limit'            => 200,
				'types'            => 'public_channel,private_channel',
				'exclude_archived' => 'true',
			];

			if ( '' !== $cursor ) {
				$params['cursor'] = $cursor;
			}

			$result = $this->request( 'conversations.list', $params, 'GET' );

			if ( is_wp_error( $result ) ) {
				return '';
			}

			foreach ( $result['channels'] ?? [] as $channel ) {
				if ( ( $channel['id'] ?? '' ) === $channel_id ) {
					return (string) ( $channel['name'] ?? '' );
				}
			}

			$cursor = $result['response_metadata']['next_cursor'] ?? '';

			if ( '' === $cursor ) {
				break;
			}
		}

		return '';
	}

	/**
	 * Run auth.test to verify credentials.
	 *
	 * @return array|\WP_Error Auth test response or \WP_Error.
	 */
	public function auth_test(): array|\WP_Error {
		return $this->request( 'auth.test', [], 'GET' );
	}

	/**
	 * Whether the Slack app may download uploaded files.
	 *
	 * Slack lists a token's scopes in the `x-oauth-scopes` header of every API
	 * response. An app installed before images were imported lacks
	 * `files:read` until the scope is added and the app is reinstalled.
	 *
	 * @return bool|null Null when Slack did not report the scopes.
	 */
	public function can_read_files(): ?bool {
		$token = Slack_Config::get_bot_token();

		if ( '' === $token ) {
			return null;
		}

		$response = wp_safe_remote_get(
			self::API_BASE_URL . 'auth.test',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => self::TIMEOUT,
			]
		);
		$scopes   = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'x-oauth-scopes' );

		if ( '' === $scopes ) {
			return null;
		}

		return in_array( 'files:read', array_map( 'trim', explode( ',', $scopes ) ), true );
	}

	/**
	 * Open a view (modal) via views.open on slack interface.
	 *
	 * @param string $trigger_id Slack trigger ID.
	 * @param array  $view       View payload.
	 * @return array|null Response array or null on failure.
	 */
	public function open_view( string $trigger_id, array $view ): ?array {
		$result = $this->request(
			'views.open',
			[
				'trigger_id' => $trigger_id,
				'view'       => $view,
			],
			'POST'
		);

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return $result;
	}

	/**
	 * Resolve a channel name to an ID by paginating conversations.list.
	 *
	 * Strips a leading '#' from the name and matches client-side.
	 *
	 * @param string $channel_name Channel name (with or without leading '#').
	 * @return array|null ['id'=>string, 'name'=>string] or null.
	 */
	public function resolve_channel_name( string $channel_name ): ?array {
		$name   = ltrim( $channel_name, '#' );
		$cursor = '';

		while ( true ) {
			$params = [
				'limit'            => 200,
				'types'            => 'public_channel,private_channel',
				'exclude_archived' => 'true',
			];

			if ( '' !== $cursor ) {
				$params['cursor'] = $cursor;
			}

			$result = $this->request( 'conversations.list', $params, 'GET' );

			if ( is_wp_error( $result ) ) {
				return null;
			}

			$channels = $result['channels'] ?? [];

			foreach ( $channels as $channel ) {
				if ( ( $channel['name'] ?? '' ) === $name ) {
					return [
						'id'   => $channel['id'],
						'name' => $channel['name'],
					];
				}
			}

			$cursor = $result['response_metadata']['next_cursor'] ?? '';

			if ( '' === $cursor ) {
				break;
			}
		}

		return null;
	}

	/**
	 * Check whether the bot is a member of a channel.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @return array ['is_member'=>bool, 'error'=>?string].
	 */
	public function is_bot_in_channel( string $channel_id ): array {
		$result = $this->get_channel_info( $channel_id );

		if ( is_wp_error( $result ) ) {
			return [
				'is_member' => false,
				'error'     => $result->get_error_message(),
			];
		}

		$channel = $result['channel'] ?? [];

		return [
			'is_member' => (bool) ( $channel['is_member'] ?? false ),
			'error'     => null,
		];
	}

	/**
	 * Build and execute a Slack API request.
	 *
	 * @param string $endpoint Slack API endpoint (e.g. 'chat.postMessage').
	 * @param array  $args     Query/body parameters.
	 * @param string $method   HTTP method ('GET' or 'POST').
	 * @param int    $timeout  Optional request timeout in seconds. Defaults to self::TIMEOUT.
	 * @return array|\WP_Error Response body array or \WP_Error.
	 */
	private function request( string $endpoint, array $args = [], string $method = 'GET', int $timeout = self::TIMEOUT ): array|\WP_Error {
		$token = Slack_Config::get_bot_token();

		if ( '' === $token ) {
			return new \WP_Error( 'slack_not_configured', __( 'Slack is not configured.', 'newspack-rolling-coverage' ) );
		}

		$url      = self::API_BASE_URL . $endpoint;
		$headers  = [
			'Authorization' => 'Bearer ' . $token,
		];

		$response = null;

		if ( 'POST' === $method ) {
			$headers['Content-Type'] = 'application/json';

			$response = wp_safe_remote_post(
				$url,
				[
					'headers' => $headers,
					'body'    => wp_json_encode( $args ),
					'timeout' => $timeout,
				]
			);
		} else {
			$url = add_query_arg( array_map( 'rawurlencode', $args ), $url );
			
			$response = wp_safe_remote_get(
				$url,
				[
					'headers' => $headers,
					'timeout' => $timeout,
				]
			);
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'slack_transport_error', $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'slack_transport_error', __( 'Invalid JSON response from Slack.', 'newspack-rolling-coverage' ) );
		}

		if ( ! ( $data['ok'] ?? false ) ) {
			$error = (string) ( $data['error'] ?? 'unknown_error' );
			return new \WP_Error( 'slack_api_error', $error );
		}

		return $data;
	}
}
