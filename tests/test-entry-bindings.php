<?php
/**
 * Tests for the core buttons bound to each entry.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * The entry template's "Read more" and share buttons are core buttons whose
 * link and label come from the entry being rendered.
 */
class Test_Entry_Bindings extends Rolling_Coverage_TestCase {

	/**
	 * The entry template's buttons, as the editor saves them.
	 */
	const BUTTONS_MARKUP = '<!-- wp:buttons --><div class="wp-block-buttons">'
		. '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"breakoutUrl"}},"text":{"source":"newspack-rolling-coverage/entry","args":{"key":"breakoutLabel"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button"></a></div><!-- /wp:button -->'
		. '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"shareUrl"}}}},"className":"newspack-rolling-coverage-share-link"} --><div class="wp-block-button newspack-rolling-coverage-share-link"><a class="wp-block-button__link wp-element-button">Share</a></div><!-- /wp:button -->'
		. '</div><!-- /wp:buttons -->';

	/**
	 * Render an entry through the buttons template.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return string Rendered entry.
	 */
	private static function render( $entry_id ) {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( self::BUTTONS_MARKUP ) );
	}

	/**
	 * Link the entry to a new breakout post with the given status.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param string $status   Breakout post status.
	 * @return int Breakout post ID.
	 */
	private static function add_breakout( $entry_id, $status ) {
		$breakout_id = self::factory()->post->create( [ 'post_status' => $status ] );
		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );
		update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $entry_id );

		return $breakout_id;
	}

	/**
	 * "Read more" stays hidden until the breakout post is published.
	 */
	public function test_read_more_is_hidden_until_the_breakout_is_published() {
		$entry_id = self::create_entry( self::create_coverage() );

		$this->assertStringNotContainsString( 'Read more', self::render( $entry_id ), 'No breakout: no button.' );

		self::add_breakout( $entry_id, 'draft' );

		$this->assertStringNotContainsString( 'Read more', self::render( $entry_id ), 'A draft breakout: no button.' );
	}

	/**
	 * A published breakout gives "Read more" its link and the entry's label.
	 */
	public function test_read_more_links_to_the_published_breakout_with_the_entry_label() {
		$entry_id    = self::create_entry( self::create_coverage() );
		$breakout_id = self::add_breakout( $entry_id, 'publish' );

		$html = self::render( $entry_id );

		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $breakout_id ) ) . '"', $html, 'The button should link to the breakout.' );
		$this->assertStringContainsString( '>Read more</a>', $html, 'The default label should be used.' );

		update_post_meta( $entry_id, Breakout::ENTRY_READ_MORE_TEXT_META, 'Full story' );

		$this->assertStringContainsString( '>Full story</a>', self::render( $entry_id ), "The entry's own label should win." );
	}

	/**
	 * The share button links to the entry.
	 */
	public function test_share_links_to_the_entry() {
		$entry_id = self::create_entry( self::create_coverage() );

		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $entry_id ) ) . '"', self::render( $entry_id ) );
	}
}
