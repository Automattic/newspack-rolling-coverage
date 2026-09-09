<?php
/**
 * Coverage Archived Notice Gutenberg block: registration and SSR.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the coverage-archived-notice block and its server-side render.
 */
class Coverage_Archived_Notice_Block {

	// The block's registered name.
	const BLOCK_NAME = 'newspack-rolling-coverage/coverage-archived-notice';

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
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/coverage-archived-notice',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * @param array $attributes Block attributes (content).
	 * @return string Rendered HTML.
	 */
	public static function render_block( $attributes ) {
		$content = isset( $attributes['content'] ) && is_string( $attributes['content'] ) && '' !== $attributes['content']
			? $attributes['content']
			: self::default_text();

		return sprintf(
			'<p %1$s>%2$s</p>',
			get_block_wrapper_attributes( [ 'class' => 'newspack-rolling-coverage-archived-notice' ] ),
			wp_kses_post( $content )
		);
	}

	/**
	 * The default notice text, used when the owner hasn't edited the block
	 * and for coverages published before this block existed.
	 *
	 * @return string
	 */
	private static function default_text(): string {
		return __( 'Coverage of this news event has concluded and this feed is now archived.', 'newspack-rolling-coverage' );
	}
}
