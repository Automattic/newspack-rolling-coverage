<?php
/**
 * Coverage Follow Gutenberg block: registration and SSR.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `newspack-rolling-coverage/coverage-follow` block and its
 * server-side render callback, which emits a "Follow" button for push
 * notifications. Rendered once at the top of the coverage by the parent block.
 */
class Coverage_Follow_Block {

	// Block name, as registered in block.json.
	const BLOCK_NAME = 'newspack-rolling-coverage/coverage-follow';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Registers the block type and localizes its editor script.
	 */
	public static function register_block() {
		$block_type = register_block_type(
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/coverage-follow',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);

		if ( ! $block_type instanceof WP_Block_Type ) {
			return;
		}

		foreach ( $block_type->editor_script_handles as $handle ) {
			wp_localize_script(
				$handle,
				'newspackRollingCoverageFollow',
				[
					'onesignalInstalled'  => Push_Notifications::is_onesignal_installed(),
					'onesignalV3Active'   => Push_Notifications::is_onesignal_v3_active(),
					'onesignalConfigured' => Push_Notifications::is_onesignal_configured(),
				]
			);
		}
	}

	/**
	 * Whether a follow button should render for the given coverage status.
	 *
	 * Requires OneSignal to be configured and the coverage not to be archived.
	 *
	 * @param string $status Coverage status.
	 * @return bool
	 */
	public static function should_render( string $status ): bool {
		if ( ! Push_Notifications::is_onesignal_configured() ) {
			return false;
		}

		return 'archived' !== $status;
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * Renders a "Follow" button that lets a reader subscribe to push
	 * notifications for the coverage.
	 *
	 * @param array $attributes Block attributes (coverageId, status).
	 * @return string Rendered HTML, or an empty string when it shouldn't appear.
	 */
	public static function render_block( $attributes ) {
		$coverage_id = (int) ( $attributes['coverageId'] ?? 0 );
		$status      = (string) ( $attributes['status'] ?? 'active' );

		if ( ! $coverage_id || ! self::should_render( $status ) ) {
			return '';
		}

		return sprintf(
			'<button type="button" %1$s data-tag="%2$s" data-label-follow="%3$s" data-label-following="%4$s" data-blocked-message="%5$s" data-error-message="%6$s" aria-pressed="false">%3$s</button>',
			get_block_wrapper_attributes( [ 'class' => 'newspack-rolling-coverage-follow wp-element-button' ] ),
			esc_attr( Push_Notifications::follow_tag( $coverage_id ) ),
			esc_html__( 'Follow', 'newspack-rolling-coverage' ),
			esc_html__( 'Following', 'newspack-rolling-coverage' ),
			esc_attr__( 'Notifications are blocked in your browser. Allow them in your browser\'s site settings, then try again.', 'newspack-rolling-coverage' ),
			esc_attr__( 'Something went wrong. Please try again.', 'newspack-rolling-coverage' )
		);
	}
}
