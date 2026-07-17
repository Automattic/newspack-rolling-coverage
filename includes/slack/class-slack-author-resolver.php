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
}
