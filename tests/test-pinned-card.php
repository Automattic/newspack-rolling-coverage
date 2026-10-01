<?php
/**
 * Tests for the pinned card and the separator that closes each entry.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * Only pinned entries show the pinned card; pinned entries and the last
 * entry drop the closing separator.
 */
class Test_Pinned_Card extends Rolling_Coverage_TestCase {

	/**
	 * The card holding a paragraph and "Read more", then a separator, as the
	 * editor saves them.
	 */
	const TEMPLATE_MARKUP = '<!-- wp:group {"className":"newspack-rolling-coverage-pinned-card","style":{"color":{"background":"#f7f7f7"},"spacing":{"margin":{"bottom":"30px"}}}} -->'
		. '<div class="wp-block-group newspack-rolling-coverage-pinned-card has-background" style="background-color:#f7f7f7;margin-bottom:30px">'
		. '<!-- wp:paragraph {"style":{"spacing":{"margin":{"bottom":"20px"}}}} --><p style="margin-bottom:20px">Card text</p><!-- /wp:paragraph -->'
		. '<!-- wp:group --><div class="wp-block-group"><!-- wp:buttons --><div class="wp-block-buttons">'
		. '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"breakoutUrl"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Read more</a></div><!-- /wp:button -->'
		. '</div><!-- /wp:buttons --></div><!-- /wp:group -->'
		. '</div><!-- /wp:group -->'
		. '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->';

	/**
	 * Forget the theme.json data a test switched to.
	 */
	public function tear_down() {
		parent::tear_down();
		wp_clean_theme_json_cache();
	}

	/**
	 * Render an entry through the card template.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param bool   $is_last  Whether no entry can load after it.
	 * @param string $markup   Template markup.
	 * @return string Rendered entry.
	 */
	private static function render( int $entry_id, bool $is_last = false, string $markup = self::TEMPLATE_MARKUP ): string {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( $markup ), 'initial', $is_last );
	}

	/**
	 * Create a pinned entry.
	 *
	 * @return int Entry post ID.
	 */
	private static function create_pinned_entry(): int {
		$entry_id = self::create_entry( self::create_coverage() );
		Post_Type::pin_entry( $entry_id );

		return $entry_id;
	}

	/**
	 * Treat the active theme as one without theme.json.
	 */
	private static function use_theme_without_theme_json(): void {
		add_filter( 'stylesheet', fn() => 'rolling-coverage-classic-test' );
		add_filter( 'theme_file_path', fn( $path, $file ) => 'theme.json' === $file ? '/nonexistent/theme.json' : $path, 10, 2 );
		wp_clean_theme_json_cache();
	}

	/**
	 * A pinned entry shows the card and no separator; an unpinned one shows
	 * the card's blocks without it, then the separator.
	 */
	public function test_only_pinned_entries_show_the_card() {
		$pinned = self::render( self::create_pinned_entry() );
		$other  = self::render( self::create_entry( self::create_coverage() ) );

		$this->assertStringContainsString( 'newspack-rolling-coverage-pinned-card', $pinned, 'A pinned entry should show the card.' );
		$this->assertStringNotContainsString( 'wp-block-separator', $pinned, 'A pinned entry should drop the separator.' );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-card', $other, 'An unpinned entry should not show the card.' );
		$this->assertStringContainsString( 'Card text', $other, "An unpinned entry should keep the card's blocks." );
		$this->assertStringContainsString( 'wp-block-separator', $other, 'An unpinned entry should keep the separator.' );
	}

	/**
	 * The card template with an entry group between the card and the
	 * separator.
	 *
	 * @return string Template markup.
	 */
	private static function markup_with_entry_group(): string {
		$separator = '<!-- wp:separator -->';
		$group     = '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} -->'
			. '<div class="wp-block-group newspack-rolling-coverage-regular-entry">'
			. '<!-- wp:paragraph --><p>Entry text</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';

		return str_replace( $separator, $group . $separator, self::TEMPLATE_MARKUP );
	}

	/**
	 * With an entry group in the template, a pinned entry shows the card
	 * alone and every other entry the entry group alone.
	 */
	public function test_entry_group_shows_for_unpinned_entries_only() {
		$markup = self::markup_with_entry_group();
		$pinned = self::render( self::create_pinned_entry(), false, $markup );
		$other  = self::render( self::create_entry( self::create_coverage() ), false, $markup );

		$this->assertStringContainsString( 'Card text', $pinned, 'A pinned entry should show the card.' );
		$this->assertStringNotContainsString( 'Entry text', $pinned, 'A pinned entry should not show the entry group.' );
		$this->assertStringNotContainsString( 'wp-block-separator', $pinned, 'A pinned entry should drop the separator.' );
		$this->assertStringContainsString( 'Entry text', $other, 'An unpinned entry should show the entry group.' );
		$this->assertStringContainsString( 'newspack-rolling-coverage-regular-entry', $other, 'An unpinned entry should keep the entry group itself.' );
		$this->assertStringNotContainsString( 'Card text', $other, "An unpinned entry should not show the card's blocks." );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-card', $other, 'An unpinned entry should not show the card.' );
		$this->assertStringContainsString( 'wp-block-separator', $other, 'An unpinned entry should keep the separator.' );
	}

	/**
	 * The entry group spaces its blocks by its own layout, and the last entry
	 * still drops the separator after it.
	 */
	public function test_entry_group_carries_the_entry_layout() {
		switch_theme( 'twentytwentyfive' );

		$html = self::render( self::create_entry( self::create_coverage() ), true, self::markup_with_entry_group() );

		$this->assertMatchesRegularExpression( '/<div class="[^"]*newspack-rolling-coverage-regular-entry[^"]*newspack-rolling-coverage-entry-layout-[0-9a-f]+/', $html );
		$this->assertStringNotContainsString( 'wp-block-separator', $html, 'The last entry should drop the separator.' );
	}

	/**
	 * With only an entry group in the template, a pinned entry shows it too,
	 * and keeps its separator.
	 */
	public function test_entry_group_alone_shows_for_pinned_entries() {
		$markup = substr( self::markup_with_entry_group(), strpos( self::markup_with_entry_group(), '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"' ) );
		$html   = self::render( self::create_pinned_entry(), false, $markup );

		$this->assertStringContainsString( 'Entry text', $html );
		$this->assertStringContainsString( 'wp-block-separator', $html );
	}

	/**
	 * On a theme without theme.json, the entry group's inner container
	 * carries the layout class.
	 */
	public function test_entry_group_inner_container_carries_the_entry_layout_without_theme_json() {
		self::use_theme_without_theme_json();

		$html       = self::render( self::create_entry( self::create_coverage() ), false, self::markup_with_entry_group() );
		$group_html = substr( $html, strpos( $html, 'newspack-rolling-coverage-regular-entry' ) );

		$this->assertMatchesRegularExpression( '/^[^>]*>\s*<div class="wp-block-group__inner-container[^"]*newspack-rolling-coverage-entry-layout-[0-9a-f]+/', $group_html, 'The first thing inside the entry group should be the inner container.' );
	}

	/**
	 * When no unpinned entry shows on the first render, the entry group's
	 * layout styles are still printed, for an entry that arrives later.
	 */
	public function test_entry_group_layout_styles_print_without_an_unpinned_entry() {
		switch_theme( 'twentytwentyfive' );

		$coverage_id = self::create_coverage();
		Post_Type::pin_entry( self::create_entry( $coverage_id ) );

		$markup      = str_replace(
			'{"className":"newspack-rolling-coverage-regular-entry"}',
			'{"className":"newspack-rolling-coverage-regular-entry","layout":{"type":"flex"},"style":{"spacing":{"blockGap":"10px"}}}',
			self::markup_with_entry_group()
		);
		$attributes  = [ 'coverageId' => $coverage_id ];
		$block       = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $markup . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];
		$group       = parse_blocks( $markup )[1];
		$group_class = wp_render_layout_support_flag( $group['innerHTML'], $group );

		preg_match( '/wp-container-core-group-is-layout-[0-9a-f]+/', $group_class, $container );
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();

		Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		$this->assertStringContainsString( $container[0], wp_style_engine_get_stylesheet_from_context( 'block-supports' ) );
	}

	/**
	 * A compact template: a pinned card and an entry group, each a row of the
	 * time and the body, with the visible pinned label in the card or without.
	 *
	 * @param bool $with_label Whether the card carries the pinned label.
	 * @return string Template markup.
	 */
	private static function compact_markup( bool $with_label = false ): string {
		$label = $with_label
			? '<!-- wp:paragraph {"className":"use-header-font newspack-rolling-coverage-pinned-label"} --><p class="use-header-font newspack-rolling-coverage-pinned-label">Pinned</p><!-- /wp:paragraph -->'
			: '';
		$date  = '<!-- wp:post-date {"format":"g:i a"} /-->';
		$body  = '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Body text</p><!-- /wp:paragraph --></div><!-- /wp:column -->';
		$row   = static fn( string $time ): string => '<!-- wp:columns {"isStackedOnMobile":false} --><div class="wp-block-columns">'
			. '<!-- wp:column {"width":"5rem"} --><div class="wp-block-column" style="flex-basis:5rem">' . $time . '</div><!-- /wp:column -->'
			. $body
			. '</div><!-- /wp:columns -->';

		return '<!-- wp:group {"className":"newspack-rolling-coverage-pinned-card"} --><div class="wp-block-group newspack-rolling-coverage-pinned-card">' . $label . $row( $date ) . '</div><!-- /wp:group -->'
			. '<!-- wp:group {"className":"newspack-rolling-coverage-regular-entry"} --><div class="wp-block-group newspack-rolling-coverage-regular-entry">' . $row( $date ) . '</div><!-- /wp:group -->';
	}

	/**
	 * A pinned entry whose template shows no pinned label is announced to
	 * screen readers first in the article; an unpinned one is not.
	 */
	public function test_pinned_entry_without_a_label_is_announced_to_screen_readers() {
		$markup = self::compact_markup();
		$pinned = self::render( self::create_pinned_entry(), false, $markup );
		$other  = self::render( self::create_entry( self::create_coverage() ), false, $markup );

		$this->assertMatchesRegularExpression( '/<article [^>]*><span class="newspack-rolling-coverage-pinned-status">Pinned<\/span>/', $pinned );
		$this->assertStringContainsString( 'Body text', $pinned );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-status', $other, 'An unpinned entry should carry no announcement.' );
		$this->assertStringContainsString( '<time', $other );
	}

	/**
	 * A pinned entry whose template shows the pinned label keeps just that
	 * label.
	 */
	public function test_pinned_entry_with_a_label_adds_no_announcement() {
		$pinned = self::render( self::create_pinned_entry(), false, self::compact_markup( true ) );

		$this->assertStringContainsString( 'newspack-rolling-coverage-pinned-label', $pinned );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-status', $pinned );
	}

	/**
	 * A label nested in a row group inside the card still counts as the
	 * pinned label.
	 */
	public function test_pinned_entry_with_a_nested_label_adds_no_announcement() {
		$label  = '<!-- wp:paragraph {"className":"use-header-font newspack-rolling-coverage-pinned-label"} --><p class="use-header-font newspack-rolling-coverage-pinned-label">Pinned</p><!-- /wp:paragraph -->';
		$markup = '<!-- wp:group {"className":"newspack-rolling-coverage-pinned-card"} --><div class="wp-block-group newspack-rolling-coverage-pinned-card">'
			. '<!-- wp:group {"layout":{"type":"flex"}} --><div class="wp-block-group">' . $label . '<!-- wp:post-date /--></div><!-- /wp:group -->'
			. '<!-- wp:paragraph --><p>Body text</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$pinned = self::render( self::create_pinned_entry(), false, $markup );

		$this->assertStringContainsString( 'newspack-rolling-coverage-pinned-label', $pinned );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-status', $pinned );
	}

	/**
	 * A compact pinned card and entry group carry no corner radius of their
	 * own, and rendering adds none.
	 */
	public function test_compact_cards_render_without_a_border_radius() {
		$markup = self::compact_markup();
		$pinned = self::render( self::create_pinned_entry(), false, $markup );
		$other  = self::render( self::create_entry( self::create_coverage() ), false, $markup );

		$this->assertStringContainsString( 'newspack-rolling-coverage-pinned-card', $pinned );
		$this->assertStringNotContainsString( 'border-radius', $pinned );
		$this->assertStringNotContainsString( 'border-radius', $other );
	}

	/**
	 * The built-in template, used when the block saves no blocks, renders the
	 * card for a pinned entry and the entry group for the others.
	 */
	public function test_default_template_renders_each_kind_of_entry() {
		$coverage_id = self::create_coverage();
		$pinned_id   = self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 10:00:00' ] );
		$other_id    = self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 11:00:00' ] );
		Post_Type::pin_entry( $pinned_id );

		$attributes = [ 'coverageId' => $coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];
		$html       = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		preg_match( '/<article id="newspack-rolling-coverage-entry-' . $pinned_id . '".*?<\/article>/s', $html, $pinned );
		preg_match( '/<article id="newspack-rolling-coverage-entry-' . $other_id . '".*?<\/article>/s', $html, $other );

		$this->assertStringContainsString( 'newspack-rolling-coverage-pinned-card', $pinned[0] );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-regular-entry', $pinned[0] );
		$this->assertStringContainsString( 'newspack-rolling-coverage-regular-entry', $other[0] );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-card', $other[0] );
		$this->assertSame( 1, substr_count( $pinned[0], 'class="wp-block-post-content ' ), 'A pinned entry should show its content once.' );
		$this->assertSame( 1, substr_count( $other[0], 'class="wp-block-post-content ' ), 'An unpinned entry should show its content once.' );
	}

	/**
	 * Without a card in the template, a pinned entry keeps its separator, so
	 * it doesn't run into the next entry.
	 */
	public function test_pinned_entry_without_a_card_keeps_the_separator() {
		$markup = '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --><!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->';

		$this->assertStringContainsString( 'wp-block-separator', self::render( self::create_pinned_entry(), false, $markup ) );
	}

	/**
	 * The last entry drops the separator.
	 */
	public function test_last_entry_drops_the_separator() {
		$entry_id = self::create_entry( self::create_coverage() );

		$this->assertStringContainsString( 'wp-block-separator', self::render( $entry_id ), 'An entry that is not last should keep it.' );
		$this->assertStringNotContainsString( 'wp-block-separator', self::render( $entry_id, true ) );
	}

	/**
	 * Without a published breakout, the card drops "Read more", wherever it
	 * sits in the card, and the space its last block keeps for it. With one,
	 * both stay.
	 */
	public function test_card_closes_up_without_a_breakout() {
		$entry_id = self::create_pinned_entry();
		$html     = self::render( $entry_id );

		$this->assertStringNotContainsString( 'Read more', $html, '"Read more" should go.' );
		$this->assertStringNotContainsString( 'margin-bottom:20px', $html, 'The last block should keep no bottom margin.' );

		$breakout_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );
		update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $entry_id );

		$html = self::render( $entry_id );

		$this->assertStringContainsString( 'Read more', $html, '"Read more" should stay.' );
		$this->assertStringContainsString( 'margin-bottom:20px', $html, 'The content should keep its margin.' );
	}

	/**
	 * A card holding the "Read more" paragraph drops it without a breakout.
	 */
	public function test_pinned_card_drops_the_read_more_paragraph_without_a_breakout() {
		$entry_id = self::create_pinned_entry();
		$markup   = '<!-- wp:group {"className":"newspack-rolling-coverage-pinned-card"} --><div class="wp-block-group newspack-rolling-coverage-pinned-card">'
			. '<!-- wp:paragraph --><p>Card text</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph {"className":"newspack-rolling-coverage-read-more"} --><p class="newspack-rolling-coverage-read-more">Read more</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';

		$html = self::render( $entry_id, false, $markup );

		$this->assertStringContainsString( 'Card text', $html );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-read-more', $html );
	}

	/**
	 * A pinned entry that is last drops the space below the card.
	 */
	public function test_last_pinned_card_drops_its_bottom_margin() {
		$entry_id = self::create_pinned_entry();

		$this->assertStringContainsString( 'margin-bottom:30px', self::render( $entry_id ), 'A card with entries after it should keep its margin.' );
		$this->assertStringNotContainsString( 'margin-bottom:30px', self::render( $entry_id, true ) );
	}

	/**
	 * On a theme with theme.json, the card itself carries its layout class.
	 */
	public function test_card_carries_the_entry_layout() {
		switch_theme( 'twentytwentyfive' );

		$html = self::render( self::create_pinned_entry() );

		$this->assertMatchesRegularExpression( '/<div class="[^"]*newspack-rolling-coverage-pinned-card[^"]*newspack-rolling-coverage-entry-layout-[0-9a-f]+/', $html );
		$this->assertStringNotContainsString( 'wp-block-group__inner-container', $html, 'Themes with theme.json have no inner container.' );
	}

	/**
	 * On a theme without theme.json, one inner container holds the card's
	 * blocks and carries its layout class.
	 */
	public function test_card_inner_container_carries_the_entry_layout_without_theme_json() {
		self::use_theme_without_theme_json();

		$html      = self::render( self::create_pinned_entry() );
		$card_html = substr( $html, strpos( $html, 'newspack-rolling-coverage-pinned-card' ) );

		$this->assertMatchesRegularExpression( '/^[^>]*>\s*<div class="wp-block-group__inner-container[^"]*newspack-rolling-coverage-entry-layout-[0-9a-f]+/', $card_html, 'The first thing inside the card should be the inner container.' );
		$this->assertSame( 1, preg_match_all( '/<div class="wp-block-group__inner-container[^"]*newspack-rolling-coverage-entry-layout-/', $html ), 'Only the card should carry the layout class.' );
	}

	/**
	 * A card laid out as a row keeps its own spacing.
	 */
	public function test_row_card_keeps_its_own_layout() {
		$markup = str_replace( '"className":"newspack-rolling-coverage-pinned-card",', '"className":"newspack-rolling-coverage-pinned-card","layout":{"type":"flex"},', self::TEMPLATE_MARKUP );

		$this->assertStringNotContainsString( 'newspack-rolling-coverage-entry-layout-', self::render( self::create_pinned_entry(), false, $markup ) );
	}

	/**
	 * The card and the entry group space their blocks by their own Block
	 * spacing, with `spacing-20` when it's unset.
	 *
	 * @dataProvider data_group_spacing
	 *
	 * @param string $style    The group's style attribute, as JSON.
	 * @param string $expected The space between the group's blocks.
	 */
	public function test_group_spacing_lays_out_its_blocks( string $style, string $expected ) {
		switch_theme( 'twentytwentyfive' );

		$markup = str_replace( '{"className":"newspack-rolling-coverage-regular-entry"}', '{"className":"newspack-rolling-coverage-regular-entry"' . $style . '}', self::markup_with_entry_group() );
		$html   = self::render( self::create_entry( self::create_coverage() ), false, $markup );

		$this->assertMatchesRegularExpression( '/newspack-rolling-coverage-regular-entry[^"]*(newspack-rolling-coverage-entry-layout-[0-9a-f]+)/', $html );
		preg_match( '/newspack-rolling-coverage-regular-entry[^"]*(newspack-rolling-coverage-entry-layout-[0-9a-f]+)/', $html, $matches );

		$this->assertStringContainsString(
			'.' . $matches[1] . ' > * + *{margin-block-start:' . $expected,
			wp_style_engine_get_stylesheet_from_context( 'block-supports', [ 'prettify' => false ] )
		);
	}

	/**
	 * Group spacing settings and the space they give.
	 *
	 * @return array[]
	 */
	public function data_group_spacing(): array {
		return [
			'unset'  => [ '', 'var(--wp--preset--spacing--20)' ],
			'preset' => [ ',"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}', 'var(--wp--preset--spacing--30)' ],
		];
	}

	/**
	 * When no pinned entry shows on the first render, the card's layout
	 * styles are still printed, for a pinned entry that arrives later.
	 */
	public function test_card_layout_styles_print_without_a_pinned_entry() {
		switch_theme( 'twentytwentyfive' );

		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$markup     = str_replace(
			[ '"className":"newspack-rolling-coverage-pinned-card",', '"margin":{"bottom":"30px"}}' ],
			[ '"className":"newspack-rolling-coverage-pinned-card","layout":{"type":"flex"},', '"margin":{"bottom":"30px"},"blockGap":"10px"}' ],
			self::TEMPLATE_MARKUP
		);
		$attributes = [ 'coverageId' => $coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . $markup . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];
		$card       = parse_blocks( $markup )[0];
		$card_class = wp_render_layout_support_flag( $card['innerHTML'], $card );

		preg_match( '/wp-container-core-group-is-layout-[0-9a-f]+/', $card_class, $container );
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();

		Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		$this->assertStringContainsString( $container[0], wp_style_engine_get_stylesheet_from_context( 'block-supports' ) );
	}

	/**
	 * The layout renders once per entry and never as the block's own content,
	 * which core would otherwise render and the block discard.
	 */
	public function test_layout_renders_only_for_entries() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 10:00:00' ] );
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 11:00:00' ] );

		$renders = 0;
		$count   = static function ( $block_content, $block ) use ( &$renders ) {
			if ( 'core/paragraph' === ( $block['blockName'] ?? '' ) && false !== strpos( $block_content, 'Entry text' ) ) {
				$renders++;
			}

			return $block_content;
		};

		// The block registers from its built files, which a test run may not have.
		$is_registered = WP_Block_Type_Registry::get_instance()->is_registered( Rolling_Coverage_Block::BLOCK_NAME );

		if ( ! $is_registered ) {
			register_block_type( Rolling_Coverage_Block::BLOCK_NAME, Rolling_Coverage_Block::block_type_args() );
		}

		add_filter( 'render_block', $count, 10, 2 );
		$html = do_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( [ 'coverageId' => $coverage_id ] ) . ' -->' . self::markup_with_entry_group() . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' );
		remove_filter( 'render_block', $count, 10 );

		if ( ! $is_registered ) {
			unregister_block_type( Rolling_Coverage_Block::BLOCK_NAME );
		}

		$this->assertSame( 2, substr_count( $html, 'Entry text' ), 'Each entry should show the layout.' );
		$this->assertSame( 2, $renders, 'The layout should render for the entries alone.' );
	}

	/**
	 * The first render drops the last entry's separator only when no more
	 * entries can load, as does the last page of load more.
	 */
	public function test_block_and_load_more_drop_the_last_separator() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 10:00:00' ] );
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 11:00:00' ] );

		$render = static function ( int $per_page ) use ( $coverage_id ): string {
			$attributes = [
				'coverageId'     => $coverage_id,
				'entriesPerPage' => $per_page,
			];
			$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . self::TEMPLATE_MARKUP . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

			return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
		};

		$this->assertSame( 1, substr_count( $render( 3 ), 'wp-block-separator' ), 'With everything loaded, only the first entry should keep it.' );

		$html = $render( 1 );

		$this->assertSame( 1, substr_count( $html, 'wp-block-separator' ), 'With more to load, the entry should keep it.' );

		preg_match( '/data-template-key="([^"]+)"/', $html, $matches );

		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'term_id', $coverage_id );
		$request->set_param( 'template_key', $matches[1] );
		$request->set_param( 'per_page', 2 );
		$request->set_param( 'before', '2026-01-01 11:00:00' );

		$this->assertStringNotContainsString( 'wp-block-separator', Rolling_Coverage_Block::get_entries( $request )->get_data()['html'], 'The last page should drop it.' );
	}
}
