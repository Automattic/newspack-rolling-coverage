<?php
/**
 * Block bindings for the per-entry buttons.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;
use WP_Block_Supports;
use WP_HTML_Tag_Processor;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the values core blocks in the Rolling Coverage template are
 * bound to: per entry, the breakout post's link and the share link; per
 * coverage, its name, the follow button's notification tag and the link to
 * the live feed.
 */
class Entry_Bindings {

	const SOURCE_NAME = 'newspack-rolling-coverage/entry';

	/**
	 * Attribute the share script looks for on the share link.
	 */
	const SHARE_ATTRIBUTE = 'data-rc-share';

	/**
	 * The share button's box: a 36px square with the icon centered. It
	 * replaces the button's own padding and size, so the circle stays 36px
	 * whatever the theme's button styles.
	 */
	const SHARE_BOX_STYLE = 'display:flex;align-items:center;justify-content:center;box-sizing:border-box;width:36px;height:36px;padding:0';

	/**
	 * Attribute the follow script looks for on the follow button.
	 */
	const FOLLOW_ATTRIBUTE = 'data-rc-follow';

	/**
	 * Attribute the view script looks for on the link to the live feed.
	 */
	const LATEST_ATTRIBUTE = 'data-rc-latest';

	/**
	 * Block context the follow button's binding reads its coverage from, set
	 * by the Rolling Coverage block and by the Follow Coverage block.
	 */
	const COVERAGE_ID_CONTEXT     = 'newspack-rolling-coverage/coverageId';
	const COVERAGE_STATUS_CONTEXT = 'newspack-rolling-coverage/coverageStatus';

	/**
	 * Class of the paragraph that labels a pinned entry.
	 */
	const PINNED_LABEL_CLASS = 'newspack-rolling-coverage-pinned-label';

	/**
	 * Class of the paragraph that links to the entry's breakout post.
	 */
	const READ_MORE_CLASS = 'newspack-rolling-coverage-read-more';

	/**
	 * Class of the paragraph that links to the entry's share URL.
	 */
	const SHARE_CLASS = 'newspack-rolling-coverage-share';

	/**
	 * Class of the paragraph that links to the coverage page's full feed.
	 */
	const ALL_UPDATES_CLASS = 'newspack-rolling-coverage-all-updates';

	/**
	 * Class of a title that links to its entry on the coverage page when the
	 * entry has no published breakout post, and shows the entry's opening
	 * words when it has no title (see untitled_fallback_title()).
	 */
	const ENTRY_LINK_CLASS = 'newspack-rolling-coverage-entry-link';

	/**
	 * How many words of an untitled entry stand in for its title.
	 */
	const UNTITLED_FALLBACK_WORDS = 15;

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_source' ] );
		add_filter( 'render_block_core/button', [ __CLASS__, 'filter_button' ], 10, 3 );
		add_filter( 'render_block_core/paragraph', [ __CLASS__, 'filter_pinned_label' ], 10, 2 );
		add_filter( 'render_block_core/paragraph', [ __CLASS__, 'link_read_more' ], 10, 2 );
		add_filter( 'render_block_core/paragraph', [ __CLASS__, 'link_share' ], 10, 2 );
		add_filter( 'render_block_core/paragraph', [ __CLASS__, 'link_all_updates' ], 10, 2 );
		add_filter( 'render_block_core/group', [ __CLASS__, 'filter_pinned_group' ], 10, 2 );
		add_filter( 'render_block_core/post-title', [ __CLASS__, 'link_title_to_breakout' ], 10, 3 );
		add_filter( 'the_title', [ __CLASS__, 'untitled_fallback_title' ], 10, 2 );
	}

	/**
	 * Register the block bindings source.
	 */
	public static function register_source(): void {
		register_block_bindings_source(
			self::SOURCE_NAME,
			[
				'label'              => __( 'Rolling Coverage Entry', 'newspack-rolling-coverage' ),
				'get_value_callback' => [ __CLASS__, 'get_value' ],
				'uses_context'       => [ 'postId', 'postType', self::COVERAGE_ID_CONTEXT, self::COVERAGE_STATUS_CONTEXT ],
			]
		);
	}

	/**
	 * Resolve a bound value for the entry in the block's context.
	 *
	 * @param array    $source_args Binding args; `key` names the value.
	 * @param WP_Block $block       The bound block.
	 * @return string|null The value, or null when the entry has none.
	 */
	public static function get_value( array $source_args, WP_Block $block ): ?string {
		if ( 'followTag' === ( $source_args['key'] ?? '' ) ) {
			$coverage_id = (int) ( $block->context[ self::COVERAGE_ID_CONTEXT ] ?? 0 );
			$status      = (string) ( $block->context[ self::COVERAGE_STATUS_CONTEXT ] ?? 'active' );

			return $coverage_id && Coverage_Follow_Block::should_render( $status ) ? Push_Notifications::follow_tag( $coverage_id ) : null;
		}

		if ( 'coverageName' === ( $source_args['key'] ?? '' ) ) {
			$coverage = get_term( (int) ( $block->context[ self::COVERAGE_ID_CONTEXT ] ?? 0 ), Taxonomy::TAXONOMY_SLUG );

			return $coverage instanceof WP_Term ? esc_html( $coverage->name ) : null;
		}

		if ( 'latestUrl' === ( $source_args['key'] ?? '' ) ) {
			return Rolling_Coverage_Block::is_rendering_entry() ? null : Rolling_Coverage_Block::live_feed_url();
		}

		$entry_id = (int) ( $block->context['postId'] ?? 0 );

		if ( ! $entry_id || Post_Type::CPT_SLUG !== get_post_type( $entry_id ) ) {
			return null;
		}

		switch ( $source_args['key'] ?? '' ) {
			case 'breakoutUrl':
				return Breakout::get_published_breakout_url( $entry_id );

			case 'shareUrl':
				return Social_Sharing::get_entry_share_url( $entry_id ) ?: null; // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		}

		return null;
	}

	/**
	 * Link an entry's title to its published breakout post, or for a title
	 * carrying ENTRY_LINK_CLASS, to the entry on the coverage page when there
	 * is none (see entry_link_url()). A title already set to link to the entry
	 * points there instead; a title whose text holds a link of its own is
	 * left alone, as links can't nest.
	 *
	 * Parameters stay untyped because this runs for every post title on the
	 * site, after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string   $block_content Rendered title.
	 * @param array    $block         Parsed block.
	 * @param WP_Block $instance      Block instance.
	 * @return string
	 */
	public static function link_title_to_breakout( $block_content, $block, $instance ) {
		if ( ! is_string( $block_content ) || '' === $block_content || ! Rolling_Coverage_Block::is_rendering_entry() || ! $instance instanceof WP_Block ) {
			return $block_content;
		}

		$entry_id = (int) ( $instance->context['postId'] ?? 0 );
		$is_entry = $entry_id && Post_Type::CPT_SLUG === get_post_type( $entry_id );
		$url      = $is_entry ? Breakout::get_published_breakout_url( $entry_id ) : null;

		if ( ! $url && $is_entry && is_array( $block ) && self::is_entry_link_title( $block ) ) {
			$url = self::entry_link_url( $entry_id );
		}

		if ( ! $url ) {
			return $block_content;
		}

		$title = new WP_HTML_Tag_Processor( $block_content );

		if ( ! empty( $block['attrs']['isLink'] ) ) {
			if ( $title->next_tag() && $title->next_tag( 'a' ) ) {
				$title->set_attribute( 'href', $url );
			}

			return $title->get_updated_html();
		}

		if ( $title->next_tag( 'a' ) || ! preg_match( '#^(\s*<([a-z][a-z0-9]*)\b[^>]*>)(.*)(</\2>\s*)$#is', $block_content, $parts ) ) {
			return $block_content;
		}

		return $parts[1] . '<a href="' . esc_url( $url ) . '">' . $parts[3] . '</a>' . $parts[4];
	}

	/**
	 * An untitled entry's opening words as its title, for a Post Title
	 * carrying ENTRY_LINK_CLASS inside an entry: its excerpt when it has one,
	 * else the start of its text. The title then renders and links as one of
	 * the entry's own would (see link_title_to_breakout()). A
	 * password-protected entry keeps its empty title.
	 *
	 * Only that block's own lookup gets the words: has_title() in
	 * Rolling_Coverage_Block::render_entry() and every other caller during the
	 * entry's render keep seeing the empty title, which the entry's shaping,
	 * such as Rolling_Coverage_Block::with_centered_title_rows(), relies on.
	 * That's why the check reads the block whose render callback is running
	 * (WP_Block_Supports::$block_to_render) rather than anything wider.
	 *
	 * Parameters stay untyped because this runs for every title on the site,
	 * after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string $title   The title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function untitled_fallback_title( $title, $post_id = 0 ) {
		$block = WP_Block_Supports::$block_to_render;

		if ( ! Rolling_Coverage_Block::is_rendering_entry() || ! is_array( $block ) || ! self::is_entry_link_title( $block ) || ! is_string( $title ) || '' !== trim( wp_strip_all_tags( $title ) ) ) {
			return $title;
		}

		$entry = get_post( (int) $post_id );

		if ( ! $entry || Post_Type::CPT_SLUG !== $entry->post_type || post_password_required( $entry ) ) {
			return $title;
		}

		$excerpt = trim( $entry->post_excerpt );
		$words   = '' !== $excerpt
			? html_entity_decode( wp_trim_words( $excerpt, self::UNTITLED_FALLBACK_WORDS, '…' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
			: Post_Type::get_entry_summary( $entry, self::UNTITLED_FALLBACK_WORDS );

		return esc_html( $words );
	}

	/**
	 * Whether a parsed block is a title that links to its entry when the entry
	 * has no published breakout post, and shows the entry's opening words
	 * when it has no title.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return bool
	 */
	public static function is_entry_link_title( array $parsed_block ): bool {
		$class_name = $parsed_block['attrs']['className'] ?? '';

		return 'core/post-title' === ( $parsed_block['blockName'] ?? '' ) &&
			is_string( $class_name ) &&
			in_array( self::ENTRY_LINK_CLASS, explode( ' ', $class_name ), true );
	}

	/**
	 * The link to an entry on the page showing its coverage, opened at the
	 * entry: the coverage's canonical URL, as notifications and the entry's
	 * own permalink use, or without one, the page found to show the coverage
	 * (see Taxonomy::get_coverage_page_url()). The entry's share link stands
	 * in when the coverage has no page; it isn't used first because it leads
	 * back to the page the feed is on, which for a capped feed on a section
	 * front isn't the coverage page.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return string The URL, or an empty string when the entry can't be linked.
	 */
	private static function entry_link_url( int $entry_id ): string {
		$entry = get_post( $entry_id );

		if ( ! $entry || 'publish' !== $entry->post_status ) {
			return '';
		}

		$coverages = get_the_terms( $entry_id, Taxonomy::TAXONOMY_SLUG );

		foreach ( is_array( $coverages ) ? $coverages : [] as $coverage ) {
			$page_url = Taxonomy::get_coverage_page_url( (int) $coverage->term_id );

			if ( '' !== $page_url ) {
				return Social_Sharing::get_entry_deep_link( $entry, $page_url );
			}
		}

		return Social_Sharing::get_entry_share_url( $entry_id );
	}

	/**
	 * Render nothing for a button whose link is bound to a value the entry
	 * doesn't have, e.g. "Read more" before the breakout post is published,
	 * or "Jump to Latest" inside an entry, and hand the share, follow and
	 * "Jump to Latest" buttons what their scripts need.
	 *
	 * Parameters stay untyped because this runs for every core button on the
	 * site, after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string   $block_content Rendered block.
	 * @param array    $block         Parsed block.
	 * @param WP_Block $instance      Block instance, with bound values applied.
	 * @return string
	 */
	public static function filter_button( $block_content, $block, $instance ) {
		$binding = $block['attrs']['metadata']['bindings']['url'] ?? null;

		if ( ! is_string( $block_content ) || ! $instance instanceof WP_Block || ! is_array( $binding ) || self::SOURCE_NAME !== ( $binding['source'] ?? '' ) ) {
			return $block_content;
		}

		if ( empty( $instance->attributes['url'] ) ) {
			return '';
		}

		$key    = $binding['args']['key'] ?? '';
		$button = new WP_HTML_Tag_Processor( $block_content );

		if ( 'shareUrl' === $key && $button->next_tag( 'a' ) ) {
			$button->set_attribute( self::SHARE_ATTRIBUTE, '' );
			$button->set_attribute( 'role', 'button' );
		}

		if ( 'shareUrl' === $key ) {
			return self::show_share_icon( $button->get_updated_html(), (int) ( $instance->context['postId'] ?? 0 ) );
		}

		if ( 'followTag' === $key && $button->next_tag( 'button' ) ) {
			$button->set_attribute( self::FOLLOW_ATTRIBUTE, '' );
			$button->set_attribute( 'data-tag', $instance->attributes['url'] );
			$button->set_attribute( 'data-label-following', __( 'Following', 'newspack-rolling-coverage' ) );
			$button->set_attribute( 'data-blocked-message', __( 'Notifications are blocked in your browser. Allow them in your browser\'s site settings, then try again.', 'newspack-rolling-coverage' ) );
			$button->set_attribute( 'data-error-message', __( 'Something went wrong. Please try again.', 'newspack-rolling-coverage' ) );
			$button->set_attribute( 'aria-pressed', 'false' );
		}

		if ( 'latestUrl' === $key && $button->next_tag( 'a' ) ) {
			$button->set_attribute( self::LATEST_ATTRIBUTE, '' );
		}

		return $button->get_updated_html();
	}

	/**
	 * Show the share button as the link icon alone. Its accessible name is
	 * the button's text and the entry it shares, so each entry's button is
	 * told apart, e.g. "Share: Polls close at 8pm".
	 *
	 * @param string $block_content Rendered share button.
	 * @param int    $entry_id      Entry the button shares.
	 * @return string
	 */
	private static function show_share_icon( string $block_content, int $entry_id ): string {
		$icon = wp_get_icon( Block_Icons::LINK );

		if ( ! $icon || ! preg_match( '#(<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)(.*?)(</a>)#s', $block_content, $link ) ) {
			return $block_content;
		}

		$label = self::plain_text( $link[2] );
		$open  = new WP_HTML_Tag_Processor( $link[1] );
		$svg   = new WP_HTML_Tag_Processor( $icon );

		if ( ! $open->next_tag( 'a' ) || ! $svg->next_tag( 'svg' ) ) {
			return $block_content;
		}

		$open->set_attribute( 'style', trim( (string) $open->get_attribute( 'style' ) . ';' . self::SHARE_BOX_STYLE, ';' ) );
		$open->set_attribute( 'aria-label', self::share_name( $label, $entry_id ) );
		$svg->set_attribute( 'fill', 'currentColor' );

		return str_replace( $link[0], $open->get_updated_html() . trim( $svg->get_updated_html() ) . $link[3], $block_content );
	}

	/**
	 * A share link's accessible name: its text and the entry it shares, so
	 * each entry's link is told apart, e.g. "Share: Polls close at 8pm".
	 *
	 * @param string $label    The link's text, as plain text.
	 * @param int    $entry_id Entry the link shares.
	 * @return string
	 */
	private static function share_name( string $label, int $entry_id ): string {
		$label = $label ? $label : __( 'Share', 'newspack-rolling-coverage' );
		$entry = self::entry_name( $entry_id );

		return $entry
			/* translators: 1: share button text, e.g. "Share", 2: entry title or its first words. */
			? sprintf( __( '%1$s: %2$s', 'newspack-rolling-coverage' ), $label, $entry )
			: $label;
	}

	/**
	 * An entry's title, or the first words of its content when it has none.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return string
	 */
	private static function entry_name( int $entry_id ): string {
		$entry = $entry_id ? get_post( $entry_id ) : null;

		if ( ! $entry || Post_Type::CPT_SLUG !== $entry->post_type ) {
			return '';
		}

		$title = self::plain_text( get_the_title( $entry ) );

		return '' !== $title ? $title : Post_Type::get_entry_summary( $entry );
	}

	/**
	 * Text as a screen reader should hear it: no tags, and entities such as
	 * the curly apostrophe core puts in titles decoded, since setting an
	 * attribute encodes the text again.
	 *
	 * @param string $text Text that may hold HTML.
	 * @return string
	 */
	private static function plain_text( string $text ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Render nothing for the pinned label on an entry that isn't pinned.
	 *
	 * Parameters stay untyped because this runs for every paragraph on the
	 * site, after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string $block_content Rendered block.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function filter_pinned_label( $block_content, $block ) {
		if ( ! Rolling_Coverage_Block::is_rendering_entry() || ! is_array( $block ) || ! self::is_pinned_label( $block ) ) {
			return $block_content;
		}

		return self::is_current_entry_pinned() ? $block_content : '';
	}

	/**
	 * Link a "Read more" paragraph to the entry's published breakout post, or
	 * render nothing when there is none.
	 *
	 * Parameters stay untyped because this runs for every paragraph on the
	 * site, after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string $block_content Rendered block.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function link_read_more( $block_content, $block ) {
		if ( ! Rolling_Coverage_Block::is_rendering_entry() || ! is_array( $block ) || ! is_string( $block_content ) || ! self::is_read_more_paragraph( $block ) ) {
			return $block_content;
		}

		$entry_id = (int) get_the_ID();
		$url      = $entry_id && Post_Type::CPT_SLUG === get_post_type( $entry_id ) ? Breakout::get_published_breakout_url( $entry_id ) : null;

		if ( ! $url ) {
			return '';
		}

		return self::link_paragraph( $block_content, [ 'href' => $url ] );
	}

	/**
	 * Link a "Share" paragraph to the entry's share URL, as a link the share
	 * script picks up, or render nothing when the entry can't be shared.
	 *
	 * Parameters stay untyped because this runs for every paragraph on the
	 * site, after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string $block_content Rendered block.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function link_share( $block_content, $block ) {
		if ( ! Rolling_Coverage_Block::is_rendering_entry() || ! is_array( $block ) || ! is_string( $block_content ) || ! self::is_share_paragraph( $block ) ) {
			return $block_content;
		}

		$entry_id = (int) get_the_ID();
		$url      = $entry_id && Post_Type::CPT_SLUG === get_post_type( $entry_id ) ? Social_Sharing::get_entry_share_url( $entry_id ) : '';

		if ( ! $url ) {
			return '';
		}

		return self::link_paragraph(
			$block_content,
			[
				'href'                => $url,
				self::SHARE_ATTRIBUTE => '',
				'role'                => 'button',
				'aria-label'          => self::share_name( self::plain_text( $block_content ), $entry_id ),
			]
		);
	}

	/**
	 * Link a "See all updates" paragraph to the coverage page, or render
	 * nothing when there is no page to link to. Entries render outside the
	 * coverage-level blocks, so a paragraph inside one never has a URL.
	 *
	 * Parameters stay untyped because this runs for every paragraph on the
	 * site, after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string $block_content Rendered block.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function link_all_updates( $block_content, $block ) {
		if ( ! is_array( $block ) || ! is_string( $block_content ) || ! self::is_all_updates_paragraph( $block ) ) {
			return $block_content;
		}

		$url = Rolling_Coverage_Block::get_all_updates_url();

		if ( '' === $url ) {
			return '';
		}

		return self::link_paragraph( $block_content, [ 'href' => $url ] );
	}

	/**
	 * Link a rendered paragraph's content. A placeholder link (`href="#"`),
	 * which the layouts ship so the editor shows a link, takes the
	 * attributes; any other link inside is the author's own and is left
	 * alone, as links can't nest; otherwise the content is wrapped in a new
	 * link.
	 *
	 * @param string $block_content Rendered paragraph.
	 * @param array  $attributes    The link's attributes, keyed by name.
	 * @return string
	 */
	private static function link_paragraph( string $block_content, array $attributes ): string {
		if ( ! preg_match( '/<p(?=[\s>])(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/i', $block_content, $tag, PREG_OFFSET_CAPTURE ) ) {
			return $block_content;
		}

		$inner_start = $tag[0][1] + strlen( $tag[0][0] );
		$close       = stripos( $block_content, '</p>', $inner_start );

		if ( false === $close ) {
			return $block_content;
		}

		$inner = substr( $block_content, $inner_start, $close - $inner_start );

		if ( preg_match( '/<a[\s>]/i', $inner ) ) {
			$links = new WP_HTML_Tag_Processor( $inner );

			while ( $links->next_tag( 'a' ) ) {
				if ( '#' === $links->get_attribute( 'href' ) ) {
					foreach ( $attributes as $name => $value ) {
						$links->set_attribute( $name, $value );
					}

					return substr( $block_content, 0, $inner_start ) . $links->get_updated_html() . substr( $block_content, $close );
				}
			}

			return $block_content;
		}

		$open = new WP_HTML_Tag_Processor( '<a>' );
		$open->next_tag();

		foreach ( $attributes as $name => $value ) {
			$open->set_attribute( $name, $value );
		}

		return substr( $block_content, 0, $inner_start )
			. $open->get_updated_html() . $inner . '</a>'
			. substr( $block_content, $close );
	}

	/**
	 * Whether a parsed block is the paragraph linking to the entry's breakout
	 * post.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return bool
	 */
	public static function is_read_more_paragraph( array $parsed_block ): bool {
		$class_name = $parsed_block['attrs']['className'] ?? '';

		return 'core/paragraph' === ( $parsed_block['blockName'] ?? '' ) &&
			is_string( $class_name ) &&
			in_array( self::READ_MORE_CLASS, explode( ' ', $class_name ), true );
	}

	/**
	 * Whether a parsed block is the paragraph linking to the entry's share
	 * URL.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return bool
	 */
	public static function is_share_paragraph( array $parsed_block ): bool {
		$class_name = $parsed_block['attrs']['className'] ?? '';

		return 'core/paragraph' === ( $parsed_block['blockName'] ?? '' ) &&
			is_string( $class_name ) &&
			in_array( self::SHARE_CLASS, explode( ' ', $class_name ), true );
	}

	/**
	 * Render nothing for the pinned row, a group holding only the pinned label
	 * and icons, on an entry that isn't pinned. A group holding anything else
	 * keeps rendering; only the label inside it goes.
	 *
	 * Parameters stay untyped because this runs for every group on the site,
	 * after other plugins' filters that may hand on unexpected types.
	 *
	 * @param string $block_content Rendered block.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function filter_pinned_group( $block_content, $block ) {
		if ( ! Rolling_Coverage_Block::is_rendering_entry() || ! is_array( $block ) || ! self::is_pinned_row( $block ) ) {
			return $block_content;
		}

		return self::is_current_entry_pinned() ? $block_content : '';
	}

	/**
	 * Whether a parsed block is the pinned row: a group holding the pinned
	 * label and nothing but icons besides.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return bool
	 */
	private static function is_pinned_row( array $parsed_block ): bool {
		$inner_blocks = $parsed_block['innerBlocks'] ?? null;

		if ( 'core/group' !== ( $parsed_block['blockName'] ?? '' ) || ! is_array( $inner_blocks ) ) {
			return false;
		}

		$has_label = false;

		foreach ( $inner_blocks as $inner_block ) {
			if ( ! is_array( $inner_block ) ) {
				return false;
			}

			if ( self::is_pinned_label( $inner_block ) ) {
				$has_label = true;
			} elseif ( 'core/icon' !== ( $inner_block['blockName'] ?? '' ) ) {
				return false;
			}
		}

		return $has_label;
	}

	/**
	 * Whether a template shows the pinned label, at any depth.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return bool
	 */
	public static function has_pinned_label( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( self::is_pinned_label( $block ) || ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && self::has_pinned_label( $block['innerBlocks'] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a parsed block is the paragraph labeling a pinned entry.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return bool
	 */
	private static function is_pinned_label( array $parsed_block ): bool {
		$class_name = $parsed_block['attrs']['className'] ?? '';

		return 'core/paragraph' === ( $parsed_block['blockName'] ?? '' ) &&
			is_string( $class_name ) &&
			in_array( self::PINNED_LABEL_CLASS, explode( ' ', $class_name ), true );
	}

	/**
	 * Whether the entry being rendered shows as pinned. Entries render with
	 * the global post swapped to the entry; a capped feed shows none pinned.
	 *
	 * @return bool
	 */
	private static function is_current_entry_pinned(): bool {
		if ( Rolling_Coverage_Block::is_ignoring_pinning() ) {
			return false;
		}

		$entry_id = (int) get_the_ID();

		return $entry_id && Post_Type::CPT_SLUG === get_post_type( $entry_id ) && Post_Type::is_pinned( $entry_id );
	}

	/**
	 * Whether a parsed block is the Rolling Coverage "Jump to Latest" button:
	 * a core Buttons block holding a button bound to the live feed's link.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return bool
	 */
	public static function is_latest_buttons( array $parsed_block ): bool {
		return self::is_buttons_bound_to( $parsed_block, 'latestUrl' );
	}

	/**
	 * Whether a parsed block is a heading bound to the coverage's name.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return bool
	 */
	public static function is_coverage_name_heading( array $parsed_block ): bool {
		$binding = $parsed_block['attrs']['metadata']['bindings']['content'] ?? [];

		return 'core/heading' === ( $parsed_block['blockName'] ?? '' ) &&
			is_array( $binding ) &&
			self::SOURCE_NAME === ( $binding['source'] ?? '' ) &&
			'coverageName' === ( $binding['args']['key'] ?? '' );
	}

	/**
	 * Whether a parsed block is the paragraph linking to the coverage page's
	 * full feed.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return bool
	 */
	public static function is_all_updates_paragraph( array $parsed_block ): bool {
		$class_name = $parsed_block['attrs']['className'] ?? '';

		return 'core/paragraph' === ( $parsed_block['blockName'] ?? '' ) &&
			is_string( $class_name ) &&
			in_array( self::ALL_UPDATES_CLASS, explode( ' ', $class_name ), true );
	}

	/**
	 * Whether a parsed block belongs to the coverage rather than to each
	 * entry, so it renders once: the Follow Coverage block, the "Jump to
	 * Latest" button, the Coverage Status block, a heading bound to the
	 * coverage's name, the "See all updates" paragraph, or a block holding one
	 * at any depth. The pinned card and the entry group always belong to each
	 * entry, whatever they hold.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return bool
	 */
	public static function is_coverage_item( array $parsed_block ): bool {
		if ( Rolling_Coverage_Block::is_entry_group( $parsed_block ) ) {
			return false;
		}

		if (
			Coverage_Follow_Block::BLOCK_NAME === ( $parsed_block['blockName'] ?? '' ) ||
			Coverage_Status_Block::BLOCK_NAME === ( $parsed_block['blockName'] ?? '' ) ||
			self::is_latest_buttons( $parsed_block ) ||
			self::is_coverage_name_heading( $parsed_block ) ||
			self::is_all_updates_paragraph( $parsed_block )
		) {
			return true;
		}

		foreach ( $parsed_block['innerBlocks'] ?? [] as $inner_block ) {
			if ( is_array( $inner_block ) && self::is_coverage_item( $inner_block ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a parsed block is a core Buttons block holding a button whose
	 * link is bound to one of this source's values.
	 *
	 * @param array  $parsed_block Parsed block.
	 * @param string $key          The bound value's key.
	 * @return bool
	 */
	private static function is_buttons_bound_to( array $parsed_block, string $key ): bool {
		if ( 'core/buttons' !== ( $parsed_block['blockName'] ?? '' ) ) {
			return false;
		}

		foreach ( $parsed_block['innerBlocks'] ?? [] as $inner_block ) {
			$binding = $inner_block['attrs']['metadata']['bindings']['url'] ?? [];

			if ( self::SOURCE_NAME === ( $binding['source'] ?? '' ) && $key === ( $binding['args']['key'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}
}
