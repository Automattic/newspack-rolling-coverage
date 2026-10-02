<?php
/**
 * Tests for the feed that opens at a shared entry.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Entry_Bindings;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Social_Sharing;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * A link to an entry older than the first page opens the feed at that entry,
 * below the coverage's pinned entries; anything else keeps the normal feed.
 */
class Test_Shared_Entry_View extends Rolling_Coverage_TestCase {

	const CONTROL_CLASS = 'newspack-rolling-coverage-new-entries';

	/**
	 * A customized "Jump to latest" button, as the editor saves it.
	 */
	const CUSTOM_LATEST_MARKUP = '<!-- wp:buttons {"className":"is-custom","layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons is-custom">'
		. '<!-- wp:button {"backgroundColor":"accent","metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"latestUrl"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link has-accent-background-color has-background wp-element-button">Back to live</a></div><!-- /wp:button -->'
		. '</div><!-- /wp:buttons -->';

	/**
	 * An entry's blocks, as the editor saves them.
	 */
	const ENTRY_MARKUP = '<!-- wp:post-title /--><!-- wp:post-content /-->';

	/**
	 * Coverage term ID.
	 *
	 * @var int
	 */
	private $coverage_id;

	/**
	 * Host page ID.
	 *
	 * @var int
	 */
	private $page_id;

	/**
	 * Entry IDs keyed by slug, entry-1 (oldest) to entry-6.
	 *
	 * @var int[]
	 */
	private $entries = [];

	/**
	 * The request URI before the test.
	 *
	 * @var string|null
	 */
	private $request_uri;

	/**
	 * Slugs of the theme's color palette for the test.
	 *
	 * @var string[]
	 */
	private $palette = [ 'base', 'contrast' ];

	/**
	 * Build a coverage of six entries, one minute apart, and a host page.
	 */
	public function set_up() {
		parent::set_up();

		$this->coverage_id = self::create_coverage();

		for ( $i = 1; $i <= 6; $i++ ) {
			$this->entries[ 'entry-' . $i ] = self::create_entry(
				$this->coverage_id,
				[
					'post_date'  => sprintf( '2026-01-01 10:%02d:00', $i ),
					'post_name'  => 'entry-' . $i,
					'post_title' => 'Entry ' . $i,
				]
			);
		}

		$this->page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stored only to be restored.

		$this->go_to( get_permalink( $this->page_id ) );
		$this->use_page_as_current_post();

		add_filter( 'wp_theme_json_data_theme', [ $this, 'set_theme_palette' ] );
		wp_clean_theme_json_cache();
	}

	/**
	 * Give the theme the test's color palette.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme data.
	 * @return WP_Theme_JSON_Data
	 */
	public function set_theme_palette( $theme_json ) {
		return $theme_json->update_with(
			[
				'version'  => 3,
				'settings' => [
					'color' => [
						'palette' => array_map(
							fn( $slug ) => [
								'slug'  => $slug,
								'name'  => $slug,
								'color' => '#123456',
							],
							$this->palette
						),
					],
				],
			]
		);
	}

	/**
	 * Swap the theme's color palette for the rest of the test.
	 *
	 * @param string[] $slugs Palette slugs.
	 */
	private function use_palette( array $slugs ) {
		$this->palette = $slugs;
		wp_clean_theme_json_cache();
	}

	/**
	 * Make the host page the current post, as it is inside the page's own content.
	 */
	private function use_page_as_current_post() {
		$GLOBALS['post'] = get_post( $this->page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Makes the host page the current post.
		setup_postdata( $GLOBALS['post'] );
	}

	/**
	 * Clear the query var.
	 */
	public function tear_down() {
		set_query_var( Social_Sharing::ENTRY_QUERY_VAR, '' );

		remove_filter( 'wp_theme_json_data_theme', [ $this, 'set_theme_palette' ] );
		wp_clean_theme_json_cache();

		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}

		parent::tear_down();
	}

	/**
	 * Render the block with a shared entry in the query.
	 *
	 * @param string|array $slug        Value of the entry query var.
	 * @param int          $coverage_id Coverage to render; the fixture's when 0.
	 * @return string
	 */
	private function render_with_shared( $slug, int $coverage_id = 0 ): string {
		return $this->render_layout_with_shared( $slug, '', $coverage_id );
	}

	/**
	 * Render the block holding a saved layout, with a shared entry in the query.
	 *
	 * @param string|array $slug        Value of the entry query var.
	 * @param string       $layout      The block's inner blocks, as the editor saves them; the default layout when empty.
	 * @param int          $coverage_id Coverage to render; the fixture's when 0.
	 * @return string
	 */
	private function render_layout_with_shared( $slug, string $layout, int $coverage_id = 0 ): string {
		set_query_var( Social_Sharing::ENTRY_QUERY_VAR, $slug );

		$attributes = [
			'coverageId'     => $coverage_id ? $coverage_id : $this->coverage_id,
			'entriesPerPage' => 2,
		];
		$name       = 'wp:newspack-rolling-coverage/rolling-coverage';
		$parsed     = parse_blocks(
			'' === $layout
				? '<!-- ' . $name . ' ' . wp_json_encode( $attributes ) . ' /-->'
				: '<!-- ' . $name . ' ' . wp_json_encode( $attributes ) . ' -->' . $layout . '<!-- /' . $name . ' -->'
		)[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $parsed ) );
	}

	/**
	 * The control above the feed, as rendered: its wrapper and its link.
	 *
	 * @param string $html Rendered HTML.
	 * @return array {
	 *     @type int         $count   How many controls the HTML holds.
	 *     @type string      $tag     The wrapper's tag name.
	 *     @type string      $classes The wrapper's classes.
	 *     @type bool        $hidden  Whether the wrapper is hidden.
	 *     @type string      $link    The link's classes.
	 *     @type string|null $href    The link's URL.
	 *     @type string|null $style   The link's inline style.
	 *     @type string      $text    The link's text.
	 *     @type string|null $live    The wrapper's live feed URL.
	 *     @type int         $marked  How many links the HTML marks for the view script.
	 *     @type string|null $newer   The wrapper's count of newer entries.
	 *     @type string|null $own     The link's own text, kept when the count replaces it.
	 * }
	 */
	private function control( string $html ): array {
		$control = [
			'count'   => substr_count( $html, self::CONTROL_CLASS ),
			'tag'     => '',
			'classes' => '',
			'hidden'  => false,
			'link'    => '',
			'href'    => null,
			'style'   => null,
			'text'    => '',
			'live'    => null,
			'marked'  => substr_count( $html, Entry_Bindings::LATEST_ATTRIBUTE ),
			'newer'   => null,
			'own'     => null,
		];
		$tags    = new WP_HTML_Tag_Processor( $html );

		if ( ! $tags->next_tag( [ 'class_name' => self::CONTROL_CLASS ] ) ) {
			return $control;
		}

		$control['tag']     = $tags->get_tag();
		$control['classes'] = (string) $tags->get_attribute( 'class' );
		$control['hidden']  = null !== $tags->get_attribute( 'hidden' );
		$control['live']    = $tags->get_attribute( 'data-live-url' );
		$control['newer']   = $tags->get_attribute( 'data-newer-count' );

		while ( $tags->next_tag( 'a' ) ) {
			if ( null === $tags->get_attribute( Entry_Bindings::LATEST_ATTRIBUTE ) ) {
				continue;
			}

			$control['link']  = (string) $tags->get_attribute( 'class' );
			$control['href']  = $tags->get_attribute( 'href' );
			$control['style'] = $tags->get_attribute( 'style' );
			$control['own']   = $tags->get_attribute( 'data-label' );

			preg_match( '#<a\b[^>]*\b' . Entry_Bindings::LATEST_ATTRIBUTE . '\b[^>]*>([^<]*)</a>#', $html, $match );

			$control['text'] = html_entity_decode( $match[1] ?? '' );
			break;
		}

		return $control;
	}

	/**
	 * Entry IDs in the order the HTML lists them.
	 *
	 * @param string $html Rendered HTML.
	 * @return int[]
	 */
	private function entry_ids_in( string $html ): array {
		preg_match_all( '/data-entry-id="(\d+)"/', $html, $matches );

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * Entry IDs for slugs.
	 *
	 * @param string ...$slugs Entry slugs.
	 * @return int[]
	 */
	private function ids( string ...$slugs ): array {
		return array_map( fn( $slug ) => $this->entries[ $slug ], $slugs );
	}

	/**
	 * Value of a data attribute on the block wrapper.
	 *
	 * @param string $html Rendered HTML.
	 * @param string $name Attribute name without the data- prefix.
	 * @return string
	 */
	private function data_attribute( string $html, string $name ): string {
		preg_match( '/data-' . preg_quote( $name, '/' ) . '="([^"]*)"/', $html, $match );

		return html_entity_decode( $match[1] ?? '' );
	}

	/**
	 * A link to an entry the first page already shows changes nothing.
	 */
	public function test_recent_shared_entry_keeps_the_normal_view() {
		$html = $this->render_with_shared( 'entry-5' );

		$this->assertStringNotContainsString( 'data-view=', $html );
		$this->assertTrue( $this->control( $html )['hidden'] );
		$this->assertSame( $this->ids( 'entry-6', 'entry-5' ), $this->entry_ids_in( $html ) );
	}

	/**
	 * A link to an older entry starts the feed at that entry and offers a link back to the live feed.
	 */
	public function test_older_shared_entry_opens_the_feed_at_that_entry() {
		$html = $this->render_with_shared( 'entry-3' );

		$this->assertStringContainsString( 'data-view="entry"', $html );
		$this->assertSame( $this->ids( 'entry-3', 'entry-2' ), $this->entry_ids_in( $html ) );
		$this->assertStringContainsString( 'data-has-more="1"', $html );

		$control = $this->control( $html );

		$this->assertSame( 1, $control['count'] );
		$this->assertSame( 'DIV', $control['tag'] );
		$this->assertContains( 'wp-block-buttons', explode( ' ', $control['classes'] ) );
		$this->assertFalse( $control['hidden'] );
		$this->assertSame( get_permalink( $this->page_id ), $control['href'] );
		$this->assertSame( '3', $control['newer'] );
		$this->assertSame( '3 Newer Posts', $control['text'] );
		$this->assertSame( 'Jump to Latest', $control['own'], 'The link keeps its own text for the view script.' );
		$this->assertSame( 'wp-block-button__link has-base-color has-contrast-background-color has-text-color has-background wp-element-button', $control['link'] );
		$this->assertSame( 'box-shadow:var(--wp--preset--shadow--elevation-1)', $control['style'] );
		$this->assertSame( get_permalink( $this->page_id ), $control['live'], 'The wrapper carries the live feed URL for the view script.' );
		$this->assertSame( 1, $control['marked'], 'Only the link to the live feed is marked for the view script.' );
		$this->assertContains( 'is-layout-flex', explode( ' ', $control['classes'] ) );
		$this->assertContains( 'is-content-justification-center', explode( ' ', $control['classes'] ) );
	}

	/**
	 * The default control takes its colors from the palette: Contrast and Base where the theme has both, else Dark Gray and White where it has both, else Contrast and Base.
	 *
	 * @dataProvider palettes
	 *
	 * @param string[] $slugs   The theme palette's slugs.
	 * @param string   $classes The color classes the link carries.
	 */
	public function test_default_control_follows_the_palette( array $slugs, string $classes ) {
		$this->use_palette( $slugs );

		$this->assertSame(
			'wp-block-button__link ' . $classes . ' has-text-color has-background wp-element-button',
			$this->control( $this->render_with_shared( 'entry-3' ) )['link']
		);
	}

	/**
	 * Theme palettes and the default control's color classes.
	 *
	 * @return array[]
	 */
	public static function palettes(): array {
		return [
			'contrast and base'                => [ [ 'accent', 'base', 'contrast' ], 'has-base-color has-contrast-background-color' ],
			'both pairs'                       => [ [ 'base', 'contrast', 'dark-gray', 'white' ], 'has-base-color has-contrast-background-color' ],
			'dark gray and white only'         => [ [ 'primary', 'dark-gray', 'medium-gray', 'white' ], 'has-white-color has-dark-gray-background-color' ],
			'contrast without base, dark gray' => [ [ 'contrast', 'dark-gray' ], 'has-white-color has-dark-gray-background-color' ],
			'neither'                          => [ [ 'primary', 'secondary' ], 'has-base-color has-contrast-background-color' ],
		];
	}

	/**
	 * The normal view holds the same control, hidden until new entries wait, linking to the page itself.
	 */
	public function test_normal_view_holds_the_same_control_hidden() {
		$shared = $this->control( $this->render_with_shared( 'entry-3' ) );
		$normal = $this->control( $this->render_with_shared( '' ) );

		$this->assertTrue( $normal['hidden'] );
		$this->assertSame( 1, $normal['count'] );
		$this->assertSame( get_permalink( $this->page_id ), $normal['href'] );
		$this->assertSame( 'Jump to Latest', $normal['text'] );
		$this->assertNull( $normal['newer'], 'Only the shared view counts newer entries.' );
		$this->assertNull( $normal['own'] );
		$this->assertSame(
			$shared,
			array_merge(
				$normal,
				[
					'hidden' => false,
					'newer'  => '3',
					'text'   => '3 Newer Posts',
					'own'    => 'Jump to Latest',
				]
			),
			'Both views render the one control; only its visibility and the shared view\'s count differ.'
		);
	}

	/**
	 * A layout's own "Jump to latest" button is the control, with its text and settings, in both views.
	 */
	public function test_customized_latest_button_is_the_control() {
		$layout = self::CUSTOM_LATEST_MARKUP . self::ENTRY_MARKUP;

		$shared = $this->control( $this->render_layout_with_shared( 'entry-3', $layout ) );

		$this->assertSame( 1, $shared['count'] );
		$this->assertFalse( $shared['hidden'] );
		$this->assertContains( 'is-custom', explode( ' ', $shared['classes'] ) );
		$this->assertContains( self::CONTROL_CLASS, explode( ' ', $shared['classes'] ), 'The wrapper carries the control class even when the layout lost it.' );
		$this->assertSame( '3 Newer Posts', $shared['text'], 'The count replaces the button\'s own text.' );
		$this->assertSame( 'Back to live', $shared['own'] );
		$this->assertSame( 'wp-block-button__link has-accent-background-color has-background wp-element-button', $shared['link'] );
		$this->assertNull( $shared['style'] );
		$this->assertSame( get_permalink( $this->page_id ), $shared['href'] );

		$normal = $this->control( $this->render_layout_with_shared( '', $layout ) );

		$this->assertTrue( $normal['hidden'] );
		$this->assertSame( 'Back to live', $normal['text'] );
	}

	/**
	 * In a layout whose items sit in the Feed group, the layout's "Jump to latest" button is the control, inside the Feed and never inside an entry.
	 */
	public function test_latest_button_in_the_feed_is_the_control() {
		$layout = '<!-- wp:group {"className":"newspack-rolling-coverage-feed","style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} --><div class="wp-block-group newspack-rolling-coverage-feed">'
			. self::CUSTOM_LATEST_MARKUP . self::ENTRY_MARKUP
			. '</div><!-- /wp:group -->';
		$html   = $this->render_layout_with_shared( 'entry-3', $layout );
		$shared = $this->control( $html );

		$this->assertSame( 1, $shared['count'] );
		$this->assertSame( 'Back to live', $shared['own'], "The layout's own button is the control." );
		$this->assertNotNull( $shared['live'] );
		$this->assertMatchesRegularExpression( '/newspack-rolling-coverage-feed[^>]*>.*' . self::CONTROL_CLASS . '/s', $html, 'The control renders inside the Feed.' );

		preg_match_all( '/<article .*?<\/article>/s', $html, $articles );

		$this->assertNotEmpty( $articles[0] );
		$this->assertStringNotContainsString( Entry_Bindings::LATEST_ATTRIBUTE, implode( '', $articles[0] ), 'No entry holds the button.' );

		$wrapper = new WP_HTML_Tag_Processor( $html );
		$wrapper->next_tag();

		$this->assertSame( '--newspack-rolling-coverage-gap:var(--wp--preset--spacing--40)', $wrapper->get_attribute( 'style' ) );
	}

	/**
	 * The "Jump to latest" button renders once above the feed, never inside an entry.
	 */
	public function test_latest_button_is_not_rendered_inside_entries() {
		$html    = $this->render_layout_with_shared( 'entry-3', self::CUSTOM_LATEST_MARKUP . self::ENTRY_MARKUP );
		$entries = substr( $html, (int) strpos( $html, 'class="newspack-rolling-coverage-entries"' ) );

		$this->assertSame( $this->ids( 'entry-3', 'entry-2' ), $this->entry_ids_in( $entries ) );
		$this->assertSame( 1, substr_count( $html, 'has-accent-background-color' ) );
		$this->assertStringNotContainsString( 'has-accent-background-color', $entries );
		$this->assertStringNotContainsString( 'wp-block-button', $entries );
	}

	/**
	 * The "Jump to latest" buttons are told apart from every other Buttons block by their binding.
	 */
	public function test_latest_buttons_are_recognized_by_their_binding() {
		$latest = parse_blocks( self::CUSTOM_LATEST_MARKUP )[0];
		$follow = $latest;

		$follow['innerBlocks'][0]['attrs']['metadata']['bindings']['url']['args']['key'] = 'followTag';

		$this->assertTrue( Entry_Bindings::is_latest_buttons( $latest ) );
		$this->assertFalse( Entry_Bindings::is_follow_buttons( $latest ) );
		$this->assertFalse( Entry_Bindings::is_latest_buttons( $follow ) );
		$this->assertTrue( Entry_Bindings::is_follow_buttons( $follow ) );
		$this->assertFalse( Entry_Bindings::is_latest_buttons( $latest['innerBlocks'][0] ), 'The button alone is not the Buttons block.' );
		$this->assertFalse( Entry_Bindings::is_latest_buttons( parse_blocks( '<!-- wp:buttons --><div class="wp-block-buttons"></div><!-- /wp:buttons -->' )[0] ) );
	}

	/**
	 * A "Jump to latest" button pasted into the entry group renders in no entry, on the page or through the entries route.
	 */
	public function test_pasted_latest_button_never_renders_inside_an_entry() {
		$layout = '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry">'
			. '<!-- wp:post-title /-->' . self::CUSTOM_LATEST_MARKUP
			. '</div><!-- /wp:group -->';

		$html    = $this->render_layout_with_shared( 'entry-3', $layout );
		$entries = substr( $html, (int) strpos( $html, 'class="newspack-rolling-coverage-entries"' ) );

		$this->assertSame( $this->ids( 'entry-3', 'entry-2' ), $this->entry_ids_in( $entries ) );
		$this->assertStringNotContainsString( 'Back to live', $entries );
		$this->assertStringNotContainsString( 'wp-block-button', $entries );
		$this->assertStringContainsString( 'has-contrast-background-color', $this->control( $html )['link'], 'The layout has no button of its own at the top, so the default one is the control.' );

		$data = self::dispatch(
			'GET',
			'/coverages/' . $this->coverage_id . '/entries',
			[
				'before'       => get_post( $this->entries['entry-2'] )->post_date_gmt,
				'per_page'     => 2,
				'template_key' => $this->data_attribute( $html, 'template-key' ),
			]
		)->get_data();

		$this->assertSame( $this->ids( 'entry-1' ), $this->entry_ids_in( $data['html'] ) );
		$this->assertStringNotContainsString( 'Back to live', $data['html'] );
		$this->assertStringNotContainsString( 'wp-block-button', $data['html'] );
	}

	/**
	 * A layout's button that cannot link to the live feed gives way to the default control.
	 *
	 * @dataProvider unusable_latest_buttons
	 *
	 * @param string $button The button inside the layout's Buttons block, as the editor saves it.
	 */
	public function test_unusable_latest_button_falls_back_to_the_default_control( string $button ) {
		$layout = '<!-- wp:buttons {"className":"is-custom"} --><div class="wp-block-buttons is-custom">' . $button . '</div><!-- /wp:buttons -->' . self::ENTRY_MARKUP;

		$this->assertSame(
			$this->control( $this->render_with_shared( 'entry-3' ) ),
			$this->control( $this->render_layout_with_shared( 'entry-3', $layout ) )
		);
		$this->assertSame(
			$this->control( $this->render_with_shared( '' ) ),
			$this->control( $this->render_layout_with_shared( '', $layout ) )
		);
	}

	/**
	 * Latest-bound buttons the site cannot render as a link to the live feed.
	 *
	 * @return array[]
	 */
	public static function unusable_latest_buttons(): array {
		$binding = '"metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"latestUrl"}}}}';

		return [
			'empty label'            => [ '<!-- wp:button {' . $binding . '} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button"></a></div><!-- /wp:button -->' ],
			'button element, no URL' => [ '<!-- wp:button {"tagName":"button",' . $binding . '} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Back to live</button></div><!-- /wp:button -->' ],
		];
	}

	/**
	 * A button a publisher adds next to "Jump to latest" is left alone: only the bound link is marked for the view script.
	 */
	public function test_only_the_latest_link_is_marked_for_the_view_script() {
		$layout = str_replace(
			'<div class="wp-block-buttons is-custom">',
			'<div class="wp-block-buttons is-custom"><!-- wp:button {"url":"https://example.com/"} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com/">Elsewhere</a></div><!-- /wp:button -->',
			self::CUSTOM_LATEST_MARKUP
		) . self::ENTRY_MARKUP;

		$html    = $this->render_layout_with_shared( 'entry-3', $layout );
		$control = $this->control( $html );

		$this->assertStringContainsString( '>Elsewhere</a>', $html );
		$this->assertSame( 1, $control['marked'] );
		$this->assertSame( '3 Newer Posts', $control['text'] );
		$this->assertSame( get_permalink( $this->page_id ), $control['href'] );
	}

	/**
	 * Without a request URI, as under WP-CLI or cron, the control links to the host post and nothing warns.
	 */
	public function test_control_links_to_the_host_post_without_a_request_uri() {
		$this->go_to( home_url( '/' ) );
		$this->use_page_as_current_post();

		unset( $_SERVER['REQUEST_URI'] );

		$this->assertNull( get_queried_object() );

		foreach ( [ 'entry-3', '' ] as $shared ) {
			$control = $this->control( $this->render_with_shared( $shared ) );

			$this->assertSame( get_permalink( $this->page_id ), $control['href'] );
			$this->assertSame( get_permalink( $this->page_id ), $control['live'] );
		}
	}

	/**
	 * With no host post and no page request, the live feed URL is the site's front page.
	 */
	public function test_live_feed_url_is_the_front_page_without_a_host_post_or_a_page_request() {
		unset( $_SERVER['REQUEST_URI'] );

		$this->assertSame( 0, Rolling_Coverage_Block::get_host_post_id() );
		$this->assertSame( home_url( '/' ), Rolling_Coverage_Block::live_feed_url() );
	}

	/**
	 * In wp-admin, where the request is not a page's, the control links to the host post.
	 */
	public function test_control_links_to_the_host_post_in_an_admin_request() {
		$this->go_to( home_url( '/' ) );
		$this->use_page_as_current_post();

		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?rolling-coverage-entry=entry-3';

		set_current_screen( 'dashboard' );

		try {
			$this->assertTrue( is_admin() );

			$control = $this->control( $this->render_with_shared( 'entry-3' ) );
		} finally {
			unset( $GLOBALS['current_screen'] );
		}

		$this->assertSame( get_permalink( $this->page_id ), $control['href'] );
		$this->assertSame( get_permalink( $this->page_id ), $control['live'] );
	}

	/**
	 * A coverage block inside an entry's content renders no control.
	 */
	public function test_block_nested_in_an_entry_has_no_control() {
		$html = Rolling_Coverage_Block::render_as_entry( fn() => $this->render_with_shared( '' ) );

		$this->assertNotEmpty( $this->entry_ids_in( $html ) );
		$this->assertSame( 0, $this->control( $html )['count'] );
		$this->assertSame( 1, $this->control( $this->render_with_shared( '' ) )['count'] );
	}

	/**
	 * The default layout's entries render with square corners.
	 */
	public function test_default_entries_render_without_a_border_radius() {
		$this->assertMatchesRegularExpression( '/<div class="wp-block-group newspack-rolling-coverage-regular-entry[^"]*"(?![^>]*border-radius)[^>]*>/', $this->render_with_shared( '' ) );
	}

	/**
	 * Without an enabled ad placement the block is not flagged as showing ads.
	 */
	public function test_block_without_an_ad_placement_is_not_flagged() {
		$this->assertStringNotContainsString( 'data-ads=', $this->render_with_shared( 'entry-3' ) );
	}

	/**
	 * A feed holds no floating control.
	 */
	public function test_feed_render_has_no_control() {
		$this->go_to( get_feed_link() );
		$this->use_page_as_current_post();

		$this->assertTrue( is_feed() );

		foreach ( [ 'entry-3', '' ] as $shared ) {
			$html = $this->render_with_shared( $shared );

			$this->assertSame( 0, $this->control( $html )['count'] );
			$this->assertNotEmpty( $this->entry_ids_in( $html ) );
		}
	}

	/**
	 * A shared entry with exactly one newer entry counts it in the singular.
	 */
	public function test_single_newer_entry_is_counted_in_the_singular() {
		Post_Type::pin_entry( $this->entries['entry-1'] );

		$html    = $this->render_with_shared( 'entry-5' );
		$control = $this->control( $html );

		$this->assertStringContainsString( 'data-view="entry"', $html );
		$this->assertSame( '1', $control['newer'] );
		$this->assertSame( '1 Newer Post', $control['text'] );
	}

	/**
	 * Pinned entries are already on the page, so they are not counted as newer posts.
	 */
	public function test_pinned_newer_entries_are_not_counted() {
		Post_Type::pin_entry( $this->entries['entry-4'] );

		$this->assertSame( '2', $this->control( $this->render_with_shared( 'entry-3' ) )['newer'] );
	}

	/**
	 * The count stops at the cap, one past a hundred, which reads as more than 100, and a newer pinned entry does not take a place under it.
	 */
	public function test_newer_count_is_capped() {
		$newer = self::factory()->post->create_many(
			Rolling_Coverage_Block::NEWER_COUNT_CAP + 1,
			[
				'post_type'   => Post_Type::CPT_SLUG,
				'post_status' => 'publish',
				'post_date'   => '2026-01-02 10:00:00',
			]
		);

		foreach ( $newer as $entry_id ) {
			wp_set_object_terms( $entry_id, [ $this->coverage_id ], Taxonomy::TAXONOMY_SLUG );
		}

		Post_Type::pin_entry( self::create_entry( $this->coverage_id, [ 'post_date' => '2026-01-03 10:00:00' ] ) );

		$control = $this->control( $this->render_with_shared( 'entry-6' ) );

		$this->assertSame( 101, Rolling_Coverage_Block::NEWER_COUNT_CAP );
		$this->assertSame( '101', $control['newer'], 'One past a hundred stands for "more than 100".' );
		$this->assertSame( '100+ Newer Posts', $control['text'] );
	}

	/**
	 * A label with inline formatting is left for the view script to replace.
	 */
	public function test_formatted_label_is_left_to_the_view_script() {
		$layout = str_replace( '>Back to live</a>', '><strong>Back</strong> to live</a>', self::CUSTOM_LATEST_MARKUP ) . self::ENTRY_MARKUP;

		$html = $this->render_layout_with_shared( 'entry-3', $layout );

		$this->assertStringContainsString( '><strong>Back</strong> to live</a>', $html );
		$this->assertStringNotContainsString( 'Newer Post', $html );
		$this->assertSame( '3', $this->control( $html )['newer'] );
		$this->assertNull( $this->control( $html )['own'], 'The untouched label is its own text.' );
	}

	/**
	 * The label is exact up to ten; from there "N+" reads as more than N.
	 *
	 * @dataProvider newer_posts_labels
	 *
	 * @param int    $count How many entries are newer.
	 * @param string $label The label the control shows.
	 */
	public function test_newer_posts_label_is_bucketed( int $count, string $label ) {
		$this->assertSame( $label, Rolling_Coverage_Block::newer_posts_label( $count ) );
	}

	/**
	 * Counts and their labels; none means the control keeps its own text.
	 *
	 * @return array[]
	 */
	public static function newer_posts_labels(): array {
		return [
			[ 0, '' ],
			[ 1, '1 Newer Post' ],
			[ 9, '9 Newer Posts' ],
			[ 10, '10 Newer Posts' ],
			[ 11, '10+ Newer Posts' ],
			[ 50, '10+ Newer Posts' ],
			[ 51, '50+ Newer Posts' ],
			[ 100, '50+ Newer Posts' ],
			[ 101, '100+ Newer Posts' ],
			[ 250, '100+ Newer Posts' ],
		];
	}

	/**
	 * Entry IDs the HTML marks as the linked entry.
	 *
	 * @param string $html Rendered HTML.
	 * @return int[]
	 */
	private function linked_ids( string $html ): array {
		preg_match_all( '/<article\b[^>]*\sdata-entry-id="(\d+)"[^>]*\sdata-linked[\s>]/', $html, $matches );

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * The entry a link names is marked, in the shared view and in the normal view, pinned or not; no other entry is.
	 */
	public function test_linked_entry_is_marked() {
		$this->assertSame( $this->ids( 'entry-3' ), $this->linked_ids( $this->render_with_shared( 'entry-3' ) ), 'Shared view.' );
		$this->assertSame( $this->ids( 'entry-5' ), $this->linked_ids( $this->render_with_shared( 'entry-5' ) ), 'Normal view.' );
		$this->assertSame( [], $this->linked_ids( $this->render_with_shared( '' ) ), 'No link.' );

		Post_Type::pin_entry( $this->entries['entry-2'] );

		$html = $this->render_with_shared( 'entry-2' );

		$this->assertStringNotContainsString( 'data-view=', $html );
		$this->assertSame( $this->ids( 'entry-2' ), $this->linked_ids( $html ), 'Normal view, pinned.' );
	}

	/**
	 * The entry the page's link names for a coverage, as the block resolves it.
	 *
	 * @param string|array $slug        Value of the entry query var.
	 * @param int          $coverage_id Coverage term ID.
	 * @return WP_Post|null
	 */
	private function linked_entry_for( $slug, int $coverage_id ): ?WP_Post {
		set_query_var( Social_Sharing::ENTRY_QUERY_VAR, $slug );

		$resolve = new ReflectionMethod( Rolling_Coverage_Block::class, 'get_linked_entry' );
		$resolve->setAccessible( true );

		return $resolve->invoke( null, $coverage_id );
	}

	/**
	 * A link names an entry for a coverage only when the entry is published and in that coverage.
	 */
	public function test_entries_outside_the_coverage_are_not_linked() {
		$other_coverage = self::create_coverage();
		$elsewhere      = self::create_entry(
			$other_coverage,
			[
				'post_date' => '2026-01-01 09:00:00',
				'post_name' => 'elsewhere',
			]
		);

		$this->assertSame( $elsewhere, $this->linked_entry_for( 'elsewhere', $other_coverage )->ID, 'The entry is linked for its own coverage.' );
		$this->assertNull( $this->linked_entry_for( 'elsewhere', $this->coverage_id ), 'An entry of another coverage.' );

		$this->assertSame( $this->entries['entry-5'], $this->linked_entry_for( 'entry-5', $this->coverage_id )->ID );
		$this->assertSame( [], $this->linked_ids( $this->render_with_shared( [ 'entry-5' ] ) ), 'A value that is not a string.' );

		wp_update_post(
			[
				'ID'          => $this->entries['entry-5'],
				'post_status' => 'draft',
			]
		);

		$this->assertNull( $this->linked_entry_for( 'entry-5', $this->coverage_id ), 'An unpublished entry.' );
	}

	/**
	 * A pinned entry in the shared view's date range shows once, at the top, and the page below it stays full.
	 */
	public function test_shared_view_shows_a_pinned_entry_once_at_the_top() {
		Post_Type::pin_entry( $this->entries['entry-2'] );

		$html = $this->render_with_shared( 'entry-3' );

		$this->assertSame( $this->ids( 'entry-2', 'entry-3', 'entry-1' ), $this->entry_ids_in( $html ) );
		$this->assertStringContainsString( 'data-has-more="0"', $html );
	}

	/**
	 * A pinned entry at the top does not hide the older entries that remain to load.
	 */
	public function test_shared_view_keeps_more_to_load_below_pinned_entries() {
		Post_Type::pin_entry( $this->entries['entry-3'] );

		$html = $this->render_with_shared( 'entry-4' );

		$this->assertSame( $this->ids( 'entry-3', 'entry-4', 'entry-2' ), $this->entry_ids_in( $html ) );
		$this->assertStringContainsString( 'data-has-more="1"', $html );
		$this->assertSame( get_post( $this->entries['entry-2'] )->post_date_gmt, $this->data_attribute( $html, 'before' ) );
	}

	/**
	 * The shared view opens below the coverage's pinned entries, in the live feed's order, and leaves out entries pinned in other coverages.
	 */
	public function test_shared_view_shows_pinned_entries_first() {
		$elsewhere = self::create_entry(
			self::create_coverage(),
			[
				'post_date' => '2026-01-01 11:00:00',
				'post_name' => 'elsewhere',
			]
		);

		Post_Type::pin_entry( $this->entries['entry-5'] );
		Post_Type::pin_entry( $elsewhere );
		Post_Type::pin_entry( $this->entries['entry-6'] );

		$html = $this->render_with_shared( 'entry-2' );

		$this->assertStringContainsString( 'data-view="entry"', $html );
		$this->assertSame( $this->ids( 'entry-5', 'entry-6', 'entry-2', 'entry-1' ), $this->entry_ids_in( $html ) );
		$this->assertSame( $this->ids( 'entry-5', 'entry-6' ), $this->entry_ids_in( $this->render_with_shared( '' ) ), 'The live feed.' );
		$this->assertSame( $this->ids( 'entry-2' ), $this->linked_ids( $html ) );
	}

	/**
	 * The oldest entry opens alone, with nothing more to load and no closing separator.
	 */
	public function test_oldest_shared_entry_is_shown_alone() {
		$html = $this->render_with_shared( 'entry-1' );

		$this->assertSame( $this->ids( 'entry-1' ), $this->entry_ids_in( $html ) );
		$this->assertStringContainsString( 'data-has-more="0"', $html );

		$entries_html = substr( $html, (int) strpos( $html, 'newspack-rolling-coverage-entries' ) );

		$this->assertStringNotContainsString( 'wp-block-separator', $entries_html );
	}

	/**
	 * Links that cannot open the feed at an entry leave the normal feed.
	 *
	 * @dataProvider unusable_shared_entries
	 *
	 * @param string $case Fixture to set up.
	 */
	public function test_unusable_shared_entries_keep_the_normal_view( string $case ) {
		$shared = 'entry-3';

		switch ( $case ) {
			case 'unknown':
				$shared = 'no-such-entry';
				break;
			case 'draft':
				wp_update_post(
					[
						'ID'          => $this->entries['entry-3'],
						'post_status' => 'draft',
					]
				);
				break;
			case 'other coverage':
				$other = self::create_entry(
					self::create_coverage(),
					[
						'post_date' => '2026-01-01 09:00:00',
						'post_name' => 'elsewhere',
					]
				);
				$this->assertGreaterThan( 0, $other );
				$shared = 'elsewhere';
				break;
			case 'pinned':
				foreach ( [ 'entry-1', 'entry-2', 'entry-3' ] as $slug ) {
					Post_Type::pin_entry( $this->entries[ $slug ] );
				}
				$shared = 'entry-3';
				break;
			case 'array':
				$shared = [ 'entry-3' ];
				break;
		}

		$html = $this->render_with_shared( $shared );

		$this->assertStringNotContainsString( 'data-view=', $html );
		$this->assertTrue( $this->control( $html )['hidden'] );
	}

	/**
	 * Fixture names for the unusable shared entries.
	 *
	 * @return array[]
	 */
	public static function unusable_shared_entries(): array {
		return [
			'unknown slug'              => [ 'unknown' ],
			'draft entry'               => [ 'draft' ],
			'entry of another coverage' => [ 'other coverage' ],
			'pinned older entry'        => [ 'pinned' ],
			'array value'               => [ 'array' ],
		];
	}

	/**
	 * The shared view polls from the newest entry of the coverage, so entries older than the page's start are not reported as new.
	 */
	public function test_shared_view_polls_from_the_coverage_not_the_page() {
		$html   = $this->render_with_shared( 'entry-2' );
		$cursor = $this->data_attribute( $html, 'cursor' );
		$latest = get_post( $this->entries['entry-6'] );

		$this->assertSame( $latest->ID . ':' . $latest->post_modified_gmt, $cursor );

		$params = [
			'cursor'       => $cursor,
			'template_key' => $this->data_attribute( $html, 'template-key' ),
		];
		$path   = '/coverages/' . $this->coverage_id . '/entries';

		$this->assertSame( [], self::dispatch( 'GET', $path, $params )->get_data()['entries'] );

		self::create_entry(
			$this->coverage_id,
			[
				'post_date'  => current_time( 'mysql' ),
				'post_name'  => 'entry-7',
				'post_title' => 'Entry 7',
			]
		);

		$entries = self::dispatch( 'GET', $path, $params )->get_data()['entries'];

		$this->assertCount( 1, $entries );
		$this->assertSame( 'insert', $entries[0]['type'] );
	}

	/**
	 * Load more can leave pinned entries out, and keeps them by default.
	 */
	public function test_load_more_can_leave_pinned_entries_out() {
		Post_Type::pin_entry( $this->entries['entry-2'] );

		$html   = $this->render_with_shared( 'entry-3' );
		$params = [
			'before'       => get_post( $this->entries['entry-4'] )->post_date_gmt,
			'per_page'     => 2,
			'template_key' => $this->data_attribute( $html, 'template-key' ),
		];
		$path   = '/coverages/' . $this->coverage_id . '/entries';

		$data = self::dispatch( 'GET', $path, array_merge( $params, [ 'skip_pinned' => true ] ) )->get_data();

		$this->assertSame( $this->ids( 'entry-3', 'entry-1' ), $this->entry_ids_in( $data['html'] ) );
		$this->assertSame( 2, $data['count'] );
		$this->assertFalse( $data['hasMore'] );

		$data = self::dispatch( 'GET', $path, $params )->get_data();

		$this->assertSame( $this->ids( 'entry-3', 'entry-2' ), $this->entry_ids_in( $data['html'] ) );
	}

	/**
	 * The link back to the live feed is the current URL without the entry, when the block is not on its host post's own page.
	 */
	public function test_pill_link_falls_back_to_the_current_url_outside_a_singular_page() {
		$this->go_to( home_url( '/' ) );
		$this->use_page_as_current_post();

		$_SERVER['REQUEST_URI'] = '/some-archive/?rolling-coverage-entry=entry-3&x=1';

		$html = $this->render_with_shared( 'entry-3' );

		$this->assertStringContainsString( 'data-view="entry"', $html );
		$this->assertSame( '/some-archive/?x=1', $this->pill_href( $html ) );
	}

	/**
	 * The fallback link stays on this site even when the request path starts with two slashes.
	 */
	public function test_pill_link_fallback_is_never_protocol_relative() {
		$this->go_to( home_url( '/' ) );
		$this->use_page_as_current_post();

		$_SERVER['REQUEST_URI'] = '//evil.example/?rolling-coverage-entry=entry-3';

		$href = $this->pill_href( $this->render_with_shared( 'entry-3' ) );

		$this->assertMatchesRegularExpression( '#^/[^/]#', $href );

		$_SERVER['REQUEST_URI'] = '/%0a/evil.example/?rolling-coverage-entry=entry-3';

		$href = $this->pill_href( $this->render_with_shared( 'entry-3' ) );

		$this->assertMatchesRegularExpression( '#^/[^/]#', $href );
	}

	/**
	 * A load-more bound that is not a real datetime is handled like any other value, without a date query error.
	 *
	 * @dataProvider invalid_datetime_bounds
	 *
	 * @param string $before Client-supplied bound.
	 */
	public function test_load_more_tolerates_bounds_that_are_not_datetimes( string $before ) {
		$html     = $this->render_with_shared( 'entry-2' );
		$response = self::dispatch(
			'GET',
			'/coverages/' . $this->coverage_id . '/entries',
			[
				'before'       => $before,
				'per_page'     => 2,
				'template_key' => $this->data_attribute( $html, 'template-key' ),
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsString( $response->get_data()['html'] );
	}

	/**
	 * Bounds that look like a datetime but are not one.
	 *
	 * @return array[]
	 */
	public static function invalid_datetime_bounds(): array {
		return [
			'month, day, and time out of range' => [ '2026-13-45 99:99:99' ],
			'day past the end of February'      => [ '2026-02-30 10:00:00' ],
		];
	}

	/**
	 * The href of the control's link to the live feed.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	private function pill_href( string $html ): string {
		return (string) $this->control( $html )['href'];
	}

	/**
	 * Load more with skip_pinned sent as the script sends it, the string "1", leaves pinned entries out and reports what remains.
	 */
	public function test_load_more_accepts_skip_pinned_as_a_string() {
		Post_Type::pin_entry( $this->entries['entry-4'] );

		$html = $this->render_with_shared( 'entry-2' );
		$data = self::dispatch(
			'GET',
			'/coverages/' . $this->coverage_id . '/entries',
			[
				'before'       => get_post( $this->entries['entry-6'] )->post_date_gmt,
				'per_page'     => 2,
				'template_key' => $this->data_attribute( $html, 'template-key' ),
				'skip_pinned'  => '1',
			]
		)->get_data();

		$this->assertSame( $this->ids( 'entry-5', 'entry-3' ), $this->entry_ids_in( $data['html'] ) );
		$this->assertTrue( $data['hasMore'] );
	}

	/**
	 * An archived coverage opens at the shared entry like an active one.
	 */
	public function test_archived_coverage_opens_at_the_shared_entry() {
		update_term_meta( $this->coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );

		$html = $this->render_with_shared( 'entry-3' );

		$this->assertStringContainsString( 'data-view="entry"', $html );
		$this->assertSame( $this->ids( 'entry-3', 'entry-2' ), $this->entry_ids_in( $html ) );
		$this->assertFalse( $this->control( $html )['hidden'] );
		$this->assertSame( get_permalink( $this->page_id ), $this->control( $html )['href'] );
	}

	/**
	 * GMT bounds are compared as they are, even when they fall in the site timezone's skipped spring-forward hour.
	 */
	public function test_gmt_bounds_ignore_the_site_timezone() {
		update_option( 'timezone_string', 'America/New_York' );

		$coverage_id = self::create_coverage();
		$dst         = [];

		foreach ( [ '02:10:00', '02:20:00', '02:30:00', '02:40:00', '02:50:00', '03:20:00' ] as $time ) {
			$gmt          = '2026-03-08 ' . $time;
			$dst[ $time ] = self::create_entry(
				$coverage_id,
				[
					'post_date_gmt' => $gmt,
					'post_date'     => get_date_from_gmt( $gmt ),
					'post_name'     => 'dst-' . $time,
				]
			);

			$this->assertSame( $gmt, get_post( $dst[ $time ] )->post_date_gmt );
		}

		$html = $this->render_with_shared( 'dst-02:30:00', $coverage_id );

		$this->assertSame( [ $dst['02:30:00'], $dst['02:20:00'] ], $this->entry_ids_in( $html ) );

		$data = self::dispatch(
			'GET',
			'/coverages/' . $coverage_id . '/entries',
			[
				'before'       => '2026-03-08 02:30:00',
				'per_page'     => 2,
				'template_key' => $this->data_attribute( $html, 'template-key' ),
			]
		)->get_data();

		$this->assertSame( [ $dst['02:20:00'], $dst['02:10:00'] ], $this->entry_ids_in( $data['html'] ) );
	}
}
