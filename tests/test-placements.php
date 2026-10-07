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
 * Covers which published places count, the blocks and links each row lists,
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
	 * A layout pattern's content: a Rolling Coverage block holding the layout.
	 *
	 * @return string
	 */
	private static function layout(): string {
		return '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":0} --><!-- wp:paragraph --><p>Entry</p><!-- /wp:paragraph --><!-- /wp:newspack-rolling-coverage/rolling-coverage -->';
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
	 * The blocks each row lists, keyed by row ID.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return array<string,string[]>
	 */
	private static function blocks( int $coverage_id ): array {
		return array_column( Placements::for_coverage( $coverage_id ), 'blocks', 'id' );
	}

	/**
	 * A Rolling Coverage block is listed with its shared layout's name,
	 * Detached when it has its own copy of a layout, or Bulletin, the
	 * built-in template it renders, when it has no usable layout, and with
	 * how many entries it shows when capped.
	 */
	public function test_feeds_are_listed_with_their_layout_and_cap() {
		$coverage_id = self::create_coverage();
		$flash_id    = self::pattern( 'Flash', self::layout() );
		$stream_id   = self::pattern( 'Stream', self::layout() );
		$draft_id    = self::factory()->post->create(
			[
				'post_type'   => 'wp_block',
				'post_status' => 'draft',
				'post_title'  => 'Draft layout',
			]
		);
		$empty_id    = self::pattern( 'Empty', '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":0} /-->' );
		$untitled_id = self::pattern( '', self::layout() );
		$page_id     = self::publish( self::feed( $coverage_id, [ 'layoutId' => $stream_id ] ), [ 'post_type' => 'page' ] );
		$home_id     = self::publish(
			self::feed(
				$coverage_id,
				[
					'latestOnly' => true,
					'layoutId'   => $flash_id,
				]
			)
		);
		$detached_id = self::publish( '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . ',"latestOnly":true,"latestCount":3} --><!-- wp:paragraph --><p>Entry</p><!-- /wp:paragraph --><!-- /wp:newspack-rolling-coverage/rolling-coverage -->' );
		$default_id  = self::publish( '<!-- wp:group -->' . self::feed( $coverage_id, [ 'latestOnly' => true ] ) . '<!-- /wp:group -->' );
		$missing_id  = self::publish( self::feed( $coverage_id, [ 'layoutId' => $draft_id ] ) );
		$emptied_id  = self::publish( self::feed( $coverage_id, [ 'layoutId' => $empty_id ] ) );
		$nameless_id = self::publish( self::feed( $coverage_id, [ 'layoutId' => $untitled_id ] ) );

		$this->assertSame(
			[
				'post:' . $nameless_id => [ 'Rolling Coverage (Untitled layout)' ],
				'post:' . $emptied_id  => [ 'Rolling Coverage (Bulletin)' ],
				'post:' . $missing_id  => [ 'Rolling Coverage (Bulletin)' ],
				'post:' . $default_id  => [ 'Rolling Coverage (Bulletin, latest 5)' ],
				'post:' . $detached_id => [ 'Rolling Coverage (Detached, latest 3)' ],
				'post:' . $home_id     => [ 'Rolling Coverage (Flash, latest 5)' ],
				'post:' . $page_id     => [ 'Rolling Coverage (Stream)' ],
			],
			self::blocks( $coverage_id )
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
				'post:' . $page_id         => [ 'Rolling Coverage (Bulletin)', 'Coverage Status', 'Follow Coverage' ],
				'post:' . $sidebar_post_id => [ 'Coverage Status', 'Follow Coverage' ],
			],
			self::blocks( $coverage_id ),
			'An Automatic block without a feed on its page shows nothing.'
		);
		$this->assertSame( [], self::blocks( $other_id ) );
	}

	/**
	 * Only a feed that shows every entry makes a post the coverage page, so
	 * a newer capped feed never takes over from an older full one.
	 */
	public function test_only_uncapped_feeds_make_the_coverage_page() {
		$coverage_id = self::create_coverage();
		$capped_only = self::publish( self::feed( $coverage_id, [ 'latestOnly' => true ] ) );
		Placements::ensure_fresh();

		$this->assertSame( [ 'post:' . $capped_only ], array_keys( self::rows( $coverage_id ) ) );
		$this->assertSame( 0, Placements::page_id( $coverage_id ), 'A capped feed alone is no coverage page.' );

		$page_id = self::publish( self::feed( $coverage_id ), [ 'post_date' => '2026-01-01 00:00:00' ] );
		self::publish( self::feed( $coverage_id, [ 'latestOnly' => true ] ) );
		Placements::ensure_fresh();

		$this->assertSame( $page_id, Placements::page_id( $coverage_id ) );
		$this->assertNotSame( $capped_only, Placements::page_id( $coverage_id ) );
	}

	/**
	 * A row lists feeds that show every entry, then capped feeds, then
	 * Coverage Status and Follow Coverage, and lists a label once even when
	 * two blocks read the same.
	 */
	public function test_a_row_lists_its_blocks_in_order_once() {
		$coverage_id = self::create_coverage();
		$bulletin_id = self::pattern( 'Bulletin', self::layout() );

		$page_id = self::publish(
			self::follow( $coverage_id ) . self::status( $coverage_id ) . self::feed( $coverage_id, [ 'latestOnly' => true ] ) . self::feed( $coverage_id ) . self::feed( $coverage_id, [ 'layoutId' => $bulletin_id ] )
		);

		$this->assertSame(
			[ 'Rolling Coverage (Bulletin)', 'Rolling Coverage (Bulletin, latest 5)', 'Coverage Status', 'Follow Coverage' ],
			self::blocks( $coverage_id )[ 'post:' . $page_id ]
		);
	}

	/**
	 * Blocks in a Rolling Coverage block's layout are part of that feed, so
	 * they are not listed on their own.
	 */
	public function test_blocks_inside_a_feed_belong_to_the_feed() {
		$coverage_id = self::create_coverage();
		$other_id    = self::create_coverage();
		$page_id     = self::publish(
			'<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} -->' . self::status( $other_id ) . self::follow() . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->'
		);

		$this->assertSame( [ 'post:' . $page_id => [ 'Rolling Coverage (Detached)' ] ], self::blocks( $coverage_id ) );
		$this->assertSame( [], self::blocks( $other_id ) );
	}

	/**
	 * An Automatic block skips a feed whose coverage is trashed, as it does
	 * on the site.
	 */
	public function test_automatic_blocks_skip_a_trashed_feed() {
		$trashed_id  = self::create_coverage( 'trash' );
		$coverage_id = self::create_coverage();
		$page_id     = self::publish( self::status() . self::feed( $trashed_id ) . self::feed( $coverage_id ) );

		$this->assertSame( [ 'post:' . $page_id => [ 'Rolling Coverage (Bulletin)', 'Coverage Status' ] ], self::blocks( $coverage_id ) );
	}

	/**
	 * A Custom block whose coverage is trashed falls back to Automatic, as
	 * it does on the site, and the map is rebuilt when that happens.
	 */
	public function test_a_custom_block_with_a_trashed_coverage_falls_back_to_automatic() {
		$chosen_id   = self::create_coverage();
		$coverage_id = self::create_coverage();
		$page_id     = self::publish( self::feed( $coverage_id ) . self::status( $chosen_id ) );

		$this->assertSame( [ 'post:' . $page_id => [ 'Coverage Status' ] ], self::blocks( $chosen_id ) );

		update_term_meta( $chosen_id, Taxonomy::STATUS_META_KEY, 'trash' );

		$this->assertSame( [], self::blocks( $chosen_id ) );
		$this->assertSame( [ 'post:' . $page_id => [ 'Rolling Coverage (Bulletin)', 'Coverage Status' ] ], self::blocks( $coverage_id ) );
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

		$this->assertSame( [ 'Front Page', 'Template', [ 'Rolling Coverage (Bulletin)' ], home_url( '/' ) ], [ $front_page['title'], $front_page['type'], $front_page['blocks'], $front_page['viewUrl'] ] );
		$this->assertSame( admin_url( 'site-editor.php?p=/wp_template/' . rawurlencode( $theme . '//front-page' ) . '&canvas=edit' ), $front_page['editUrl'] );
		$this->assertSame( [ 'Header', 'Template part', [ 'Rolling Coverage (Bulletin, latest 5)' ], '' ], [ $header['title'], $header['type'], $header['blocks'], $header['viewUrl'] ] );
		$this->assertSame( [ 'Coverage Status' ], $archive['blocks'] );

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

		$this->assertSame( [], self::blocks( $coverage_id ), 'A pattern only drafts use is not a place.' );

		$post_id = self::publish( 'Intro.' . self::ref( $box_id ) );
		Placements::flush();

		$rows = self::rows( $coverage_id );

		$this->assertSame( [ 'wp_block:' . $box_id ], array_keys( $rows ), 'The post holds no block of its own, so the pattern is the place.' );
		$this->assertSame( [ 'Storm box', 'Pattern', [ 'Rolling Coverage (Bulletin, latest 5)', 'Coverage Status' ], '' ], [ $rows[ 'wp_block:' . $box_id ]['title'], $rows[ 'wp_block:' . $box_id ]['type'], $rows[ 'wp_block:' . $box_id ]['blocks'], $rows[ 'wp_block:' . $box_id ]['viewUrl'] ] );
		$this->assertSame( get_edit_post_link( $box_id, 'raw' ), $rows[ 'wp_block:' . $box_id ]['editUrl'], 'On a classic theme a pattern opens in the block editor.' );

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
	 * the pattern is on, so that page lists it.
	 */
	public function test_automatic_blocks_in_a_pattern_tag_the_page_using_it() {
		$coverage_id = self::create_coverage();
		$header_id   = self::pattern( 'Live header', self::status() . self::follow() );
		$page_id     = self::publish( self::ref( $header_id ) . self::feed( $coverage_id ) );

		$this->assertSame( [ 'post:' . $page_id => [ 'Rolling Coverage (Bulletin)', 'Coverage Status', 'Follow Coverage' ] ], self::blocks( $coverage_id ) );
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
		$this->assertSame( [ 'Right Sidebar', 'Widget area', [ 'Rolling Coverage (Bulletin, latest 5)', 'Follow Coverage' ], '', admin_url( 'widgets.php' ) ], [ $row['title'], $row['type'], $row['blocks'], $row['viewUrl'], $row['editUrl'] ] );
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
		$this->assertSame( [ 'Single Posts', 'Template, on this coverage’s breakout posts', [ 'Coverage Status' ], get_permalink( $newest_id ) ], [ $single['title'], $single['type'], $single['blocks'], $single['viewUrl'] ] );
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

		$this->assertSame( [ 'breakout:widget_area:' . self::SIDEBAR_ID => [ 'Coverage Status' ] ], self::blocks( $coverage_id ) );
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
		$request->set_param( '_fields', 'id,' . Placements::REST_FIELD );

		self::log_in_as( 'author' );

		$this->assertSame( [ 'post:' . $page_id ], array_column( rest_get_server()->dispatch( $request )->get_data()[ Placements::REST_FIELD ], 'id' ) );

		self::log_in_as( 'subscriber' );

		$this->assertSame( [], rest_get_server()->dispatch( $request )->get_data()[ Placements::REST_FIELD ] );
	}

	/**
	 * `_fields` can name the field as an array, or by one of its subfields.
	 */
	public function test_rest_field_accepts_every_form_of_fields() {
		$coverage_id = self::create_coverage();
		$page_id     = self::publish( self::feed( $coverage_id ) );

		foreach ( [ [ 'id', Placements::REST_FIELD ], Placements::REST_FIELD . '.id' ] as $fields ) {
			$request = new WP_REST_Request( 'GET', '/wp/v2/' . Taxonomy::REST_BASE . '/' . $coverage_id );
			$request->set_param( '_fields', $fields );

			$this->assertSame( 'post:' . $page_id, rest_get_server()->dispatch( $request )->get_data()[ Placements::REST_FIELD ][0]['id'] ?? null, wp_json_encode( $fields ) );
		}
	}

	/**
	 * A change made while the request is ending schedules the rebuild at
	 * once, since a shutdown callback added then may never run.
	 */
	public function test_a_change_during_shutdown_schedules_at_once() {
		wp_clear_scheduled_hook( Placements::REBUILD_HOOK );
		remove_all_actions( 'shutdown' );
		add_action( 'shutdown', [ Placements::class, 'flush' ], 20 );

		do_action( 'shutdown' );

		$this->assertNotFalse( wp_next_scheduled( Placements::REBUILD_HOOK ) );
	}

	/**
	 * A build that outlives its lock, which another rebuild then took over,
	 * stores nothing and leaves the other rebuild's lock alone.
	 */
	public function test_a_build_that_outlives_its_lock_stores_nothing() {
		global $wpdb;

		$coverage_id = self::create_coverage();
		self::publish( self::feed( $coverage_id ) );
		$stored = [
			'pages'    => [],
			'places'   => [],
			'breakout' => [],
			'patterns' => [],
			'theme'    => self::theme(),
		];
		update_option( Placements::OPTION, $stored, false );
		$taken_over = false;
		$take_over  = function ( $query ) use ( &$taken_over, $wpdb ) {
			if ( ! $taken_over && str_contains( $query, 'ORDER BY post_date DESC' ) ) {
				$taken_over = true;
				$wpdb->update( $wpdb->options, [ 'option_value' => 'successor' ], [ 'option_name' => Placements::LOCK_OPTION ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}
			return $query;
		};
		add_filter( 'query', $take_over );
		Placements::rebuild();
		remove_filter( 'query', $take_over );

		$this->assertTrue( $taken_over );
		$this->assertSame( $stored, get_option( Placements::OPTION ) );
		$this->assertSame( 'successor', $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Placements::LOCK_OPTION ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The block editor fetches coverages without `_fields`, and must not
	 * wait on a rebuild after every save; nor does the page URL field.
	 */
	public function test_rest_reads_without_the_field_named_never_rebuild() {
		$coverage_id = self::create_coverage();
		self::publish( self::feed( $coverage_id ) );
		Placements::rebuild();
		self::publish( self::feed( $coverage_id, [ 'latestOnly' => true ] ) );
		$token = get_option( Placements::STALE_OPTION );
		$data  = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/' . Taxonomy::REST_BASE . '/' . $coverage_id ) )->get_data();

		$this->assertSame( [], $data[ Placements::REST_FIELD ] );
		$this->assertNotFalse( $token );
		$this->assertSame( $token, get_option( Placements::STALE_OPTION ), 'Nothing was rebuilt.' );
	}

	/**
	 * The theme signature a fresh map is stored with.
	 *
	 * @return string
	 */
	private static function theme(): string {
		return get_stylesheet() . '@' . wp_get_theme()->get( 'Version' );
	}

	/**
	 * Whether an action marks the stored map out of date.
	 *
	 * @param callable $action   Action to run.
	 * @param int[]    $patterns Synced patterns the stored map knows lead to a block.
	 * @return bool
	 */
	private static function clears_map( callable $action, array $patterns = [] ): bool {
		update_option(
			Placements::OPTION,
			[
				'pages'    => [ 1 => 1 ],
				'places'   => [],
				'breakout' => [],
				'patterns' => $patterns,
				'theme'    => self::theme(),
			],
			false
		);
		delete_option( Placements::STALE_OPTION );

		$action();

		return false !== get_option( Placements::STALE_OPTION );
	}

	/**
	 * Patterns and posts using a known pattern clear the map when what they
	 * show changes; posts using another pattern, drafts and edits that leave
	 * the blocks as they were don't, so saving an article never waits on a
	 * rebuild it doesn't need.
	 */
	public function test_only_saves_that_change_what_is_shown_clear_the_map() {
		$coverage_id = self::create_coverage();
		$known_id    = self::pattern( 'Storm box', self::feed( $coverage_id ) );
		$other_id    = self::pattern( 'Newsletter', '<!-- wp:paragraph --><p>Sign up</p><!-- /wp:paragraph -->' );
		$article_id  = self::publish( '<!-- wp:paragraph --><p>Roads are closed.</p><!-- /wp:paragraph -->' . self::feed( $coverage_id, [ 'latestOnly' => true ] ) );

		$this->assertFalse(
			self::clears_map(
				fn() => wp_update_post(
					[
						'ID'           => $article_id,
						'post_title'   => 'Roads closed across the county',
						'post_content' => '<!-- wp:paragraph --><p>Most roads are closed.</p><!-- /wp:paragraph -->' . self::feed( $coverage_id, [ 'latestOnly' => true ] ),
					]
				)
			),
			'Editing the text around a capped feed.'
		);
		$this->assertTrue(
			self::clears_map(
				fn() => wp_update_post(
					[
						'ID'           => $article_id,
						'post_content' => self::feed( $coverage_id ),
					]
				)
			),
			'Turning the capped feed into a full one.'
		);
		$this->assertTrue(
			self::clears_map(
				fn() => wp_update_post(
					[
						'ID'           => $article_id,
						'post_content' => self::feed( $coverage_id, [ 'layoutId' => $other_id ] ),
					]
				)
			),
			'Changing the feed\'s layout.'
		);
		$this->assertTrue(
			self::clears_map(
				fn() => wp_update_post(
					[
						'ID'           => $article_id,
						'post_content' => '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} --><!-- wp:paragraph --><p>Entry</p><!-- /wp:paragraph --><!-- /wp:newspack-rolling-coverage/rolling-coverage -->',
					]
				)
			),
			'Detaching the feed\'s layout.'
		);
		$this->assertTrue(
			self::clears_map(
				fn() => wp_update_post(
					[
						'ID'           => $article_id,
						'post_content' => self::feed(
							$coverage_id,
							[
								'latestOnly'  => true,
								'latestCount' => 3,
							]
						) . self::feed( $coverage_id ),
					]
				)
			),
			'Adding a capped feed next to the full one.'
		);
		$this->assertTrue(
			self::clears_map(
				fn() => wp_update_post(
					[
						'ID'           => $article_id,
						'post_content' => self::feed(
							$coverage_id,
							[
								'latestOnly'  => true,
								'latestCount' => 4,
							]
						) . self::feed( $coverage_id ),
					]
				)
			),
			'Changing how many entries a capped feed shows.'
		);
		$this->assertFalse(
			self::clears_map(
				fn() => wp_update_post(
					[
						'ID'         => $known_id,
						'post_title' => 'Renamed',
					]
				)
			),
			'Renaming a pattern.'
		);
		$this->assertTrue(
			self::clears_map(
				fn() => wp_update_post(
					[
						'ID'           => $known_id,
						'post_content' => self::status( $coverage_id ),
					]
				)
			),
			'Changing a pattern\'s blocks.'
		);
		$this->assertTrue( self::clears_map( fn() => self::publish( self::ref( $known_id ) ), [ $known_id ] ), 'A post using a pattern that shows a coverage.' );
		$this->assertFalse( self::clears_map( fn() => self::publish( self::ref( $other_id ) ), [ $known_id ] ), 'A post using another pattern.' );
		$this->assertFalse( self::clears_map( fn() => self::publish( self::feed( $coverage_id ), [ 'post_status' => 'draft' ] ) ), 'A draft.' );
	}

	/**
	 * Templates and template parts clear the map whatever they hold: which
	 * of them a breakout post renders with depends on their slugs and the
	 * parts they hold, and resetting one hands its slug back to the theme's
	 * file, which the deleted post doesn't show.
	 */
	public function test_templates_always_clear_the_map() {
		$this->assertTrue( self::clears_map( fn() => self::publish( 'Plain.', [ 'post_type' => 'wp_template' ] ) ), 'Customizing a template.' );
		$this->assertTrue( self::clears_map( fn() => self::publish( 'Plain.', [ 'post_type' => 'wp_template_part' ] ) ), 'Customizing a template part.' );

		$template_id = self::publish( 'Plain.', [ 'post_type' => 'wp_template' ] );

		$this->assertTrue(
			self::clears_map(
				fn() => wp_update_post(
					[
						'ID'           => $template_id,
						'post_content' => '<!-- wp:template-part {"slug":"header"} /-->',
					]
				)
			),
			'Editing a template.'
		);
		$this->assertTrue( self::clears_map( fn() => wp_delete_post( $template_id, true ) ), 'Resetting a template.' );
	}

	/**
	 * A pattern that only wraps another one leads to a block too, so a post
	 * starting to use it clears the map, and the inner pattern is listed.
	 */
	public function test_patterns_wrapping_a_pattern_with_a_block_count() {
		$coverage_id = self::create_coverage();
		$inner_id    = self::pattern( 'Storm status', self::status( $coverage_id ) );
		$wrapper_id  = self::pattern( 'Storm header', self::ref( $inner_id ) );

		self::publish( self::ref( $wrapper_id ), [ 'post_type' => 'page' ] );

		$this->assertSame( [ 'wp_block:' . $inner_id => [ 'Coverage Status' ] ], self::blocks( $coverage_id ) );

		$patterns = get_option( Placements::OPTION )['patterns'];

		$this->assertEqualsCanonicalizing( [ $inner_id, $wrapper_id ], $patterns );
		$this->assertTrue( self::clears_map( fn() => self::publish( self::ref( $wrapper_id ) ), $patterns ), 'A post starting to use the wrapper.' );
	}

	/**
	 * Widget changes, theme switches and updates clear the map; translation
	 * updates don't.
	 */
	public function test_widgets_and_theme_changes_clear_the_map() {
		$this->assertTrue( self::clears_map( fn() => update_option( 'widget_block', [ 2 => [ 'content' => self::status() ] ] ) ), 'Editing block widgets.' );
		$this->assertTrue( self::clears_map( fn() => update_option( 'sidebars_widgets', [ 'sidebar-1' => [ 'block-2' ] ] ) ), 'Moving widgets.' );
		$this->assertSame( 10, has_action( 'after_switch_theme', [ Placements::class, 'flush' ] ), 'Switching themes, once the new theme is loaded.' );
		$this->assertTrue( self::clears_map( fn() => Placements::flush_on_upgrade( null, [ 'type' => 'theme' ] ) ), 'Updating a theme.' );
		$this->assertTrue( self::clears_map( fn() => Placements::flush_on_upgrade( null, [ 'type' => 'plugin' ] ) ), 'Updating a plugin.' );
		$this->assertFalse( self::clears_map( fn() => Placements::flush_on_upgrade( null, [ 'type' => 'translation' ] ) ), 'Updating translations.' );
	}

	/**
	 * Only moves into or out of the trash change which coverage a Custom
	 * block shows, so only they clear the map.
	 */
	public function test_coverage_status_clears_the_map_only_around_the_trash() {
		$coverage_id = self::create_coverage( 'active' );

		$this->assertFalse( self::clears_map( fn() => update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, 'paused' ) ), 'Pausing a coverage.' );
		$this->assertTrue( self::clears_map( fn() => update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, 'trash' ) ), 'Trashing a coverage.' );
		$this->assertTrue( self::clears_map( fn() => update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, 'active' ) ), 'Restoring a coverage.' );
		$this->assertFalse( self::clears_map( fn() => add_term_meta( self::create_coverage(), Taxonomy::STATUS_META_KEY, 'active' ) ), 'A coverage created live.' );
		$this->assertTrue( self::clears_map( fn() => add_term_meta( self::create_coverage(), Taxonomy::STATUS_META_KEY, 'trash' ) ), 'A coverage created in the trash.' );
		$this->assertFalse( self::clears_map( fn() => delete_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY ) ), 'Removing a live status.' );
		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, 'trash' );
		$this->assertTrue( self::clears_map( fn() => delete_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY ) ), 'Removing a trashed status.' );
		$this->assertFalse( self::clears_map( fn() => update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/live/' ) ) ), 'Other coverage meta.' );
	}

	/**
	 * An Automatic block in a breakout post follows its entry's coverage, so
	 * moving that entry to another coverage clears the map.
	 */
	public function test_moving_a_broken_out_entry_clears_the_map() {
		$coverage_id = self::create_coverage();
		$other_id    = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		$plain_id    = self::create_entry( $coverage_id );

		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, self::publish( self::status() ) );

		$this->assertFalse( self::clears_map( fn() => wp_set_object_terms( $plain_id, [ $other_id ], Taxonomy::TAXONOMY_SLUG ) ), 'An entry without a breakout post.' );
		$this->assertFalse( self::clears_map( fn() => wp_set_object_terms( $entry_id, [ $coverage_id ], Taxonomy::TAXONOMY_SLUG ) ), 'The same coverage again.' );
		$this->assertTrue( self::clears_map( fn() => wp_set_object_terms( $entry_id, [ $other_id ], Taxonomy::TAXONOMY_SLUG ) ), 'Another coverage.' );
	}

	/**
	 * An Automatic block in a breakout post shows its entry's coverage.
	 */
	public function test_automatic_blocks_in_a_breakout_post_show_its_coverage() {
		$coverage_id = self::create_coverage();
		$breakout_id = self::breakout( $coverage_id );

		wp_update_post(
			[
				'ID'           => $breakout_id,
				'post_content' => self::follow() . '<!-- wp:paragraph --><p>Breakout story.</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertSame( [ 'post:' . $breakout_id => [ 'Follow Coverage' ] ], self::blocks( $coverage_id ) );
	}

	/**
	 * Admin reads rebuild an out-of-date map, or one built for another
	 * theme; the front end keeps the stored one.
	 */
	public function test_admin_reads_rebuild_an_out_of_date_map() {
		$coverage_id = self::create_coverage();
		$page_id     = self::publish( self::feed( $coverage_id ) );

		update_option(
			Placements::OPTION,
			[
				'pages'    => [],
				'places'   => [],
				'breakout' => [],
				'patterns' => [],
				'theme'    => 'another-theme@1.0',
			],
			false
		);
		delete_option( Placements::STALE_OPTION );

		$this->assertSame( 0, Placements::page_id( $coverage_id ), 'The front end keeps the stored map.' );
		$this->assertSame( [ 'post:' . $page_id ], array_keys( self::rows( $coverage_id ) ), 'An admin read rebuilds a map built for another theme.' );
		$this->assertSame( $page_id, Placements::page_id( $coverage_id ) );
	}

	/**
	 * A canonical URL saved with different percent-encoding still matches
	 * its page.
	 */
	public function test_the_main_page_matches_whatever_the_encoding() {
		$this->set_permalink_structure( '/%postname%/' );
		$coverage_id = self::create_coverage();
		$page_id     = self::publish(
			self::feed( $coverage_id ),
			[
				'post_type' => 'page',
				'post_name' => 'ураган',
			]
		);
		$slug = strtolower( rawurlencode( 'ураган' ) );

		$this->assertStringContainsString( '/' . $slug . '/', get_permalink( $page_id ) );

		update_term_meta( $coverage_id, Taxonomy::CANONICAL_URL_META_KEY, home_url( '/' . strtoupper( $slug ) . '/' ) );
		$rows = Placements::for_coverage( $coverage_id );

		$this->assertSame( [ 'post:' . $page_id ], array_column( $rows, 'id' ) );
		$this->assertTrue( $rows[0]['isMain'] );
	}

	/**
	 * A long request's cached copy of the mark can be out of date once a
	 * rebuild elsewhere deletes it; a change must still mark the map.
	 */
	public function test_a_change_marks_the_map_even_when_the_cached_mark_is_out_of_date() {
		global $wpdb;

		Placements::flush();
		get_option( Placements::STALE_OPTION );
		$wpdb->delete( $wpdb->options, [ 'option_name' => Placements::STALE_OPTION ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		Placements::flush();

		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Placements::STALE_OPTION ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * A long request's cached copy of the scheduled events can still list a
	 * rebuild that has already run; a change must still schedule one.
	 */
	public function test_a_rebuild_is_scheduled_even_when_the_cached_events_are_out_of_date() {
		global $wpdb;

		wp_clear_scheduled_hook( Placements::REBUILD_HOOK );
		wp_schedule_single_event( time(), Placements::REBUILD_HOOK );
		$wpdb->update( $wpdb->options, [ 'option_value' => maybe_serialize( [ 'version' => 2 ] ) ], [ 'option_name' => 'cron' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertNotFalse( wp_next_scheduled( Placements::REBUILD_HOOK ), 'The cached events still list it.' );

		Placements::schedule_rebuild();

		$this->assertStringContainsString( Placements::REBUILD_HOOK, (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'cron'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Listing every coverage reads placements once per coverage, so the
	 * out-of-date check runs once per request until the next change.
	 */
	public function test_the_freshness_check_runs_once_per_request_until_a_change() {
		$coverage_id = self::create_coverage();
		Placements::for_coverage( $coverage_id );
		Placements::rebuild();
		$checks = 0;
		$count  = function ( $query ) use ( &$checks ) {
			if ( str_contains( $query, Placements::STALE_OPTION ) ) {
				++$checks;
			}
			return $query;
		};
		add_filter( 'query', $count );
		Placements::for_coverage( $coverage_id );
		Placements::for_coverage( self::create_coverage() );
		$before_change = $checks;
		Placements::flush();
		$checks = 0;
		Placements::for_coverage( $coverage_id );
		remove_filter( 'query', $count );

		$this->assertSame( 0, $before_change );
		$this->assertGreaterThan( 0, $checks, 'A change makes the next read check again.' );
	}

	/**
	 * Only one rebuild runs at a time: while another holds the lock, an admin
	 * read uses the stored map and the scheduled event tries again later. A
	 * lock whose time is up is taken over.
	 */
	public function test_only_one_rebuild_runs_at_a_time() {
		global $wpdb;

		$coverage_id = self::create_coverage();
		$page_id     = self::publish( self::feed( $coverage_id ) );
		$stored      = [
			'pages'    => [],
			'places'   => [],
			'breakout' => [],
			'patterns' => [],
			'theme'    => self::theme(),
		];
		update_option( Placements::OPTION, $stored, false );
		wp_clear_scheduled_hook( Placements::REBUILD_HOOK );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->options,
			[
				'option_name'  => Placements::LOCK_OPTION,
				'option_value' => (string) ( time() + 60 ),
				'autoload'     => 'off',
			]
		);

		$this->assertSame( [], Placements::for_coverage( $coverage_id ), 'An admin read uses the stored map.' );

		Placements::rebuild();

		$this->assertSame( $stored, get_option( Placements::OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( Placements::REBUILD_HOOK ), 'The event tries again later.' );

		$wpdb->update( $wpdb->options, [ 'option_value' => (string) ( time() - 1 ) ], [ 'option_name' => Placements::LOCK_OPTION ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		Placements::rebuild();

		$this->assertSame( [ $coverage_id => $page_id ], get_option( Placements::OPTION )['pages'], 'An abandoned lock is taken over.' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Placements::LOCK_OPTION ) ), 'The lock is released.' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Activation schedules the first build; deactivation drops the map and
	 * any pending rebuild.
	 */
	public function test_activation_schedules_a_build_and_deactivation_cleans_up() {
		delete_option( Placements::STALE_OPTION );
		wp_clear_scheduled_hook( Placements::REBUILD_HOOK );

		Placements::activate();

		$this->assertNotFalse( wp_next_scheduled( Placements::REBUILD_HOOK ) );

		Placements::rebuild();
		Placements::deactivate();

		$this->assertFalse( get_option( Placements::OPTION ) );
		$this->assertFalse( get_option( Placements::STALE_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Placements::REBUILD_HOOK ) );
	}
}
