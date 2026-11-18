<?php
/**
 * Tests for the page patterns in the "Rolling Coverage - Pages" category.
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

		$this->assertSame( 'Rolling Coverage - Pages', $category['label'] );
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
		$names = [
			'newspack-rolling-coverage/coverage-status',
			'newspack-rolling-coverage/coverage-follow',
			'newspack-rolling-coverage/update-timer',
			Scoreboard_Block::BLOCK_NAME,
			Rolling_Coverage_Block::BLOCK_NAME,
		];

		foreach ( $names as $name ) {
			foreach ( self::find( parse_blocks( self::pattern( $slug )['content'] ), $name ) as $block ) {
				$this->assertArrayNotHasKey( 'coverageId', $block['attrs'], $name );
			}
		}
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
	 * With Newspack Blocks, the side column rows take its style that shows the
	 * side column second from tablet width up; without it, they have no style.
	 */
	public function test_side_columns_use_the_newspack_blocks_order_style() {
		foreach ( [ true, false ] as $reorder ) {
			$markup  = Page_Patterns::side_columns_open( $reorder ) . '<!-- wp:column /--></div><!-- /wp:columns -->';
			$columns = parse_blocks( $markup )[0];

			$this->assertSame( 'wide', $columns['attrs']['align'] );
			$this->assertSame( $reorder, Page_Patterns::FIRST_COLUMN_TO_SECOND === ( $columns['attrs']['className'] ?? '' ) );
			$this->assertSame( $reorder, str_contains( $columns['innerHTML'], Page_Patterns::FIRST_COLUMN_TO_SECOND ) );
		}

		$this->assertStringNotContainsString( Page_Patterns::FIRST_COLUMN_TO_SECOND, self::pattern( 'match-side' )['content'] );
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
	 * The score keys of a scoreboard's bound paragraphs, in order.
	 *
	 * @param array $scoreboard Parsed Scoreboard block.
	 * @return string[]
	 */
	private static function bound_keys( array $scoreboard ): array {
		$bound = array_filter(
			self::find( $scoreboard['innerBlocks'], 'core/paragraph' ),
			static fn( $paragraph ) => isset( $paragraph['attrs']['metadata']['bindings'] )
		);

		return array_values(
			array_map(
				static function ( $paragraph ) {
					$binding = $paragraph['attrs']['metadata']['bindings']['content'];

					return Scoreboard_Block::SOURCE_NAME === $binding['source'] ? $binding['args']['key'] : '';
				},
				$bound
			)
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
	 * Beside the feed, the scoreboard and the collapsed line-ups are in the
	 * first column, so they stack above the feed on phones, with a row for
	 * each team.
	 */
	public function test_match_side_column_comes_first() {
		$columns = parse_blocks( self::pattern( 'match-side' )['content'] )[0]['innerBlocks'];

		$this->assertSame( '33.33%', $columns[0]['attrs']['width'] );
		$this->assertSame( Scoreboard_Block::BLOCK_NAME, $columns[0]['innerBlocks'][0]['blockName'] );
		$this->assertCount( 1, self::find( $columns[0]['innerBlocks'], 'core/details' ) );
		$this->assertCount( 2, self::find( $columns[0]['innerBlocks'], 'core/heading' ) );
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
	 * The centered scoreline has the block's dash between the scores, a size
	 * down from them, and the stacked scoreboard beside the feed has none.
	 */
	public function test_match_scoreboards_dash() {
		self::theme(
			[
				'typography' => [
					'defaultFontSizes' => false,
					'fontSizes'        => array_map(
						static fn( $slug ) => [
							'slug' => $slug,
							'name' => $slug,
							'size' => '2rem',
						],
						[ 'x-large', 'xxxx-large', 'xxxxx-large' ]
					),
				],
			]
		);

		$centered = self::find( parse_blocks( self::pattern( 'match-centered' )['content'] ), Scoreboard_Block::BLOCK_NAME )[0];
		$parts    = self::find( $centered['innerBlocks'], 'core/paragraph' );

		$this->assertStringContainsString( Scoreboard_Block::DASH_CLASS, $parts[2]['attrs']['className'] );
		$this->assertArrayNotHasKey( 'bindings', $parts[2]['attrs']['metadata'] );
		$this->assertSame( 'xxxx-large', $parts[2]['attrs']['fontSize'] );
		$this->assertSame( 'xxxxx-large', $parts[1]['attrs']['fontSize'] );
		$this->assertStringContainsString( '>–</p>', $parts[2]['innerHTML'] );

		$side = self::find( parse_blocks( self::pattern( 'match-side' )['content'] ), Scoreboard_Block::BLOCK_NAME )[0];
		$this->assertStringNotContainsString( Scoreboard_Block::DASH_CLASS, serialize_block( $side ) );
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
		$this->assertSame( 'contrast-3', $parts[5]['attrs']['textColor'] );
		$this->assertArrayNotHasKey( 'fontSize', $parts[5]['attrs'] );
		$this->assertSame( '<p class="has-text-align-right use-header-font has-display-font-family has-x-large-font-size" style="font-weight:700;line-height:1.2"></p>', $parts[0]['innerHTML'] );
	}

	/**
	 * Gives the theme font families, font sizes and palette for a test.
	 *
	 * @param array $settings Theme JSON settings.
	 * @param array $styles   Theme JSON styles.
	 */
	private static function theme( array $settings, array $styles = [] ) {
		add_filter(
			'wp_theme_json_data_theme',
			static fn( $data ) => $data->update_with(
				[
					'version'  => WP_Theme_JSON::LATEST_SCHEMA,
					'settings' => $settings,
					'styles'   => $styles,
				]
			)
		);
		wp_clean_theme_json_cache();
	}

	/**
	 * A slug core writes in kebab case in a class is matched the way the
	 * editor saves it.
	 */
	public function test_scoreboard_classes_kebab_case_the_slugs() {
		self::theme(
			[
				'color'      => [
					'palette' => [
						[
							'slug'  => 'contrast3',
							'name'  => 'Contrast 3',
							'color' => '#6c6c6c',
						],
					],
				],
				'typography' => [
					'fontFamilies'     => [
						[
							'slug'       => 'ibmPlexSans',
							'name'       => 'IBM Plex Sans',
							'fontFamily' => 'sans-serif',
						],
					],
					'defaultFontSizes' => false,
					'fontSizes'        => [
						[
							'slug' => 'serif2',
							'name' => 'Serif 2',
							'size' => '2rem',
						],
						[
							'slug' => 'huge2',
							'name' => 'Huge 2',
							'size' => '5rem',
						],
					],
				],
			],
			[ 'elements' => [ 'heading' => [ 'typography' => [ 'fontFamily' => 'var(--wp--preset--font-family--ibm-plex-sans)' ] ] ] ]
		);

		$markup = Page_Patterns::scoreboard(
			[
				'name_size'   => [ 'serif2' ],
				'score_size'  => [ 'huge2' ],
				'status_size' => [ 'serif2' ],
			]
		);
		$parts  = self::find( parse_blocks( $markup ), 'core/paragraph' );

		$this->assertSame( 'ibmPlexSans', $parts[0]['attrs']['fontFamily'] );
		$this->assertSame( 'serif2', $parts[0]['attrs']['fontSize'] );
		$this->assertStringContainsString( 'has-ibm-plex-sans-font-family', $parts[0]['innerHTML'] );
		$this->assertStringContainsString( 'has-serif-2-font-size', $parts[0]['innerHTML'] );
		$this->assertStringContainsString( 'has-huge-2-font-size', $parts[1]['innerHTML'] );
	}

	/**
	 * Team names are Large only on a theme with a block scale, else Normal,
	 * as the block's own template sets them.
	 */
	public function test_scoreboard_name_size_follows_the_theme_scale() {
		self::theme(
			[
				'typography' => [
					'defaultFontSizes' => false,
					'fontSizes'        => [
						[
							'slug' => 'normal',
							'name' => 'Normal',
							'size' => '1rem',
						],
						[
							'slug' => 'large',
							'name' => 'Large',
							'size' => '1.5rem',
						],
						[
							'slug' => 'huge',
							'name' => 'Huge',
							'size' => '3rem',
						],
					],
				],
			]
		);

		$parts = self::find( parse_blocks( Page_Patterns::scoreboard( [] ) ), 'core/paragraph' );

		$this->assertSame( 'normal', $parts[0]['attrs']['fontSize'] );
		$this->assertSame( 'huge', $parts[1]['attrs']['fontSize'] );
	}

	/**
	 * Static headings take the first size the theme has, so a classic theme
	 * gets a preset where the block theme's slugs don't exist.
	 */
	public function test_headings_use_a_size_the_theme_has() {
		self::theme(
			[
				'typography' => [
					'defaultFontSizes' => false,
					'fontSizes'        => [
						[
							'slug' => 'small',
							'name' => 'Small',
							'size' => '0.8rem',
						],
						[
							'slug' => 'normal',
							'name' => 'Normal',
							'size' => '1rem',
						],
						[
							'slug' => 'large',
							'name' => 'Large',
							'size' => '1.5rem',
						],
						[
							'slug' => 'huge',
							'name' => 'Huge',
							'size' => '3rem',
						],
					],
				],
			]
		);

		foreach ( [ 'follow-band', 'follow-page', 'live-page-latest-first', 'every-live-story', 'match-side' ] as $slug ) {
			foreach ( self::find( parse_blocks( self::pattern( $slug )['content'] ), 'core/heading' ) as $heading ) {
				$this->assertContains( $heading['attrs']['fontSize'] ?? '', [ 'small', 'normal', 'large' ], $slug );
				$this->assertStringContainsString( 'has-' . $heading['attrs']['fontSize'] . '-font-size', $heading['innerHTML'], $slug );
			}
		}
	}

	/**
	 * Blocks without the layouts inside their Rolling Coverage blocks.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return array[]
	 */
	private static function without_feeds( array $blocks ): array {
		$kept = [];

		foreach ( $blocks as $block ) {
			if ( Rolling_Coverage_Block::BLOCK_NAME !== $block['blockName'] ) {
				$block['innerBlocks'] = self::without_feeds( $block['innerBlocks'] );
				$kept[]               = $block;
			}
		}

		return $kept;
	}

	/**
	 * A heading that comes before any other in a pattern is a level 2.
	 */
	public function test_first_headings_are_level_two() {
		foreach ( [ 'follow-page', 'every-live-story', 'match-side' ] as $slug ) {
			$headings = self::find( self::without_feeds( parse_blocks( self::pattern( $slug )['content'] ) ), 'core/heading' );

			$this->assertNotEmpty( $headings, $slug );

			foreach ( $headings as $heading ) {
				$this->assertArrayNotHasKey( 'level', $heading['attrs'], $slug );
				$this->assertMatchesRegularExpression( '/^\s*<h2 /', $heading['innerHTML'], $slug );
			}
		}
	}

	/**
	 * The Follow button is the markup the block builds for its own.
	 */
	public function test_follow_markup_is_the_blocks_own() {
		$blocks = parse_blocks( Page_Patterns::follow() );

		$this->assertSame( 'newspack-rolling-coverage/coverage-follow', $blocks[0]['blockName'] );
		$this->assertSame( 'Follow', $blocks[0]['innerBlocks'][0]['attrs']['metadata']['name'] );
		$button = $blocks[0]['innerBlocks'][0]['innerBlocks'][0];
		$this->assertSame( 'followTag', $button['attrs']['metadata']['bindings']['url']['args']['key'] );
		$this->assertStringContainsString( '>Follow</button>', $button['innerHTML'] );
	}
}
