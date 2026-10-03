<?php
/**
 * Rolling Coverage feeds on Lite Site pages.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block_Type_Registry;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a coverage's feed live on the text-only pages the Lite Site plugin
 * serves, with the same view script as full pages.
 *
 * Lite Site renders a post's blocks through its own content filter, then
 * strips scripts, styles and every attribute outside a short allowlist, and
 * never prints enqueued assets. On those pages the feed renders its entries
 * as text, asks Lite Site to keep the attributes the view script polls with,
 * and prints that script itself. Polls from a lite page ask the entries
 * route for entries in the same text-only form.
 */
class Lite_Feed {

	/**
	 * Lite Site's content filter. A block that renders while it runs is
	 * rendering for a lite page.
	 */
	const CONTENT_FILTER = 'newspack_lite_site_post_content';

	/**
	 * The few styles the feed needs on a lite page, which prints no block
	 * styles: the status region is for screen readers only, the new-posts
	 * control floats at the top of the screen, and entries are set apart.
	 */
	const STYLES = '
		.newspack-rolling-coverage-status {
			position: absolute;
			width: 1px;
			height: 1px;
			overflow: hidden;
			clip-path: inset(50%);
			white-space: nowrap;
		}
		.newspack-rolling-coverage-new-entries {
			position: fixed;
			z-index: 1;
			top: var(--newspack-rolling-coverage-control-top, 1rem);
			left: 50%;
			margin: 0;
			transform: translateX(-50%);
		}
		.newspack-rolling-coverage-new-entries[hidden] {
			display: none;
		}
		.newspack-rolling-coverage-new-entries a {
			display: block;
			padding: 0.5em 1em;
			border-radius: 2em;
			background: #000;
			color: #fff;
			text-decoration: none;
			white-space: nowrap;
		}
		.newspack-rolling-coverage-entry + .newspack-rolling-coverage-entry {
			margin-top: 1.5rem;
			padding-top: 1.5rem;
			border-top: 1px solid #ddd;
		}
		.newspack-rolling-coverage-entry-meta {
			margin: 0 0 0.25rem;
			font-size: 0.875em;
		}
		.newspack-rolling-coverage-entry h3 {
			margin: 0 0 0.5rem;
		}
	';

	/**
	 * Whether this request renders a feed for a lite page or answers a lite
	 * entries request. Never cleared: once set, entry bodies and the page
	 * around them keep the feed's markup.
	 *
	 * @var bool
	 */
	private static $has_feed = false;

	/**
	 * Hook into Lite Site, whose hooks only run for lite pages.
	 */
	public static function init() {
		add_filter( 'newspack_lite_site_allowed_html', [ __CLASS__, 'allow_feed_markup' ] );
		add_action( 'newspack_lite_site_styles', [ __CLASS__, 'print_styles' ] );
		add_action( 'newspack_lite_site_single_after_footer', [ __CLASS__, 'print_script' ] );
	}

	/**
	 * Print the feed's styles inside Lite Site's style element, on a page
	 * that carries a feed. Lite Site renders the content before the head, so
	 * by then the feed has rendered.
	 */
	public static function print_styles(): void {
		if ( ! self::$has_feed ) {
			return;
		}

		echo self::STYLES; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS.
	}

	/**
	 * Print the view script at the end of a lite page that carries a feed.
	 *
	 * Lite pages print no enqueued scripts, so this prints the block's own
	 * view script and its dependencies. Lite Site serves the page from a
	 * cache shared by every reader, and `wp_enqueue_scripts` never runs on
	 * it, so no reader-specific settings print with the script: it polls
	 * without cookies and tracks no reader events.
	 */
	public static function print_script(): void {
		if ( ! self::$has_feed ) {
			return;
		}

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( Rolling_Coverage_Block::BLOCK_NAME );

		if ( $block_type ) {
			wp_print_scripts( $block_type->view_script_handles );
		}
	}

	/**
	 * Whether blocks are rendering for a lite page right now.
	 *
	 * @return bool
	 */
	public static function is_lite_render(): bool {
		return doing_filter( self::CONTENT_FILTER ) && self::is_available();
	}

	/**
	 * Whether Lite Site is loaded, so entries can be cleaned the way it cleans its pages.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( 'Newspack_Lite_Site\Lite_Site' );
	}

	/**
	 * Record that this request serves a lite feed.
	 */
	public static function add_feed(): void {
		self::$has_feed = true;
	}

	/**
	 * Keep the feed's markup on a lite page that carries a feed.
	 *
	 * The view script reads the feed's settings and each entry's ID and
	 * pinned state from data attributes, keeps the new-posts control hidden
	 * until entries wait behind it, and announces new entries through a
	 * status region. The data attribute wildcards keep whatever it reads
	 * later. Lite pages without a feed keep Lite Site's own list.
	 *
	 * @param array $allowed_html Elements and attributes Lite Site keeps, in wp_kses() form.
	 * @return array
	 */
	public static function allow_feed_markup( $allowed_html ) {
		if ( ! self::$has_feed || ! is_array( $allowed_html ) ) {
			return $allowed_html;
		}

		$feed_markup = [
			'div'     => [
				'data-*'      => true,
				'hidden'      => true,
				'role'        => true,
				'aria-live'   => true,
				'aria-hidden' => true,
			],
			'article' => [
				'class'  => true,
				'data-*' => true,
			],
			'time'    => [
				'datetime' => true,
			],
			'a'       => [
				'data-*' => true,
			],
		];

		foreach ( $feed_markup as $tag => $attributes ) {
			$current              = isset( $allowed_html[ $tag ] ) && is_array( $allowed_html[ $tag ] ) ? $allowed_html[ $tag ] : [];
			$allowed_html[ $tag ] = array_merge( $current, $attributes );
		}

		return $allowed_html;
	}

	/**
	 * Render an entry as text: its time, whether it is pinned, its title and
	 * its body, which Lite Site cleans like the rest of the page, or a notice
	 * in place of a protected entry's body.
	 *
	 * Built from the entry alone, not the block's layout, and carrying the
	 * attributes the view script uses to place and replace entries. It cleans
	 * the body with Lite Site, so it needs Lite Site loaded: callers check
	 * is_lite_render() or is_available() first.
	 *
	 * @param WP_Post $entry     Entry post object.
	 * @param string  $arrival   How the entry reached the page: 'initial', 'poll' or 'load_more'; empty for an edit the page already shows.
	 * @param bool    $is_capped Whether the entry shows in a capped feed, which shows every entry as unpinned, whatever its pinned state.
	 * @return string Entry HTML.
	 */
	public static function render_entry( WP_Post $entry, string $arrival, bool $is_capped = false ): string {
		$is_pinned   = ! $is_capped && Post_Type::is_pinned( $entry->ID );
		$time_format = get_option( 'time_format' );
		$meta        = sprintf(
			'<time datetime="%s">%s</time>',
			esc_attr( get_the_date( 'c', $entry ) ),
			esc_html( get_the_date( $time_format ? $time_format : 'g:i a', $entry ) )
		);

		if ( $is_pinned ) {
			$meta .= ' &middot; ' . esc_html__( 'Pinned', 'newspack-rolling-coverage' );
		}

		return sprintf(
			'<article class="%1$s-entry" data-entry-id="%2$d" data-arrival="%3$s"%4$s><p class="%1$s-entry-meta">%5$s</p>%6$s%7$s%8$s</article>',
			Rolling_Coverage_Block::MARKUP_PREFIX,
			$entry->ID,
			esc_attr( $arrival ),
			$is_pinned ? ' data-pinned' : '',
			$meta,
			Rolling_Coverage_Block::has_title( $entry ) ? '<h3>' . esc_html( get_the_title( $entry ) ) . '</h3>' : '',
			Archive_Mode::is_entry_archived( $entry->ID ) ? Rolling_Coverage_Block::render_archived_entry_notice() : '',
			self::render_body( $entry )
		);
	}

	/**
	 * Clean an entry's content as Lite Site cleans a post's, with the entry
	 * as the global post for blocks and shortcodes that read it.
	 *
	 * A protected entry gets a notice in place of its body, whatever the
	 * reader's postpass cookie: Lite Site caches the page for every reader,
	 * and lite polls and load more are public.
	 *
	 * @param WP_Post $entry Entry post object.
	 * @return string Cleaned HTML.
	 */
	private static function render_body( WP_Post $entry ): string {
		if ( '' !== $entry->post_password ) {
			return sprintf(
				'<p class="%s-entry-protected">%s</p>',
				Rolling_Coverage_Block::MARKUP_PREFIX,
				esc_html__( 'This content is password protected.', 'newspack-rolling-coverage' )
			);
		}

		global $post;

		$previous_post = $post;
		$post          = $entry; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $entry );

		try {
			return \Newspack_Lite_Site\Lite_Site::clean_content( $entry->post_content );
		} finally {
			$post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $previous_post );
		}
	}
}
