<?php
/**
 * Slack message content processor.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a Slack message to block markup, keeping the formatting its author
 * used: paragraphs, line breaks, bold, italic, strikethrough, inline code,
 * links, lists, quotes, code blocks and emoji.
 */
class Slack_Content_Processor {

	/**
	 * Most distinct users a message's mentions are resolved for. Each lookup
	 * can hit the Slack API inside the webhook request, which Slack retries
	 * after three seconds.
	 *
	 * @var int
	 */
	const MAX_MENTION_LOOKUPS = 3;

	/**
	 * Link schemes that are rendered as links.
	 *
	 * @var string[]
	 */
	const LINK_PROTOCOLS = [ 'http', 'https', 'mailto' ];

	/**
	 * Resolves a Slack user ID to a display name, or '' when unknown.
	 *
	 * @var callable|null
	 */
	private $resolve_user;

	/**
	 * Display names resolved so far, keyed by Slack user ID.
	 *
	 * @var array<string, string>
	 */
	private $user_names = [];

	/**
	 * Constructor.
	 *
	 * @param callable|null $resolve_user Receives a Slack user ID and returns
	 *                                    its display name, or '' when unknown.
	 */
	public function __construct( ?callable $resolve_user = null ) {
		$this->resolve_user = $resolve_user;
	}

	/**
	 * Convert a Slack message to block markup.
	 *
	 * Slack sends the message twice: as `blocks`, a structured rich-text tree
	 * of what the author composed, and as `text`, the same message in Slack's
	 * mrkdwn. The rich text is used when present, since it describes lists,
	 * quotes and code blocks exactly; the mrkdwn is the fallback.
	 *
	 * @param string $text   Raw Slack message text (mrkdwn).
	 * @param array  $blocks Slack message blocks.
	 * @return string Block markup, or '' when the message has no content.
	 */
	public function process( string $text, array $blocks = [] ): string {
		$markup = $this->render_rich_text_blocks( $blocks );

		if ( '' !== $markup ) {
			return $markup;
		}

		return $this->render_mrkdwn( $text );
	}

	/**
	 * Convert Slack-specific markup to plain text.
	 *
	 * @param string $text Raw Slack text.
	 * @return string Plain text with Slack markup resolved.
	 */
	public function to_plain_text( string $text ): string {
		$text = preg_replace_callback(
			'/<@([A-Z0-9]+)(?:\|([^>]+))?>/',
			fn( $matches ) => '@' . ( isset( $matches[2] ) && '' !== $matches[2] ? $matches[2] : $this->user_name( $matches[1] ) ),
			$text
		);

		// Channel mentions: <#C123|channel-name> → #channel-name.
		$text = preg_replace( '/<#([A-Z0-9]+)\|([^>]+)>/', '#$2', $text );

		// Channel mentions: <#C123> → #C123.
		$text = preg_replace( '/<#([A-Z0-9]+)>/', '#$1', $text );

		// Links with label: <https://example.com|label> → label.
		$text = preg_replace( '/<(https?:\/\/[^|>]+)\|([^>]+)>/', '$2', $text );

		// Bare links: <https://example.com> → https://example.com.
		$text = preg_replace( '/<(https?:\/\/[^>]+)>/', '$1', $text );

		// Mailto links: <mailto:addr|display> → addr.
		$text = preg_replace( '/<mailto:([^|>]+)\|([^>]+)>/', '$1', $text );

		// Special mentions: <!everyone> → @everyone, <!channel> → @channel, <!here> → @here.
		$text = preg_replace( '/<!(everyone|channel|here)>/', '@$1', $text );

		return (string) $text;
	}

	/**
	 * Render every `rich_text` block of a message.
	 *
	 * @param array $blocks Slack message blocks.
	 * @return string Block markup.
	 */
	private function render_rich_text_blocks( array $blocks ): string {
		$output = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || 'rich_text' !== ( $block['type'] ?? '' ) || ! is_array( $block['elements'] ?? null ) ) {
				continue;
			}

			$elements = array_values( array_filter( $block['elements'], 'is_array' ) );
			$count    = count( $elements );
			$index    = 0;

			while ( $index < $count ) {
				$element = $elements[ $index ];

				switch ( $element['type'] ?? '' ) {
					case 'rich_text_list':
						$lists = [];
						while ( $index < $count && 'rich_text_list' === ( $elements[ $index ]['type'] ?? '' ) ) {
							$lists[] = $elements[ $index ];
							++$index;
						}
						$output[] = $this->render_lists( $lists );
						continue 2;

					case 'rich_text_quote':
						$paragraphs = $this->paragraphs( $this->render_inline( $element['elements'] ?? [] ) );
						if ( '' !== $paragraphs ) {
							$output[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . $paragraphs . "</blockquote>\n<!-- /wp:quote -->";
						}
						break;

					case 'rich_text_preformatted':
						$code = trim( $this->render_inline( $element['elements'] ?? [], false ), "\n" );
						if ( '' !== trim( $code ) ) {
							$output[] = "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . $code . "</code></pre>\n<!-- /wp:code -->";
						}
						break;

					case 'rich_text_section':
						$output[] = $this->paragraphs( $this->render_inline( $element['elements'] ?? [] ) );
						break;
				}

				++$index;
			}
		}

		return implode( "\n\n", array_filter( $output, fn( $markup ) => '' !== $markup ) );
	}

	/**
	 * Render a run of consecutive Slack lists.
	 *
	 * Slack sends a nested list as separate flat lists, each with an `indent`
	 * level, so the nesting is rebuilt from those levels.
	 *
	 * @param array $lists Consecutive `rich_text_list` elements.
	 * @return string Block markup.
	 */
	private function render_lists( array $lists ): string {
		$output = [];
		$count  = count( $lists );
		$index  = 0;

		while ( $index < $count ) {
			$output[] = $this->render_list( $lists, $index, (int) ( $lists[ $index ]['indent'] ?? 0 ) );
		}

		return implode( "\n\n", array_filter( $output, fn( $markup ) => '' !== $markup ) );
	}

	/**
	 * Render one list block at the given indent, consuming the lists that
	 * belong to it (its own items and anything nested deeper).
	 *
	 * @param array $lists  Consecutive `rich_text_list` elements.
	 * @param int   $index  Position of the first list to render; advanced past
	 *                      the lists this call consumes.
	 * @param int   $indent Indent level of this list.
	 * @return string Block markup.
	 */
	private function render_list( array $lists, int &$index, int $indent ): string {
		$count   = count( $lists );
		$ordered = 'ordered' === ( $lists[ $index ]['style'] ?? '' );
		$start   = (int) ( $lists[ $index ]['offset'] ?? 0 ) + 1;
		$items   = [];

		while ( $index < $count ) {
			$list        = $lists[ $index ];
			$list_indent = (int) ( $list['indent'] ?? 0 );

			if ( $list_indent < $indent ) {
				break;
			}

			if ( $list_indent > $indent ) {
				$nested = $this->render_list( $lists, $index, $list_indent );
				if ( empty( $items ) ) {
					$items[] = [
						'html'   => '',
						'nested' => '',
					];
				}
				$items[ count( $items ) - 1 ]['nested'] .= $nested;
				continue;
			}

			if ( ! empty( $items ) && ( 'ordered' === ( $list['style'] ?? '' ) ) !== $ordered ) {
				break;
			}

			foreach ( (array) ( $list['elements'] ?? [] ) as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}
				$items[] = [
					'html'   => $this->line_breaks( trim( $this->render_inline( $section['elements'] ?? [] ) ) ),
					'nested' => '',
				];
			}

			++$index;
		}

		$items = array_filter( $items, fn( $item ) => '' !== $item['html'] || '' !== $item['nested'] );

		if ( empty( $items ) ) {
			return '';
		}

		$item_markup = array_map(
			fn( $item ) => "<!-- wp:list-item -->\n<li>" . $item['html'] . $item['nested'] . "</li>\n<!-- /wp:list-item -->",
			$items
		);

		if ( ! $ordered ) {
			return "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . implode( "\n\n", $item_markup ) . "</ul>\n<!-- /wp:list -->";
		}

		$attributes = [ 'ordered' => true ];
		$start_attr = '';

		if ( $start > 1 ) {
			$attributes['start'] = $start;
			$start_attr          = ' start="' . $start . '"';
		}

		return '<!-- wp:list ' . wp_json_encode( $attributes ) . " -->\n<ol" . $start_attr . ' class="wp-block-list">' . implode( "\n\n", $item_markup ) . "</ol>\n<!-- /wp:list -->";
	}

	/**
	 * Render inline rich-text elements to HTML. Newlines are kept as "\n"
	 * and no tag spans one, so callers can split the result on them.
	 *
	 * @param mixed $elements   Inline elements.
	 * @param bool  $use_styles Whether to apply text styles.
	 * @return string HTML.
	 */
	private function render_inline( $elements, bool $use_styles = true ): string {
		if ( ! is_array( $elements ) ) {
			return '';
		}

		$html = '';

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$style = $use_styles && is_array( $element['style'] ?? null ) ? $element['style'] : [];

			switch ( $element['type'] ?? '' ) {
				case 'text':
					$html .= $this->styled( (string) ( $element['text'] ?? '' ), $style );
					break;

				case 'link':
					$url   = (string) ( $element['url'] ?? '' );
					$label = (string) ( $element['text'] ?? '' );
					if ( '' === trim( $label ) ) {
						$label = preg_replace( '/^mailto:/i', '', $url );
					}
					$html .= $this->link( $url, $this->styled( (string) preg_replace( '/\s+/', ' ', $label ), $style ) );
					break;

				case 'emoji':
					$html .= $this->emoji( $element );
					break;

				case 'user':
					$html .= $this->styled( '@' . $this->user_name( (string) ( $element['user_id'] ?? '' ) ), $style );
					break;

				case 'usergroup':
					$html .= $this->styled( '@' . (string) ( $element['usergroup_id'] ?? '' ), $style );
					break;

				case 'channel':
					$html .= $this->styled( '#' . (string) ( $element['channel_id'] ?? '' ), $style );
					break;

				case 'broadcast':
					$html .= $this->styled( '@' . (string) ( $element['range'] ?? '' ), $style );
					break;

				case 'date':
					$html .= $this->styled( $this->date( $element ), $style );
					break;

				case 'color':
					$html .= $this->styled( (string) ( $element['value'] ?? '' ), $style );
					break;
			}
		}

		return $html;
	}

	/**
	 * Escape text and wrap each of its lines in the given styles.
	 *
	 * @param string $text  Text.
	 * @param array  $style Slack style flags.
	 * @return string HTML.
	 */
	private function styled( string $text, array $style ): string {
		$lines = explode( "\n", $text );

		foreach ( $lines as &$line ) {
			if ( '' === $line ) {
				continue;
			}

			$line = esc_html( $line );

			if ( ! empty( $style['code'] ) ) {
				$line = '<code>' . $line . '</code>';
			}
			if ( ! empty( $style['strike'] ) ) {
				$line = '<s>' . $line . '</s>';
			}
			if ( ! empty( $style['italic'] ) ) {
				$line = '<em>' . $line . '</em>';
			}
			if ( ! empty( $style['bold'] ) ) {
				$line = '<strong>' . $line . '</strong>';
			}
		}
		unset( $line );

		return implode( "\n", $lines );
	}

	/**
	 * Wrap HTML in a link, or return it bare when the URL's scheme is not
	 * one that is safe to render.
	 *
	 * @param string $url  Link URL.
	 * @param string $html Link content.
	 * @return string HTML.
	 */
	private function link( string $url, string $html ): string {
		$href = esc_url( $url, self::LINK_PROTOCOLS );

		if ( '' === $href || '' === $html ) {
			return $html;
		}

		return '<a href="' . $href . '">' . $html . '</a>';
	}

	/**
	 * Render an emoji as its character, or as `:name:` for custom emoji.
	 *
	 * @param array $element Emoji element.
	 * @return string HTML.
	 */
	private function emoji( array $element ): string {
		$unicode = (string) ( $element['unicode'] ?? '' );

		if ( preg_match( '/^[0-9a-f]{1,6}(?:-[0-9a-f]{1,6})*$/i', $unicode ) ) {
			$characters = array_map( fn( $code_point ) => (string) mb_chr( (int) hexdec( $code_point ), 'UTF-8' ), explode( '-', $unicode ) );
			return esc_html( implode( '', $characters ) );
		}

		$name = (string) ( $element['name'] ?? '' );

		return '' === $name ? '' : esc_html( ':' . $name . ':' );
	}

	/**
	 * Text for a date element: Slack's fallback text, else the timestamp in
	 * the site's date format.
	 *
	 * @param array $element Date element.
	 * @return string Plain text.
	 */
	private function date( array $element ): string {
		$fallback = (string) ( $element['fallback'] ?? '' );

		if ( '' !== $fallback ) {
			return $fallback;
		}

		$timestamp = (int) ( $element['timestamp'] ?? 0 );

		return $timestamp > 0 ? (string) wp_date( get_option( 'date_format' ), $timestamp ) : '';
	}

	/**
	 * Display name for a mentioned user, or the user ID when it cannot be
	 * resolved.
	 *
	 * @param string $user_id Slack user ID.
	 * @return string Name, without the "@".
	 */
	private function user_name( string $user_id ): string {
		if ( isset( $this->user_names[ $user_id ] ) ) {
			return $this->user_names[ $user_id ];
		}

		$name = '';

		if ( '' !== $user_id && null !== $this->resolve_user && count( $this->user_names ) < self::MAX_MENTION_LOOKUPS ) {
			$name = (string) call_user_func( $this->resolve_user, $user_id );
		}

		$this->user_names[ $user_id ] = '' !== trim( $name ) ? $name : $user_id;

		return $this->user_names[ $user_id ];
	}

	/**
	 * Split inline HTML into paragraph blocks: a blank line starts a new
	 * paragraph, a single newline is a line break.
	 *
	 * @param string $html Inline HTML with "\n" newlines.
	 * @return string Block markup.
	 */
	private function paragraphs( string $html ): string {
		$paragraphs = [];

		foreach ( preg_split( '/\n\s*\n/', $html ) as $paragraph ) {
			$paragraph = $this->line_breaks( trim( $paragraph ) );

			if ( '' !== $paragraph ) {
				$paragraphs[] = "<!-- wp:paragraph -->\n<p>" . $paragraph . "</p>\n<!-- /wp:paragraph -->";
			}
		}

		return implode( "\n\n", $paragraphs );
	}

	/**
	 * Turn newlines into line breaks, dropping any whitespace around them.
	 *
	 * @param string $html Inline HTML.
	 * @return string HTML.
	 */
	private function line_breaks( string $html ): string {
		return (string) preg_replace( '/[ \t]*\n[ \t]*/', '<br>', $html );
	}

	/**
	 * Render mrkdwn text, for messages that arrive without rich-text blocks:
	 * links, mentions, bold, italic, strikethrough, inline code, paragraphs
	 * and line breaks.
	 *
	 * @param string $text Raw Slack message text.
	 * @return string Block markup.
	 */
	private function render_mrkdwn( string $text ): string {
		$links = [];

		$text = (string) preg_replace_callback(
			'/<((?:https?:\/\/|mailto:)[^|>]+)(?:\|([^>]*))?>/i',
			function ( $matches ) use ( &$links ) {
				$label = isset( $matches[2] ) && '' !== trim( $matches[2] )
					? $matches[2]
					: preg_replace( '/^mailto:/i', '', $matches[1] );

				$links[] = $this->link( $matches[1], esc_html( wp_specialchars_decode( $label ) ) );

				return "\u{E000}" . ( count( $links ) - 1 ) . "\u{E001}";
			},
			$text
		);

		$text = wp_strip_all_tags( $this->to_plain_text( $text ) );
		$html = esc_html( wp_specialchars_decode( $text ) );

		$inline_styles = [
			'`'  => 'code',
			'\*' => 'strong',
			'_'  => 'em',
			'~'  => 's',
		];

		foreach ( $inline_styles as $marker => $tag ) {
			$html = (string) preg_replace(
				'/(?<![\w' . $marker . '])' . $marker . '(?=\S)([^\n]+?)(?<=\S)' . $marker . '(?![\w' . $marker . '])/u',
				'<' . $tag . '>$1</' . $tag . '>',
				$html
			);
		}

		$html = (string) preg_replace_callback(
			"/\u{E000}(\d+)\u{E001}/u",
			fn( $matches ) => $links[ (int) $matches[1] ] ?? '',
			$html
		);

		return $this->paragraphs( $html );
	}
}
