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
 * name the post in the content or the excerpt instead. Wherever the card
 * names the post, the site's Full story label (Breakout_Label) comes first.
 * Everything else in the entry, such as its date, byline, Read more and
 * Share, renders as usual, and the entry's stored content is never changed.
 */
class Breakout_Card {

	/**
	 * Object cache group for breakout posts' generated summaries.
	 */
	const CACHE_GROUP = 'newspack_rolling_coverage_breakout_card';

	/**
	 * What a card's excerpt holds between hold_excerpt() and
	 * filter_excerpt() when it has nothing to show, so core's excerpt filter
	 * sees an excerpt and builds none from the entry's content. A comment,
	 * so it shows nothing should it ever be printed.
	 */
	const NO_EXCERPT = '<!-- newspack-rolling-coverage-no-excerpt -->';

	/**
	 * Class of the Full story label, on its own line above the title, or
	 * with LABEL_PREFIX_CLASS, leading a one-line title.
	 */
	const LABEL_CLASS = 'newspack-rolling-coverage-breakout-label';

	/**
	 * Class of a Full story label that leads its title on the same line.
	 */
	const LABEL_PREFIX_CLASS = 'newspack-rolling-coverage-breakout-label--prefix';

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
	 * ID, link, title and summary, as plain text (see summary()). Null while
	 * the entry has no published breakout post.
	 *
	 * Working out the summary reads the post's content, and a gated post's
	 * free preview can render it, so callers ask for it before the entry
	 * renders, and only when the entry's template shows it.
	 *
	 * @param int  $entry_id     Entry post ID.
	 * @param bool $with_summary Whether to work out the summary; the card's
	 *                           summary is empty without it.
	 * @return array{post_id: int, url: string, title: string, summary: string}|null
	 */
	public static function for_entry( int $entry_id, bool $with_summary = true ): ?array {
		$url  = Breakout::get_published_breakout_url( $entry_id );
		$post = null !== $url ? get_post( Breakout::get_existing_breakout_id( $entry_id ) ) : null;

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		// Not get_the_title(), which starts a password-protected post's title with "Protected:".
		return [
			'post_id' => $post->ID,
			'url'     => $url,
			'title'   => self::plain_text( apply_filters( 'the_title', $post->post_title, $post->ID ) ),
			'summary' => $with_summary ? self::summary_or_entry_words( $post, $entry_id ) : '',
		];
	}

	/**
	 * The breakout post's summary (see summary()), or when the post has none
	 * to give, such as a password-protected post or a gated one with no free
	 * preview, the entry's own opening words, which readers could already
	 * see in the feed, so a card never shows a title over an empty space.
	 * A restricted entry gives none (see Entry_Bindings::public_summary()).
	 *
	 * @param WP_Post $post     Breakout post.
	 * @param int     $entry_id Entry post ID.
	 * @return string
	 */
	private static function summary_or_entry_words( WP_Post $post, int $entry_id ): string {
		$summary = self::summary( $post );
		$entry   = '' === $summary ? get_post( $entry_id ) : null;

		return $entry instanceof WP_Post ? Entry_Bindings::public_summary( $entry, self::excerpt_length() ) : $summary;
	}

	/**
	 * A breakout post's summary, as plain text: nothing for a password
	 * protected post; its hand-written excerpt when it has one; for a
	 * restricted post (see is_restricted()), its content gate's free
	 * preview; and for any other, its opening words, read from what every
	 * reader may see (see public_summary()). A post whose summary leads back
	 * to its own, such as through a feed in its free preview, gets nothing
	 * there.
	 *
	 * @param WP_Post $post Breakout post.
	 * @return string
	 */
	private static function summary( WP_Post $post ): string {
		if ( '' !== $post->post_password || isset( self::$summarizing[ $post->ID ] ) ) {
			return '';
		}

		$excerpt = self::plain_text( $post->post_excerpt );

		if ( '' !== $excerpt ) {
			return $excerpt;
		}

		self::$summarizing[ $post->ID ] = true;

		try {
			return self::is_restricted( $post ) ? self::teaser_summary( $post ) : self::public_summary( $post );
		} finally {
			unset( self::$summarizing[ $post->ID ] );
		}
	}

	/**
	 * A breakout post's opening words, cut to the `excerpt_length` filter's
	 * length and ending in an ellipsis when cut, read from its stored blocks
	 * without those Newspack hides from readers who aren't signed in
	 * (Entry_Bindings::public_summary()). Nothing is rendered, so the
	 * summary is the same whoever reads first, which matters since it's
	 * cached for everyone.
	 *
	 * Cached by the post's ID, its modified time and the length, so an edit
	 * makes a new key and a context asking for another length gets its own.
	 * Which blocks Newspack hides also depends on its gates and Reader
	 * Activation, which can change without an edit, so a summary lasts an
	 * hour.
	 *
	 * @param WP_Post $post Breakout post.
	 * @return string
	 */
	private static function public_summary( WP_Post $post ): string {
		$length  = self::excerpt_length();
		$key     = $post->ID . ':' . $post->post_modified_gmt . ':' . $length;
		$found   = false;
		$summary = wp_cache_get( $key, self::CACHE_GROUP, false, $found );

		if ( $found && is_string( $summary ) ) {
			return $summary;
		}

		$summary = Entry_Bindings::public_summary( $post, $length );
		wp_cache_set( $key, $summary, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $summary;
	}

	/**
	 * A restricted breakout post's summary without a hand-written excerpt:
	 * the free preview its Newspack content gate shows every reader, cut to
	 * the `excerpt_length` filter's length (Post_Type::get_html_excerpt()),
	 * or nothing when the gate has no free preview or something else
	 * restricts the post. The " [&hellip;]" Newspack's overlay gate ends the
	 * preview with gives way to a plain ellipsis. Newspack gives no teaser
	 * while WooCommerce Memberships is active, so a Memberships rule leaves
	 * the summary empty.
	 *
	 * @param WP_Post $post Breakout post.
	 * @return string
	 */
	private static function teaser_summary( WP_Post $post ): string {
		if ( ! class_exists( '\Newspack\Content_Gate' ) || ! method_exists( '\Newspack\Content_Gate', 'get_teaser_outside_article' ) ) {
			return '';
		}

		$teaser = \Newspack\Content_Gate::get_teaser_outside_article( $post );

		if ( null === $teaser ) {
			return '';
		}

		$teaser  = (string) preg_replace( '#\s*\[(?:&hellip;|&\#8230;|&\#x2026;|\x{2026})\](?=(?:\s|</[a-z][a-z0-9]*>)*$)#iu', '', $teaser, 1, $marked );
		$summary = Post_Type::get_html_excerpt( $teaser, self::excerpt_length(), '&hellip;' );

		if ( $marked > 0 && '' !== $summary && '&hellip;' !== substr( $summary, -8 ) ) {
			$summary .= '&hellip;';
		}

		return self::plain_text( $summary );
	}

	/**
	 * The number of words a summary keeps: the `excerpt_length` filter's
	 * value, core's translatable 55 by default.
	 *
	 * @return int
	 */
	private static function excerpt_length(): int {
		return max( 1, (int) apply_filters( 'excerpt_length', (int) _x( '55', 'excerpt_length' ) ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter and default, translated with core.
	}

	/**
	 * Whether readers who aren't signed in are kept from a breakout post's
	 * text, whoever is reading: a Newspack content gate or a WooCommerce
	 * Memberships rule covers it (see Entry_Bindings::is_withheld()), or a
	 * `newspack_post_has_restrictions` callback says it's restricted. The
	 * answer can't depend on the reader, since polls render the summary for
	 * everyone.
	 *
	 * @param WP_Post $post Breakout post.
	 * @return bool
	 */
	private static function is_restricted( WP_Post $post ): bool {
		return Entry_Bindings::is_withheld( $post ) || (bool) apply_filters( 'newspack_post_has_restrictions', false, $post->ID );
	}

	/**
	 * Render an entry as its card, with the card's filters active for that
	 * entry alone. Each filter acts only for a card's own blocks, so other
	 * entries rendering meanwhile keep their own text. The filters stay
	 * until the outermost card is done.
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
			add_filter( 'get_the_excerpt', [ __CLASS__, 'hold_excerpt' ], 9, 2 );
			add_filter( 'get_the_excerpt', [ __CLASS__, 'filter_excerpt' ], 20, 2 );
			add_filter( 'the_content', [ __CLASS__, 'stand_in_content' ], PHP_INT_MIN );
			add_filter( 'render_block_core/post-excerpt', [ __CLASS__, 'render_excerpt' ], 10, 3 );
			add_filter( 'render_block_core/post-content', [ __CLASS__, 'render_content' ], 9, 3 );
			add_filter( 'render_block_core/post-title', [ __CLASS__, 'label_title' ], 11, 3 );
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
				remove_filter( 'get_the_excerpt', [ __CLASS__, 'hold_excerpt' ], 9 );
				remove_filter( 'get_the_excerpt', [ __CLASS__, 'filter_excerpt' ], 20 );
				remove_filter( 'the_content', [ __CLASS__, 'stand_in_content' ], PHP_INT_MIN );
				remove_filter( 'render_block_core/post-excerpt', [ __CLASS__, 'render_excerpt' ], 10 );
				remove_filter( 'render_block_core/post-content', [ __CLASS__, 'render_content' ], 9 );
				remove_filter( 'render_block_core/post-title', [ __CLASS__, 'label_title' ], 11 );
			}
		}
	}

	/**
	 * The breakout post's title for a card's Post Title. Only that block's
	 * own lookup gets it, read from the block whose render callback is
	 * running, so anything else reading the entry's title during its render,
	 * such as Share's accessible name, keeps the entry's. A post without a
	 * title leaves the title as it is, such as Ticker's opening words for an
	 * untitled entry (see Entry_Bindings::untitled_fallback_title()).
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

		return null !== $card && '' !== $card['title'] ? esc_html( $card['title'] ) : $title;
	}

	/**
	 * A card's Post Title, once rendered and linked to the post (see
	 * Entry_Bindings::link_title_to_breakout()), with the Full story label
	 * inside the heading, before the link, so it stays with the title in
	 * every layout, screen readers read it before the title, and the link's
	 * name stays the title. A title carrying Entry_Bindings::ENTRY_LINK_CLASS,
	 * Ticker's one-line headline, has the label lead it on the same line;
	 * any other has it on a line of its own above. A title that renders
	 * nothing gets none.
	 *
	 * Parameters stay untyped because this runs for every post title on the
	 * site while a card renders, after other plugins' filters that may hand
	 * on unexpected types.
	 *
	 * @param string   $block_content Rendered title.
	 * @param array    $block         Parsed block.
	 * @param WP_Block $instance      Block instance.
	 * @return string
	 */
	public static function label_title( $block_content, $block, $instance ) {
		$card = self::card_for_instance( $instance );

		if ( null === $card || ! is_string( $block_content ) || ! is_array( $block ) || ! preg_match( '#^(\s*<([a-z][a-z0-9]*)\b[^>]*>)(.*</\2>\s*)$#is', $block_content, $parts ) ) {
			return $block_content;
		}

		return $parts[1] . self::label_html( Entry_Bindings::is_entry_link_title( $block ) ) . $parts[3];
	}

	/**
	 * The Full story label as HTML, followed by a space, so the text reads
	 * apart from the title after it wherever it's read without styles.
	 *
	 * @param bool $is_prefix Whether the label leads its title on the same
	 *                        line rather than sitting above it.
	 * @return string
	 */
	private static function label_html( bool $is_prefix = false ): string {
		$label = Breakout_Label::get();

		if ( $is_prefix ) {
			/* translators: %s: the Full story label, leading the title of a broken-out entry's published post on the same line. */
			$label = sprintf( __( '%s:', 'newspack-rolling-coverage' ), $label );
		}

		return sprintf(
			'<span class="%s">%s</span> ',
			esc_attr( 'use-header-font ' . self::LABEL_CLASS . ( $is_prefix ? ' ' . self::LABEL_PREFIX_CLASS : '' ) ),
			esc_html( $label )
		);
	}

	/**
	 * The Full story label as a plain paragraph, for a lite page.
	 *
	 * @return string
	 */
	public static function lite_label_html(): string {
		return sprintf( '<p class="%s">%s</p>', esc_attr( self::LABEL_CLASS ), esc_html( Breakout_Label::get() ) );
	}

	/**
	 * What a card's Post Excerpt shows: the breakout post's summary, or in
	 * a template without a Post Title, the post's title, so the card still
	 * names it. Only that block's own lookup gets it, as in filter_title().
	 * Runs after the other excerpt filters, replacing what they built.
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
	 * What a card's Post Excerpt holds before core's excerpt filter: its text
	 * (see filter_excerpt()), or NO_EXCERPT when it has none, so core never
	 * builds an excerpt from the entry's content, rendering its blocks, only
	 * for it to be thrown away.
	 *
	 * Parameters stay untyped, as in filter_excerpt().
	 *
	 * @param string           $excerpt The excerpt.
	 * @param WP_Post|int|null $post    The post.
	 * @return string
	 */
	public static function hold_excerpt( $excerpt, $post = null ) {
		$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;
		$card    = self::card_for_block( 'core/post-excerpt', $post_id );

		if ( null === $card ) {
			return $excerpt;
		}

		$text = self::excerpt_text( $card );

		return '' !== $text ? esc_html( $text ) : self::NO_EXCERPT;
	}

	/**
	 * A card's Post Excerpt once rendered: nothing when it has nothing to
	 * show, such as the summary of a password protected post under its
	 * title. For a password protected entry, core never asks the excerpt
	 * filters and says there is no excerpt, so the card's text takes that
	 * message's place, cut to the block's length as core cuts it. An excerpt
	 * showing the post's title, a one-liner like Flash's, has the Full story
	 * label lead it on the same line, outside the words the block counts.
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
	public static function render_excerpt( $block_content, $block, $instance ) {
		$card = self::card_for_instance( $instance );

		if ( null === $card || ! is_string( $block_content ) ) {
			return $block_content;
		}

		$text = self::excerpt_text( $card );

		if ( '' === $text ) {
			return '';
		}

		if ( post_password_required( (int) ( $instance->context['postId'] ?? 0 ) ) ) {
			$excerpt = esc_html( $text );
			$length  = $instance->attributes['excerptLength'] ?? null;

			if ( isset( $length ) ) {
				$excerpt = wp_trim_words( $excerpt, (int) $length );
			}

			$block_content = (string) preg_replace_callback(
				'#(<p class="wp-block-post-excerpt__excerpt">).*?((?:\s<a class="wp-block-post-excerpt__more-link".*?</a>)?\s*</p>)#s',
				static fn( $parts ) => $parts[1] . $excerpt . $parts[2],
				$block_content,
				1
			);
		}

		if ( ! self::excerpt_shows_title( $card ) ) {
			return $block_content;
		}

		return (string) preg_replace_callback(
			'#<p class="wp-block-post-excerpt__excerpt">#',
			static fn( $parts ) => $parts[0] . self::label_html( true ),
			$block_content,
			1
		);
	}

	/**
	 * What a card's Post Content renders in place of the entry's content,
	 * so the entry's own blocks never render. The block wraps it and adds
	 * its classes and styles as it would for the entry's content. Runs
	 * before every other content filter, which then get the card's content
	 * (see render_content()).
	 *
	 * Parameters stay untyped because this runs for all content on the site
	 * while a card renders, and other plugins may apply the filter to
	 * unexpected types.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function stand_in_content( $content ) {
		$card = self::card_for_block( 'core/post-content', (int) get_the_ID() );

		return null !== $card ? self::content_html( $card ) : $content;
	}

	/**
	 * A card's Post Content: the card's content (see content_html()) inside
	 * the block's own wrapper, so its classes and styles still apply, and
	 * whatever other content filters added to it, such as ads, dropped.
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

		$html = self::content_html( $card );

		if ( '' === $html ) {
			return '';
		}

		if ( is_string( $block_content ) && preg_match( '#^(\s*<([a-z][a-z0-9]*)\b[^>]*>).*(</\2>\s*)$#is', $block_content, $parts ) ) {
			return $parts[1] . $html . $parts[3];
		}

		return self::wrap( $html, $block );
	}

	/**
	 * The HTML a card's Post Content holds: the breakout post's summary as a
	 * paragraph, after, in a template without a Post Title, a paragraph
	 * holding the Full story label on a line of its own above the post's
	 * title, linked to the post. Empty when the card has neither to show.
	 *
	 * @param array $card A rendering card.
	 * @return string
	 */
	private static function content_html( array $card ): string {
		$html = '';

		if ( ! $card['has_title_block'] && '' !== $card['title'] ) {
			$html .= sprintf( '<p>%s<strong><a href="%s">%s</a></strong></p>', self::label_html(), esc_url( $card['url'] ), esc_html( $card['title'] ) );
		}

		if ( '' !== $card['summary'] ) {
			$html .= '<p>' . esc_html( $card['summary'] ) . '</p>';
		}

		return $html;
	}

	/**
	 * Wrap a card's content as core's Post Content block wraps its own, for
	 * a Post Content that rendered nothing, such as when a content filter
	 * emptied it.
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
		return self::excerpt_shows_title( $card ) ? $card['title'] : $card['summary'];
	}

	/**
	 * Whether a card's Post Excerpt shows the post's title: in a template
	 * without a Post Title, when the post has one.
	 *
	 * @param array $card A rendering card.
	 * @return bool
	 */
	private static function excerpt_shows_title( array $card ): bool {
		return ! $card['has_title_block'] && '' !== $card['title'];
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
