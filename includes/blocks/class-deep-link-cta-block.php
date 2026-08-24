<?php
/**
 * Deep Link CTA Gutenberg block: registration and SSR.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `newspack-rolling-coverage/deep-link-cta` block and its
 * server-side render callback. This block is not inserted by editors; it is
 * rendered by the parent rolling-coverage block when a deep link points to
 * an entry not in the initial SSR set.
 *
 * The modal content is pre-rendered at SSR into a hidden <template> element.
 * On click, the front-end clones the template into a native <dialog>. No
 * REST fetch or options-table lookup is needed at modal-open time.
 */
class Deep_Link_CTA_Block {

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
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/deep-link-cta',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * Renders a hidden CTA populated with entry data (entry id, title)
	 * from the block attributes. The parent rolling-coverage block passes
	 * these attributes when it renders the CTA via render_block() in
	 * response to the deep-link query var. JS un-hides the CTA when the
	 * deep-linked entry is not in the initial SSR set.
	 *
	 * The modal content is rendered into a hidden <template> sibling so the
	 * front-end can clone it on click without a REST round-trip.
	 *
	 * @param array    $attributes Block attributes (entryId, entryTitle).
	 * @param string   $content    Block content (unused).
	 * @param WP_Block $block      Block instance.
	 * @return string Rendered HTML.
	 */
	public static function render_block( $attributes, $content, WP_Block $block ) {
		$entry_id    = (int) ( $attributes['entryId'] ?? 0 );
		$entry_title = isset( $attributes['entryTitle'] ) && is_string( $attributes['entryTitle'] ) ? $attributes['entryTitle'] : '';
		$cta_text    = isset( $attributes['ctaText'] ) && is_string( $attributes['ctaText'] ) && '' !== $attributes['ctaText'] ? $attributes['ctaText'] : '';
		$button_text = isset( $attributes['buttonText'] ) && is_string( $attributes['buttonText'] ) && '' !== $attributes['buttonText'] ? $attributes['buttonText'] : '';

		if ( ! $entry_id ) {
			return '';
		}

		if ( '' !== $cta_text ) {
			$text = str_replace( '{{entry_title}}', esc_html( $entry_title ), $cta_text );
		} else {
			$text = sprintf(
				/* translators: 1: deep-linked entry title. */
				__( 'You linked to an older entry (%1$s).', 'newspack-rolling-coverage' ),
				'<strong>' . esc_html( $entry_title ) . '</strong>'
			);
		}

		if ( '' === $button_text ) {
			$button_text = __( 'View', 'newspack-rolling-coverage' );
		}

		// If the entry has a published breakout post, link directly to it.
		$breakout_id  = Breakout::get_existing_breakout_id( $entry_id );
		$breakout_url = '';

		if ( $breakout_id && 'publish' === get_post_status( $breakout_id ) ) {
			$breakout_url = get_permalink( $breakout_id );
		}

		if ( $breakout_url ) {
			return sprintf(
				'<div %1$s hidden role="status" aria-live="polite"><p class="newspack-rolling-coverage-cta__text">%2$s</p><a href="%3$s" class="newspack-rolling-coverage-cta__button wp-element-button">%4$s</a></div>',
				get_block_wrapper_attributes( [ 'class' => 'newspack-rolling-coverage-cta' ] ),
				wp_kses_post( $text ),
				esc_url( $breakout_url ),
				esc_html( $button_text )
			);
		}

		// Non-breakout path: render modal template, then button + hidden <template>.
		$modal_html = self::render_modal_template( $block, $entry_id );

		return sprintf(
			'<div %1$s hidden role="status" aria-live="polite"><p class="newspack-rolling-coverage-cta__text">%2$s</p><button type="button" class="newspack-rolling-coverage-cta__button wp-element-button" aria-haspopup="dialog">%3$s</button><template class="newspack-rolling-coverage-cta__modal-template">%4$s</template></div>',
			get_block_wrapper_attributes( [ 'class' => 'newspack-rolling-coverage-cta' ] ),
			wp_kses_post( $text ),
			esc_html( $button_text ),
			$modal_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block HTML, already escaped during render.
		);
	}

	/**
	 * Renders the modal template blocks with the entry as post context.
	 *
	 * Uses the saved inner blocks (the editor-customized modal template) if
	 * present, otherwise falls back to the default template.
	 *
	 * @param WP_Block $block     Block instance (for inner-block access).
	 * @param int      $entry_id  Entry post ID to render against.
	 * @return string Rendered modal HTML.
	 */
	private static function render_modal_template( WP_Block $block, int $entry_id ): string {
		$entry = get_post( $entry_id );

		if ( ! $entry instanceof WP_Post || 'publish' !== $entry->post_status ) {
			return '';
		}

		$template = self::get_modal_template( $block );

		global $post;
		$previous_post = $post;
		$post          = $entry; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $entry );

		try {
			$html = ( new WP_Block(
				[
					'blockName'    => null,
					'attrs'        => [],
					'innerBlocks'  => $template,
					'innerHTML'    => '',
					'innerContent' => array_fill( 0, count( $template ), null ),
				],
				[
					'postId'   => $entry->ID,
					'postType' => $entry->post_type,
				]
			) )->render( [ 'dynamic' => false ] );
		} finally {
			$post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $previous_post );
		}

		return $html;
	}

	/**
	 * Gets the modal template from the block's inner blocks, or returns
	 * the default template.
	 *
	 * @param WP_Block $block Block instance.
	 * @return array[] Array of parsed-block-shaped arrays.
	 */
	private static function get_modal_template( WP_Block $block ): array {
		$inner_blocks = $block->parsed_block['innerBlocks'] ?? [];

		if ( ! empty( $inner_blocks ) ) {
			return $inner_blocks;
		}

		return self::default_modal_template();
	}

	/**
	 * The hardcoded fallback modal template: title, date, content, spacer, author.
	 *
	 * @return array[] Array of parsed-block-shaped arrays.
	 */
	public static function default_modal_template(): array {
		return [
			[
				'blockName'    => 'core/post-title',
				'attrs'        => [ 'level' => 3 ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[
				'blockName'    => 'core/post-date',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[
				'blockName'    => 'core/post-content',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[
				'blockName'    => 'core/spacer',
				'attrs'        => [ 'height' => '20px' ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[
				'blockName'    => 'core/post-author-name',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
		];
	}
}
