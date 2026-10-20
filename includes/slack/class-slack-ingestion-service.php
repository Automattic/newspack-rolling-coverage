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
	 * Message subtypes a person writes: a plain message, one that shares a
	 * file, and a thread reply also sent to the channel. The others are
	 * edits, deletions and the notices Slack posts in the channel, such as
	 * someone joining or setting the channel's description. Slack adds new
	 * notices over time, so a subtype not listed here is skipped. Keep in
	 * step with `SlackAdapter::shouldIngest()` in
	 * `src/admin/utils/slack-adapter.ts`.
	 *
	 * @var string[]
	 */
	const INGESTED_SUBTYPES = [ '', 'file_share', 'thread_broadcast' ];

	/**
	 * Drops bot messages, edits, deletions and Slack's notices, and, unless
	 * told otherwise, messages that start with the ignore prefix.
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

		if ( '' !== $bot_id || ! in_array( $subtype, self::INGESTED_SUBTYPES, true ) ) {
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
