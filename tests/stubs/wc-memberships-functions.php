<?php
/**
 * A stand-in for the WooCommerce Memberships functions that say whether a
 * post is restricted, for tests that run without the plugin: it restricts
 * the posts a test names, unless the test also marks them public.
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
 * IDs of the posts an admin marked public, which no rule restricts.
 *
 * @var int[]
 */
$GLOBALS['newspack_rolling_coverage_public_posts'] = [];

/**
 * Whether a membership rule restricts the post for the current reader: a
 * rule covers it and it isn't public, which, as in Memberships, the
 * `wc_memberships_is_post_public` filter can decide per reader.
 *
 * @param int|null $post_id Post ID.
 * @return bool
 */
function wc_memberships_is_post_content_restricted( $post_id = null ) {
	$post_id   = (int) $post_id;
	$is_public = in_array( $post_id, $GLOBALS['newspack_rolling_coverage_public_posts'], true );

	return in_array( $post_id, $GLOBALS['newspack_rolling_coverage_restricted_posts'], true ) &&
		! apply_filters( 'wc_memberships_is_post_public', $is_public, $post_id, null );
}

/**
 * The plugin's main object, answering from the rules and public posts above.
 *
 * @return object
 */
function wc_memberships() {
	static $memberships = null;

	if ( null === $memberships ) {
		$memberships = new class() {

			/**
			 * The rules, which restrict the posts the test named.
			 *
			 * @return object
			 */
			public function get_rules_instance() {
				return new class() {

					/**
					 * The content restriction rules that cover a post.
					 *
					 * @param int $post_id Post ID.
					 * @return array
					 */
					public function get_post_content_restriction_rules( $post_id ) {
						return in_array( (int) $post_id, $GLOBALS['newspack_rolling_coverage_restricted_posts'], true ) ? [ 'rule' ] : [];
					}
				};
			}

			/**
			 * The restrictions, which list the posts the test marked public.
			 *
			 * @return object
			 */
			public function get_restrictions_instance() {
				return new class() {

					/**
					 * IDs of the posts an admin marked public.
					 *
					 * @param string $which_post_type Post type, unused.
					 * @return int[]
					 */
					public function get_public_posts( $which_post_type = 'any' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Matches Memberships' signature.
						return $GLOBALS['newspack_rolling_coverage_public_posts'];
					}
				};
			}
		};
	}

	return $memberships;
}
