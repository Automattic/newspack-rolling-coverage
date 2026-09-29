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
	 * Render an entry through the card template.
	 *
	 * @param int    $entry_id     Entry post ID.
	 * @param bool   $is_last      Whether no entry can load after it.
	 * @param string $markup       Template markup.
	 * @param string $layout_class The entries' layout container class.
	 * @return string Rendered entry.
	 */
	private static function render( int $entry_id, bool $is_last = false, string $markup = self::TEMPLATE_MARKUP, string $layout_class = '' ): string {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( $markup ), 'initial', '', $layout_class, $is_last );
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
	 * A pinned entry that is last drops the space below the card.
	 */
	public function test_last_pinned_card_drops_its_bottom_margin() {
		$entry_id = self::create_pinned_entry();

		$this->assertStringContainsString( 'margin-bottom:30px', self::render( $entry_id ), 'A card with entries after it should keep its margin.' );
		$this->assertStringNotContainsString( 'margin-bottom:30px', self::render( $entry_id, true ) );
	}

	/**
	 * On a theme with theme.json, the card itself carries the entries'
	 * layout class.
	 */
	public function test_card_carries_the_entry_layout() {
		switch_theme( 'twentytwentyfive' );

		$html = self::render( self::create_pinned_entry(), false, self::TEMPLATE_MARKUP, 'entry-layout-test' );

		$this->assertMatchesRegularExpression( '/<div class="[^"]*newspack-rolling-coverage-pinned-card[^"]*entry-layout-test/', $html );
		$this->assertStringNotContainsString( 'wp-block-group__inner-container', $html, 'Themes with theme.json have no inner container.' );
	}

	/**
	 * On a theme without theme.json, one inner container holds the card's
	 * blocks and carries the entries' layout class.
	 */
	public function test_card_inner_container_carries_the_entry_layout_without_theme_json() {
		self::use_theme_without_theme_json();

		$html      = self::render( self::create_pinned_entry(), false, self::TEMPLATE_MARKUP, 'entry-layout-test' );
		$card_html = substr( $html, strpos( $html, 'newspack-rolling-coverage-pinned-card' ) );

		$this->assertMatchesRegularExpression( '/^[^>]*>\s*<div class="wp-block-group__inner-container[^"]*entry-layout-test/', $card_html, 'The first thing inside the card should be the inner container.' );
		$this->assertSame( 1, preg_match_all( '/<div class="wp-block-group__inner-container[^"]*entry-layout-test/', $html ), 'Only the card should carry the layout class.' );
	}

	/**
	 * A card laid out as a row keeps its own spacing.
	 */
	public function test_row_card_keeps_its_own_layout() {
		$markup = str_replace( '"className":"newspack-rolling-coverage-pinned-card",', '"className":"newspack-rolling-coverage-pinned-card","layout":{"type":"flex"},', self::TEMPLATE_MARKUP );
		$html   = self::render( self::create_pinned_entry(), false, $markup, 'entry-layout-test' );

		$this->assertSame( 1, substr_count( $html, 'entry-layout-test' ), 'Only the entry should carry the layout class.' );
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
