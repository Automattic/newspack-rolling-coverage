<?php
/**
 * Block bindings for the per-entry buttons.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;
use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the values core Button blocks in the Rolling Coverage template
 * are bound to: per entry, the breakout post's link and label and the share
 * link; per coverage, the follow button's notification tag.
 */
class Entry_Bindings {

	const SOURCE_NAME = 'newspack-rolling-coverage/entry';

	/**
	 * Attribute the share script looks for on the share link.
	 */
	const SHARE_ATTRIBUTE = 'data-rc-share';

	/**
	 * Attribute the follow script looks for on the follow button.
	 */
	const FOLLOW_ATTRIBUTE = 'data-rc-follow';

	/**
	 * Block context the Rolling Coverage block renders its follow button with.
	 */
	const COVERAGE_ID_CONTEXT     = 'newspack-rolling-coverage/coverageId';
	const COVERAGE_STATUS_CONTEXT = 'newspack-rolling-coverage/coverageStatus';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_source' ] );
		add_filter( 'render_block_core/button', [ __CLASS__, 'filter_button' ], 10, 3 );
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

		$entry_id = (int) ( $block->context['postId'] ?? 0 );

		if ( ! $entry_id || Post_Type::CPT_SLUG !== get_post_type( $entry_id ) ) {
			return null;
		}

		switch ( $source_args['key'] ?? '' ) {
			case 'breakoutUrl':
				$breakout_id = Breakout::get_existing_breakout_id( $entry_id );

				if ( ! $breakout_id || 'publish' !== get_post_status( $breakout_id ) ) {
					return null;
				}

				return get_permalink( $breakout_id ) ?: null; // phpcs:ignore Universal.Operators.DisallowShortTernary.Found

			case 'breakoutLabel':
				$label = get_post_meta( $entry_id, Breakout::ENTRY_READ_MORE_TEXT_META, true );

				return $label ? $label : __( 'Read more', 'newspack-rolling-coverage' );

			case 'shareUrl':
				return Social_Sharing::get_entry_share_url( $entry_id ) ?: null; // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		}

		return null;
	}

	/**
	 * Render nothing for a button whose link is bound to a value the entry
	 * doesn't have, e.g. "Read more" before the breakout post is published,
	 * and hand the share and follow buttons what their scripts need.
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

		if ( 'followTag' === $key && $button->next_tag( 'button' ) ) {
			$button->set_attribute( self::FOLLOW_ATTRIBUTE, '' );
			$button->set_attribute( 'data-tag', $instance->attributes['url'] );
			$button->set_attribute( 'data-label-following', __( 'Following', 'newspack-rolling-coverage' ) );
			$button->set_attribute( 'data-blocked-message', __( 'Notifications are blocked in your browser. Allow them in your browser\'s site settings, then try again.', 'newspack-rolling-coverage' ) );
			$button->set_attribute( 'data-error-message', __( 'Something went wrong. Please try again.', 'newspack-rolling-coverage' ) );
			$button->set_attribute( 'aria-pressed', 'false' );
		}

		return $button->get_updated_html();
	}

	/**
	 * Whether a parsed block is the Rolling Coverage follow button: a core
	 * Buttons block holding a button bound to the coverage's follow tag.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return bool
	 */
	public static function is_follow_buttons( array $parsed_block ): bool {
		if ( 'core/buttons' !== ( $parsed_block['blockName'] ?? '' ) ) {
			return false;
		}

		foreach ( $parsed_block['innerBlocks'] ?? [] as $inner_block ) {
			$binding = $inner_block['attrs']['metadata']['bindings']['url'] ?? [];

			if ( self::SOURCE_NAME === ( $binding['source'] ?? '' ) && 'followTag' === ( $binding['args']['key'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}
}
