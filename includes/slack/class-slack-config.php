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
	const BOT_USER_DISPLAY_NAME = 'Slack Bot';
	const BOT_AVATAR_PATH       = 'assets/slack-bot-avatar.svg';

	const PUBLISH_MODE_PUBLISH  = 'publish';
	const PUBLISH_MODE_DRAFT    = 'draft';
	const PUBLISH_MODE_REACTION = 'reaction';

	/**
	 * What a linked channel does with its messages: publish each one, save
	 * each one as a draft, or wait for someone to react to it with the
	 * publish or draft emoji.
	 */
	const PUBLISH_MODES = [ self::PUBLISH_MODE_PUBLISH, self::PUBLISH_MODE_DRAFT, self::PUBLISH_MODE_REACTION ];

	const DEFAULT_PUBLISH_REACTION = 'mega';
	const DEFAULT_DRAFT_REACTION   = 'memo';

	/**
	 * Reactions the bot adds to a message once it has an entry. Standard
	 * emoji, so every workspace has them.
	 */
	const BOT_PUBLISHED_REACTION = 'large_green_circle';
	const BOT_DRAFT_REACTION     = 'large_yellow_circle';

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
			'connected'        => false,
			'workspace_name'   => '',
			'workspace_id'     => '',
			'ignore_prefix'    => self::DEFAULT_IGNORE_PREFIX,
			'publish_reaction' => self::DEFAULT_PUBLISH_REACTION,
			'draft_reaction'   => self::DEFAULT_DRAFT_REACTION,
			'bot_user_id'      => 0,
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
	 * A Slack emoji name as Slack sends it in a reaction: lowercase, without
	 * the colons people type around it.
	 *
	 * @param string $name Emoji name, with or without colons.
	 * @return string The name, or '' when Slack would not accept it as one.
	 */
	public static function normalize_reaction_name( string $name ): string {
		$name = strtolower( trim( trim( $name ), ':' ) );

		return preg_match( '/^[a-z0-9_+\'-]{1,100}$/', $name ) ? $name : '';
	}

	/**
	 * The emoji that publishes a message in a channel set to Emoji trigger.
	 *
	 * @return string Emoji name.
	 */
	public static function get_publish_reaction(): string {
		return self::get_reaction_setting( 'publish_reaction', self::DEFAULT_PUBLISH_REACTION );
	}

	/**
	 * The emoji that saves a message as a draft in a channel set to Emoji trigger.
	 *
	 * @return string Emoji name.
	 */
	public static function get_draft_reaction(): string {
		return self::get_reaction_setting( 'draft_reaction', self::DEFAULT_DRAFT_REACTION );
	}

	/**
	 * A stored emoji name, or the fallback when it is missing or unusable.
	 *
	 * @param string $key      Settings key.
	 * @param string $fallback Default emoji name.
	 * @return string Emoji name.
	 */
	private static function get_reaction_setting( string $key, string $fallback ): string {
		$name = self::normalize_reaction_name( (string) ( self::get_settings()[ $key ] ?? '' ) );

		return '' === $name ? $fallback : $name;
	}

	/**
	 * Whether two emoji names can be the publish and draft emoji: both
	 * valid, and different, so one reaction never means both.
	 *
	 * @param string $publish_reaction Emoji name that publishes a message.
	 * @param string $draft_reaction   Emoji name that saves a message as a draft.
	 * @return bool
	 */
	public static function are_valid_reactions( string $publish_reaction, string $draft_reaction ): bool {
		$publish = self::normalize_reaction_name( $publish_reaction );
		$draft   = self::normalize_reaction_name( $draft_reaction );

		return '' !== $publish && '' !== $draft && $publish !== $draft;
	}

	/**
	 * Store the publish and draft emoji.
	 *
	 * @param string $publish_reaction Emoji name that publishes a message.
	 * @param string $draft_reaction   Emoji name that saves a message as a draft.
	 * @return bool False, storing nothing, when the pair is not valid.
	 */
	public static function set_reactions( string $publish_reaction, string $draft_reaction ): bool {
		if ( ! self::are_valid_reactions( $publish_reaction, $draft_reaction ) ) {
			return false;
		}

		self::update_settings(
			[
				'publish_reaction' => self::normalize_reaction_name( $publish_reaction ),
				'draft_reaction'   => self::normalize_reaction_name( $draft_reaction ),
			]
		);

		return true;
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
	 * A linked channel's publish mode. Channels linked before publish modes
	 * stored only whether they auto-publish, which reads as Auto-publish or
	 * Draft, so they need no migration.
	 *
	 * @param array $channel_settings The channel's entry in the channel map.
	 * @return string One of self::PUBLISH_MODES.
	 */
	public static function resolve_publish_mode( array $channel_settings ): string {
		$mode = (string) ( $channel_settings['publish_mode'] ?? '' );

		if ( self::is_publish_mode( $mode ) ) {
			return $mode;
		}

		return empty( $channel_settings['autopublish'] ) ? self::PUBLISH_MODE_DRAFT : self::PUBLISH_MODE_PUBLISH;
	}

	/**
	 * Whether a value is one of the publish modes.
	 *
	 * @param string $mode Value to check.
	 * @return bool
	 */
	public static function is_publish_mode( string $mode ): bool {
		return in_array( $mode, self::PUBLISH_MODES, true );
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
	 * Whether a user is the Slack bot user.
	 *
	 * @param \WP_User|false|null $user User, or what a failed lookup returns.
	 * @return bool
	 */
	public static function is_bot_user( $user ): bool {
		return $user instanceof \WP_User && self::BOT_USER_LOGIN === $user->user_login;
	}

	/**
	 * Give the bot user the bundled avatar, so Slack entries are recognisable
	 * wherever an author avatar shows. The user is matched by login rather
	 * than the stored ID, which a disconnect deletes while the user and its
	 * entries remain. Email lookups never match: WordPress drops the bot's
	 * `@localhost` address, so the user has no email. An avatar uploaded to
	 * the bot user with Simple Local Avatars wins, so publishers can replace
	 * the default; that plugin's own fallback avatar does not.
	 *
	 * @param array $args        Avatar data arguments.
	 * @param mixed $id_or_email User ID, email, WP_User, WP_Post or WP_Comment.
	 * @return array Avatar data, with the bundled URL for the bot user.
	 */
	public static function filter_bot_avatar( array $args, $id_or_email ): array {
		if ( $id_or_email instanceof \WP_User ) {
			$user = $id_or_email;
		} elseif ( $id_or_email instanceof \WP_Post ) {
			$user = get_userdata( (int) $id_or_email->post_author );
		} elseif ( $id_or_email instanceof \WP_Comment ) {
			$user = get_userdata( (int) $id_or_email->user_id );
		} elseif ( is_numeric( $id_or_email ) ) {
			$user = get_userdata( (int) $id_or_email );
		} else {
			return $args;
		}

		if ( ! self::is_bot_user( $user ) ) {
			return $args;
		}

		if ( isset( $args['url'] ) && self::has_uploaded_avatar( $user->ID, (int) $args['size'] ) ) {
			return $args;
		}

		$args['url']          = NEWSPACK_ROLLING_COVERAGE_URL . self::BOT_AVATAR_PATH;
		$args['found_avatar'] = true;

		return $args;
	}

	/**
	 * Whether Simple Local Avatars has a usable avatar uploaded for a user.
	 * Asks the plugin rather than reading its meta, which is keyed per site
	 * on some multisites and can outlive a deleted image.
	 *
	 * @param int $user_id User ID.
	 * @param int $size    Avatar size in pixels.
	 * @return bool True if the plugin resolves an uploaded avatar.
	 */
	private static function has_uploaded_avatar( int $user_id, int $size ): bool {
		global $simple_local_avatars;

		if ( ! $simple_local_avatars instanceof \Simple_Local_Avatars ) {
			return false;
		}

		return '' !== (string) $simple_local_avatars->get_simple_local_avatar_url( $user_id, $size );
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

				// Mirror the generic source term-meta cleanup from
				// unlink_channel_to_term() so a full disconnect leaves no
				// orphaned source linkage behind.
				delete_term_meta( $term_id, Taxonomy::META_SOURCE );
				delete_term_meta( $term_id, Taxonomy::META_SOURCE_REF );
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
