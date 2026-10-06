<?php
/**
 * Every published place that shows a coverage.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Finds every published place where the plugin's blocks show a coverage:
 * posts and pages, the active theme's templates and template parts, synced
 * patterns that published content uses, and block widgets. The scan runs
 * once after a change and is stored in an option, so reading it costs one
 * option lookup.
 */
class Placements {

	// Option holding the stored map.
	const OPTION = 'rolling_coverage_placements';

	// REST field on the coverage term listing its placements.
	const REST_FIELD = 'placements';

	// Blocks that can show a coverage.
	const BLOCKS = [
		Rolling_Coverage_Block::BLOCK_NAME,
		Coverage_Status_Block::BLOCK_NAME,
		Coverage_Follow_Block::BLOCK_NAME,
	];

	// Template slugs a breakout post can use, in the order WordPress looks for them.
	const SINGLE_POST_TEMPLATES = [ 'single-post', 'single', 'singular', 'index' ];

	// How many synced patterns deep the referrer search follows nesting.
	const MAX_PATTERN_DEPTH = 5;

	// How many published breakout posts the breakout check looks through.
	const MAX_BREAKOUT_POSTS = 500;

	// Place types.
	const TYPE_POST          = 'post';
	const TYPE_TEMPLATE      = 'wp_template';
	const TYPE_TEMPLATE_PART = 'wp_template_part';
	const TYPE_PATTERN       = 'wp_block';
	const TYPE_WIDGET_AREA   = 'widget_area';

	// Block tags, before they are turned into labels.
	const TAG_FULL   = 'full';
	const TAG_LATEST = 'latest';
	const TAG_STATUS = 'status';
	const TAG_FOLLOW = 'follow';

	/**
	 * Sites whose stored map this request changed since it last stored it,
	 * keyed by blog ID.
	 *
	 * @var array<int,true>
	 */
	private static $stale = [];

	/**
	 * The newest published breakout post of each coverage, worked out once
	 * per request.
	 *
	 * @var array<int,WP_Post>|null
	 */
	private static $breakout_posts = null;

	/**
	 * Whether this request has loaded the posts the stored map points at.
	 *
	 * @var bool
	 */
	private static $primed = false;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_field' ] );
		add_action( 'post_updated', [ __CLASS__, 'flush_on_update' ], 10, 3 );
		add_action( 'transition_post_status', [ __CLASS__, 'flush_on_status_change' ], 10, 3 );
		add_action( 'deleted_post', [ __CLASS__, 'flush_on_delete' ], 10, 2 );
		add_action( 'add_option_widget_block', [ __CLASS__, 'flush' ] );
		add_action( 'update_option_widget_block', [ __CLASS__, 'flush' ] );
		add_action( 'add_option_sidebars_widgets', [ __CLASS__, 'flush' ] );
		add_action( 'update_option_sidebars_widgets', [ __CLASS__, 'flush' ] );
		add_action( 'switch_theme', [ __CLASS__, 'flush' ] );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'flush' ] );
		add_action( 'added_term_meta', [ __CLASS__, 'flush_on_coverage_status' ], 10, 3 );
		add_action( 'updated_term_meta', [ __CLASS__, 'flush_on_coverage_status' ], 10, 3 );
		add_action( 'deleted_term_meta', [ __CLASS__, 'flush_on_coverage_status' ], 10, 3 );
		add_action( 'delete_' . Taxonomy::TAXONOMY_SLUG, [ __CLASS__, 'flush' ] );
	}

	/**
	 * Registers the placements REST field on the coverage term.
	 */
	public static function register_rest_field() {
		register_rest_field(
			Taxonomy::TAXONOMY_SLUG,
			self::REST_FIELD,
			[
				'get_callback' => [ __CLASS__, 'get_rest_field' ],
				'schema'       => [
					'description' => __( 'Published places that show the coverage, the main page first.', 'newspack-rolling-coverage' ),
					'type'        => 'array',
					'context'     => [ 'edit', 'view' ],
					'readonly'    => true,
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'       => [ 'type' => 'string' ],
							'title'    => [ 'type' => 'string' ],
							'type'     => [ 'type' => 'string' ],
							'tags'     => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
							'viewUrl'  => [ 'type' => 'string' ],
							'editUrl'  => [ 'type' => 'string' ],
							'isMain'   => [ 'type' => 'boolean' ],
							'breakout' => [ 'type' => 'boolean' ],
						],
					],
				],
			]
		);
	}

	/**
	 * REST field callback listing a coverage's placements.
	 *
	 * Only editors see them, so public term requests never pay for the lookup.
	 *
	 * @param array $term Coverage REST object data.
	 * @return array[]
	 */
	public static function get_rest_field( array $term ): array {
		if ( ! isset( $term['id'] ) || ! current_user_can( 'edit_posts' ) ) {
			return [];
		}

		return self::for_coverage( (int) $term['id'] );
	}

	/**
	 * The newest published post whose own content holds an uncapped Rolling
	 * Coverage block for a coverage. A capped block only shows a few entries
	 * and links to the coverage page, so it never makes a post that page.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return int Post ID, or 0 when there is none.
	 */
	public static function page_id( int $coverage_id ): int {
		return (int) ( self::get_map()['pages'][ $coverage_id ] ?? 0 );
	}

	/**
	 * Lists every published place that shows a coverage, one row per place,
	 * with the coverage's main page first.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return array[] Rows with id, title, type, tags, viewUrl, editUrl, isMain and breakout.
	 */
	public static function for_coverage( int $coverage_id ): array {
		$map  = self::get_map();
		$rows = [];

		self::prime( $map );

		foreach ( $map['places'][ $coverage_id ] ?? [] as $place ) {
			$row = self::resolve( $place );

			if ( $row ) {
				$rows[] = $row;
			}
		}

		$breakout_post = $map['breakout'] ? ( self::breakout_posts()[ $coverage_id ] ?? null ) : null;

		if ( $breakout_post ) {
			foreach ( $map['breakout'] as $place ) {
				$row = self::resolve( $place );

				if ( $row ) {
					$row['id']       = 'breakout:' . $row['id'];
					$row['type']     = self::breakout_type_label( $place['type'] );
					$row['viewUrl']  = (string) get_permalink( $breakout_post );
					$row['breakout'] = true;
					$rows[]          = $row;
				}
			}
		}

		return self::put_main_page_first( $rows, (string) get_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, true ) );
	}

	/**
	 * Marks the row whose page is the coverage's canonical URL as the main
	 * page and moves it to the top, or adds a row for the canonical URL when
	 * no place matches it.
	 *
	 * @param array[] $rows          Placement rows.
	 * @param string  $canonical_url Coverage's canonical URL.
	 * @return array[]
	 */
	private static function put_main_page_first( array $rows, string $canonical_url ): array {
		if ( '' === $canonical_url ) {
			return $rows;
		}

		$canonical = self::comparable_url( $canonical_url );

		foreach ( $rows as $index => $row ) {
			if ( ! $row['breakout'] && '' !== $row['viewUrl'] && self::comparable_url( $row['viewUrl'] ) === $canonical ) {
				$row['isMain'] = true;
				unset( $rows[ $index ] );
				array_unshift( $rows, $row );

				return array_values( $rows );
			}
		}

		array_unshift(
			$rows,
			[
				'id'       => 'canonical',
				'title'    => untrailingslashit( (string) preg_replace( '#^https?://#i', '', $canonical_url ) ),
				'type'     => '',
				'tags'     => [],
				'viewUrl'  => $canonical_url,
				'editUrl'  => '',
				'isMain'   => true,
				'breakout' => false,
			]
		);

		return $rows;
	}

	/**
	 * A URL reduced to its path and query. A canonical URL is always on this
	 * site, so they tell whether two links open the same page, whatever
	 * scheme or host name each was saved with.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function comparable_url( string $url ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		return '/' . trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Turns a stored place into a row, or null when it is no longer published.
	 *
	 * @param array $place Stored place.
	 * @return array|null
	 */
	private static function resolve( array $place ): ?array {
		$row = [
			'id'       => $place['type'] . ':' . $place['id'],
			'title'    => '',
			'type'     => '',
			'tags'     => array_map( [ __CLASS__, 'tag_label' ], $place['tags'] ),
			'viewUrl'  => '',
			'editUrl'  => '',
			'isMain'   => false,
			'breakout' => false,
		];

		switch ( $place['type'] ) {
			case self::TYPE_POST:
				$post = get_post( (int) $place['id'] );

				if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
					return null;
				}

				$post_type      = get_post_type_object( $post->post_type );
				$row['title']   = self::post_title( $post );
				$row['type']    = $post_type ? $post_type->labels->singular_name : '';
				$row['viewUrl'] = (string) get_permalink( $post );
				$row['editUrl'] = (string) get_edit_post_link( $post, 'raw' );
				break;

			case self::TYPE_TEMPLATE:
			case self::TYPE_TEMPLATE_PART:
				$row['title']   = $place['title'];
				$row['type']    = self::TYPE_TEMPLATE === $place['type'] ? __( 'Template', 'newspack-rolling-coverage' ) : __( 'Template part', 'newspack-rolling-coverage' );
				$row['viewUrl'] = self::TYPE_TEMPLATE === $place['type'] ? self::template_url( $place['slug'] ?? '' ) : '';
				$row['editUrl'] = current_user_can( 'edit_theme_options' ) ? self::site_editor_url( $place['type'], rawurlencode( (string) $place['id'] ) ) : '';
				break;

			case self::TYPE_PATTERN:
				$post = get_post( (int) $place['id'] );

				if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
					return null;
				}

				$row['title']   = self::post_title( $post );
				$row['type']    = __( 'Pattern', 'newspack-rolling-coverage' );
				$row['editUrl'] = current_user_can( 'edit_theme_options' ) ? self::site_editor_url( self::TYPE_PATTERN, (string) $post->ID ) : (string) get_edit_post_link( $post, 'raw' );
				break;

			case self::TYPE_WIDGET_AREA:
				global $wp_registered_sidebars;

				$row['title']   = (string) ( $wp_registered_sidebars[ $place['id'] ]['name'] ?? $place['id'] );
				$row['type']    = __( 'Widget area', 'newspack-rolling-coverage' );
				$row['editUrl'] = current_user_can( 'edit_theme_options' ) ? admin_url( 'widgets.php' ) : '';
				break;

			default:
				return null;
		}

		return $row;
	}

	/**
	 * A post's title as plain text.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function post_title( WP_Post $post ): string {
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );

		return '' !== $title ? $title : __( '(no title)', 'newspack-rolling-coverage' );
	}

	/**
	 * The Site Editor link that opens a template, template part or pattern.
	 *
	 * @param string $type Post type.
	 * @param string $id   Template ID, encoded, or pattern post ID.
	 * @return string
	 */
	private static function site_editor_url( string $type, string $id ): string {
		return admin_url( 'site-editor.php?p=/' . $type . '/' . $id . '&canvas=edit' );
	}

	/**
	 * The front-end address a template renders at, for the templates that
	 * render one page: the front page and the posts page.
	 *
	 * @param string $slug Template slug.
	 * @return string
	 */
	private static function template_url( string $slug ): string {
		if ( 'front-page' === $slug ) {
			return home_url( '/' );
		}

		if ( 'home' === $slug ) {
			$posts_page = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;

			return $posts_page ? (string) get_permalink( $posts_page ) : home_url( '/' );
		}

		return '';
	}

	/**
	 * The label shown for a block tag.
	 *
	 * @param string $tag Stored tag: full, latest:<layout ID>, status or follow.
	 * @return string
	 */
	private static function tag_label( string $tag ): string {
		if ( self::TAG_FULL === $tag ) {
			return __( 'Full', 'newspack-rolling-coverage' );
		}

		if ( self::TAG_STATUS === $tag ) {
			return __( 'Status', 'newspack-rolling-coverage' );
		}

		if ( self::TAG_FOLLOW === $tag ) {
			return __( 'Follow', 'newspack-rolling-coverage' );
		}

		$layout = get_post( (int) substr( $tag, strlen( self::TAG_LATEST ) + 1 ) );

		if ( $layout instanceof WP_Post && 'wp_block' === $layout->post_type && 'publish' === $layout->post_status && '' !== $layout->post_title ) {
			return html_entity_decode( wp_strip_all_tags( $layout->post_title ), ENT_QUOTES, 'UTF-8' );
		}

		return __( 'Latest', 'newspack-rolling-coverage' );
	}

	/**
	 * What a row that shows a coverage on its breakout posts is.
	 *
	 * @param string $type Place type.
	 * @return string
	 */
	private static function breakout_type_label( string $type ): string {
		if ( self::TYPE_TEMPLATE_PART === $type ) {
			return __( 'Template part, on this coverage’s breakout posts', 'newspack-rolling-coverage' );
		}

		if ( self::TYPE_WIDGET_AREA === $type ) {
			return __( 'Widget area, on this coverage’s breakout posts', 'newspack-rolling-coverage' );
		}

		return __( 'Template, on this coverage’s breakout posts', 'newspack-rolling-coverage' );
	}

	/**
	 * Loads every post the stored map points at in one query, so listing all
	 * coverages doesn't query post by post.
	 *
	 * @param array $map Stored map.
	 */
	private static function prime( array $map ): void {
		if ( self::$primed ) {
			return;
		}

		self::$primed = true;
		$ids          = [];

		foreach ( $map['places'] as $places ) {
			foreach ( $places as $place ) {
				if ( in_array( $place['type'], [ self::TYPE_POST, self::TYPE_PATTERN ], true ) ) {
					$ids[] = (int) $place['id'];
				}

				foreach ( $place['tags'] as $tag ) {
					if ( 0 === strpos( $tag, self::TAG_LATEST . ':' ) ) {
						$ids[] = (int) substr( $tag, strlen( self::TAG_LATEST ) + 1 );
					}
				}
			}
		}

		$ids = array_filter( array_unique( $ids ) );

		if ( $ids ) {
			_prime_post_caches( $ids, false, false );
		}
	}

	/**
	 * The newest published breakout post of each coverage, by the rule the
	 * Coverage Status and Follow Coverage blocks use to pick a breakout
	 * post's coverage.
	 *
	 * @return array<int,WP_Post> Map of coverage term ID => breakout post.
	 */
	private static function breakout_posts(): array {
		if ( null !== self::$breakout_posts ) {
			return self::$breakout_posts;
		}

		self::$breakout_posts = [];

		$entry_ids = get_posts(
			[
				'post_type'              => Post_Type::CPT_SLUG,
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX_BREAKOUT_POSTS,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'meta_key'               => Breakout::BREAKOUT_STATUS_FIELD, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => 'publish', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'suppress_filters'       => false,
			]
		);

		if ( ! $entry_ids ) {
			return self::$breakout_posts;
		}

		update_postmeta_cache( $entry_ids );
		update_object_term_cache( $entry_ids, Post_Type::CPT_SLUG );

		$breakout_ids = array_filter(
			array_map(
				function ( $entry_id ) {
					return (int) get_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, true );
				},
				$entry_ids
			)
		);

		if ( ! $breakout_ids ) {
			return self::$breakout_posts;
		}

		_prime_post_caches( $breakout_ids, false, true );

		foreach ( $breakout_ids as $breakout_id ) {
			$post = get_post( $breakout_id );

			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
				continue;
			}

			$coverage_id = Page_Coverages::breakout_coverage_id( $breakout_id );
			$newest      = self::$breakout_posts[ $coverage_id ] ?? null;

			if ( $coverage_id && ( ! $newest || $post->post_date > $newest->post_date ) ) {
				self::$breakout_posts[ $coverage_id ] = $post;
			}
		}

		return self::$breakout_posts;
	}

	/**
	 * Invalidates the map when a published post, template, template part or
	 * pattern showing a coverage changes, or a post stops showing one. Other
	 * writes, such as comment counts, entries and drafts, leave it as it was.
	 *
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post_after  Post after the update.
	 * @param WP_Post $post_before Post before the update.
	 */
	public static function flush_on_update( $post_id, $post_after, $post_before ): void {
		if ( self::shows_blocks( $post_after ) || self::shows_blocks( $post_before ) ) {
			self::flush();
		}
	}

	/**
	 * Invalidates the map when a post showing a coverage is published or
	 * unpublished, including a scheduled post going live, which changes its
	 * status without an update.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function flush_on_status_change( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && in_array( 'publish', [ $new_status, $old_status ], true ) && self::holds_blocks( $post ) ) {
			self::flush();
		}
	}

	/**
	 * Invalidates the map when a published post showing a coverage is
	 * deleted without going through the trash.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function flush_on_delete( $post_id, $post = null ): void {
		if ( self::shows_blocks( $post ) ) {
			self::flush();
		}
	}

	/**
	 * Invalidates the map when a coverage's status changes: a Coverage Status
	 * or Follow Coverage block set to a trashed coverage falls back to the
	 * page's own one.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $term_id  Term ID.
	 * @param string $meta_key Meta key.
	 */
	public static function flush_on_coverage_status( $meta_id, $term_id, $meta_key ): void {
		if ( Taxonomy::STATUS_META_KEY === $meta_key ) {
			self::flush();
		}
	}

	/**
	 * Marks the current site's map out of date, and has the request rebuild
	 * the stored one once it ends. Readers keep the stored map until then,
	 * so they never rebuild it themselves, and the request that made the
	 * change, which is sure to see it, writes last.
	 */
	public static function flush(): void {
		self::$stale[ get_current_blog_id() ] = true;
		self::$breakout_posts                 = null;
		self::$primed                         = false;

		if ( ! has_action( 'shutdown', [ __CLASS__, 'rebuild' ] ) ) {
			add_action( 'shutdown', [ __CLASS__, 'rebuild' ] );
		}
	}

	/**
	 * Rebuilds the stored map of every site this request changed since it
	 * last read it.
	 */
	public static function rebuild(): void {
		foreach ( array_keys( self::$stale ) as $blog_id ) {
			$switched = get_current_blog_id() !== $blog_id && switch_to_blog( $blog_id );

			self::get_map();

			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Whether a post is published and shows one of the blocks, so the map
	 * may list it.
	 *
	 * @param mixed $post Post object.
	 * @return bool
	 */
	private static function shows_blocks( $post ): bool {
		return $post instanceof WP_Post && 'publish' === $post->post_status && self::holds_blocks( $post );
	}

	/**
	 * Whether a post can be a place and its content holds one of the blocks
	 * or uses a synced pattern that does.
	 *
	 * @param mixed $post Post object.
	 * @return bool
	 */
	private static function holds_blocks( $post ): bool {
		if ( ! $post instanceof WP_Post || ! self::can_hold_blocks( $post->post_type ) ) {
			return false;
		}

		foreach ( self::BLOCKS as $block_name ) {
			if ( false !== strpos( $post->post_content, '<!-- wp:' . $block_name . ' ' ) ) {
				return true;
			}
		}

		if ( false === strpos( $post->post_content, '<!-- wp:block ' ) ) {
			return false;
		}

		$stored = get_option( self::OPTION );
		$known  = is_array( $stored ) ? ( $stored['patterns'] ?? [] ) : [];

		return (bool) array_intersect( self::pattern_refs( $post->post_content ), $known );
	}

	/**
	 * Whether posts of a type can be a place: viewable posts other than
	 * entries and attachments, templates, template parts and patterns.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private static function can_hold_blocks( string $post_type ): bool {
		return in_array( $post_type, [ self::TYPE_TEMPLATE, self::TYPE_TEMPLATE_PART, self::TYPE_PATTERN ], true ) || self::can_be_page( $post_type );
	}

	/**
	 * Whether posts of a type have a page of their own that can show a coverage.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private static function can_be_page( string $post_type ): bool {
		return Post_Type::CPT_SLUG !== $post_type && 'attachment' !== $post_type && is_post_type_viewable( $post_type );
	}

	/**
	 * The synced patterns a post's content refers to.
	 *
	 * @param string $content Post content.
	 * @return int[]
	 */
	private static function pattern_refs( string $content ): array {
		preg_match_all( '/<!-- wp:block \{[^}]*"ref":(\d+)/', $content, $matches );

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * Returns the stored map, building and storing it when it is missing or
	 * this request changed it.
	 *
	 * @return array{pages: array<int,int>, places: array<int,array[]>, breakout: array[], patterns: int[]}
	 */
	private static function get_map(): array {
		$stored = isset( self::$stale[ get_current_blog_id() ] ) ? false : get_option( self::OPTION );

		if ( is_array( $stored ) && isset( $stored['pages'], $stored['places'], $stored['breakout'], $stored['patterns'] ) ) {
			return $stored;
		}

		$map = self::build();

		update_option( self::OPTION, $map, false );
		unset( self::$stale[ get_current_blog_id() ] );

		return $map;
	}

	/**
	 * Scans every published place for the blocks.
	 *
	 * @return array{pages: array<int,int>, places: array<int,array[]>, breakout: array[], patterns: int[]}
	 */
	private static function build(): array {
		$map = [
			'pages'    => [],
			'places'   => [],
			'breakout' => [],
			'patterns' => [],
		];

		$patterns = self::patterns_with_blocks();
		$refs     = [];

		$map['patterns'] = array_keys( $patterns );

		foreach ( self::posts_to_scan( array_keys( $patterns ) ) as $post ) {
			$found = self::scan( $post->post_content );
			$refs += $found['refs'];

			if ( $found['automatic'] ) {
				$coverage_id = Page_Coverages::feed_coverage_ids( (int) $post->ID )[0] ?? Page_Coverages::breakout_coverage_id( (int) $post->ID );

				if ( $coverage_id ) {
					self::add_tags( $found['coverages'], $coverage_id, array_keys( $found['automatic'] ) );
				}
			}

			foreach ( $found['coverages'] as $coverage_id => $tags ) {
				$map['places'][ $coverage_id ][] = [
					'type' => self::TYPE_POST,
					'id'   => (int) $post->ID,
					'tags' => array_keys( $tags ),
				];

				if ( isset( $tags[ self::TAG_FULL ] ) && ! isset( $map['pages'][ $coverage_id ] ) ) {
					$map['pages'][ $coverage_id ] = (int) $post->ID;
				}
			}
		}

		$templates = self::scan_templates();

		foreach ( $templates as $template ) {
			$refs += $template['found']['refs'];
			self::add_places( $map, $template['place'], $template['found'] );

			if ( $template['found']['automatic'] && $template['single'] ) {
				$map['breakout'][] = $template['place'] + [ 'tags' => self::sort_tags( array_keys( $template['found']['automatic'] ) ) ];
			}
		}

		foreach ( self::scan_widget_areas() as $sidebar_id => $found ) {
			$refs += $found['refs'];
			$place = [
				'type' => self::TYPE_WIDGET_AREA,
				'id'   => $sidebar_id,
			];

			self::add_places( $map, $place, $found );

			if ( $found['automatic'] ) {
				$map['breakout'][] = $place + [ 'tags' => self::sort_tags( array_keys( $found['automatic'] ) ) ];
			}
		}

		foreach ( $patterns as $pattern_id => $found ) {
			if ( isset( $refs[ $pattern_id ] ) ) {
				self::add_places(
					$map,
					[
						'type' => self::TYPE_PATTERN,
						'id'   => $pattern_id,
					],
					$found
				);
			}
		}

		foreach ( $map['places'] as $coverage_id => $places ) {
			foreach ( $places as $index => $place ) {
				$map['places'][ $coverage_id ][ $index ]['tags'] = self::sort_tags( $place['tags'] );
			}
		}

		return $map;
	}

	/**
	 * Adds a place to every coverage its own blocks show.
	 *
	 * @param array $map   Map being built.
	 * @param array $place Place, without tags.
	 * @param array $found What the scan found in it.
	 */
	private static function add_places( array &$map, array $place, array $found ): void {
		foreach ( $found['coverages'] as $coverage_id => $tags ) {
			$map['places'][ $coverage_id ][] = $place + [ 'tags' => array_keys( $tags ) ];
		}
	}

	/**
	 * Orders tags as the row shows them: feeds first, then Status and Follow.
	 *
	 * @param string[] $tags Tags.
	 * @return string[]
	 */
	private static function sort_tags( array $tags ): array {
		$rank = function ( string $tag ): int {
			if ( self::TAG_FULL === $tag ) {
				return 0;
			}

			if ( self::TAG_STATUS === $tag ) {
				return 2;
			}

			return self::TAG_FOLLOW === $tag ? 3 : 1;
		};

		usort(
			$tags,
			function ( $a, $b ) use ( $rank ) {
				return $rank( $a ) - $rank( $b );
			}
		);

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Adds tags to a coverage in a scan result.
	 *
	 * @param array    $coverages Map of coverage ID => tag set.
	 * @param int      $coverage_id Coverage term ID.
	 * @param string[] $tags        Tags.
	 */
	private static function add_tags( array &$coverages, int $coverage_id, array $tags ): void {
		foreach ( $tags as $tag ) {
			$coverages[ $coverage_id ][ $tag ] = true;
		}
	}

	/**
	 * Published synced patterns holding one of the blocks, with what each
	 * shows. Built-in layouts hold a Rolling Coverage block without a
	 * coverage, so they show nothing and are left out.
	 *
	 * @return array<int,array> Map of pattern ID => scan result.
	 */
	private static function patterns_with_blocks(): array {
		global $wpdb;

		$patterns = [];
		$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = '' AND (" . self::blocks_like_clause() . ')',
				array_merge( [ self::TYPE_PATTERN ], self::blocks_like_values() )
			)
		);

		foreach ( $rows as $row ) {
			$found = self::scan( $row->post_content );

			if ( $found['coverages'] || $found['automatic'] ) {
				$patterns[ (int) $row->ID ] = $found;
			}
		}

		return $patterns;
	}

	/**
	 * Published posts that may show a coverage, newest first: those whose
	 * content holds one of the blocks, and those using one of the given
	 * synced patterns, directly or through other patterns.
	 *
	 * @param int[] $pattern_ids Synced patterns holding one of the blocks.
	 * @return object[] Rows with ID and post_content.
	 */
	private static function posts_to_scan( array $pattern_ids ): array {
		global $wpdb;

		$post_types = array_values( array_filter( get_post_types(), [ __CLASS__, 'can_be_page' ] ) );

		if ( ! $post_types ) {
			return [];
		}

		$referrer_ids = self::pattern_referrers( $pattern_ids, $post_types );
		$type_holders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$id_clause    = $referrer_ids ? ' OR ID IN (' . implode( ',', array_map( 'intval', $referrer_ids ) ) . ')' : '';

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ({$type_holders}) AND ((" . self::blocks_like_clause() . "){$id_clause}) ORDER BY post_date DESC, ID DESC",
				array_merge( $post_types, self::blocks_like_values() )
			)
		);
	}

	/**
	 * Published posts that use any of the given synced patterns, directly or
	 * through other synced patterns.
	 *
	 * @param int[]    $pattern_ids Synced pattern IDs.
	 * @param string[] $post_types  Post types that can be a place.
	 * @return int[]
	 */
	private static function pattern_referrers( array $pattern_ids, array $post_types ): array {
		global $wpdb;

		$referrers = [];
		$seen      = array_fill_keys( $pattern_ids, true );
		$frontier  = $pattern_ids;
		$types     = array_merge( $post_types, [ self::TYPE_PATTERN ] );

		for ( $depth = 0; $frontier && $depth < self::MAX_PATTERN_DEPTH; $depth++ ) {
			$likes  = [];
			$values = $types;

			foreach ( $frontier as $pattern_id ) {
				$likes[]  = 'post_content LIKE %s OR post_content LIKE %s';
				$values[] = '%' . $wpdb->esc_like( '"ref":' . $pattern_id . '}' ) . '%';
				$values[] = '%' . $wpdb->esc_like( '"ref":' . $pattern_id . ',' ) . '%';
			}

			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared
					"SELECT ID, post_type FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND (' . implode( ' OR ', $likes ) . ')',
					$values
				)
			);

			$frontier = [];

			foreach ( $rows as $row ) {
				$id = (int) $row->ID;

				if ( self::TYPE_PATTERN !== $row->post_type ) {
					$referrers[ $id ] = $id;
				} elseif ( ! isset( $seen[ $id ] ) ) {
					$seen[ $id ] = true;
					$frontier[]  = $id;
				}
			}
		}

		return array_values( $referrers );
	}

	/**
	 * SQL matching content that holds one of the blocks.
	 *
	 * @return string
	 */
	private static function blocks_like_clause(): string {
		return implode( ' OR ', array_fill( 0, count( self::BLOCKS ), 'post_content LIKE %s' ) );
	}

	/**
	 * Values for blocks_like_clause().
	 *
	 * @return string[]
	 */
	private static function blocks_like_values(): array {
		global $wpdb;

		return array_map(
			function ( $block_name ) use ( $wpdb ) {
				return '%' . $wpdb->esc_like( '<!-- wp:' . $block_name . ' ' ) . '%';
			},
			self::BLOCKS
		);
	}

	/**
	 * Scans the active theme's published templates and template parts (core
	 * leaves out customized ones that aren't published), and
	 * marks those a breakout post renders with: the single post template
	 * and the template parts it holds.
	 *
	 * @return array[] Items with place, found and single.
	 */
	private static function scan_templates(): array {
		$items = [];
		$types = [];

		if ( wp_is_block_theme() || current_theme_supports( 'block-templates' ) ) {
			$types[] = self::TYPE_TEMPLATE;
		}

		if ( wp_is_block_theme() || current_theme_supports( 'block-template-parts' ) ) {
			$types[] = self::TYPE_TEMPLATE_PART;
		}

		$parts = [];

		foreach ( $types as $type ) {
			foreach ( get_block_templates( [], $type ) as $template ) {
				$key           = $type . ':' . $template->slug;
				$items[ $key ] = [
					'place'  => [
						'type'  => $type,
						'id'    => $template->id,
						'slug'  => $template->slug,
						'title' => html_entity_decode( wp_strip_all_tags( (string) $template->title ), ENT_QUOTES, 'UTF-8' ),
					],
					'found'  => self::scan( (string) $template->content ),
					'single' => false,
				];

				if ( self::TYPE_TEMPLATE_PART === $type ) {
					$parts[ $template->slug ] = $key;
				}
			}
		}

		foreach ( self::SINGLE_POST_TEMPLATES as $slug ) {
			$key = self::TYPE_TEMPLATE . ':' . $slug;

			if ( isset( $items[ $key ] ) ) {
				$queue = [ $key ];

				while ( $queue ) {
					$current = array_shift( $queue );

					if ( $items[ $current ]['single'] ) {
						continue;
					}

					$items[ $current ]['single'] = true;

					foreach ( array_keys( $items[ $current ]['found']['parts'] ) as $part_slug ) {
						if ( isset( $parts[ $part_slug ] ) ) {
							$queue[] = $parts[ $part_slug ];
						}
					}
				}

				break;
			}
		}

		return array_values( $items );
	}

	/**
	 * Scans the block widgets of each registered widget area. Reads the
	 * stored widgets rather than wp_get_sidebars_widgets(), which can hand a
	 * front-end request the copy it loaded before the change.
	 *
	 * @return array<string,array> Map of widget area ID => scan result.
	 */
	private static function scan_widget_areas(): array {
		$areas     = [];
		$instances = get_option( 'widget_block', [] );

		if ( ! is_array( $instances ) ) {
			return $areas;
		}

		$sidebars = get_option( 'sidebars_widgets', [] );

		if ( ! is_array( $sidebars ) ) {
			return $areas;
		}

		foreach ( $sidebars as $sidebar_id => $widget_ids ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widget_ids ) || ! is_registered_sidebar( $sidebar_id ) ) {
				continue;
			}

			$content = '';

			foreach ( $widget_ids as $widget_id ) {
				if ( preg_match( '/^block-(\d+)$/', (string) $widget_id, $matches ) ) {
					$content .= (string) ( $instances[ (int) $matches[1] ]['content'] ?? '' );
				}
			}

			if ( '' !== $content ) {
				$found = self::scan( $content );

				if ( $found['coverages'] || $found['automatic'] || $found['refs'] ) {
					$areas[ (string) $sidebar_id ] = $found;
				}
			}
		}

		return $areas;
	}

	/**
	 * Scans block content for what it shows.
	 *
	 * @param string $content Block content.
	 * @return array{coverages: array<int,array<string,true>>, automatic: array<string,true>, refs: array<int,true>, parts: array<string,true>}
	 */
	private static function scan( string $content ): array {
		$found = [
			'coverages' => [],
			'automatic' => [],
			'refs'      => [],
			'parts'     => [],
		];

		self::walk( parse_blocks( $content ), $found, false, [] );

		return $found;
	}

	/**
	 * Walks parsed blocks, recording the coverages the place's own blocks
	 * show, its Automatic Status and Follow Coverage blocks, the synced
	 * patterns it uses and the template parts it holds.
	 *
	 * Blocks inside a Rolling Coverage block are part of its layout and show
	 * its coverage, so the walk doesn't go into one. A synced pattern is a
	 * place of its own, so inside one only Automatic blocks count: they show
	 * the coverage of the page the pattern sits in.
	 *
	 * @param array[] $blocks     Parsed blocks.
	 * @param array   $found      Scan result, updated in place.
	 * @param bool    $in_pattern Whether the walk is inside a synced pattern.
	 * @param array   $seen       Synced patterns and theme patterns already walked.
	 */
	private static function walk( array $blocks, array &$found, bool $in_pattern, array $seen ): void {
		foreach ( $blocks as $block ) {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

			if ( Rolling_Coverage_Block::BLOCK_NAME === $name ) {
				$coverage_id = (int) ( $attrs['coverageId'] ?? 0 );

				if ( ! $in_pattern && $coverage_id > 0 ) {
					$tag = empty( $attrs['latestOnly'] ) ? self::TAG_FULL : self::TAG_LATEST . ':' . (int) ( $attrs['layoutId'] ?? 0 );
					self::add_tags( $found['coverages'], $coverage_id, [ $tag ] );
				}

				continue;
			}

			if ( Coverage_Status_Block::BLOCK_NAME === $name || Coverage_Follow_Block::BLOCK_NAME === $name ) {
				$tag    = Coverage_Status_Block::BLOCK_NAME === $name ? self::TAG_STATUS : self::TAG_FOLLOW;
				$chosen = (int) ( $attrs['coverageId'] ?? 0 );

				if ( Page_Coverages::is_followable( $chosen ) ) {
					if ( ! $in_pattern ) {
						self::add_tags( $found['coverages'], $chosen, [ $tag ] );
					}
				} else {
					$found['automatic'][ $tag ] = true;
				}

				continue;
			}

			if ( 'core/block' === $name ) {
				$ref = (int) ( $attrs['ref'] ?? 0 );

				if ( $ref && ! isset( $seen[ 'ref:' . $ref ] ) ) {
					$found['refs'][ $ref ] = true;
					$pattern               = get_post( $ref );

					if ( $pattern instanceof WP_Post && self::TYPE_PATTERN === $pattern->post_type && 'publish' === $pattern->post_status && '' === $pattern->post_password ) {
						self::walk( parse_blocks( $pattern->post_content ), $found, true, $seen + [ 'ref:' . $ref => true ] );
					}
				}

				continue;
			}

			if ( 'core/pattern' === $name ) {
				$slug    = (string) ( $attrs['slug'] ?? '' );
				$pattern = '' !== $slug && ! isset( $seen[ 'slug:' . $slug ] ) ? \WP_Block_Patterns_Registry::get_instance()->get_registered( $slug ) : null;

				if ( is_array( $pattern ) && ! empty( $pattern['content'] ) ) {
					self::walk( parse_blocks( $pattern['content'] ), $found, $in_pattern, $seen + [ 'slug:' . $slug => true ] );
				}

				continue;
			}

			if ( 'core/template-part' === $name && ! empty( $attrs['slug'] ) ) {
				$found['parts'][ (string) $attrs['slug'] ] = true;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $found, $in_pattern, $seen );
			}
		}
	}
}
