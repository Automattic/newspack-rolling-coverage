<?php
/**
 * Slack configuration storage backed by wp_options.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Static class storing all Slack configuration in wp_options (autoload=false).
 */
class Slack_Config {

	const OPTION_BOT_TOKEN      = 'rolling_coverage_slack_bot_token';
	const OPTION_SIGNING_SECRET = 'rolling_coverage_slack_signing_secret';
	const OPTION_SETTINGS       = 'rolling_coverage_slack_settings';
	const OPTION_CHANNEL_MAP    = 'rolling_coverage_slack_channel_map';
	const OPTION_BOT_USER_ID    = 'rolling_coverage_slack_bot_user_id';
	const DEFAULT_IGNORE_PREFIX = '~~';
	const MAX_PREFIX_LENGTH     = 10;
	const BOT_USER_LOGIN        = 'rolling_coverage_slack_bot';
	const BOT_USER_DISPLAY_NAME = 'Slack bot';

	// Term-meta keys live on Taxonomy as the single source of truth; reuse
	// them here for convenient access from the channel map operations below.
	const TERM_META_CHANNEL_ID   = Taxonomy::META_SLACK_CHANNEL_ID;
	const TERM_META_CHANNEL_NAME = Taxonomy::META_SLACK_CHANNEL_NAME;

	/**
	 * Default settings array.
	 *
	 * @return array<string, mixed> Default settings.
	 */
	private static function default_settings(): array {
		return [
			'connected'      => false,
			'workspace_name' => '',
			'workspace_id'   => '',
			'ignore_prefix'  => self::DEFAULT_IGNORE_PREFIX,
			'bot_user_id'    => 0,
		];
	}

	/**
	 * Get the bot token, or empty string if not set.
	 *
	 * @return string Bot token or ''.
	 */
	public static function get_bot_token(): string {
		return (string) get_option( self::OPTION_BOT_TOKEN, '' );
	}

	/**
	 * Validate and store the bot token.
	 *
	 * @param string $token Bot token (must match ^xoxb-[a-zA-Z0-9-]+$).
	 * @return bool True on success.
	 */
	public static function set_bot_token( string $token ): bool {
		if ( ! preg_match( '/^xoxb-[a-zA-Z0-9-]+$/', $token ) ) {
			return false;
		}

		return update_option( self::OPTION_BOT_TOKEN, $token, false );
	}

	/**
	 * Get a masked version of the bot token for display.
	 *
	 * @return string '...' followed by the last 4 characters, or '' if empty.
	 */
	public static function get_masked_bot_token(): string {
		$token = self::get_bot_token();

		if ( '' === $token ) {
			return '';
		}

		return '...' . substr( $token, -4 );
	}

	/**
	 * Get the signing secret, or empty string if not set.
	 *
	 * @return string Signing secret or ''.
	 */
	public static function get_signing_secret(): string {
		return (string) get_option( self::OPTION_SIGNING_SECRET, '' );
	}

	/**
	 * Validate and store the signing secret.
	 *
	 * @param string $secret Signing secret (must match ^[a-f0-9]{32}$).
	 * @return bool True on success.
	 */
	public static function set_signing_secret( string $secret ): bool {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $secret ) ) {
			return false;
		}

		return update_option( self::OPTION_SIGNING_SECRET, $secret, false );
	}

	/**
	 * Check whether both bot token and signing secret are configured.
	 *
	 * @return bool True if both are non-empty.
	 */
	public static function is_configured(): bool {
		return '' !== self::get_bot_token() && '' !== self::get_signing_secret();
	}

	/**
	 * Get merged settings (defaults overridden by stored values).
	 *
	 * @return array<string, mixed> Settings array.
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION_SETTINGS, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return wp_parse_args( $stored, self::default_settings() );
	}

	/**
	 * Merge and persist settings.
	 *
	 * @param array $settings Partial settings to merge.
	 * @return bool True on success.
	 */
	public static function update_settings( array $settings ): bool {
		$merged = wp_parse_args( $settings, self::get_settings() );
		return update_option( self::OPTION_SETTINGS, $merged, false );
	}

	/**
	 * Get the configured ignore prefix, falling back to the default.
	 *
	 * @return string Validated ignore prefix.
	 */
	public static function get_ignore_prefix(): string {
		$prefix = (string) ( self::get_settings()['ignore_prefix'] ?? self::DEFAULT_IGNORE_PREFIX );

		if ( ! preg_match( '/^[\x21-\x7E]{1,' . self::MAX_PREFIX_LENGTH . '}$/', $prefix ) ) {
			return self::DEFAULT_IGNORE_PREFIX;
		}

		return $prefix;
	}

	/**
	 * Validate and store the ignore prefix in settings.
	 *
	 * @param string $prefix Ignore prefix (must match ^[\x21-\x7E]{1,10}$).
	 * @return bool True on success.
	 */
	public static function set_ignore_prefix( string $prefix ): bool {
		if ( ! preg_match( '/^[\x21-\x7E]{1,' . self::MAX_PREFIX_LENGTH . '}$/', $prefix ) ) {
			return false;
		}

		return self::update_settings( [ 'ignore_prefix' => $prefix ] );
	}

	/**
	 * Get the channel map.
	 *
	 * @return array<string, array> Channel map keyed by channel ID.
	 */
	public static function get_channel_map(): array {
		$map = get_option( self::OPTION_CHANNEL_MAP, [] );

		if ( ! is_array( $map ) ) {
			return [];
		}

		return $map;
	}

	/**
	 * Update or insert a channel entry in the map.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @param array  $data        Partial channel data to merge.
	 * @return bool True on success.
	 */
	public static function update_channel( string $channel_id, array $data ): bool {
		$map = self::get_channel_map();
		$existing = $map[ $channel_id ] ?? [];
		$map[ $channel_id ] = wp_parse_args( $data, $existing );

		return update_option( self::OPTION_CHANNEL_MAP, $map, false );
	}

	/**
	 * Remove a channel from the map.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @return bool True on success.
	 */
	public static function remove_channel( string $channel_id ): bool {
		$map = self::get_channel_map();
		if ( ! isset( $map[ $channel_id ] ) ) {
			return true;
		}

		unset( $map[ $channel_id ] );

		return update_option( self::OPTION_CHANNEL_MAP, $map, false );
	}

	/**
	 * Find the term ID linked to a given channel.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @return int|null Term ID or null if not linked.
	 */
	public static function find_linked_term_id( string $channel_id ): ?int {
		$map = self::get_channel_map();

		if ( ! isset( $map[ $channel_id ] ) ) {
			return null;
		}

		$term_id = (int) ( $map[ $channel_id ]['term_id'] ?? 0 );

		return $term_id > 0 ? $term_id : null;
	}

	/**
	 * Get the raw channel settings entry from the map.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @return array|null Channel settings or null.
	 */
	public static function get_channel_settings( string $channel_id ): ?array {
		$map = self::get_channel_map();

		if ( ! isset( $map[ $channel_id ] ) ) {
			return null;
		}

		return $map[ $channel_id ];
	}

	/**
	 * Check whether autopublish is enabled for a channel.
	 *
	 * @param string $channel_id Slack channel ID.
	 * @return bool True if autopublish is enabled.
	 */
	public static function is_autopublish_enabled( string $channel_id ): bool {
		$settings = self::get_channel_settings( $channel_id );

		if ( null === $settings ) {
			return false;
		}

		return (bool) ( $settings['autopublish'] ?? false );
	}

	/**
	 * Get or create the Slack bot WordPress user ID.
	 *
	 * Order of resolution: cached option → existing user by login →
	 * wp_insert_user → cache → explicit 0 fallback.
	 *
	 * The 0 fallback is intentional and consistent: on the Slack webhook
	 * path no WP user is logged in, so get_current_user_id() would silently
	 * return 0 and mask the failure. Returning 0 explicitly means callers
	 * always see the same "no bot user" sentinel and must handle it.
	 *
	 * @return int User ID, or 0 if the bot user could not be created/resolved.
	 */
	public static function get_or_create_bot_user_id(): int {
		$cached = (int) get_option( self::OPTION_BOT_USER_ID, 0 );

		if ( $cached > 0 && get_user_by( 'id', $cached ) ) {
			return $cached;
		}

		$existing = get_user_by( 'login', self::BOT_USER_LOGIN );

		if ( $existing ) {
			update_option( self::OPTION_BOT_USER_ID, $existing->ID, false );
			return (int) $existing->ID;
		}

		$password = wp_generate_password( 64, true, true );
		$user_id  = wp_insert_user(
			[
				'user_login'   => self::BOT_USER_LOGIN,
				'user_email'   => 'rolling-coverage-slack-bot@localhost',
				'display_name' => self::BOT_USER_DISPLAY_NAME,
				'user_pass'    => $password,
				'role'         => 'author',
			]
		);

		if ( is_wp_error( $user_id ) || ! $user_id ) {
			// 0 means "no bot user" — callers must handle this explicitly.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Slack: could not create/resolve bot user: ' . ( is_wp_error( $user_id ) ? $user_id->get_error_message() : 'unknown' ) );
			return 0;
		}

		update_option( self::OPTION_BOT_USER_ID, (int) $user_id, false );

		return (int) $user_id;
	}

	/**
	 * Delete all Slack options, clear term meta on linked terms, and fire
	 * the rolling_coverage_slack_channel_unlinked action for each linked
	 * channel so listeners behave as if each were unlinked individually.
	 *
	 * @return bool True on success.
	 */
	public static function clear_all(): bool {
		$map = self::get_channel_map();

		// Clean up per-channel term meta and fire the unlinked action.
		foreach ( $map as $channel_id => $channel ) {
			$term_id = (int) ( $channel['term_id'] ?? 0 );

			if ( $term_id > 0 ) {
				delete_term_meta( $term_id, self::TERM_META_CHANNEL_ID );
				delete_term_meta( $term_id, self::TERM_META_CHANNEL_NAME );
			}

			/** This action is documented in includes/slack/class-slack-webhook-controller.php. */
			do_action( 'rolling_coverage_slack_channel_unlinked', (string) $channel_id );
		}

		delete_option( self::OPTION_BOT_TOKEN );
		delete_option( self::OPTION_SIGNING_SECRET );
		delete_option( self::OPTION_SETTINGS );
		delete_option( self::OPTION_CHANNEL_MAP );
		delete_option( self::OPTION_BOT_USER_ID );

		return true;
	}

	/**
	 * Hooked to delete_rolling_coverage: remove linked channel from the map.
	 *
	 * @param int $term_id Term ID being deleted.
	 * @return void
	 */
	public static function on_term_deleted( int $term_id ): void {
		$map = self::get_channel_map();

		foreach ( $map as $channel_id => $channel ) {
			if ( (int) ( $channel['term_id'] ?? 0 ) === $term_id ) {
				self::remove_channel( $channel_id );

				// Fire the unlinked action for consistency with the other unlink paths.
				/** This action is documented in includes/slack/class-slack-webhook-controller.php. */
				do_action( 'rolling_coverage_slack_channel_unlinked', (string) $channel_id );

				return;
			}
		}
	}
}
