<?php
/**
 * Tests for the list of places that show a coverage.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Placements;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * Covers which published places count, the tags and links each row gets,
 * the breakout template row, the main page coming first, and when the
 * stored map is rebuilt.
 */
class Test_Placements extends Rolling_Coverage_TestCase {

	/**
	 * Widget area registered for the widget tests.
	 */
	const SIDEBAR_ID = 'placements-sidebar';

	/**
	 * Start every test from a fresh map, and let templates count.
	 */
	public function set_up() {
		parent::set_up();
		add_theme_support( 'block-templates' );
		add_theme_support( 'block-template-parts' );
		Placements::flush();
		self::log_in_as( 'administrator' );
	}

	/**
	 * Undo the theme support and widget area.
	 */
	public function tear_down() {
		remove_theme_support( 'block-templates' );
		remove_theme_support( 'block-template-parts' );
		unregister_sidebar( self::SIDEBAR_ID );
		parent::tear_down();
	}

	/**
	 * A Rolling Coverage block.
	 *
	 * @param int   $coverage_id Coverage term ID.
	 * @param array $attrs       More attributes.
	 * @return string
	 */
	private static function feed( int $coverage_id, array $attrs = [] ): string {
		return '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( array_merge( [ 'coverageId' => $coverage_id ], $attrs ) ) . ' /-->';
	}

	/**
	 * A Coverage Status block.
	 *
	 * @param int $coverage_id Chosen coverage, or 0 for Automatic.
	 * @return string
	 */
	private static function status( int $coverage_id = 0 ): string {
		return $coverage_id ? '<!-- wp:newspack-rolling-coverage/coverage-status {"coverageId":' . $coverage_id . '} /-->' : '<!-- wp:newspack-rolling-coverage/coverage-status /-->';
	}

	/**
	 * A Follow Coverage block.
	 *
	 * @param int $coverage_id Chosen coverage, or 0 for Automatic.
	 * @return string
	 */
	private static function follow( int $coverage_id = 0 ): string {
		$attrs = $coverage_id ? ' {"coverageId":' . $coverage_id . '}' : '';

		return '<!-- wp:newspack-rolling-coverage/coverage-follow' . $attrs . ' --><div class="wp-block-buttons"></div><!-- /wp:newspack-rolling-coverage/coverage-follow -->';
	}

	/**
	 * A published post.
	 *
	 * @param string $content Content.
	 * @param array  $args    More post arguments.
	 * @return int Post ID.
	 */
	private static function publish( string $content, array $args = [] ): int {
		return self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_content' => $content,
				],
				$args
			)
		);
	}

	/**
	 * A published synced pattern.
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @return int Pattern ID.
	 */
	private static function pattern( string $title, string $content ): int {
		return self::publish(
			$content,
			[
				'post_type'  => 'wp_block',
				'post_title' => $title,
			]
		);
	}

	/**
	 * A reference to a synced pattern.
	 *
	 * @param int $pattern_id Pattern ID.
	 * @return string
	 */
	private static function ref( int $pattern_id ): string {
		return '<!-- wp:block {"ref":' . $pattern_id . '} /-->';
	}

	/**
	 * A customized template or template part of the active theme.
	 *
	 * @param string $type    wp_template or wp_template_part.
	 * @param string $slug    Slug.
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @return int Post ID.
	 */
	private static function template( string $type, string $slug, string $title, string $content ): int {
		$id = self::publish(
			$content,
			[
				'post_type'  => $type,
				'post_name'  => $slug,
				'post_title' => $title,
			]
		);
		wp_set_post_terms( $id, get_stylesheet(), 'wp_theme' );
		Placements::flush();

		return $id;
	}

	/**
	 * Gives a coverage a published entry with a published breakout post.
	 *
	 * @param int   $coverage_id Coverage term ID.
	 * @param array $args        Breakout post arguments.
	 * @return int Breakout post ID.
	 */
	private static function breakout( int $coverage_id, array $args = [] ): int {
		$entry_id    = self::create_entry( $coverage_id );
		$breakout_id = self::publish( 'Breakout story.', $args );

		update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $entry_id );
		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );
		update_post_meta( $entry_id, Breakout::BREAKOUT_STATUS_FIELD, get_post_status( $breakout_id ) );
		Placements::flush();

		return $breakout_id;
	}

	/**
	 * The rows of a coverage, keyed by row ID.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return array<string,array>
	 */
	private static function rows( int $coverage_id ): array {
		return array_column( Placements::for_coverage( $coverage_id ), null, 'id' );
	}

	/**
	 * The tags of each row, keyed by row ID.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return array<string,string[]>
	 */
	private static function tags( int $coverage_id ): array {
		return array_column( Placements::for_coverage( $coverage_id ), 'tags', 'id' );
	}

	/**
	 * A full feed is tagged Full, a capped one with its layout's name, or
	 * Latest when it has a layout of its own.
	 */
	public function test_feeds_are_tagged_by_what_they_show() {
		$coverage_id = self::create_coverage();
		$flash_id    = self::pattern( 'Flash', '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":0} /-->' );
		$page_id     = self::publish( self::feed( $coverage_id ), [ 'post_type' => 'page' ] );
		$home_id     = self::publish(
			self::feed(
				$coverage_id,
				[
					'latestOnly' => true,
					'layoutId'   => $flash_id,
				]
			)
		);
		$detached_id = self::publish( '<!-- wp:group -->' . self::feed( $coverage_id, [ 'latestOnly' => true ] ) . '<!-- /wp:group -->' );

		$this->assertSame(
			[
				'post:' . $detached_id => [ 'Latest' ],
				'post:' . $home_id     => [ 'Flash' ],
				'post:' . $page_id     => [ 'Full' ],
			],
			self::tags( $coverage_id )
		);
		$this->assertSame( [], Placements::for_coverage( self::create_coverage() ), 'A coverage nothing shows has no placements.' );
	}

	/**
	 * Each post row links to the post and its editor, and says what the post is.
	 */
	public function test_post_rows_link_to_the_post_and_its_editor() {
		$coverage_id = self::create_coverage();
		$page_id     = self::publish(
			self::feed( $coverage_id ),
			[
				'post_type'  => 'page',
				'post_title' => 'Storm &amp; Flood Live',
			]
		);

		$row = self::rows( $coverage_id )[ 'post:' . $page_id ];

		$this->assertSame( 'Storm & Flood Live', $row['title'] );
		$this->assertSame( 'Page', $row['type'] );
		$this->assertSame( get_permalink( $page_id ), $row['viewUrl'] );
		$this->assertSame( get_edit_post_link( $page_id, 'raw' ), $row['editUrl'] );
		$this->assertFalse( $row['isMain'] );
	}

	/**
	 * Custom Status and Follow Coverage blocks show their chosen coverage,
	 * and Automatic ones beside a page's feed join that page's row.
	 */
	public function test_status_and_follow_blocks_tag_the_place_they_show() {
		$coverage_id = self::create_coverage();
		$other_id    = self::create_coverage();

		$sidebar_post_id = self::publish( self::status( $coverage_id ) . self::follow( $coverage_id ) );
		$page_id         = self::publish( self::follow() . self::feed( $coverage_id ) . self::status() );
		self::publish( self::status() . self::follow(), [ 'post_title' => 'No feed' ] );

		$this->assertSame(
			[
				'post:' . $page_id         => [ 'Full', 'Status', 'Follow' ],
				'post:' . $sidebar_post_id => [ 'Status', 'Follow' ],
			],
			self::tags( $coverage_id ),
			'An Automatic block without a feed on its page shows nothing.'
		);
		$this->assertSame( [], self::tags( $other_id ) );
	}

	/**
	 * Blocks in a Rolling Coverage block's layout are part of that feed, so
	 * they add no tags.
	 */
	public function test_blocks_inside_a_feed_belong_to_the_feed() {
		$coverage_id = self::create_coverage();
		$other_id    = self::create_coverage();
		$page_id     = self::publish(
			'<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} -->' . self::status( $other_id ) . self::follow() . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->'
		);

		$this->assertSame( [ 'post:' . $page_id => [ 'Full' ] ], self::tags( $coverage_id ) );
		$this->assertSame( [], self::tags( $other_id ) );
	}

	/**
	 * A Custom block whose coverage is trashed falls back to Automatic, as
	 * it does on the site, and the map is rebuilt when that happens.
	 */
	public function test_a_custom_block_with_a_trashed_coverage_falls_back_to_automatic() {
		$chosen_id   = self::create_coverage();
		$coverage_id = self::create_coverage();
		$page_id     = self::publish( self::feed( $coverage_id ) . self::status( $chosen_id ) );

		$this->assertSame( [ 'post:' . $page_id => [ 'Status' ] ], self::tags( $chosen_id ) );

		update_term_meta( $chosen_id, Taxonomy::STATUS_META_KEY, 'trash' );

		$this->assertSame( [], self::tags( $chosen_id ) );
		$this->assertSame( [ 'post:' . $page_id => [ 'Full', 'Status' ] ], self::tags( $coverage_id ) );
	}

	/**
	 * Only published, public places count: drafts, private and
	 * password-protected posts, entries and trashed posts don't.
	 */
	public function test_only_published_public_posts_count() {
		$coverage_id = self::create_coverage();
		$block       = self::feed( $coverage_id );

		self::publish( $block, [ 'post_status' => 'draft' ] );
		self::publish( $block, [ 'post_status' => 'private' ] );
		self::publish( $block, [ 'post_password' => 'secret' ] );
		self::publish( $block, [ 'post_status' => 'trash' ] );
		self::create_entry( $coverage_id, [ 'post_content' => $block ] );

		$this->assertSame( [], Placements::for_coverage( $coverage_id ) );
		$this->assertSame( 0, Placements::page_id( $coverage_id ), 'None of them can be the coverage page either.' );

		$page_id = self::publish( $block );

		$this->assertSame( [ 'post:' . $page_id ], array_keys( self::rows( $coverage_id ) ) );
	}

	/**
	 * Templates and template parts are places, opened in the Site Editor.
	 * The front page template is viewed on the home page; others have no
	 * page of their own.
	 */
	public function test_templates_and_template_parts_are_places() {
		$coverage_id = self::create_coverage();
		self::template( 'wp_template', 'front-page', 'Front Page', self::feed( $coverage_id ) );
		self::template( 'wp_template', 'archive', 'All Archives', self::status( $coverage_id ) );
		self::template( 'wp_template_part', 'header', 'Header', self::feed( $coverage_id, [ 'latestOnly' => true ] ) );

		$rows       = self::rows( $coverage_id );
		$theme      = get_stylesheet();
		$front_page = $rows[ 'wp_template:' . $theme . '//front-page' ];
		$header     = $rows[ 'wp_template_part:' . $theme . '//header' ];
		$archive    = $rows[ 'wp_template:' . $theme . '//archive' ];

		$this->assertSame( [ 'Front Page', 'Template', [ 'Full' ], home_url( '/' ) ], [ $front_page['title'], $front_page['type'], $front_page['tags'], $front_page['viewUrl'] ] );
		$this->assertSame( admin_url( 'site-editor.php?p=/wp_template/' . rawurlencode( $theme . '//front-page' ) . '&canvas=edit' ), $front_page['editUrl'] );
		$this->assertSame( [ 'Header', 'Template part', [ 'Latest' ], '' ], [ $header['title'], $header['type'], $header['tags'], $header['viewUrl'] ] );
		$this->assertSame( [ 'Status' ], $archive['tags'] );

		self::log_in_as( 'editor' );

		$this->assertSame( '', self::rows( $coverage_id )[ 'wp_template:' . $theme . '//front-page' ]['editUrl'], 'Only users who can edit the theme get the Site Editor link.' );
	}

	/**
	 * Without block template support, the theme's templates are never used,
	 * so they aren't places.
	 */
	public function test_templates_only_count_when_the_theme_uses_them() {
		$coverage_id = self::create_coverage();
		self::template( 'wp_template', 'front-page', 'Front Page', self::feed( $coverage_id ) );

		remove_theme_support( 'block-templates' );
		remove_theme_support( 'block-template-parts' );
		Placements::flush();

		if ( wp_is_block_theme() ) {
			$this->markTestSkipped( 'The test theme is a block theme.' );
		}

		$this->assertSame( [], Placements::for_coverage( $coverage_id ) );
	}

	/**
	 * A synced pattern is a place once published content uses it, directly
	 * or through another pattern, and opens in the Site Editor.
	 */
	public function test_synced_patterns_count_once_published_content_uses_them() {
		$coverage_id = self::create_coverage();
		$box_id      = self::pattern( 'Storm box', self::feed( $coverage_id, [ 'latestOnly' => true ] ) . self::status( $coverage_id ) );
		$unused_id   = self::pattern( 'Unused box', self::feed( $coverage_id ) );
		$outer_id    = self::pattern( 'Sidebar stack', self::ref( $unused_id ) );

		self::publish( self::ref( $box_id ), [ 'post_status' => 'draft' ] );
		Placements::flush();

		$this->assertSame( [], self::tags( $coverage_id ), 'A pattern only drafts use is not a place.' );

		$post_id = self::publish( 'Intro.' . self::ref( $box_id ) );
		Placements::flush();

		$rows = self::rows( $coverage_id );

		$this->assertSame( [ 'wp_block:' . $box_id ], array_keys( $rows ), 'The post holds no block of its own, so the pattern is the place.' );
		$this->assertSame( [ 'Storm box', 'Pattern', [ 'Latest', 'Status' ], '' ], [ $rows[ 'wp_block:' . $box_id ]['title'], $rows[ 'wp_block:' . $box_id ]['type'], $rows[ 'wp_block:' . $box_id ]['tags'], $rows[ 'wp_block:' . $box_id ]['viewUrl'] ] );
		$this->assertSame( admin_url( 'site-editor.php?p=/wp_block/' . $box_id . '&canvas=edit' ), $rows[ 'wp_block:' . $box_id ]['editUrl'] );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => self::ref( $outer_id ),
			]
		);

		$this->assertSame( [ 'wp_block:' . $unused_id ], array_keys( self::rows( $coverage_id ) ), 'A pattern used through another pattern counts.' );
	}

	/**
	 * An Automatic block in a synced pattern shows the coverage of the page
	 * the pattern is on, so it tags that page.
	 */
	public function test_automatic_blocks_in_a_pattern_tag_the_page_using_it() {
		$coverage_id = self::create_coverage();
		$header_id   = self::pattern( 'Live header', self::status() . self::follow() );
		$page_id     = self::publish( self::ref( $header_id ) . self::feed( $coverage_id ) );

		$this->assertSame( [ 'post:' . $page_id => [ 'Full', 'Status', 'Follow' ] ], self::tags( $coverage_id ) );
	}

	/**
	 * Block widgets make their widget area a place, opened in the Widgets
	 * screen; inactive widgets don't count.
	 */
	public function test_block_widgets_make_their_area_a_place() {
		$coverage_id = self::create_coverage();
		register_sidebar(
			[
				'id'   => self::SIDEBAR_ID,
				'name' => 'Right Sidebar',
			]
		);
		update_option(
			'widget_block',
			[
				2              => [ 'content' => self::feed( $coverage_id, [ 'latestOnly' => true ] ) ],
				3              => [ 'content' => self::follow( $coverage_id ) ],
				4              => [ 'content' => self::feed( $coverage_id ) ],
				'_multiwidget' => 1,
			]
		);
		update_option(
			'sidebars_widgets',
			[
				self::SIDEBAR_ID      => [ 'block-2', 'block-3' ],
				'wp_inactive_widgets' => [ 'block-4' ],
				'array_version'       => 3,
			]
		);

		$row = self::rows( $coverage_id )[ 'widget_area:' . self::SIDEBAR_ID ] ?? null;

		$this->assertNotNull( $row );
		$this->assertSame( [ 'Right Sidebar', 'Widget area', [ 'Latest', 'Follow' ], '', admin_url( 'widgets.php' ) ], [ $row['title'], $row['type'], $row['tags'], $row['viewUrl'], $row['editUrl'] ] );
		$this->assertCount( 1, Placements::for_coverage( $coverage_id ), 'An inactive widget is not a place.' );
	}

	/**
	 * An Automatic block in the single post template, or a template part it
	 * holds, shows a coverage on each of its breakout posts. The template is
	 * listed once, for coverages with a published breakout post, and views
	 * the newest one.
	 */
	public function test_a_single_post_template_lists_once_for_coverages_with_breakout_posts() {
		$coverage_id = self::create_coverage();
		$other_id    = self::create_coverage();
		$theme       = get_stylesheet();

		self::template( 'wp_template', 'single', 'Single Posts', '<!-- wp:template-part {"slug":"post-header"} /-->' . self::status() );
		self::template( 'wp_template_part', 'post-header', 'Post Header', self::follow() );
		self::template( 'wp_template_part', 'footer', 'Footer', self::follow() );
		self::template( 'wp_template', 'page', 'Pages', self::status() );

		self::breakout( $coverage_id, [ 'post_date' => '2026-01-01 10:00:00' ] );
		$newest_id = self::breakout( $coverage_id, [ 'post_date' => '2026-02-01 10:00:00' ] );
		self::breakout( $other_id, [ 'post_password' => 'secret' ] );

		$rows = self::rows( $coverage_id );

		$this->assertSame( [ 'breakout:wp_template:' . $theme . '//single', 'breakout:wp_template_part:' . $theme . '//post-header' ], array_keys( $rows ), 'Only the single post template and the parts it holds count.' );

		$single = $rows[ 'breakout:wp_template:' . $theme . '//single' ];

		$this->assertTrue( $single['breakout'] );
		$this->assertSame( [ 'Single Posts', 'Template, on this coverage’s breakout posts', [ 'Status' ], get_permalink( $newest_id ) ], [ $single['title'], $single['type'], $single['tags'], $single['viewUrl'] ] );
		$this->assertSame( [], Placements::for_coverage( $other_id ), 'A coverage without a public breakout post gets no row.' );
	}

	/**
	 * Automatic in a widget area also shows on breakout posts.
	 */
	public function test_an_automatic_widget_lists_for_coverages_with_breakout_posts() {
		$coverage_id = self::create_coverage();
		register_sidebar(
			[
				'id'   => self::SIDEBAR_ID,
				'name' => 'Right Sidebar',
			]
		);
		update_option(
			'widget_block',
			[
				2              => [ 'content' => self::status() ],
				'_multiwidget' => 1,
			]
		);
		update_option( 'sidebars_widgets', [ self::SIDEBAR_ID => [ 'block-2' ] ] );

		$this->assertSame( [], Placements::for_coverage( $coverage_id ) );

		self::breakout( $coverage_id );

		$this->assertSame( [ 'breakout:widget_area:' . self::SIDEBAR_ID => [ 'Status' ] ], self::tags( $coverage_id ) );
	}

	/**
	 * The place at the canonical URL comes first, marked as the main page.
	 * A canonical URL no place matches gets a row of its own, to view only.
	 */
	public function test_the_main_page_comes_first() {
		$coverage_id = self::create_coverage();
		$main_id     = self::publish(
			self::feed( $coverage_id ),
			[
				'post_type' => 'page',
				'post_date' => '2026-01-01 10:00:00',
			]
		);
		$newer_id    = self::publish(
			self::feed( $coverage_id, [ 'latestOnly' => true ] ),
			[ 'post_date' => '2026-02-01 10:00:00' ]
		);

		$this->assertSame( [ 'post:' . $newer_id, 'post:' . $main_id ], array_keys( self::rows( $coverage_id ) ), 'Newest first without a canonical URL.' );

		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, set_url_scheme( get_permalink( $main_id ), 'https' ) );
		$rows = Placements::for_coverage( $coverage_id );

		$this->assertSame( [ 'post:' . $main_id, 'post:' . $newer_id ], array_column( $rows, 'id' ), 'The canonical page should come first, whatever its scheme.' );
		$this->assertSame( [ true, false ], array_column( $rows, 'isMain' ) );

		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/live/storm/' ) );
		$rows = Placements::for_coverage( $coverage_id );

		$this->assertSame( [ 'canonical', 'post:' . $newer_id, 'post:' . $main_id ], array_column( $rows, 'id' ) );
		$this->assertSame( [ true, '', home_url( '/live/storm/' ) ], [ $rows[0]['isMain'], $rows[0]['editUrl'], $rows[0]['viewUrl'] ] );
		$this->assertStringEndsWith( '/live/storm', $rows[0]['title'] );
	}

	/**
	 * The REST field lists the placements for users who can edit posts only.
	 */
	public function test_rest_field_is_only_for_users_who_can_edit_posts() {
		$coverage_id = self::create_coverage();
		$page_id     = self::publish( self::feed( $coverage_id ) );
		$request     = new WP_REST_Request( 'GET', '/wp/v2/' . Taxonomy::REST_BASE . '/' . $coverage_id );

		self::log_in_as( 'author' );

		$this->assertSame( [ 'post:' . $page_id ], array_column( rest_get_server()->dispatch( $request )->get_data()[ Placements::REST_FIELD ], 'id' ) );

		self::log_in_as( 'subscriber' );

		$this->assertSame( [], rest_get_server()->dispatch( $request )->get_data()[ Placements::REST_FIELD ] );
	}

	/**
	 * Whether an action clears the stored map.
	 *
	 * @param callable $action   Action to run.
	 * @param int[]    $patterns Synced patterns the stored map knows show a coverage.
	 * @return bool
	 */
	private static function clears_map( callable $action, array $patterns = [] ): bool {
		$sentinel = [
			'pages'    => [ 1 => 1 ],
			'places'   => [],
			'breakout' => [],
			'patterns' => $patterns,
		];

		Placements::rebuild();
		update_option( Placements::OPTION, $sentinel, false );

		$action();
		Placements::rebuild();

		return get_option( Placements::OPTION ) !== $sentinel;
	}

	/**
	 * Templates, template parts, patterns and posts using a known pattern
	 * clear the map when saved; posts using another pattern don't.
	 */
	public function test_saving_templates_and_patterns_clears_the_map() {
		$coverage_id = self::create_coverage();
		$known_id    = self::pattern( 'Storm box', self::feed( $coverage_id ) );
		$other_id    = self::pattern( 'Newsletter', '<!-- wp:paragraph --><p>Sign up</p><!-- /wp:paragraph -->' );

		$this->assertTrue( self::clears_map( fn() => self::publish( self::feed( $coverage_id ), [ 'post_type' => 'wp_template' ] ) ), 'A template with the block.' );
		$this->assertTrue( self::clears_map( fn() => self::publish( self::status(), [ 'post_type' => 'wp_template_part' ] ) ), 'A template part with the block.' );
		$this->assertTrue(
			self::clears_map(
				fn() => wp_update_post(
					[
						'ID'         => $known_id,
						'post_title' => 'Renamed',
					]
				)
			),
			'A pattern with the block.'
		);
		$this->assertTrue( self::clears_map( fn() => self::publish( self::ref( $known_id ) ), [ $known_id ] ), 'A post using a pattern that shows a coverage.' );
		$this->assertFalse( self::clears_map( fn() => self::publish( self::ref( $other_id ) ), [ $known_id ] ), 'A post using another pattern.' );
		$this->assertFalse( self::clears_map( fn() => self::publish( self::feed( $coverage_id ), [ 'post_status' => 'draft' ] ) ), 'A draft.' );
	}

	/**
	 * Widget changes, theme switches and coverage status changes clear the map.
	 */
	public function test_widgets_themes_and_coverage_status_clear_the_map() {
		$coverage_id = self::create_coverage();

		$this->assertTrue( self::clears_map( fn() => update_option( 'widget_block', [ 2 => [ 'content' => self::status() ] ] ) ), 'Editing block widgets.' );
		$this->assertTrue( self::clears_map( fn() => update_option( 'sidebars_widgets', [ 'sidebar-1' => [ 'block-2' ] ] ) ), 'Moving widgets.' );
		$this->assertTrue( self::clears_map( fn() => do_action( 'switch_theme', 'Other', wp_get_theme() ) ), 'Switching themes.' );
		$this->assertTrue( self::clears_map( fn() => update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, 'trash' ) ), 'Trashing a coverage.' );
		$this->assertFalse( self::clears_map( fn() => update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/live/' ) ) ), 'Other coverage meta.' );
	}
}
