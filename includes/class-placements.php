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
 * in a scheduled event after a change and is stored in an option, so
 * reading it costs one option lookup and saving a post never waits on it.
 */
class Placements {

	// Option holding the stored map.
	const OPTION = 'rolling_coverage_placements';

	// Option set while the stored map is out of date, holding a token per change.
	const STALE_OPTION = 'rolling_coverage_placements_stale';

	// Scheduled event that rebuilds the map.
	const REBUILD_HOOK = 'newspack_rolling_coverage_rebuild_placements';

	// Option row held while a rebuild runs, holding when it expires.
	const LOCK_OPTION = 'rolling_coverage_placements_lock';

	// Seconds after which a rebuild's lock counts as abandoned.
	const LOCK_TTL = 300;

	// How many posts a rebuild reads at a time.
	const SCAN_BATCH = 100;

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
	 * The newest published breakout post of each coverage, worked out once
	 * per request and site, keyed by blog ID.
	 *
	 * @var array<int,array<int,WP_Post>>
	 */
	private static $breakout_posts = [];

	/**
	 * Sites whose map this request has already checked or rebuilt since its
	 * last change, keyed by blog ID.
	 *
	 * @var array<int,true>
	 */
	private static $fresh = [];

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
		add_action( 'after_switch_theme', [ __CLASS__, 'flush' ] );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'flush_on_upgrade' ], 10, 2 );
		add_action( 'added_term_meta', [ __CLASS__, 'flush_on_added_coverage_status' ], 10, 4 );
		add_action( 'update_term_meta', [ __CLASS__, 'flush_on_coverage_status_change' ], 10, 4 );
		add_action( 'delete_term_meta', [ __CLASS__, 'flush_on_deleted_coverage_status' ], 10, 3 );
		add_action( 'delete_' . Taxonomy::TAXONOMY_SLUG, [ __CLASS__, 'flush' ] );
		add_action( 'set_object_terms', [ __CLASS__, 'flush_on_breakout_entry_terms' ], 10, 6 );
		add_action( self::REBUILD_HOOK, [ __CLASS__, 'rebuild' ] );
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
	 * Only editors see them, and only when the request names the field in
	 * `_fields`, so public term requests and the block editor's coverage
	 * lookups never pay for it.
	 *
	 * @param array                 $term       Coverage REST object data.
	 * @param string                $field_name Field name.
	 * @param \WP_REST_Request|null $request    Request.
	 * @return array[]
	 */
	public static function get_rest_field( array $term, $field_name = self::REST_FIELD, $request = null ): array {
		if ( ! isset( $term['id'] ) || ! current_user_can( 'edit_posts' ) || ! $request instanceof \WP_REST_Request ) {
			return [];
		}

		if ( ! in_array( self::REST_FIELD, wp_parse_list( (string) $request['_fields'] ), true ) ) {
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
	 * Rebuilds the stored map now when it is out of date, missing, or was
	 * built for another theme. For admin reads, which must show what was just
	 * saved; the front end keeps the stored map until the scheduled rebuild.
	 * Checks once per request until the next change. When another rebuild
	 * holds the lock, the stored map is used as it is.
	 */
	public static function ensure_fresh(): void {
		$blog_id = get_current_blog_id();

		if ( isset( self::$fresh[ $blog_id ] ) ) {
			return;
		}

		self::$fresh[ $blog_id ] = true;
		$stored                  = get_option( self::OPTION );

		if ( null !== self::read_token() || ! self::is_map( $stored ) || self::theme_signature() !== $stored['theme'] ) {
			self::rebuild_locked();
		}
	}

	/**
	 * Lists every published place that shows a coverage, one row per place,
	 * with the coverage's main page first. Rebuilds an out-of-date map first,
	 * since only the admin reads this.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return array[] Rows with id, title, type, tags, viewUrl, editUrl, isMain and breakout.
	 */
	public static function for_coverage( int $coverage_id ): array {
		self::ensure_fresh();

		$map  = self::get_map();
		$rows = [];

		self::prime( $map['places'][ $coverage_id ] ?? [] );

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
	 * scheme, host name or percent-encoding each was saved with.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function comparable_url( string $url ): string {
		$path  = strtolower( rawurldecode( trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) ) );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		return '/' . $path . ( '' !== $query ? '?' . $query : '' );
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
				$row['editUrl'] = wp_is_block_theme() && current_user_can( 'edit_theme_options' ) ? self::site_editor_url( self::TYPE_PATTERN, (string) $post->ID ) : (string) get_edit_post_link( $post, 'raw' );
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
	 * Loads the posts and layouts a coverage's places point at in one query.
	 *
	 * @param array[] $places Stored places of one coverage.
	 */
	private static function prime( array $places ): void {
		$ids = [];

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
		$blog_id = get_current_blog_id();

		if ( ! isset( self::$breakout_posts[ $blog_id ] ) ) {
			self::$breakout_posts[ $blog_id ] = self::find_breakout_posts();
		}

		return self::$breakout_posts[ $blog_id ];
	}

	/**
	 * Works out breakout_posts() for the current site.
	 *
	 * @return array<int,WP_Post> Map of coverage term ID => breakout post.
	 */
	private static function find_breakout_posts(): array {
		$breakout_posts = [];

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
			return $breakout_posts;
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
			return $breakout_posts;
		}

		_prime_post_caches( $breakout_ids, false, true );

		foreach ( $breakout_ids as $breakout_id ) {
			$post = get_post( $breakout_id );

			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
				continue;
			}

			$coverage_id = Page_Coverages::breakout_coverage_id( $breakout_id );
			$newest      = $breakout_posts[ $coverage_id ] ?? null;

			if ( $coverage_id && ( ! $newest || $post->post_date > $newest->post_date ) ) {
				$breakout_posts[ $coverage_id ] = $post;
			}
		}

		return $breakout_posts;
	}

	/**
	 * Invalidates the map when a save changes what a published post or
	 * pattern shows, or a post starts or stops showing a coverage. Saves that
	 * leave it showing the same, such as a new title or a typo fix around a
	 * capped feed, keep the map. Templates are handled on the status change
	 * every save fires.
	 *
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post_after  Post after the update.
	 * @param WP_Post $post_before Post before the update.
	 */
	public static function flush_on_update( $post_id, $post_after, $post_before ): void {
		if ( self::signature( $post_after ) !== self::signature( $post_before ) ) {
			self::flush();
		}
	}

	/**
	 * Invalidates the map when a post showing a coverage is published or
	 * unpublished, including a scheduled post going live, which changes its
	 * status without an update, and whenever a template or template part is
	 * saved, whatever it holds, including a theme template's first
	 * customization: they are saved rarely, and which of them a breakout
	 * post renders with depends on more than their own content.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function flush_on_status_change( $new_status, $old_status, $post ): void {
		if ( self::is_template( $post ) || ( $new_status !== $old_status && in_array( 'publish', [ $new_status, $old_status ], true ) && self::holds_blocks( $post ) ) ) {
			self::flush();
		}
	}

	/**
	 * Invalidates the map when a published post showing a coverage is
	 * deleted without going through the trash, or a template or template
	 * part is deleted, which can hand its slug back to the theme's file.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function flush_on_delete( $post_id, $post = null ): void {
		if ( self::is_template( $post ) || self::shows_blocks( $post ) ) {
			self::flush();
		}
	}

	/**
	 * Invalidates the map when a theme or plugin is updated, since theme
	 * files hold templates and plugins can register them. Translation
	 * updates keep it.
	 *
	 * @param mixed $upgrader   Upgrader instance.
	 * @param mixed $hook_extra Details of the update.
	 */
	public static function flush_on_upgrade( $upgrader, $hook_extra = [] ): void {
		if ( is_array( $hook_extra ) && in_array( $hook_extra['type'] ?? '', [ 'theme', 'plugin' ], true ) ) {
			self::flush();
		}
	}

	/**
	 * Invalidates the map when a coverage is first given a trashed status. A
	 * Coverage Status or Follow Coverage block set to a trashed coverage
	 * falls back to the page's own one.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $term_id    Term ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function flush_on_added_coverage_status( $meta_id, $term_id, $meta_key, $meta_value ): void {
		if ( Taxonomy::STATUS_META_KEY === $meta_key && 'trash' === $meta_value ) {
			self::flush();
		}
	}

	/**
	 * Invalidates the map when a coverage moves into or out of the trash.
	 * Runs before the change is saved, so it can read the old status.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $term_id    Term ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New meta value.
	 */
	public static function flush_on_coverage_status_change( $meta_id, $term_id, $meta_key, $meta_value ): void {
		if ( Taxonomy::STATUS_META_KEY === $meta_key && ( 'trash' === get_term_meta( (int) $term_id, $meta_key, true ) ) !== ( 'trash' === $meta_value ) ) {
			self::flush();
		}
	}

	/**
	 * Invalidates the map when a trashed status is removed from a coverage.
	 * Runs before the change is saved, so it can read the old status.
	 *
	 * @param int[]  $meta_ids Meta IDs.
	 * @param int    $term_id  Term ID.
	 * @param string $meta_key Meta key.
	 */
	public static function flush_on_deleted_coverage_status( $meta_ids, $term_id, $meta_key ): void {
		if ( Taxonomy::STATUS_META_KEY === $meta_key && 'trash' === get_term_meta( (int) $term_id, $meta_key, true ) ) {
			self::flush();
		}
	}

	/**
	 * Invalidates the map when an entry with a breakout post moves to
	 * another coverage, since an Automatic block in that post follows it.
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Terms set.
	 * @param int[]  $tt_ids     New term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Whether the terms were appended.
	 * @param int[]  $old_tt_ids Old term taxonomy IDs.
	 */
	public static function flush_on_breakout_entry_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( Taxonomy::TAXONOMY_SLUG !== $taxonomy ) {
			return;
		}

		$new = array_map( 'intval', (array) $tt_ids );
		$old = array_map( 'intval', (array) $old_tt_ids );
		sort( $new );
		sort( $old );

		if ( $new !== $old && get_post_meta( (int) $object_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, true ) ) {
			self::flush();
		}
	}

	/**
	 * Marks the current site's map out of date, and has the request schedule
	 * one rebuild as it ends, after the change's own writes. Readers keep the
	 * stored map until then; admin reads rebuild it at once. Each change
	 * stores a new token, written past the object cache so a long request
	 * whose cached copy went stale still marks it, and a rebuild that
	 * started before the change leaves the map marked out of date.
	 *
	 * While switched to another site, that site's rebuild is scheduled at
	 * once, in its own cron, since the request may not be on it as it ends.
	 */
	public static function flush(): void {
		$blog_id = get_current_blog_id();

		unset( self::$breakout_posts[ $blog_id ], self::$fresh[ $blog_id ] );
		self::write_option( self::STALE_OPTION, uniqid( '', true ) );

		if ( is_multisite() && ms_is_switched() ) {
			self::schedule_rebuild();
			return;
		}

		if ( ! has_action( 'shutdown', [ __CLASS__, 'schedule_rebuild' ] ) ) {
			add_action( 'shutdown', [ __CLASS__, 'schedule_rebuild' ] );
		}
	}

	/**
	 * Schedules one rebuild unless one is already due. Reads the scheduled
	 * events from the database, since a long request's cached copy can still
	 * list an event that has already run, and reloads them before scheduling
	 * so the write builds on the current list.
	 */
	public static function schedule_rebuild(): void {
		global $wpdb;

		$cron = maybe_unserialize(
			$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'cron' )
			)
		);

		foreach ( is_array( $cron ) ? $cron : [] as $events ) {
			if ( is_array( $events ) && isset( $events[ self::REBUILD_HOOK ] ) ) {
				return;
			}
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_schedule_single_event( time(), self::REBUILD_HOOK );
	}

	/**
	 * Rebuilds the stored map when it is out of date or missing. Runs from the
	 * scheduled event; when another rebuild holds the lock, tries again later.
	 */
	public static function rebuild(): void {
		if ( null === self::read_token() && self::is_map( get_option( self::OPTION ) ) ) {
			return;
		}

		if ( ! self::rebuild_locked() ) {
			wp_schedule_single_event( time() + self::LOCK_TTL, self::REBUILD_HOOK );
		}
	}

	/**
	 * Schedules the first build when the plugin is activated.
	 */
	public static function activate(): void {
		self::flush();
		self::schedule_rebuild();
	}

	/**
	 * Drops the stored map, its marks and any pending rebuild when the
	 * plugin is deactivated.
	 */
	public static function deactivate(): void {
		delete_option( self::OPTION );
		delete_option( self::STALE_OPTION );
		delete_option( self::LOCK_OPTION );
		wp_clear_scheduled_hook( self::REBUILD_HOOK );
	}

	/**
	 * Builds and stores the map while holding the lock, so two rebuilds never
	 * run at once and an older build never overwrites a newer one. The
	 * out-of-date token is read inside the lock, and cleared only if no
	 * change replaced it while building; such a change keeps the map marked,
	 * though the new map is still stored, since it is newer than the last.
	 *
	 * @return bool Whether this request rebuilt it; false when another holds the lock.
	 */
	private static function rebuild_locked(): bool {
		global $wpdb;

		if ( ! self::lock() ) {
			return false;
		}

		try {
			$token = self::read_token();

			self::write_option( self::OPTION, self::build() );

			if ( null !== $token ) {
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::STALE_OPTION, $token )
				);
				self::forget_option( self::STALE_OPTION );
			}
		} finally {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION )
			);
		}

		return true;
	}

	/**
	 * Takes the rebuild lock, an option row inserted only if absent, or taken
	 * over once its holder's time is up.
	 *
	 * @return bool Whether this request holds the lock.
	 */
	private static function lock(): bool {
		global $wpdb;

		$expires = (string) ( time() + self::LOCK_TTL );

		if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK_OPTION, $expires ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return true;
		}

		$held_until = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $held_until < time()
			&& (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $expires, self::LOCK_OPTION, $held_until ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The current out-of-date token, read from the database.
	 *
	 * @return string|null Token, or null when the map is up to date.
	 */
	private static function read_token(): ?string {
		global $wpdb;

		$token = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::STALE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null === $token ? null : (string) $token;
	}

	/**
	 * Writes an option straight to the database, inserting or replacing it,
	 * and drops this request's cached copy. Unlike update_option(), it never
	 * compares against a cached value that may be out of date.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	private static function write_option( string $name, $value ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'off'",
				$name,
				maybe_serialize( $value )
			)
		);
		self::forget_option( $name );
	}

	/**
	 * Drops an option from this request's caches, so the next get_option()
	 * reads the database.
	 *
	 * @param string $name Option name.
	 */
	private static function forget_option( string $name ): void {
		wp_cache_delete( $name, 'options' );

		foreach ( [ 'notoptions', 'alloptions' ] as $key ) {
			$cached = wp_cache_get( $key, 'options' );

			if ( is_array( $cached ) && array_key_exists( $name, $cached ) ) {
				unset( $cached[ $name ] );
				wp_cache_set( $key, $cached, 'options' );
			}
		}
	}

	/**
	 * Whether a stored value is a complete map.
	 *
	 * @param mixed $stored Stored option value.
	 * @return bool
	 */
	private static function is_map( $stored ): bool {
		return is_array( $stored ) && isset( $stored['pages'], $stored['places'], $stored['breakout'], $stored['patterns'], $stored['theme'] );
	}

	/**
	 * The active theme and its version, so a map built for another theme is
	 * rebuilt on the next admin read.
	 *
	 * @return string
	 */
	private static function theme_signature(): string {
		return get_stylesheet() . '@' . wp_get_theme()->get( 'Version' );
	}

	/**
	 * Whether a post is a template or template part.
	 *
	 * @param mixed $post Post object.
	 * @return bool
	 */
	private static function is_template( $post ): bool {
		return $post instanceof WP_Post && in_array( $post->post_type, [ self::TYPE_TEMPLATE, self::TYPE_TEMPLATE_PART ], true );
	}

	/**
	 * What a post contributes to the map, or null when it contributes
	 * nothing: what its blocks show, the patterns it uses, and the fields
	 * that decide whether and where it is listed.
	 *
	 * @param mixed $post Post object.
	 * @return string|null
	 */
	private static function signature( $post ): ?string {
		if ( ! self::shows_blocks( $post ) ) {
			return null;
		}

		return md5(
			wp_json_encode(
				[
					$post->post_type,
					$post->post_status,
					$post->post_password,
					$post->post_date,
					self::scan( $post->post_content ),
				]
			)
		);
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
	 * Returns the stored map, even when it is out of date. Never builds one:
	 * when there is none, it schedules a build and returns an empty map, so a
	 * front-end render never waits on a scan.
	 *
	 * @return array{pages: array<int,int>, places: array<int,array[]>, breakout: array[], patterns: int[], theme: string}
	 */
	private static function get_map(): array {
		$stored = get_option( self::OPTION );

		if ( self::is_map( $stored ) ) {
			return $stored;
		}

		if ( null === self::read_token() ) {
			self::flush();
		}

		return [
			'pages'    => [],
			'places'   => [],
			'breakout' => [],
			'patterns' => [],
			'theme'    => '',
		];
	}

	/**
	 * Scans every published place for the blocks.
	 *
	 * @return array{pages: array<int,int>, places: array<int,array[]>, breakout: array[], patterns: int[], theme: string}
	 */
	private static function build(): array {
		$map = [
			'pages'    => [],
			'places'   => [],
			'breakout' => [],
			'patterns' => [],
			'theme'    => self::theme_signature(),
		];

		$patterns = self::patterns_with_blocks();
		$refs     = [];
		$scan     = self::posts_to_scan( array_keys( $patterns ) );

		$map['patterns'] = $scan['patterns'];

		foreach ( array_chunk( $scan['ids'], self::SCAN_BATCH ) as $batch ) {
			foreach ( self::post_contents( $batch ) as $post_id => $content ) {
				$found = self::scan( $content );
				$refs += $found['refs'];

				if ( $found['automatic'] ) {
					$coverage_id = self::first_feed( $found['feeds'] );
					$coverage_id = $coverage_id ? $coverage_id : Page_Coverages::breakout_coverage_id( $post_id );

					if ( $coverage_id ) {
						self::add_tags( $found['coverages'], $coverage_id, array_keys( $found['automatic'] ) );
					}
				}

				foreach ( $found['coverages'] as $coverage_id => $tags ) {
					$map['places'][ $coverage_id ][] = [
						'type' => self::TYPE_POST,
						'id'   => $post_id,
						'tags' => array_keys( $tags ),
					];

					if ( isset( $tags[ self::TAG_FULL ] ) && ! isset( $map['pages'][ $coverage_id ] ) ) {
						$map['pages'][ $coverage_id ] = $post_id;
					}
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
	 * content holds one of the blocks,
	 * and those using one of the given synced patterns, directly or through
	 * other patterns. Also returns every pattern that leads to the blocks,
	 * the given ones and the patterns wrapping them.
	 *
	 * @param int[] $pattern_ids Synced patterns holding one of the blocks.
	 * @return array{ids: int[], patterns: int[]}
	 */
	private static function posts_to_scan( array $pattern_ids ): array {
		global $wpdb;

		$post_types = array_values( array_filter( get_post_types(), [ __CLASS__, 'can_be_page' ] ) );

		if ( ! $post_types ) {
			return [
				'ids'      => [],
				'patterns' => $pattern_ids,
			];
		}

		$referrers    = self::pattern_referrers( $pattern_ids, $post_types );
		$type_holders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$id_clause    = $referrers['posts'] ? ' OR ID IN (' . implode( ',', array_map( 'intval', $referrers['posts'] ) ) . ')' : '';

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ({$type_holders}) AND ((" . self::blocks_like_clause() . "){$id_clause}) ORDER BY post_date DESC, ID DESC",
				array_merge( $post_types, self::blocks_like_values() )
			)
		);

		return [
			'ids'      => array_map( 'intval', $ids ),
			'patterns' => $referrers['patterns'],
		];
	}

	/**
	 * The content of a batch of posts, in the order given, read straight
	 * from the database so a rebuild doesn't fill the object cache.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int,string> Map of post ID => content.
	 */
	private static function post_contents( array $ids ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		);

		$contents = array_fill_keys( $ids, null );

		foreach ( $rows as $row ) {
			$contents[ (int) $row->ID ] = (string) $row->post_content;
		}

		return array_filter(
			$contents,
			function ( $content ) {
				return null !== $content;
			}
		);
	}

	/**
	 * The first coverage among a post's uncapped feeds that can be followed,
	 * as an Automatic block picks it.
	 *
	 * @param int[] $feeds Coverage IDs of the feeds, in page order.
	 * @return int Coverage term ID, or 0.
	 */
	private static function first_feed( array $feeds ): int {
		foreach ( $feeds as $coverage_id ) {
			if ( Page_Coverages::is_followable( $coverage_id ) ) {
				return $coverage_id;
			}
		}

		return 0;
	}

	/**
	 * Published posts that use any of the given synced patterns, directly or
	 * through other synced patterns.
	 *
	 * @param int[]    $pattern_ids Synced pattern IDs.
	 * @param string[] $post_types  Post types that can be a place.
	 * @return array{posts: int[], patterns: int[]} The posts, and the given patterns with those wrapping them.
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

		return [
			'posts'    => array_values( $referrers ),
			'patterns' => array_keys( $seen ),
		];
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
	 * @return array{coverages: array<int,array<string,true>>, automatic: array<string,true>, refs: array<int,true>, parts: array<string,true>, feeds: int[]}
	 */
	private static function scan( string $content ): array {
		$found = [
			'coverages' => [],
			'automatic' => [],
			'refs'      => [],
			'parts'     => [],
			'feeds'     => [],
		];

		self::walk( parse_blocks( $content ), $found, false, [] );

		return $found;
	}

	/**
	 * Walks parsed blocks, recording the coverages the place's own blocks
	 * show, its Automatic Status and Follow Coverage blocks, the synced
	 * patterns it uses, the template parts it holds, and its uncapped feeds
	 * in page order, patterns included, for Automatic blocks to pick from.
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

				if ( $coverage_id > 0 && empty( $attrs['latestOnly'] ) ) {
					$found['feeds'][] = $coverage_id;
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
