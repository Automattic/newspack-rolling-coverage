<?php
/**
 * Entries rendered as a card for their published breakout post.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;
use WP_Block_Supports;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Once an entry's breakout post is published, feeds show the entry as a card
 * for that post, in the layout's own style: the entry's Post Title shows the
 * post's title, and its Post Content and Post Excerpt show the post's
 * summary in place of the entry's own text. Layouts without a Post Title
 * name the post in the content or the excerpt instead. Everything else in
 * the entry, such as its date, byline, Read more and Share, renders as
 * usual, and the entry's stored content is never changed.
 */
class Breakout_Card {

	/**
	 * The cards of the entries rendering now, by entry ID, each with whether
	 * the template it renders through holds a Post Title.
	 *
	 * @var array[]
	 */
	private static $rendering = [];

	/**
	 * Breakout posts whose summary is being worked out, by post ID.
	 *
	 * @var true[]
	 */
	private static $summarizing = [];

	/**
	 * The card an entry shows for its published breakout post: the post's
	 * link, its title and its summary, as plain text. The summary is the
	 * post's excerpt, hand-written or generated, and empty for a password
	 * protected post. Null while the entry has no published breakout post.
	 *
	 * The summary renders the post's content, so this is worked out before
	 * the entry renders, while the entry-only filters are not active.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return array{url: string, title: string, summary: string}|null
	 */
	public static function for_entry( int $entry_id ): ?array {
		$url  = Breakout::get_published_breakout_url( $entry_id );
		$post = null !== $url ? get_post( Breakout::get_existing_breakout_id( $entry_id ) ) : null;

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		// Not get_the_title(), which starts a password-protected post's title with "Protected:".
		return [
			'url'     => $url,
			'title'   => self::plain_text( apply_filters( 'the_title', $post->post_title, $post->ID ) ),
			'summary' => self::summary( $post ),
		];
	}

	/**
	 * A breakout post's summary: its excerpt as plain text, or nothing for a
	 * password protected post. A post whose excerpt leads back to its own
	 * summary, such as through a feed in its content, gets nothing there.
	 *
	 * @param WP_Post $post Breakout post.
	 * @return string
	 */
	private static function summary( WP_Post $post ): string {
		if ( '' !== $post->post_password || isset( self::$summarizing[ $post->ID ] ) ) {
			return '';
		}

		self::$summarizing[ $post->ID ] = true;

		try {
			return self::plain_text( get_the_excerpt( $post ) );
		} finally {
			unset( self::$summarizing[ $post->ID ] );
		}
	}

	/**
	 * Render an entry as its card, with the card's filters active for that
	 * entry alone. A feed nested in the entry renders its own entries as
	 * they are, and the filters stay until the outermost card is done.
	 *
	 * @param int      $entry_id        Entry post ID.
	 * @param array    $card            The entry's card (see for_entry()).
	 * @param bool     $has_title_block Whether the template the entry renders
	 *                                  through holds a Post Title.
	 * @param callable $render          Renders the entry and returns its HTML.
	 * @return string
	 */
	public static function render( int $entry_id, array $card, bool $has_title_block, callable $render ): string {
		$previous = self::$rendering[ $entry_id ] ?? null;

		if ( ! self::$rendering ) {
			add_filter( 'the_title', [ __CLASS__, 'filter_title' ], 20, 2 );
			add_filter( 'get_the_excerpt', [ __CLASS__, 'filter_excerpt' ], 20, 2 );
			add_filter( 'render_block_core/post-excerpt', [ __CLASS__, 'drop_empty_excerpt' ], 10, 3 );
			add_filter( 'render_block_core/post-content', [ __CLASS__, 'render_content' ], 9, 3 );
		}

		self::$rendering[ $entry_id ] = $card + [ 'has_title_block' => $has_title_block ];

		try {
			return (string) $render();
		} finally {
			if ( null === $previous ) {
				unset( self::$rendering[ $entry_id ] );
			} else {
				self::$rendering[ $entry_id ] = $previous;
			}

			if ( ! self::$rendering ) {
				remove_filter( 'the_title', [ __CLASS__, 'filter_title' ], 20 );
				remove_filter( 'get_the_excerpt', [ __CLASS__, 'filter_excerpt' ], 20 );
				remove_filter( 'render_block_core/post-excerpt', [ __CLASS__, 'drop_empty_excerpt' ], 10 );
				remove_filter( 'render_block_core/post-content', [ __CLASS__, 'render_content' ], 9 );
			}
		}
	}

	/**
	 * The breakout post's title for a card's Post Title. Only that block's
	 * own lookup gets it, read from the block whose render callback is
	 * running, so anything else reading the entry's title during its render,
	 * such as Share's accessible name, keeps the entry's.
	 *
	 * Parameters stay untyped because this runs for every title on the site
	 * while a card renders, after other plugins' filters that may hand on
	 * unexpected types.
	 *
	 * @param string $title   The title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function filter_title( $title, $post_id = 0 ) {
		$card = self::card_for_block( 'core/post-title', (int) $post_id );

		return null !== $card ? esc_html( $card['title'] ) : $title;
	}

	/**
	 * What a card's Post Excerpt shows: the breakout post's summary, or in
	 * a template without a Post Title, the post's title, so the card still
	 * names it. Only that block's own lookup gets it, as in filter_title().
	 *
	 * Parameters stay untyped because this runs for every excerpt on the
	 * site while a card renders, after other plugins' filters that may hand
	 * on unexpected types.
	 *
	 * @param string           $excerpt The excerpt.
	 * @param WP_Post|int|null $post    The post.
	 * @return string
	 */
	public static function filter_excerpt( $excerpt, $post = null ) {
		$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;
		$card    = self::card_for_block( 'core/post-excerpt', $post_id );

		return null !== $card ? esc_html( self::excerpt_text( $card ) ) : $excerpt;
	}

	/**
	 * Render nothing for a card's Post Excerpt when it has nothing to show,
	 * such as the summary of a password protected post under its title.
	 *
	 * Parameters stay untyped because this runs for every excerpt block on
	 * the site while a card renders, after other plugins' filters that may
	 * hand on unexpected types.
	 *
	 * @param string   $block_content Rendered block.
	 * @param array    $block         Parsed block.
	 * @param WP_Block $instance      Block instance.
	 * @return string
	 */
	public static function drop_empty_excerpt( $block_content, $block, $instance ) {
		$card = self::card_for_instance( $instance );

		return null !== $card && '' === self::excerpt_text( $card ) ? '' : $block_content;
	}

	/**
	 * A card's Post Content: the breakout post's summary in place of the
	 * entry's blocks, inside the block's own wrapper, so its classes and
	 * styles still apply. In a template without a Post Title, the post's
	 * title comes first, linked to the post. Runs before the archived
	 * entry's notice is added (see Rolling_Coverage_Block::render_entry()).
	 *
	 * Parameters stay untyped because this runs for every post content block
	 * on the site while a card renders, after other plugins' filters that
	 * may hand on unexpected types.
	 *
	 * @param string   $block_content Rendered block.
	 * @param array    $block         Parsed block.
	 * @param WP_Block $instance      Block instance.
	 * @return string
	 */
	public static function render_content( $block_content, $block, $instance ) {
		$card = self::card_for_instance( $instance );

		if ( null === $card || ! is_array( $block ) ) {
			return $block_content;
		}

		$html = '';

		if ( ! $card['has_title_block'] && '' !== $card['title'] ) {
			$html .= sprintf( '<p><strong><a href="%s">%s</a></strong></p>', esc_url( $card['url'] ), esc_html( $card['title'] ) );
		}

		if ( '' !== $card['summary'] ) {
			$html .= '<p>' . esc_html( $card['summary'] ) . '</p>';
		}

		if ( '' === $html ) {
			return '';
		}

		if ( is_string( $block_content ) && preg_match( '#^(\s*<([a-z][a-z0-9]*)\b[^>]*>).*(</\2>\s*)$#is', $block_content, $parts ) ) {
			return $parts[1] . $html . $parts[3];
		}

		return self::wrap( $html, $block );
	}

	/**
	 * Wrap a card's content as core's Post Content block wraps its own, for
	 * an entry whose content renders nothing, such as one with a title alone.
	 *
	 * @param string $html  The card's content.
	 * @param array  $block Parsed Post Content block.
	 * @return string
	 */
	private static function wrap( string $html, array $block ): string {
		$parent                             = WP_Block_Supports::$block_to_render;
		WP_Block_Supports::$block_to_render = $block;

		try {
			$attributes = get_block_wrapper_attributes();
		} finally {
			WP_Block_Supports::$block_to_render = $parent;
		}

		$tag_name = strtolower( (string) ( $block['attrs']['tagName'] ?? '' ) );
		$tag_name = in_array( $tag_name, [ 'main', 'section', 'article' ], true ) ? $tag_name : 'div';

		return sprintf( '<%1$s %2$s>%3$s</%1$s>', $tag_name, $attributes, $html );
	}

	/**
	 * The text a card's Post Excerpt shows (see filter_excerpt()).
	 *
	 * @param array $card A rendering card.
	 * @return string
	 */
	private static function excerpt_text( array $card ): string {
		return $card['has_title_block'] || '' === $card['title'] ? $card['summary'] : $card['title'];
	}

	/**
	 * The card of the entry a block renders for, while that entry renders.
	 *
	 * @param mixed $instance Block instance.
	 * @return array|null
	 */
	private static function card_for_instance( $instance ): ?array {
		if ( ! $instance instanceof WP_Block || ! Rolling_Coverage_Block::is_rendering_entry() ) {
			return null;
		}

		return self::$rendering[ (int) ( $instance->context['postId'] ?? 0 ) ] ?? null;
	}

	/**
	 * The card of an entry rendering now, when the block whose render
	 * callback is running is of the given type.
	 *
	 * @param string $block_name Block name.
	 * @param int    $entry_id   Entry post ID.
	 * @return array|null
	 */
	private static function card_for_block( string $block_name, int $entry_id ): ?array {
		$block = WP_Block_Supports::$block_to_render;

		if ( ! Rolling_Coverage_Block::is_rendering_entry() || ! is_array( $block ) || $block_name !== ( $block['blockName'] ?? '' ) ) {
			return null;
		}

		return self::$rendering[ $entry_id ] ?? null;
	}

	/**
	 * Text without tags, with entities decoded, to be escaped where it's
	 * printed.
	 *
	 * @param string $text Text that may hold HTML.
	 * @return string
	 */
	private static function plain_text( string $text ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
