<?php
/**
 * New Label Gutenberg block: registration and SSR.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `newspack-rolling-coverage/new-label` block, the label a
 * layout places in an entry to mark it as new when it arrives while the
 * reader has the page open. The server renders it hidden and tagged in every
 * entry; the feed's view script reveals it on a new entry and hides it again
 * once the reader has seen it (`new-entry-marks.ts`). Anywhere else it
 * renders nothing.
 */
class New_Label_Block {

	// Block name, as registered in block.json.
	const BLOCK_NAME = 'newspack-rolling-coverage/new-label';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Registers the block type.
	 */
	public static function register_block(): void {
		register_block_type(
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/new-label',
			self::block_type_args()
		);
	}

	/**
	 * The block type's server-side settings.
	 *
	 * @return array
	 */
	public static function block_type_args(): array {
		return [
			'render_callback' => [ __CLASS__, 'render_block' ],
		];
	}

	/**
	 * The label's text: the block's own with its tags stripped, or the
	 * translated "New" when that leaves only spaces. The stored text is
	 * already HTML, so it isn't decoded and its entities render as typed.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	private static function text( array $attributes ): string {
		$content = $attributes['content'] ?? '';
		$text    = is_string( $content ) ? wp_strip_all_tags( $content ) : '';

		// Decoded only to test for spaces, as the editor can store a no-break space, which trim() leaves.
		if ( 1 === preg_match( '/^[\s\x{00A0}]*$/u', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
			/* translators: Label on a liveblog entry published while the reader has the page open. */
			return __( 'New', 'newspack-rolling-coverage' );
		}

		return $text;
	}

	/**
	 * Server-side render callback: the label, hidden and tagged with
	 * Entry_Bindings::NEW_MARKER_ATTR for the view script to reveal, inside
	 * an entry. Nothing outside one, and nothing in a syndication feed, where
	 * no script runs to manage the label and a reader may drop `hidden`.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( array $attributes ): string {
		if ( ! Rolling_Coverage_Block::is_rendering_entry() || is_feed() ) {
			return '';
		}

		$extra = ! empty( $attributes['visuallyHidden'] ) ? [ 'class' => 'screen-reader-text' ] : [];

		return sprintf(
			'<p %s hidden %s>%s</p>',
			get_block_wrapper_attributes( $extra ),
			esc_attr( Entry_Bindings::NEW_MARKER_ATTR ),
			esc_html( self::text( $attributes ) )
		);
	}
}
