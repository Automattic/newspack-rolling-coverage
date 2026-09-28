<?php
/**
 * Block bindings for the per-entry buttons.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the per-entry values core Button blocks in the entry template
 * are bound to: the breakout post's link and label, and the share link.
 */
class Entry_Bindings {

	const SOURCE_NAME = 'newspack-rolling-coverage/entry';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_source' ] );
		add_filter( 'render_block_core/button', [ __CLASS__, 'hide_unavailable_button' ], 10, 3 );
	}

	/**
	 * Register the block bindings source.
	 */
	public static function register_source() {
		register_block_bindings_source(
			self::SOURCE_NAME,
			[
				'label'              => __( 'Rolling Coverage Entry', 'newspack-rolling-coverage' ),
				'get_value_callback' => [ __CLASS__, 'get_value' ],
				'uses_context'       => [ 'postId', 'postType' ],
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
		$entry_id = (int) ( $block->context['postId'] ?? 0 );

		if ( ! $entry_id || Post_Type::CPT_SLUG !== get_post_type( $entry_id ) ) {
			return null;
		}

		switch ( $source_args['key'] ?? '' ) {
			case 'breakoutUrl':
				$breakout_id = Breakout::get_existing_breakout_id( $entry_id );

				return $breakout_id && 'publish' === get_post_status( $breakout_id ) ? get_permalink( $breakout_id ) : null;

			case 'breakoutLabel':
				$label = get_post_meta( $entry_id, Breakout::ENTRY_READ_MORE_TEXT_META, true );

				return $label ? $label : __( 'Read more', 'newspack-rolling-coverage' );

			case 'shareUrl':
				$share_url = Social_Sharing::get_entry_share_url( $entry_id );

				return $share_url ? $share_url : null;
		}

		return null;
	}

	/**
	 * Render nothing for a button whose link is bound to a value the entry
	 * doesn't have, e.g. "Read more" before the breakout post is published.
	 *
	 * @param string   $block_content Rendered block.
	 * @param array    $block         Parsed block.
	 * @param WP_Block $instance      Block instance.
	 * @return string
	 */
	public static function hide_unavailable_button( $block_content, $block, $instance ) {
		$binding = $block['attrs']['metadata']['bindings']['url'] ?? null;

		if ( ! is_array( $binding ) || self::SOURCE_NAME !== ( $binding['source'] ?? '' ) || ! $instance instanceof WP_Block ) {
			return $block_content;
		}

		return null === self::get_value( $binding['args'] ?? [], $instance ) ? '' : $block_content;
	}
}
