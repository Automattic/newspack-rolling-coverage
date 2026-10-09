<?php
/**
 * A stand-in for the WooCommerce Memberships function that says whether a
 * post is restricted, for tests that run without the plugin: it restricts
 * the posts a test names.
 *
 * @package Newspack_Rolling_Coverage
 */

define( 'NEWSPACK_ROLLING_COVERAGE_WC_MEMBERSHIPS_STUB', true );

/**
 * IDs of the posts a membership rule restricts.
 *
 * @var int[]
 */
$GLOBALS['newspack_rolling_coverage_restricted_posts'] = [];

/**
 * Whether a membership rule restricts the post.
 *
 * @param int|null $post_id Post ID.
 * @return bool
 */
function wc_memberships_is_post_content_restricted( $post_id = null ) {
	return in_array( (int) $post_id, $GLOBALS['newspack_rolling_coverage_restricted_posts'], true );
}
