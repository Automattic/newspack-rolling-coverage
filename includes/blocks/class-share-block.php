<?php
/**
 * Share Block Gutenberg block: registration and SSR.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `newspack-rolling-coverage/share` block and its
 * server-side render callback, which emits a "Share" button carrying the
 * entry's deep-link URL.
 */
class Share_Block {

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
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/share',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * Renders a "Share" button carrying the entry's deep-link URL in a
	 * `data-share-url` attribute, or an empty string if the entry has no
	 * canonical page (e.g. its coverage has no block placement yet).
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

		$share_url = Social_Sharing::get_entry_share_url( $entry_id );

		if ( ! $share_url ) {
			return '';
		}

		$label = isset( $attributes['label'] ) && is_string( $attributes['label'] )
			? $attributes['label']
			: __( 'Share', 'newspack-rolling-coverage' );

		return sprintf(
			'<button %1$s type="button" data-share-url="%2$s" aria-label="%3$s">%4$s</button>',
			get_block_wrapper_attributes(
				[ 'class' => 'newspack-rolling-coverage-share-link wp-element-button' ]
			),
			esc_attr( $share_url ),
			esc_attr( __( 'Share this entry', 'newspack-rolling-coverage' ) ),
			esc_html( $label )
		);
	}
}
