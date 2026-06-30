<?php
/**
 * Breakout Post Link Gutenberg block: registration and SSR.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `newspack-rolling-coverage/breakout-post-link` block and its server-side render.
 */
class Breakout_Post_Link_Block {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Registers the block type and its server-side render callback.
	 */
	public static function register_block() {
		register_block_type(
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/breakout-post-link',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * Renders a link to the entry's breakout post, or an empty string if the entry has no published breakout post.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Saved inner content.
	 * @param WP_Block $block      Block instance, providing the `postId` context.
	 * @return string Rendered HTML, or an empty string.
	 */
	public static function render_block( $attributes, $content, WP_Block $block ) {
		$entry_id = (int) ( $block->context['postId'] ?? 0 );

		if ( ! $entry_id ) {
			return '';
		}

		$breakout_id = Breakout::get_existing_breakout_id( $entry_id );

		// Render nothing until the entry has a published breakout post.
		if ( ! $breakout_id || 'publish' !== get_post_status( $breakout_id ) ) {
			return '';
		}

		$label = get_post_meta( $entry_id, Breakout::ENTRY_READ_MORE_TEXT_META, true );
		$label = $label ? $label : __( 'Read more', 'newspack-rolling-coverage' );

		return sprintf(
			'<a %1$s href="%2$s">%3$s</a>',
			get_block_wrapper_attributes( [ 'class' => 'newspack-rolling-coverage-breakout-post-link wp-element-button' ] ),
			esc_url( get_permalink( $breakout_id ) ),
			esc_html( $label )
		);
	}
}
