<?php
/**
 * A stand-in for Newspack's Content_Gate, for tests that run without
 * Newspack: a post carrying the `zz_gate_teaser` meta is withheld outside
 * its own article, with that meta as its teaser, as a content gate covering
 * it would withhold it.
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
	 * Meta that puts a post behind the stand-in's gate and holds its teaser.
	 */
	const TEASER_META = 'zz_gate_teaser';

	/**
	 * The teaser that stands in for a gated post's body outside its own
	 * article, or null when the post isn't gated. Like Newspack's, it leaves
	 * a password-protected post to core, and it's empty for a gate that shows
	 * no free preview.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string|null
	 */
	public static function get_teaser_outside_article( $post ) {
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) || ! metadata_exists( 'post', $post->ID, self::TEASER_META ) ) {
			return null;
		}

		return (string) get_post_meta( $post->ID, self::TEASER_META, true );
	}
}
