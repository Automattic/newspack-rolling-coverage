<?php
/**
 * Follow Coverage Gutenberg block: registration and SSR.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `newspack-rolling-coverage/coverage-follow` block, which
 * wraps a core "Follow" button that lets readers subscribe to a coverage's
 * push notifications. Inside a Rolling Coverage block it follows that
 * block's coverage; elsewhere, the chosen one, or else the page's first.
 * It adds no markup of its own.
 */
class Coverage_Follow_Block {

	// Block name, as registered in block.json.
	const BLOCK_NAME = 'newspack-rolling-coverage/coverage-follow';

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_filter( 'render_block_context', [ __CLASS__, 'add_coverage_context' ], 10, 3 );
	}

	/**
	 * Registers the block type.
	 */
	public static function register_block(): void {
		register_block_type(
			NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'dist/blocks/coverage-follow',
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
	 * Hands the coverage the block follows to the blocks it holds, so the
	 * button's follow binding carries that coverage's tag. Core passes a
	 * block's context on to its descendants, so only the block's own
	 * children need it, and each block hands on its own coverage.
	 *
	 * Parameters stay untyped because this runs for every block on the site,
	 * after other plugins' filters that may hand on unexpected types.
	 *
	 * @param array         $context      Block context.
	 * @param array         $parsed_block Parsed block being rendered.
	 * @param WP_Block|null $parent_block The block holding it, if any.
	 * @return array
	 */
	public static function add_coverage_context( $context, $parsed_block, $parent_block = null ) {
		if ( ! is_array( $context ) || ! $parent_block instanceof WP_Block || self::BLOCK_NAME !== $parent_block->name ) {
			return $context;
		}

		return array_merge( $context, self::coverage_context( $parent_block ) );
	}

	/**
	 * Server-side render callback: the block's button, already rendered with
	 * the coverage in its context, or nothing when there's no coverage to
	 * follow, it's archived, or OneSignal isn't set up.
	 *
	 * @param array    $attributes Block attributes (unused).
	 * @param string   $content    Rendered inner blocks.
	 * @param WP_Block $block      Block instance.
	 * @return string
	 */
	public static function render_block( array $attributes, string $content, WP_Block $block ): string {
		$context = self::coverage_context( $block );

		if ( ! $context || ! self::should_render( $context[ Entry_Bindings::COVERAGE_STATUS_CONTEXT ] ) ) {
			return '';
		}

		return $content;
	}

	/**
	 * The coverage the block follows, as the context the follow binding
	 * reads: inside a Rolling Coverage block, that block's, on any page;
	 * elsewhere, the chosen one while it can be followed, otherwise the
	 * page's first feed.
	 *
	 * @param WP_Block $block Block instance.
	 * @return array Coverage ID and status context, or empty when it follows none.
	 */
	private static function coverage_context( WP_Block $block ): array {
		if ( isset( $block->context[ Entry_Bindings::COVERAGE_ID_CONTEXT ] ) ) {
			$coverage_id = (int) $block->context[ Entry_Bindings::COVERAGE_ID_CONTEXT ];
			$status      = (string) ( $block->context[ Entry_Bindings::COVERAGE_STATUS_CONTEXT ] ?? '' );
		} else {
			// WP_Block serves attributes through __get alone, which `??` can't see.
			$attributes  = $block->attributes;
			$chosen      = (int) ( $attributes['coverageId'] ?? 0 );
			$coverage_id = Page_Coverages::is_followable( $chosen ) ? $chosen : ( Page_Coverages::feed_coverage_ids( Page_Coverages::page_id( $block ) )[0] ?? 0 );
			$status      = '';
		}

		if ( $coverage_id <= 0 ) {
			return [];
		}

		return [
			Entry_Bindings::COVERAGE_ID_CONTEXT     => $coverage_id,
			Entry_Bindings::COVERAGE_STATUS_CONTEXT => '' !== $status ? $status : Rolling_Coverage_Block::coverage_status( $coverage_id ),
		];
	}
}
