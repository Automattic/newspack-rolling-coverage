<?php
/**
 * Slack message content processor.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Converts Slack markup to Gutenberg-ready HTML.
 */
class Slack_Content_Processor {

	/**
	 * Process Slack text into Gutenberg block HTML.
	 *
	 * @param string $text Raw Slack message text.
	 * @return string Gutenberg block-wrapped, sanitized HTML.
	 */
	public function process( string $text ): string {
		$plain     = $this->to_plain_text( $text );
		$sanitized = (string) wp_strip_all_tags( $plain );

		return $this->wrap_in_gutenberg_block( $sanitized );
	}

	/**
	 * Convert Slack text to un-escaped plain text (for title derivation).
	 *
	 * This is the un-escaped counterpart to process(): it returns the plain
	 * text after Slack markup resolution and tag stripping, but BEFORE
	 * esc_html() is applied in wrap_in_gutenberg_block(). The ingestion
	 * service uses this for post_title so entities like AT&T are not
	 * double-encoded as AT&amp;T.
	 *
	 * @param string $text Raw Slack message text.
	 * @return string Un-escaped plain text (tags stripped, no HTML encoding).
	 */
	public function to_plain_text_sanitized( string $text ): string {
		return (string) wp_strip_all_tags( $this->to_plain_text( $text ) );
	}

	/**
	 * Convert Slack-specific markup to plain text.
	 *
	 * @param string $text Raw Slack text.
	 * @return string Plain text with Slack markup resolved.
	 */
	public function to_plain_text( string $text ): string {
		// User mentions: <@U123|displayname> → @displayname.
		$text = preg_replace( '/<@([A-Z0-9]+)\|([^>]+)>/', '@$2', $text );

		// User mentions: <@U123> → @U123.
		$text = preg_replace( '/<@([A-Z0-9]+)>/', '@$1', $text );

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
	 * Wrap text in a Gutenberg paragraph block.
	 *
	 * @param string $text Sanitized plain text.
	 * @return string Gutenberg block markup.
	 */
	public function wrap_in_gutenberg_block( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
	}
}
