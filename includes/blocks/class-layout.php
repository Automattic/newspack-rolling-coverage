<?php
/**
 * The shared layout Rolling Coverage blocks sync to.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the shared layout in a synced pattern whose content is one Rolling
 * Coverage block, and hands that block's inner blocks to every Rolling
 * Coverage block pointing at it through its `layoutId` attribute.
 */
class Layout {

	const DEFAULT_OPTION = 'rolling_coverage_default_layout_id';

	const PATTERN_CATEGORY = 'rolling-coverage';

	const PATTERN_TAXONOMY = 'wp_pattern_category';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'render_block_data', [ __CLASS__, 'inject_layout' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * The layout blocks a pattern holds.
	 *
	 * Only published patterns resolve, so a story can never render a draft or
	 * private pattern it points at.
	 *
	 * @param int $layout_id Pattern (wp_block) ID.
	 * @return array[]|null Inner blocks of the pattern's Rolling Coverage block, or null.
	 */
	public static function get_layout_blocks( int $layout_id ): ?array {
		if ( $layout_id <= 0 ) {
			return null;
		}

		$post = get_post( $layout_id );

		if ( ! $post instanceof WP_Post || 'wp_block' !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( Rolling_Coverage_Block::BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
				return $block['innerBlocks'] ?? [];
			}
		}

		return null;
	}

	/**
	 * Swaps a synced block's inner blocks for its pattern's before core builds
	 * the block, so everything that reads the block's inner blocks sees the
	 * shared layout. An unusable pattern leaves no inner blocks, which renders
	 * the built-in default layout.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return array
	 */
	public static function inject_layout( $parsed_block ) {
		if ( Rolling_Coverage_Block::BLOCK_NAME !== ( $parsed_block['blockName'] ?? '' ) ) {
			return $parsed_block;
		}

		$layout_id = (int) ( $parsed_block['attrs']['layoutId'] ?? 0 );

		if ( $layout_id <= 0 ) {
			return $parsed_block;
		}

		$inner_blocks = self::get_layout_blocks( $layout_id ) ?? [];

		$parsed_block['innerBlocks']  = $inner_blocks;
		$parsed_block['innerHTML']    = '';
		$parsed_block['innerContent'] = array_fill( 0, count( $inner_blocks ), null );

		return $parsed_block;
	}

	/**
	 * The default layout's ID, when it still resolves.
	 *
	 * @return int Pattern ID, or 0.
	 */
	public static function get_default_layout_id(): int {
		$layout_id = (int) get_option( self::DEFAULT_OPTION, 0 );

		return null !== self::get_layout_blocks( $layout_id ) ? $layout_id : 0;
	}

	/**
	 * Registers the route the editor creates the default layout through.
	 */
	public static function register_routes() {
		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/layouts/default',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_default_layout' ],
				'permission_callback' => [ __CLASS__, 'can_create_layout' ],
				'args'                => [
					'content' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * Whether the current user can create and publish patterns.
	 *
	 * @return bool
	 */
	public static function can_create_layout(): bool {
		$post_type = get_post_type_object( 'wp_block' );

		return $post_type && current_user_can( $post_type->cap->create_posts ) && current_user_can( $post_type->cap->publish_posts );
	}

	/**
	 * REST callback: returns the default layout, creating it from the posted
	 * markup when none resolves. The editor supplies the markup because its
	 * template is the one core validates the pattern's blocks against.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_default_layout( WP_REST_Request $request ) {
		$existing = self::get_default_layout_id();

		if ( $existing ) {
			return new WP_REST_Response( [ 'id' => $existing ], 200 );
		}

		$content = (string) $request->get_param( 'content' );
		$blocks  = array_values(
			array_filter(
				parse_blocks( $content ),
				static fn( $block ) => ! empty( $block['blockName'] )
			)
		);

		if ( 1 !== count( $blocks ) || Rolling_Coverage_Block::BLOCK_NAME !== $blocks[0]['blockName'] ) {
			return new WP_Error(
				'rolling_coverage_invalid_layout',
				/* translators: %s: Rolling Coverage, the product name. */
				sprintf( __( 'The layout must be a single %s block.', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
				[ 'status' => 400 ]
			);
		}

		$layout_id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => 'wp_block',
					'post_status'  => 'publish',
					/* translators: %s: Rolling Coverage, the product name. */
					'post_title'   => sprintf( __( '%s layout', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
					'post_content' => $content,
				]
			),
			true
		);

		if ( is_wp_error( $layout_id ) ) {
			return $layout_id;
		}

		self::assign_pattern_category( $layout_id );
		update_option( self::DEFAULT_OPTION, $layout_id, false );

		return new WP_REST_Response( [ 'id' => $layout_id ], 201 );
	}

	/**
	 * Files a layout under the Rolling Coverage pattern category, creating the
	 * category the first time.
	 *
	 * @param int $layout_id Pattern ID.
	 */
	private static function assign_pattern_category( int $layout_id ): void {
		$term = term_exists( self::PATTERN_CATEGORY, self::PATTERN_TAXONOMY );

		if ( ! $term ) {
			$term = wp_insert_term( 'Rolling Coverage', self::PATTERN_TAXONOMY, [ 'slug' => self::PATTERN_CATEGORY ] );
		}

		if ( is_wp_error( $term ) ) {
			return;
		}

		wp_set_object_terms( $layout_id, [ (int) $term['term_id'] ], self::PATTERN_TAXONOMY );
	}
}
