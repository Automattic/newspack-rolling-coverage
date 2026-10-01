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
	 * Drops bot messages, edited/deleted messages, channel join/leave events
	 * and replies inside a thread.
	 *
	 * @param array $event Slack event payload.
	 * @return bool True if the message should be skipped.
	 */
	public static function should_filter_message( array $event ): bool {
		$subtype   = (string) ( $event['subtype'] ?? '' );
		$bot_id    = (string) ( $event['bot_id'] ?? '' );
		$text      = (string) ( $event['text'] ?? '' );
		$ts        = (string) ( $event['ts'] ?? '' );
		$thread_ts = (string) ( $event['thread_ts'] ?? '' );

		if ( 'bot_message' === $subtype || '' !== $bot_id ) {
			return true;
		}

		if ( in_array( $subtype, [ 'message_changed', 'message_deleted' ], true ) ) {
			return true;
		}

		if ( in_array( $subtype, [ 'channel_join', 'channel_leave', 'group_join', 'group_leave' ], true ) ) {
			return true;
		}

		// Only a channel's top-level messages become entries, so a thread can
		// hold the newsroom's discussion of one. This includes a reply that is
		// also sent to the channel. The message a thread hangs from carries
		// its own timestamp as the thread's and is kept.
		if ( '' !== $thread_ts && $thread_ts !== $ts ) {
			return true;
		}

		$ignore_prefix = Slack_Config::get_ignore_prefix();

		if ( '' !== $ignore_prefix && 0 === strpos( $text, $ignore_prefix ) ) {
			return true;
		}

		return false;
	}
}
