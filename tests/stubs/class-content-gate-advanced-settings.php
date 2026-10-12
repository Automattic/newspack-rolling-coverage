<?php
/**
 * A stand-in for Newspack's Content_Gate_Advanced_Settings, for tests that
 * run without Newspack: it answers with the feed restriction mode a test
 * sets.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack;

/**
 * Stand-in for \Newspack\Content_Gate_Advanced_Settings.
 */
class Content_Gate_Advanced_Settings {

	/**
	 * Marks this class as the test stand-in.
	 */
	const IS_TEST_STUB = true;

	/**
	 * The feed restriction mode: 'off', 'truncate' or 'exclude'.
	 *
	 * @var string
	 */
	public static $feed_mode = 'truncate';

	/**
	 * The contexts the mode was asked for, in order.
	 *
	 * @var array[]
	 */
	public static $contexts = [];

	/**
	 * The feed restriction mode.
	 *
	 * @param array $context The feed query and the post being rendered.
	 * @return string
	 */
	public static function get_feed_restriction_mode( $context = [] ): string {
		self::$contexts[] = $context;

		return self::$feed_mode;
	}
}
