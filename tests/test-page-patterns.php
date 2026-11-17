<?php
/**
 * Tests for the page patterns in the "Rolling Coverage pages" category.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Layout_Templates;
use Newspack_Rolling_Coverage\Page_Patterns;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Scoreboard_Block;

/**
 * The patterns register in their own category, hold their layouts as inner
 * blocks and stay out of the new-page pattern window.
 */
class Test_Page_Patterns extends Rolling_Coverage_TestCase {

	const SLUGS = [
		'live-page',
		'live-page-latest-first',
		'live-page-side-column',
		'match-side',
		'match-centered',
		'breaking-news',
		'follow-page',
		'follow-band',
		'every-live-story',
	];

	/**
	 * Registers the patterns again, as they are on a fresh request.
	 */
	public function set_up() {
		parent::set_up();
		self::unregister_all();
		Page_Patterns::register();
	}

	/**
	 * Leaves the registry as the test found it.
	 */
	public function tear_down() {
		wp_clean_theme_json_cache();
		self::unregister_all();
		parent::tear_down();
	}

	/**
	 * Removes the category and the patterns.
	 */
	private static function unregister_all(): void {
		foreach ( self::SLUGS as $slug ) {
			if ( WP_Block_Patterns_Registry::get_instance()->is_registered( 'newspack-rolling-coverage/' . $slug ) ) {
				unregister_block_pattern( 'newspack-rolling-coverage/' . $slug );
			}
		}

		if ( WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( Page_Patterns::CATEGORY ) ) {
			unregister_block_pattern_category( Page_Patterns::CATEGORY );
		}
	}

	/**
	 * A registered pattern, with its content read.
	 *
	 * @param string $slug Pattern slug.
	 * @return array
	 */
	private static function pattern( string $slug ): array {
		return WP_Block_Patterns_Registry::get_instance()->get_registered( 'newspack-rolling-coverage/' . $slug );
	}

	/**
	 * The patterns' slugs.
	 *
	 * @return array[]
	 */
	public function slugs(): array {
		return array_map( static fn( $slug ) => [ $slug ], array_combine( self::SLUGS, self::SLUGS ) );
	}

	/**
	 * Every Rolling Coverage block in markup, nested ones included.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return array[]
	 */
	private static function feeds( array $blocks ): array {
		$found = [];

		foreach ( $blocks as $block ) {
			if ( Rolling_Coverage_Block::BLOCK_NAME === $block['blockName'] ) {
				$found[] = $block;
			}

			$found = array_merge( $found, self::feeds( $block['innerBlocks'] ) );
		}

		return $found;
	}

	/**
	 * The category exists and is not the layout category.
	 */
	public function test_registers_the_category() {
		$category = WP_Block_Pattern_Categories_Registry::get_instance()->get_registered( 'rolling-coverage-pages' );

		$this->assertSame( 'Rolling Coverage pages', $category['label'] );
		$this->assertNotSame( \Newspack_Rolling_Coverage\Layout::PATTERN_CATEGORY, Page_Patterns::CATEGORY );
	}

	/**
	 * Each pattern is in the category and out of the new-page window.
	 *
	 * @dataProvider slugs
	 *
	 * @param string $slug Pattern slug.
	 */
	public function test_pattern_is_registered_for_the_inserter_only( string $slug ) {
		$pattern = self::pattern( $slug );

		$this->assertSame( [ 'rolling-coverage-pages' ], $pattern['categories'] );
		$this->assertNotEmpty( $pattern['title'] );
		$this->assertNotEmpty( $pattern['description'] );
		$this->assertNotEmpty( $pattern['keywords'] );
		$this->assertNotEmpty( $pattern['viewportWidth'] );
		$this->assertArrayNotHasKey( 'blockTypes', $pattern );
		$this->assertArrayNotHasKey( 'templateTypes', $pattern );
		$this->assertStringNotContainsString( 'core/post-content', wp_json_encode( $pattern ) );
	}

	/**
	 * Every Rolling Coverage block in a pattern holds its layout and no
	 * liveblog or shared layout. The list of liveblogs has no feed.
	 *
	 * @dataProvider slugs
	 *
	 * @param string $slug Pattern slug.
	 */
	public function test_feeds_hold_a_layout_and_no_coverage( string $slug ) {
		$feeds = self::feeds( parse_blocks( self::pattern( $slug )['content'] ) );

		if ( 'every-live-story' === $slug ) {
			$this->assertEmpty( $feeds );
		} else {
			$this->assertNotEmpty( $feeds );
		}

		foreach ( $feeds as $feed ) {
			$this->assertNotEmpty( $feed['innerBlocks'] );
			$this->assertArrayNotHasKey( 'coverageId', $feed['attrs'] );
			$this->assertArrayNotHasKey( 'layoutId', $feed['attrs'] );
		}

		$this->assertStringNotContainsString( '{{', self::pattern( $slug )['content'] );
	}

	/**
	 * The pattern's layout is the built-in template, filled in for the site.
	 */
	public function test_embedded_layout_matches_the_built_in_template() {
		$expected = serialize_blocks( Layout_Templates::get_blocks( 'clock' ) );
		$feed     = self::feeds( parse_blocks( self::pattern( 'live-page' )['content'] ) )[0];

		$this->assertSame( $expected, serialize_blocks( $feed['innerBlocks'] ) );
		$this->assertFalse( $feed['attrs']['showAvatar'] );
	}

	/**
	 * The blocks each pattern is made of, and the settings picking its layout
	 * would have made.
	 */
	public function test_patterns_use_the_expected_layouts_and_settings() {
		$latest = self::feeds( parse_blocks( self::pattern( 'live-page-latest-first' )['content'] ) );

		$this->assertCount( 2, $latest );
		$this->assertTrue( $latest[0]['attrs']['latestOnly'] );
		$this->assertSame( 1, $latest[0]['attrs']['latestCount'] );
		$this->assertSame( serialize_blocks( Layout_Templates::get_blocks( 'stream' ) ), serialize_blocks( $latest[1]['innerBlocks'] ) );

		$flash = self::feeds( parse_blocks( self::pattern( 'breaking-news' )['content'] ) )[0];

		$this->assertTrue( $flash['attrs']['hideWhenEnded'] );
		$this->assertSame( serialize_blocks( Layout_Templates::get_blocks( 'flash' ) ), serialize_blocks( $flash['innerBlocks'] ) );

		$wire = self::feeds( parse_blocks( self::pattern( 'follow-band' )['content'] ) )[0];

		$this->assertSame( 5, $wire['attrs']['latestCount'] );
		$this->assertSame( serialize_blocks( Layout_Templates::get_blocks( 'wire' ) ), serialize_blocks( $wire['innerBlocks'] ) );
	}

	/**
	 * Status, Follow, Update Timer and Scoreboard blocks have no liveblog of
	 * their own.
	 *
	 * @dataProvider slugs
	 *
	 * @param string $slug Pattern slug.
	 */
	public function test_status_and_follow_blocks_follow_the_page_or_wait_for_a_pick( string $slug ) {
		$content = self::pattern( $slug )['content'];

		$this->assertDoesNotMatchRegularExpression( '/wp:newspack-rolling-coverage\/(coverage-status|coverage-follow|update-timer|scoreboard) \{[^}]*"coverageId"/', $content );
	}

	/**
	 * The pattern's own blocks, without those of the layouts inside its feeds.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return array[]
	 */
	private static function own_blocks( array $blocks ): array {
		$own = [];

		foreach ( $blocks as $block ) {
			if ( Rolling_Coverage_Block::BLOCK_NAME === $block['blockName'] ) {
				continue;
			}

			$own[] = $block['attrs'];
			$own   = array_merge( $own, self::own_blocks( $block['innerBlocks'] ) );
		}

		return $own;
	}

	/**
	 * No pattern hides blocks by viewport: editors see each block once.
	 *
	 * @dataProvider slugs
	 *
	 * @param string $slug Pattern slug.
	 */
	public function test_patterns_hide_no_blocks( string $slug ) {
		$this->assertStringNotContainsString( 'blockVisibility', wp_json_encode( self::own_blocks( parse_blocks( self::pattern( $slug )['content'] ) ) ) );
	}

	/**
	 * The side column is the first column, so it stacks first on phones.
	 */
	public function test_side_column_comes_first() {
		$columns = parse_blocks( self::pattern( 'live-page-side-column' )['content'] )[0]['innerBlocks'];

		$this->assertSame( '33.33%', $columns[0]['attrs']['width'] );
		$this->assertSame( 'newspack-rolling-coverage/coverage-status', $columns[0]['innerBlocks'][0]['blockName'] );
		$this->assertSame( Rolling_Coverage_Block::BLOCK_NAME, $columns[1]['innerBlocks'][0]['blockName'] );
	}

	/**
	 * The lead entry is set at headline scale with block attributes.
	 */
	public function test_latest_entry_is_the_lead() {
		add_filter(
			'wp_theme_json_data_theme',
			static fn( $data ) => $data->update_with(
				[
					'version'  => WP_Theme_JSON::LATEST_SCHEMA,
					'settings' => [
						'typography' => [
							'fontSizes' => [
								[
									'slug' => 'large',
									'name' => 'large',
									'size' => '1.5rem',
								],
								[
									'slug' => 'xx-large',
									'name' => 'xx-large',
									'size' => '3rem',
								],
							],
						],
					],
				]
			)
		);
		wp_clean_theme_json_cache();

		$lead = self::feeds( parse_blocks( self::pattern( 'live-page-latest-first' )['content'] ) )[0];
		$json = wp_json_encode( $lead['innerBlocks'] );

		$this->assertStringContainsString( '"fontSize":"xx-large"', $json );
		$this->assertStringContainsString( 'post-content', $json );
	}

	/**
	 * Every block of a name in parsed blocks, nested ones included.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @param string  $name   Block name.
	 * @return array[]
	 */
	private static function find( array $blocks, string $name ): array {
		$found = [];

		foreach ( $blocks as $block ) {
			if ( $name === $block['blockName'] ) {
				$found[] = $block;
			}

			$found = array_merge( $found, self::find( $block['innerBlocks'], $name ) );
		}

		return $found;
	}

	/**
	 * The score keys a scoreboard's paragraphs are bound to, in order.
	 *
	 * @param array $scoreboard Parsed Scoreboard block.
	 * @return string[]
	 */
	private static function bound_keys( array $scoreboard ): array {
		return array_map(
			static function ( $paragraph ) {
				$binding = $paragraph['attrs']['metadata']['bindings']['content'];

				return Scoreboard_Block::SOURCE_NAME === $binding['source'] ? $binding['args']['key'] : '';
			},
			self::find( $scoreboard['innerBlocks'], 'core/paragraph' )
		);
	}

	/**
	 * The Match patterns show the page's score above or beside a Clock feed.
	 */
	public function test_match_patterns_show_the_score_and_a_clock_feed() {
		$clock = serialize_blocks( Layout_Templates::get_blocks( 'clock' ) );

		foreach ( [ 'match-side', 'match-centered' ] as $slug ) {
			$blocks      = parse_blocks( self::pattern( $slug )['content'] );
			$scoreboards = self::find( $blocks, Scoreboard_Block::BLOCK_NAME );
			$feeds       = self::feeds( $blocks );

			$this->assertCount( 1, $scoreboards, $slug );
			$this->assertArrayNotHasKey( 'coverageId', $scoreboards[0]['attrs'], $slug );
			$this->assertCount( 1, $feeds, $slug );
			$this->assertSame( $clock, serialize_blocks( $feeds[0]['innerBlocks'] ), $slug );
			$this->assertCount( 1, self::find( $blocks, 'newspack-rolling-coverage/coverage-status' ), $slug );
		}
	}

	/**
	 * Beside the feed, the scoreboard and line-ups are in the first column,
	 * so they stack above the feed on phones, with a row for each team.
	 */
	public function test_match_side_column_comes_first() {
		$columns = parse_blocks( self::pattern( 'match-side' )['content'] )[0]['innerBlocks'];

		$this->assertSame( '33.33%', $columns[0]['attrs']['width'] );
		$this->assertSame( Scoreboard_Block::BLOCK_NAME, $columns[0]['innerBlocks'][0]['blockName'] );
		$this->assertCount( 3, self::find( $columns[0]['innerBlocks'], 'core/heading' ) );
		$this->assertCount( 2, self::find( $columns[0]['innerBlocks'], 'core/list' ) );
		$this->assertEmpty( self::feeds( $columns[0]['innerBlocks'] ) );
		$this->assertCount( 1, self::feeds( $columns[1]['innerBlocks'] ) );
		$this->assertSame( [ 'team1Name', 'team1Score', 'team2Name', 'team2Score', 'status' ], self::bound_keys( $columns[0]['innerBlocks'][0] ) );
	}

	/**
	 * The centered scoreline leads the page, with the scores in the middle
	 * of one line.
	 */
	public function test_match_centered_leads_with_the_score() {
		$blocks = parse_blocks( self::pattern( 'match-centered' )['content'] );
		$blocks = array_values( array_filter( $blocks, static fn( $block ) => ! empty( $block['blockName'] ) ) );

		$this->assertSame( Scoreboard_Block::BLOCK_NAME, $blocks[0]['blockName'] );
		$this->assertSame( 'newspack-rolling-coverage/coverage-status', $blocks[1]['blockName'] );
		$this->assertSame( 'center', $blocks[1]['attrs']['layout']['justifyContent'] );
		$this->assertSame( Rolling_Coverage_Block::BLOCK_NAME, $blocks[2]['blockName'] );
		$this->assertSame( [ 'team1Name', 'team1Score', 'team2Score', 'team2Name', 'status' ], self::bound_keys( $blocks[0] ) );
	}

	/**
	 * The scoreboards take the theme's sizes and heading font as presets,
	 * with the markup core saves for those settings.
	 */
	public function test_scoreboard_uses_the_theme_presets() {
		add_filter(
			'wp_theme_json_data_theme',
			static fn( $data ) => $data->update_with(
				[
					'version'  => WP_Theme_JSON::LATEST_SCHEMA,
					'settings' => [
						'color'      => [
							'palette' => [
								[
									'slug'  => 'contrast-3',
									'name'  => 'Contrast 3',
									'color' => '#6c6c6c',
								],
							],
						],
						'typography' => [
							'fontFamilies'     => [
								[
									'slug'       => 'display',
									'name'       => 'Display',
									'fontFamily' => 'Georgia, serif',
								],
							],
							'defaultFontSizes' => false,
							'fontSizes'        => [
								[
									'slug' => 'x-small',
									'name' => 'x-small',
									'size' => '0.875rem',
								],
								[
									'slug' => 'x-large',
									'name' => 'x-large',
									'size' => '2rem',
								],
								[
									'slug' => 'xxxxx-large',
									'name' => 'xxxxx-large',
									'size' => '5rem',
								],
							],
						],
					],
					'styles'   => [
						'elements' => [
							'heading' => [ 'typography' => [ 'fontFamily' => 'var(--wp--preset--font-family--display)' ] ],
						],
					],
				]
			)
		);
		wp_clean_theme_json_cache();

		$scoreboard = self::find( parse_blocks( self::pattern( 'match-centered' )['content'] ), Scoreboard_Block::BLOCK_NAME )[0];
		$parts      = self::find( $scoreboard['innerBlocks'], 'core/paragraph' );

		$this->assertSame( 'x-large', $parts[0]['attrs']['fontSize'] );
		$this->assertSame( 'display', $parts[0]['attrs']['fontFamily'] );
		$this->assertSame( 'xxxxx-large', $parts[1]['attrs']['fontSize'] );
		$this->assertSame( 'contrast-3', $parts[4]['attrs']['textColor'] );
		$this->assertArrayNotHasKey( 'fontSize', $parts[4]['attrs'] );
		$this->assertSame( '<p class="has-text-align-right use-header-font has-display-font-family has-x-large-font-size" style="font-weight:700;line-height:1.2"></p>', $parts[0]['innerHTML'] );
	}
}
