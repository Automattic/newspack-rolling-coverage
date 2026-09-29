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
	 * The card holding the content and "Read more", then a separator, as the
	 * editor saves them.
	 */
	const TEMPLATE_MARKUP = '<!-- wp:group {"className":"newspack-rolling-coverage-pinned-card","style":{"color":{"background":"#f7f7f7"}}} -->'
		. '<div class="wp-block-group newspack-rolling-coverage-pinned-card has-background" style="background-color:#f7f7f7">'
		. '<!-- wp:paragraph {"style":{"spacing":{"margin":{"bottom":"20px"}}}} --><p style="margin-bottom:20px">Card text</p><!-- /wp:paragraph -->'
		. '<!-- wp:buttons --><div class="wp-block-buttons">'
		. '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"breakoutUrl"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Read more</a></div><!-- /wp:button -->'
		. '</div><!-- /wp:buttons -->'
		. '</div><!-- /wp:group -->'
		. '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->';

	/**
	 * Render an entry through the card template.
	 *
	 * @param int  $entry_id Entry post ID.
	 * @param bool $is_last  Whether no entry can load after it.
	 * @return string Rendered entry.
	 */
	private static function render( int $entry_id, bool $is_last = false ): string {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( self::TEMPLATE_MARKUP ), 'initial', '', '', $is_last );
	}

	/**
	 * A pinned entry shows the card and no separator; an unpinned one shows
	 * the card's blocks without it, then the separator.
	 */
	public function test_only_pinned_entries_show_the_card() {
		$coverage_id = self::create_coverage();
		$pinned_id   = self::create_entry( $coverage_id );
		$other_id    = self::create_entry( $coverage_id );
		Post_Type::pin_entry( $pinned_id );

		$pinned = self::render( $pinned_id );
		$other  = self::render( $other_id );

		$this->assertStringContainsString( 'newspack-rolling-coverage-pinned-card', $pinned, 'A pinned entry should show the card.' );
		$this->assertStringNotContainsString( 'wp-block-separator', $pinned, 'A pinned entry should drop the separator.' );
		$this->assertStringNotContainsString( 'newspack-rolling-coverage-pinned-card', $other, 'An unpinned entry should not show the card.' );
		$this->assertStringContainsString( 'Card text', $other, "An unpinned entry should keep the card's blocks." );
		$this->assertStringContainsString( 'wp-block-separator', $other, 'An unpinned entry should keep the separator.' );
	}

	/**
	 * The last entry drops the separator.
	 */
	public function test_last_entry_drops_the_separator() {
		$entry_id = self::create_entry( self::create_coverage() );

		$this->assertStringNotContainsString( 'wp-block-separator', self::render( $entry_id, true ) );
	}

	/**
	 * Without a published breakout, the card drops "Read more" and the space
	 * its last block keeps for it. With one, both stay.
	 */
	public function test_card_closes_up_without_a_breakout() {
		$entry_id = self::create_entry( self::create_coverage() );
		Post_Type::pin_entry( $entry_id );

		$template  = parse_blocks( self::TEMPLATE_MARKUP );
		$card      = Rolling_Coverage_Block::shape_entry_template( $template, true, false, false )[0];
		$paragraph = $card['innerBlocks'][0];

		$this->assertCount( 1, $card['innerBlocks'], 'The empty Buttons block should go.' );
		$this->assertArrayNotHasKey( 'bottom', $paragraph['attrs']['style']['spacing']['margin'] ?? [], 'The last block should keep no bottom margin.' );
		$this->assertCount( 1, array_filter( $card['innerContent'], 'is_null' ), 'The card should keep one placeholder per inner block.' );

		$with_breakout = Rolling_Coverage_Block::shape_entry_template( $template, true, true, false )[0];

		$this->assertCount( 2, $with_breakout['innerBlocks'], '"Read more" should stay.' );
		$this->assertSame( '20px', $with_breakout['innerBlocks'][0]['attrs']['style']['spacing']['margin']['bottom'], 'The content should keep its margin.' );

		$breakout_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );
		update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $entry_id );

		$this->assertStringContainsString( 'Read more', self::render( $entry_id ), 'A published breakout should show "Read more" in the card.' );
	}

	/**
	 * The card spaces its blocks with the entries' layout class.
	 */
	public function test_card_carries_the_entry_layout() {
		$card = Rolling_Coverage_Block::shape_entry_template( parse_blocks( self::TEMPLATE_MARKUP ), true, true, false, 'entry-layout-test' )[0];

		$this->assertStringContainsString( 'entry-layout-test', implode( '', array_filter( $card['innerContent'], 'is_string' ) ) );
	}
}
