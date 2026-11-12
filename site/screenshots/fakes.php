<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- A mu-plugin that is copied under another name.
/**
 * Plugin Name: Rolling Coverage screenshot fakes
 * Description: Stands in for the services the docs screenshots show, on a site set aside for screenshots. The seed installs and removes this file.
 *
 * Fakes the WordPress AI plugin and OneSignal when they aren't active, so
 * the AI and Push Notifications screens appear without keys, and answers Key
 * Takeaways with sample text. Blocks every request WordPress's HTTP API makes
 * to another host, and all email, turns away Slack's webhooks, and gives
 * every account a fixed avatar. Does nothing on a site the seed hasn't
 * seeded.
 *
 * @package Newspack_Rolling_Coverage
 */

defined( 'ABSPATH' ) || exit;

if ( ! get_option( 'newspack_rc_screenshots_state' ) ) {
	return;
}

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WordPress\\AI\\Abstracts\\Abstract_Feature' ) ) {
			/**
			 * Stand-in for the AI plugin's feature base class, which Rolling
			 * Coverage checks for to decide that the AI plugin is active.
			 */
			abstract class Newspack_RC_Screenshots_AI_Feature {
				/**
				 * Whether the feature is enabled.
				 *
				 * @return bool
				 */
				public function is_enabled(): bool {
					return true;
				}
			}
			class_alias( 'Newspack_RC_Screenshots_AI_Feature', 'WordPress\\AI\\Abstracts\\Abstract_Feature' );
		}

		$onesignal = defined( 'ONESIGNAL_PLUGIN_URL' ) || class_exists( 'OneSignal' ) || array_filter(
			(array) get_option( 'active_plugins', [] ),
			function ( $plugin ) {
				return 0 === strpos( $plugin, 'onesignal-free-web-push-notifications/' );
			}
		);
		if ( ! $onesignal && ! function_exists( 'onesignal_create_notification' ) ) {
			/**
			 * Stand-in for OneSignal's sender, so Rolling Coverage sees OneSignal as installed.
			 */
			function onesignal_create_notification() {} // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- One file, so the seed can copy it into mu-plugins.

			add_filter(
				'pre_option_OneSignalWPSetting',
				function () {
					return [
						'app_id'           => '00000000-0000-0000-0000-000000000000',
						'app_rest_api_key' => 'screenshots',
					];
				}
			);
		}
	},
	0
);

add_filter(
	'rest_dispatch_request',
	function ( $result, $request, $route ) {
		if ( null !== $result || 'POST' !== strtoupper( $request->get_method() ) || 0 !== strcasecmp( $route, '/rolling-coverage/v1/coverages/(?P<coverage_id>\d+)/generate-key-takeaways' ) ) {
			return $result;
		}
		sleep( 2 );
		if ( get_option( 'newspack_rc_screenshots_ai_error' ) ) {
			return new WP_Error( 'ai_failed', 'The AI provider did not respond. Try again in a minute.', [ 'status' => 500 ] );
		}
		$text = "- Storm Ingrid made landfall near the harbor at 6:40 a.m., with gusts above 70 mph.\n"
			. "- About 18,000 homes are without power; crews expect restoration by Friday evening.\n"
			. "- Flood warnings remain in effect along the river until midnight.\n"
			. '- Schools in the district are closed today, and the Harbor Road bridge is shut.';
		return new WP_REST_Response( [ 'result' => $text ], 200 );
	},
	10,
	3
);

add_filter(
	'rest_pre_dispatch',
	function ( $result, $server, $request ) {
		if ( null === $result && preg_match( '#^/rolling-coverage/v1/slack/(events|commands|interactions)/?$#i', $request->get_route() ) ) {
			return new WP_Error( 'newspack_rc_screenshots_blocked', 'Slack is not connected on the screenshot site.', [ 'status' => 403 ] );
		}
		return $result;
	},
	10,
	3
);

add_filter(
	'pre_http_request',
	function ( $response, $args, $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) !== $host ) {
			return new WP_Error( 'newspack_rc_screenshots_blocked', 'Blocked on the screenshot site.' );
		}
		return $response;
	},
	10,
	3
);

add_filter( 'pre_option_ping_sites', '__return_empty_string' );
add_filter( 'pre_wp_mail', '__return_false' );

add_filter(
	'pre_get_avatar_data',
	function ( $args, $id_or_email ) {
		static $avatars = null;

		if ( ! defined( 'NEWSPACK_ROLLING_COVERAGE_URL' ) ) {
			return $args;
		}

		$user = false;
		if ( is_numeric( $id_or_email ) ) {
			$user = get_user_by( 'id', (int) $id_or_email );
		} elseif ( $id_or_email instanceof WP_User ) {
			$user = $id_or_email;
		} elseif ( $id_or_email instanceof WP_Post ) {
			$user = get_user_by( 'id', (int) $id_or_email->post_author );
		} elseif ( $id_or_email instanceof WP_Comment ) {
			$user = $id_or_email->user_id ? get_user_by( 'id', (int) $id_or_email->user_id ) : false;
		} elseif ( is_string( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
		}

		if ( $user && 'rolling_coverage_slack_bot' === $user->user_login ) {
			return $args;
		}

		if ( null === $avatars ) {
			$file    = NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'site/screenshots/content.json';
			$json    = is_readable( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			$content = json_decode( (string) $json, true );
			$avatars = array_column( $content['users'] ?? [], 'avatar', 'login' );
		}

		$avatar               = $user && isset( $avatars[ $user->user_login ] ) ? $avatars[ $user->user_login ] : 'default.svg';
		$args['url']          = NEWSPACK_ROLLING_COVERAGE_URL . 'site/screenshots/assets/avatars/' . $avatar;
		$args['found_avatar'] = true;
		return $args;
	},
	20,
	2
);
