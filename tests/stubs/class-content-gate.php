<?php
/**
 * A stand-in for Newspack's Content_Gate, for tests that run without
 * Newspack: it withholds the posts a test names.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack;

/**
 * Stand-in for \Newspack\Content_Gate.
 */
class Content_Gate {

	/**
	 * Marks this class as the test stand-in.
	 */
	const IS_TEST_STUB = true;

	/**
	 * IDs of the posts the gate withholds outside their own page.
	 *
	 * @var int[]
	 */
	public static $withheld = [];

	/**
	 * The teaser of a withheld post, or null for a post readers may see.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string|null
	 */
	public static function get_teaser_outside_article( $post ) {
		return in_array( (int) $post->ID, self::$withheld, true ) ? 'The teaser.' : null;
	}
}
