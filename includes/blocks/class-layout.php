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

	const BUILT_IN_SLUGS = [ 'default', 'stream', 'rail', 'clock', 'margin', 'minute', 'byline', 'wire', 'digest', 'flash' ];

	const SLUG_META_KEY = '_rolling_coverage_layout';

	const PATTERN_CATEGORY = 'rolling-coverage';

	const PATTERN_TAXONOMY = 'wp_pattern_category';

	/**
	 * Seconds after which a creation lock counts as abandoned.
	 */
	const LOCK_TIMEOUT = 30;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'render_block_data', [ __CLASS__, 'inject_layout' ], 10, 1 );
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
				return self::detach_nested_blocks( $block['innerBlocks'] ?? [] );
			}
		}

		return null;
	}

	/**
	 * Drops `layoutId` from every Rolling Coverage block nested in a layout.
	 * A synced block inside the layout would otherwise pull the layout into
	 * itself again at every level, without end.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return array[]
	 */
	private static function detach_nested_blocks( array $blocks ): array {
		foreach ( $blocks as $index => $block ) {
			if ( Rolling_Coverage_Block::BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
				unset( $blocks[ $index ]['attrs']['layoutId'] );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::detach_nested_blocks( $block['innerBlocks'] );
			}
		}

		return $blocks;
	}

	/**
	 * Swaps a synced block's inner blocks for its pattern's before core builds
	 * the block, so everything that reads the block's inner blocks sees the
	 * shared layout. An unusable pattern leaves no inner blocks, which renders
	 * the built-in default layout.
	 *
	 * Untyped: the filter runs for every block, and another callback may
	 * hand it something that isn't a block.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return array
	 */
	public static function inject_layout( $parsed_block ) {
		if ( ! is_array( $parsed_block ) || Rolling_Coverage_Block::BLOCK_NAME !== ( $parsed_block['blockName'] ?? '' ) ) {
			return $parsed_block;
		}

		$layout_id = (int) ( $parsed_block['attrs']['layoutId'] ?? 0 );

		if ( $layout_id <= 0 ) {
			if ( ! empty( $parsed_block['innerBlocks'] ) ) {
				$parsed_block['innerBlocks'] = self::detach_nested_blocks( $parsed_block['innerBlocks'] );
			}

			return $parsed_block;
		}

		$inner_blocks = self::get_layout_blocks( $layout_id ) ?? [];

		$parsed_block['innerBlocks']  = $inner_blocks;
		$parsed_block['innerHTML']    = '';
		$parsed_block['innerContent'] = array_fill( 0, count( $inner_blocks ), null );

		return $parsed_block;
	}

	/**
	 * The option that stores a built-in layout's pattern ID.
	 *
	 * @param string $slug Built-in layout slug.
	 * @return string
	 */
	public static function option_name( string $slug ): string {
		return "rolling_coverage_{$slug}_layout_id";
	}

	/**
	 * A built-in layout's pattern ID, when it still resolves.
	 *
	 * The option can read as missing under a persistent object cache when a
	 * concurrent request writes back a stale `notoptions` list, so a pattern
	 * tagged with the slug is the fallback, and repairs the option.
	 *
	 * @param string $slug Built-in layout slug.
	 * @return int Pattern ID, or 0.
	 */
	public static function get_layout_id( string $slug ): int {
		$layout_id = (int) get_option( self::option_name( $slug ), 0 );

		if ( null !== self::get_layout_blocks( $layout_id ) ) {
			return $layout_id;
		}

		$found = get_posts(
			[
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [
					[
						'key'   => self::SLUG_META_KEY,
						'value' => $slug,
					],
				],
			]
		);
		$found = $found ? (int) $found[0] : 0;

		if ( ! $found || null === self::get_layout_blocks( $found ) ) {
			return 0;
		}

		update_option( self::option_name( $slug ), $found, false );

		return $found;
	}

	/**
	 * The Rolling Coverage pattern category's term ID.
	 *
	 * @return int Term ID, or 0 when the category doesn't exist.
	 */
	public static function get_pattern_category_id(): int {
		$term = get_term_by( 'slug', self::PATTERN_CATEGORY, self::PATTERN_TAXONOMY );

		return $term ? (int) $term->term_id : 0;
	}

	/**
	 * Registers the route the editor creates the built-in layouts through.
	 */
	public static function register_routes() {
		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/layouts/(?P<slug>' . implode( '|', self::BUILT_IN_SLUGS ) . ')',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_layout' ],
				'permission_callback' => [ __CLASS__, 'can_create_layout' ],
				'args'                => [
					'slug'    => [
						'type' => 'string',
						'enum' => self::BUILT_IN_SLUGS,
					],
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
	 * REST callback: returns a built-in layout, creating it from the posted
	 * markup when none resolves. The editor supplies the markup because its
	 * template is the one core validates the pattern's blocks against.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_layout( WP_REST_Request $request ) {
		$slug     = (string) $request['slug'];
		$existing = self::get_layout_id( $slug );

		if ( $existing ) {
			return self::layout_response( $existing, 200 );
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

		if ( ! self::acquire_lock( $slug ) ) {
			return new WP_Error(
				'rolling_coverage_layout_locked',
				__( 'This layout is being created. Try again in a moment.', 'newspack-rolling-coverage' ),
				[ 'status' => 409 ]
			);
		}

		try {
			$existing = self::find_layout_id_uncached( $slug );

			if ( $existing ) {
				update_option( self::option_name( $slug ), $existing, false );
				return self::layout_response( $existing, 200 );
			}

			$layout_id = wp_insert_post(
				wp_slash(
					[
						'post_type'    => 'wp_block',
						'post_status'  => 'publish',
						'post_title'   => self::get_title( $slug ),
						'post_content' => $content,
					]
				),
				true
			);

			if ( is_wp_error( $layout_id ) ) {
				return $layout_id;
			}

			self::assign_pattern_category( $layout_id );
			update_post_meta( $layout_id, self::SLUG_META_KEY, $slug );
			update_option( self::option_name( $slug ), $layout_id, false );

			return self::layout_response( $layout_id, 201 );
		} finally {
			delete_option( self::lock_name( $slug ) );
		}
	}

	/**
	 * A built-in layout's ID, with the pattern category's ID so the editor
	 * can list the layouts once the first one has created the category.
	 *
	 * @param int $layout_id Pattern ID.
	 * @param int $status    HTTP status.
	 * @return WP_REST_Response
	 */
	private static function layout_response( int $layout_id, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'id'         => $layout_id,
				'categoryId' => self::get_pattern_category_id(),
			],
			$status
		);
	}

	/**
	 * A built-in layout's pattern ID, read from the database rather than this
	 * request's caches, which may predate another request creating it. The
	 * option's cached copies are cleared so later reads see the database.
	 *
	 * @param string $slug Built-in layout slug.
	 * @return int Pattern ID, or 0.
	 */
	private static function find_layout_id_uncached( string $slug ): int {
		global $wpdb;

		$option = self::option_name( $slug );

		wp_cache_delete( $option, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$from_option = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );

		if ( null !== self::get_layout_blocks( $from_option ) ) {
			return $from_option;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tagged = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'wp_block' AND p.post_status = 'publish' AND m.meta_key = %s AND m.meta_value = %s ORDER BY p.ID ASC LIMIT 1",
				self::SLUG_META_KEY,
				$slug
			)
		);

		return null !== self::get_layout_blocks( $tagged ) ? $tagged : 0;
	}

	/**
	 * The option that locks a built-in layout's creation.
	 *
	 * @param string $slug Built-in layout slug.
	 * @return string
	 */
	public static function lock_name( string $slug ): string {
		return "rolling_coverage_{$slug}_layout_lock";
	}

	/**
	 * Takes the lock on a built-in layout's creation, so two first-time
	 * requests can't both insert a pattern. The insert and the stale-lock
	 * takeover each go straight to the database, as `add_option()` overwrites
	 * an existing row and the object cache can hide one.
	 *
	 * @param string $slug Built-in layout slug.
	 * @return bool Whether the lock is now held.
	 */
	private static function acquire_lock( string $slug ): bool {
		global $wpdb;

		$name = self::lock_name( $slug );
		$now  = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $now ) );

		if ( $inserted ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$locked_at = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		if ( null === $locked_at ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (bool) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $now ) );
		}

		if ( (int) $locked_at > $now - self::LOCK_TIMEOUT ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $now, $name, $locked_at ) );
	}

	/**
	 * The title a built-in layout's pattern is created with.
	 *
	 * @param string $slug Built-in layout slug.
	 * @return string
	 */
	private static function get_title( string $slug ): string {
		return match ( $slug ) {
			/* translators: %s: Rolling Coverage, the product name. */
			'stream' => sprintf( __( '%s: Stream', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
			/* translators: %s: Rolling Coverage, the product name. */
			'rail'   => sprintf( __( '%s: Rail', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
			/* translators: %s: Rolling Coverage, the product name. */
			'clock'  => sprintf( __( '%s: Clock', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
			/* translators: %s: Rolling Coverage, the product name. */
			'margin' => sprintf( __( '%s: Margin', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
			/* translators: %s: Rolling Coverage, the product name. */
			'minute' => sprintf( __( '%s: Minute', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
			/* translators: %s: Rolling Coverage, the product name. */
			'byline' => sprintf( __( '%s: Byline', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
			/* translators: %s: Rolling Coverage, the product name. */
			'wire'   => sprintf( __( '%s: Wire', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
			/* translators: %s: Rolling Coverage, the product name. */
			'digest' => sprintf( __( '%s: Digest', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
			/* translators: %s: Rolling Coverage, the product name. */
			'flash'  => sprintf( __( '%s: Flash', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
			/* translators: %s: Rolling Coverage, the product name. */
			default  => sprintf( __( '%s: Bulletin', 'newspack-rolling-coverage' ), 'Rolling Coverage' ),
		};
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
			$existing_id = (int) $term->get_error_data( 'term_exists' );

			if ( ! $existing_id ) {
				return;
			}

			$term = [ 'term_id' => $existing_id ];
		}

		wp_set_object_terms( $layout_id, [ (int) $term['term_id'] ], self::PATTERN_TAXONOMY );
	}
}
