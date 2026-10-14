<?php
/**
 * Slack-specific message filtering.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Slack-specific message filtering. Returns whether a Slack event should be ingested.
 */
class Slack_Ingestion_Service {

	/**
	 * Drops bot messages, edited/deleted messages, channel join/leave events,
	 * and, unless told otherwise, messages that start with the ignore prefix.
	 *
	 * @param array $event               Slack event payload, or a message read from Slack.
	 * @param bool  $honor_ignore_prefix Whether the ignore prefix skips the message. A
	 *                                   reaction in an Emoji trigger channel is the
	 *                                   opt-in itself, so it passes false.
	 * @return bool True if the message should be skipped.
	 */
	public static function should_filter_message( array $event, bool $honor_ignore_prefix = true ): bool {
		$subtype = (string) ( $event['subtype'] ?? '' );
		$bot_id  = (string) ( $event['bot_id'] ?? '' );
		$text    = (string) ( $event['text'] ?? '' );

		if ( 'bot_message' === $subtype || '' !== $bot_id ) {
			return true;
		}

		if ( in_array( $subtype, [ 'message_changed', 'message_deleted' ], true ) ) {
			return true;
		}

		if ( in_array( $subtype, [ 'channel_join', 'channel_leave', 'group_join', 'group_leave' ], true ) ) {
			return true;
		}

		return $honor_ignore_prefix && self::has_ignore_prefix( $text );
	}

	/**
	 * Whether a message opts out of ingestion by starting with the ignore prefix.
	 *
	 * @param string $text Message text.
	 * @return bool True if the text starts with the ignore prefix.
	 */
	public static function has_ignore_prefix( string $text ): bool {
		$ignore_prefix = Slack_Config::get_ignore_prefix();

		return '' !== $ignore_prefix && str_starts_with( $text, $ignore_prefix );
	}
}
