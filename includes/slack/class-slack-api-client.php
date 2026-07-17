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
	 * Short timeout for API calls made from within the Slack webhook handler,
	 * where the total response must stay under Slack's 3-second webhook limit.
	 */
	const WEBHOOK_TIMEOUT = 1;

	const TRANSIENT_USER_CACHE = 'rolling_coverage_slack_user_';

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
		$cache_key = self::TRANSIENT_USER_CACHE . $user_id;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$result = $this->request( 'users.info', [ 'user' => $user_id ], 'GET', $timeout );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$profile = $result['user'] ?? [];
		set_transient( $cache_key, $profile, HOUR_IN_SECONDS / 12 );

		return $profile;
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
