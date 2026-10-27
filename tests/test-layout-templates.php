<?php
/**
 * Tests for the built-in layouts as the plugin ships them, and the patterns
 * that follow them until someone edits them.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Entry_Name;
use Newspack_Rolling_Coverage\Layout;
use Newspack_Rolling_Coverage\Layout_Templates;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * A built-in layout renders from the plugin, filled in for the site, while
 * its pattern holds the content it last got from the plugin.
 */
class Test_Layout_Templates extends Rolling_Coverage_TestCase {

	const MARKER = 'Pattern content marker';

	/**
	 * Forget the theme.json data a test switched to.
	 */
	public function tear_down() {
		parent::tear_down();
		wp_clean_theme_json_cache();
	}

	/**
	 * Markup for a layout pattern holding one paragraph.
	 *
	 * @param string $text Paragraph text.
	 * @return string
	 */
	private static function layout_markup( string $text = self::MARKER ): string {
		return '<!-- wp:newspack-rolling-coverage/rolling-coverage --><!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph --><!-- /wp:newspack-rolling-coverage/rolling-coverage -->';
	}

	/**
	 * Creates a built-in layout's pattern through the route the editor uses.
	 *
	 * @param string $slug Built-in layout slug.
	 * @return int Pattern ID.
	 */
	private static function create_built_in( string $slug = 'stream' ): int {
		self::log_in_as( 'editor' );

		return (int) self::dispatch( 'POST', '/layouts/' . $slug )->get_data()['id'];
	}

	/**
	 * Gives an unedited built-in layout's pattern the layout an earlier
	 * version of the plugin shipped, as a site that has since updated holds
	 * it: other content, with the hash recorded for that content.
	 *
	 * @param int $layout_id Pattern ID.
	 */
	private static function store_earlier_layout( int $layout_id ): void {
		global $wpdb;

		$wpdb->update( $wpdb->posts, [ 'post_content' => self::layout_markup() ], [ 'ID' => $layout_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		update_post_meta( $layout_id, Layout::HASH_META_KEY, md5( self::layout_markup() ) );
		clean_post_cache( $layout_id );
	}

	/**
	 * Renders a story block synced to a layout, as core does.
	 *
	 * @param int $layout_id Pattern ID.
	 * @return string
	 */
	private static function render_synced( int $layout_id ): string {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		$attributes = [
			'coverageId' => $coverage_id,
			'layoutId'   => $layout_id,
		];
		$parsed     = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];
		$block      = apply_filters( 'render_block_data', $parsed, $parsed, null );

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * Replaces the theme's palette and font sizes for one test.
	 *
	 * @param string[] $colors     Palette slugs.
	 * @param string[] $font_sizes Font size slugs.
	 */
	private static function set_theme_presets( array $colors, array $font_sizes ): void {
		$settings = [
			'color'      => [
				'palette' => array_map(
					static fn( $slug ) => [
						'slug'  => $slug,
						'name'  => $slug,
						'color' => '#777',
					],
					$colors
				),
			],
			'typography' => [
				'fontSizes' => array_map(
					static fn( $slug ) => [
						'slug' => $slug,
						'name' => $slug,
						'size' => '1rem',
					],
					$font_sizes
				),
			],
		];

		add_filter(
			'wp_theme_json_data_theme',
			static fn( $data ) => $data->update_with(
				[
					'version'  => WP_Theme_JSON::LATEST_SCHEMA,
					'settings' => $settings,
				]
			)
		);
		wp_clean_theme_json_cache();
	}

	/**
	 * The built-in layout slugs.
	 *
	 * @return array[]
	 */
	public function built_in_slugs(): array {
		return array_map( static fn( $slug ) => [ $slug ], array_combine( Layout::BUILT_IN_SLUGS, Layout::BUILT_IN_SLUGS ) );
	}

	/**
	 * Every built-in layout ships, with each of its tokens filled in.
	 *
	 * @dataProvider built_in_slugs
	 *
	 * @param string $slug Built-in layout slug.
	 */
	public function test_every_built_in_layout_fills_in_every_token( string $slug ) {
		$blocks = Layout_Templates::get_blocks( $slug );

		$this->assertNotEmpty( $blocks );
		$this->assertStringNotContainsString( '{{', serialize_blocks( $blocks ) );
	}

	/**
	 * Themes and the colors and sizes a layout takes from them.
	 *
	 * @return array[]
	 */
	public function theme_presets(): array {
		return [
			'Newspack Block Theme'  => [ [ 'base', 'contrast-3' ], [ 'small', 'medium', 'x-large' ], 'contrast-3', 'medium' ],
			'Newspack Theme'        => [ [ 'primary', 'medium-gray' ], [ 'small', 'normal', 'large' ], 'medium-gray', 'normal' ],
			'theme without presets' => [ [], [], null, 'medium' ],
		];
	}

	/**
	 * A layout's muted dates and its font sizes follow the theme, as the
	 * editor picks them, and a theme without a muted color gets none.
	 *
	 * @dataProvider theme_presets
	 *
	 * @param string[]    $colors     Palette slugs.
	 * @param string[]    $font_sizes Font size slugs.
	 * @param string|null $muted      The muted color expected.
	 * @param string      $size       The font size expected for a medium heading.
	 */
	public function test_layout_takes_the_theme_muted_color_and_font_sizes( array $colors, array $font_sizes, ?string $muted, string $size ) {
		self::set_theme_presets( $colors, $font_sizes );

		$ticker = serialize_blocks( Layout_Templates::get_blocks( 'ticker' ) );

		$this->assertStringContainsString( '"fontSize":"' . $size . '"', $ticker );
		$this->assertStringContainsString( 'has-' . $size . '-font-size', $ticker, 'A static block saves the size as a class too.' );

		if ( $muted ) {
			$this->assertStringContainsString( '"textColor":"' . $muted . '"', $ticker );
		} else {
			$this->assertStringNotContainsString( '"textColor"', $ticker, 'The muted dates are Ticker\'s only text colors.' );
		}
	}

	/**
	 * The link to the coverage page uses the site's name for entries.
	 */
	public function test_layout_links_to_the_coverage_in_the_site_name_for_entries() {
		$this->assertStringContainsString( 'See all entries', serialize_blocks( Layout_Templates::get_blocks( 'wire' ) ) );

		update_option(
			Entry_Name::OPTION_KEY,
			[
				'singular' => 'update',
				'plural'   => 'updates',
			]
		);

		$this->assertStringContainsString( 'See all updates', serialize_blocks( Layout_Templates::get_blocks( 'wire' ) ) );
	}

	/**
	 * A layout's words come translated, as the editor shows them.
	 */
	public function test_layout_words_are_translated() {
		add_filter(
			'gettext_newspack-rolling-coverage',
			static fn( $translation, $text ) => 'Share' === $text ? 'Partilhar' : $translation,
			10,
			2
		);

		$stream = serialize_blocks( Layout_Templates::get_blocks( 'stream' ) );

		$this->assertStringContainsString( '>Partilhar<', $stream );
		$this->assertStringNotContainsString( '>Share<', $stream );
	}

	/**
	 * A built-in layout nobody has edited renders as the plugin ships it, not
	 * as its pattern was created.
	 */
	public function test_unedited_built_in_layout_renders_the_plugins_layout() {
		$layout_id = self::create_built_in();
		self::store_earlier_layout( $layout_id );

		$html = self::render_synced( $layout_id );

		$this->assertStringNotContainsString( self::MARKER, $html );
		$this->assertStringContainsString( 'data-rc-share', $html );
	}

	/**
	 * Once someone edits a built-in layout's pattern, it renders as edited.
	 */
	public function test_edited_built_in_layout_renders_its_pattern() {
		$layout_id = self::create_built_in();
		wp_update_post(
			[
				'ID'           => $layout_id,
				'post_content' => self::layout_markup( 'Edited layout' ),
			]
		);

		$this->assertStringContainsString( 'Edited layout', self::render_synced( $layout_id ) );
	}

	/**
	 * A layout created through content filters that change what is stored,
	 * as kses does for a user without unfiltered_html, records the hash of
	 * what was stored, so it still follows the plugin.
	 */
	public function test_layout_created_through_content_filters_follows_the_plugin() {
		// Slashed in and out, as kses filters content.
		add_filter( 'content_save_pre', static fn( $content ) => addslashes( str_replace( 'newspack-rolling-coverage-share', 'newspack-rolling-coverage-share is-filtered', stripslashes( $content ) ) ) );

		$layout_id = self::create_built_in();
		$stored    = get_post_field( 'post_content', $layout_id, 'raw' );

		$this->assertStringContainsString( 'is-filtered', $stored );
		$this->assertSame( md5( $stored ), get_post_meta( $layout_id, Layout::HASH_META_KEY, true ) );
		$this->assertSame( 'stream', Layout::get_unedited_slug( get_post( $layout_id ) ) );
		$this->assertStringNotContainsString( 'is-filtered', self::render_synced( $layout_id ) );
	}

	/**
	 * Ways to rename a built-in layout: from the Patterns list, which sends
	 * only the title, or from the pattern editor, which also sends the layout
	 * it was handed, through content filters or not.
	 *
	 * @return array[]
	 */
	public function renames(): array {
		return [
			'Patterns list'                          => [ false, false ],
			'pattern editor'                         => [ true, false ],
			'pattern editor, through content filter' => [ true, true ],
		];
	}

	/**
	 * Renaming a built-in layout leaves it following the plugin.
	 *
	 * @dataProvider renames
	 *
	 * @param bool $sends_layout Whether the save sends the layout the editor was handed.
	 * @param bool $is_filtered  Whether a content filter changes what is stored.
	 */
	public function test_renamed_built_in_layout_still_follows_the_plugin( bool $sends_layout, bool $is_filtered ) {
		$layout_id = self::create_built_in();
		// Content other than the plugin's, so a save that sends the layout has to record its hash.
		self::store_earlier_layout( $layout_id );

		if ( $is_filtered ) {
			// Slashed in and out, as kses filters content.
			add_filter( 'content_save_pre', static fn( $content ) => addslashes( str_replace( 'newspack-rolling-coverage-share', 'newspack-rolling-coverage-share is-filtered', stripslashes( $content ) ) ) );
		}

		$changes = [
			'ID'         => $layout_id,
			'post_title' => 'Our stream',
		];

		if ( $sends_layout ) {
			$changes['post_content'] = Layout_Templates::get_pattern_content( 'stream' );
		}

		wp_update_post( wp_slash( $changes ) );

		$this->assertSame( 'stream', Layout::get_unedited_slug( get_post( $layout_id ) ) );
	}

	/**
	 * Updates to a built-in layout created before its content hash was
	 * recorded, and whether the layout should still follow the plugin.
	 *
	 * @return array[]
	 */
	public function legacy_updates(): array {
		return [
			'never updated'                            => [ [], false, true ],
			'renamed'                                  => [ [ 'post_title' => 'Our stream' ], false, true ],
			'content edited'                           => [ [ 'post_content' => self::layout_markup( self::MARKER . ', edited' ) ], false, false ],
			'edited before this release, then renamed' => [ [ 'post_title' => 'Our stream' ], true, false ],
		];
	}

	/**
	 * A built-in layout created without a content hash follows the plugin
	 * until its content is edited.
	 *
	 * @dataProvider legacy_updates
	 *
	 * @param array $changes        Fields an update changes, or none for no update.
	 * @param bool  $edited_before  Whether the content was edited before the hash existed.
	 * @param bool  $follows_plugin Whether the layout should still follow the plugin.
	 */
	public function test_legacy_built_in_layout_follows_the_plugin_until_edited( array $changes, bool $edited_before, bool $follows_plugin ) {
		global $wpdb;

		$layout_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => self::layout_markup(),
				'post_date'    => '2026-10-01 10:00:00',
			]
		);
		update_post_meta( $layout_id, Layout::SLUG_META_KEY, 'stream' );

		if ( $edited_before ) {
			// An edit saved by a version without the save hook.
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->posts,
				[
					'post_content'      => self::layout_markup( self::MARKER . ', edited before' ),
					'post_modified'     => '2026-10-05 10:00:00',
					'post_modified_gmt' => '2026-10-05 10:00:00',
				],
				[ 'ID' => $layout_id ]
			);
			clean_post_cache( $layout_id );
		}

		if ( $changes ) {
			wp_update_post( [ 'ID' => $layout_id ] + $changes );
		}

		$renders_pattern = false !== strpos( self::render_synced( $layout_id ), self::MARKER );

		$this->assertSame( ! $follows_plugin, $renders_pattern );
	}

	/**
	 * The contexts the editor reads patterns in: the pattern editor's, and
	 * the synced preview's and the picker's.
	 *
	 * @return array[]
	 */
	public function editor_contexts(): array {
		return [
			'edit' => [ 'edit' ],
			'view' => [ 'view' ],
		];
	}

	/**
	 * The editor reads an unedited built-in layout as the plugin ships it, so
	 * its previews, Detach and the pattern editor start from it, and an
	 * edited one as its pattern holds it.
	 *
	 * @dataProvider editor_contexts
	 *
	 * @param string $context REST context.
	 */
	public function test_editor_reads_an_unedited_built_in_layout_from_the_plugin( string $context ) {
		$layout_id = self::create_built_in();
		self::store_earlier_layout( $layout_id );
		$request = new WP_REST_Request( 'GET', '/wp/v2/blocks/' . $layout_id );
		$request->set_param( 'context', $context );

		$this->assertSame( Layout_Templates::get_pattern_content( 'stream' ), rest_do_request( $request )->get_data()['content']['raw'] );

		wp_update_post(
			[
				'ID'           => $layout_id,
				'post_content' => self::layout_markup( 'Edited layout' ),
			]
		);

		$this->assertSame( self::layout_markup( 'Edited layout' ), rest_do_request( $request )->get_data()['content']['raw'] );
	}
}
