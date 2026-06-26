<?php
/**
 * Slack author resolution and entry author display filtering.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Slack users to WordPress authors and filters entry author display.
 */
class Slack_Author_Resolver {

	/**
	 * Get or create the Slack bot WordPress user ID.
	 *
	 * @return int WordPress user ID.
	 */
	public static function get_slack_bot_user_id(): int {
		return Slack_Config::get_or_create_bot_user_id();
	}

	/**
	 * Filter the entry author display name for Slack-sourced entries.
	 *
	 * @param string $display_name Original display name.
	 * @param int    $entry_id     Entry post ID.
	 * @return string Filtered display name.
	 */
	public static function filter_entry_author_display( string $display_name, int $entry_id ): string {
		$source = get_post_meta( $entry_id, Post_Type::META_ENTRY_SOURCE, true );

		if ( 'slack' !== $source ) {
			return $display_name;
		}

		$name = (string) get_post_meta( $entry_id, Post_Type::META_SLACK_AUTHOR_NAME, true );

		if ( '' === $name ) {
			return 'Slack User';
		}

		return 'Slack: ' . esc_html( $name );
	}
}
