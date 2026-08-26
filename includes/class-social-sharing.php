<?php
/**
 * Social sharing: share URL generation and redirect from entry permalink
 * back to the source page where the rolling-coverage block is placed.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Handles social sharing: share URL generation, redirect from entry
 * permalink to the source page, and deep-link entry resolution.
 */
class Social_Sharing {

	// Query var used to deep-link an entry for non-JS crawlers.
	const ENTRY_QUERY_VAR = 'rolling-coverage-entry';

	// Query var carrying the source page URL (where the block is placed).
	const SOURCE_QUERY_VAR = 'rc_source';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'query_vars', [ __CLASS__, 'register_query_var' ] );
		add_action( 'wp_head', [ __CLASS__, 'inject_redirect_script' ] );
	}

	/**
	 * Register the entry deep-link and source query vars so WordPress
	 * recognizes them.
	 *
	 * @param mixed $vars Existing public query vars (array or string).
	 * @return array Filtered query vars.
	 */
	public static function register_query_var( $vars ) {
		if ( ! is_array( $vars ) ) {
			$vars = [];
		}
		$vars[] = self::ENTRY_QUERY_VAR;
		$vars[] = self::SOURCE_QUERY_VAR;
		return $vars;
	}

	/**
	 * Inject a client-side redirect script in <head> when an entry is
	 * visited with an `rc_source` query var.
	 *
	 * Social crawlers (Facebook, Twitter, LinkedIn, etc.) do not execute
	 * JavaScript, so they see the entry's own page with correct OG tags
	 * rendered by the active SEO plugin. Human visitors are redirected
	 * to the source page with the deep-link query var so the existing JS
	 * workflow (scroll to entry or CTA modal) takes over.
	 */
	public static function inject_redirect_script(): void {
		if ( ! is_singular( Post_Type::CPT_SLUG ) ) {
			return;
		}

		$entry = get_queried_object();

		if ( ! $entry instanceof WP_Post ) {
			return;
		}

		// Mirror Core's ?p=<id> guard to prevent slug enumeration of non-published posts.
		if ( ! is_post_publicly_viewable( $entry ) ) {
			return;
		}

		$slug = $entry->post_name;

		if ( ! $slug ) {
			return;
		}

		$source_post_id = (int) get_query_var( self::SOURCE_QUERY_VAR );

		if ( ! $source_post_id ) {
			return;
		}

		$source_url = get_permalink( $source_post_id );

		if ( ! $source_url ) {
			return;
		}

		// Carry utm_* and other campaign params, dropping rc_source and the entry var we set ourselves.
		$incoming_params = array_map( 'strval', array_filter( $_GET, 'is_scalar' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		unset( $incoming_params[ self::SOURCE_QUERY_VAR ], $incoming_params[ self::ENTRY_QUERY_VAR ] );

		$redirect_url = add_query_arg(
			array_merge( [ self::ENTRY_QUERY_VAR => $slug ], $incoming_params ),
			$source_url
		) . '#' . Rolling_Coverage_Block::MARKUP_PREFIX . '-entry-' . $entry->ID;

		echo '<script>window.location.replace(' . wp_json_encode( $redirect_url ) . ');</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build the shareable URL for an entry.
	 *
	 * The URL points to the entry's own permalink with an `rc_source`
	 * query var carrying the source page's post ID so social crawlers
	 * see correct OG tags on the entry's page, while human visitors
	 * are redirected back to the source page.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return string Share URL, or empty string if no entry or source URL.
	 */
	public static function get_entry_share_url( int $entry_id ): string {
		$entry = get_post( $entry_id );

		if ( ! $entry instanceof WP_Post || Post_Type::CPT_SLUG !== $entry->post_type || 'publish' !== $entry->post_status ) {
			return '';
		}

		$entry_permalink = get_permalink( $entry );

		if ( ! $entry_permalink ) {
			return '';
		}

		$source_post_id = Rolling_Coverage_Block::get_host_post_id();

		if ( ! $source_post_id ) {
			return $entry_permalink;
		}

		return add_query_arg( self::SOURCE_QUERY_VAR, $source_post_id, $entry_permalink );
	}

	/**
	 * Resolve an entry slug to its WP_Post.
	 *
	 * @param string $slug Entry slug (post_name).
	 * @return WP_Post|null Entry post object, or null if not found.
	 */
	public static function resolve_entry_by_slug( string $slug ): ?WP_Post {
		$slug = trim( $slug );

		if ( '' === $slug ) {
			return null;
		}

		$query = new \WP_Query(
			[
				'post_type'           => Post_Type::CPT_SLUG,
				'post_status'         => 'publish',
				'name'                => $slug,
				'posts_per_page'      => 1,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			]
		);

		if ( ! empty( $query->posts ) && $query->posts[0] instanceof WP_Post ) {
			return $query->posts[0];
		}

		return null;
	}
}
