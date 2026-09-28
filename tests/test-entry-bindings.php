<?php
/**
 * Tests for the core buttons bound to each entry.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Push_Notifications;
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
	private static function render( int $entry_id ): string {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( self::BUTTONS_MARKUP ) );
	}

	/**
	 * Link the entry to a new breakout post with the given status.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param string $status   Breakout post status.
	 * @return int Breakout post ID.
	 */
	private static function add_breakout( int $entry_id, string $status ): int {
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

		$html = self::render( $entry_id );

		$this->assertStringNotContainsString( 'Read more', $html, 'No breakout: no button.' );
		$this->assertStringContainsString( '>Share</a>', $html, 'The rest of the template should still render.' );

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
	 * The share button links to the entry and is marked for the share script.
	 */
	public function test_share_links_to_the_entry_as_a_button() {
		$entry_id = self::create_entry( self::create_coverage() );

		$this->assertMatchesRegularExpression(
			'#<a (?=[^>]*href="' . preg_quote( esc_url( get_permalink( $entry_id ) ), '#' ) . '")(?=[^>]*data-rc-share)(?=[^>]*role="button")#',
			self::render( $entry_id )
		);
	}

	/**
	 * An entry's relative date is marked so the front end keeps it current;
	 * a date in any other format, or outside an entry, is left alone.
	 */
	public function test_only_relative_entry_dates_are_marked_for_refresh() {
		$entry_id = self::create_entry( self::create_coverage() );
		$entry    = get_post( $entry_id );
		$relative = parse_blocks( '<!-- wp:post-date {"format":"human-diff"} /-->' );
		$absolute = parse_blocks( '<!-- wp:post-date /-->' );

		$this->assertStringContainsString( 'data-rc-relative', Rolling_Coverage_Block::render_entry( $entry, $relative ), 'A relative entry date should be marked.' );
		$this->assertStringNotContainsString( 'data-rc-relative', Rolling_Coverage_Block::render_entry( $entry, $absolute ), 'An absolute entry date should not be marked.' );

		$post_id = self::factory()->post->create();
		$html    = ( new WP_Block(
			$relative[0],
			[
				'postId'   => $post_id,
				'postType' => 'post',
			] 
		) )->render();

		$this->assertStringNotContainsString( 'data-rc-relative', $html, 'A relative date outside an entry should not be marked.' );
	}

	/**
	 * Buttons not bound to an entry render untouched, anywhere on the site.
	 */
	public function test_other_buttons_are_left_alone() {
		$markup = '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.test/">Donate</a></div><!-- /wp:button -->';

		$this->assertSame( trim( render_block( parse_blocks( $markup )[0] ) ), trim( parse_blocks( $markup )[0]['innerHTML'] ) );
	}

	/**
	 * A coverage block with no saved inner blocks renders the same buttons
	 * from the server's fallback template.
	 */
	public function test_fallback_template_renders_the_bound_buttons() {
		$coverage_id = self::create_coverage();
		$entry_id    = self::create_entry( $coverage_id );
		$breakout_id = self::add_breakout( $entry_id, 'publish' );

		$attributes = [ 'coverageId' => $coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' /-->' )[0];

		// Called directly: the block type registers from the built assets, which the test run doesn't have.
		$html = Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );

		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $breakout_id ) ) . '"', $html, '"Read more" should link to the breakout.' );
		$this->assertStringContainsString( 'data-rc-share', $html, 'Share should be marked for the share script.' );
	}

	/**
	 * The follow button, as the editor saves it.
	 */
	const FOLLOW_MARKUP = '<!-- wp:buttons --><div class="wp-block-buttons">'
		. '<!-- wp:button {"tagName":"button","metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"followTag"}}}}} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Follow</button></div><!-- /wp:button -->'
		. '</div><!-- /wp:buttons -->';

	/**
	 * Render a coverage block holding the follow button and the entry buttons.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string Rendered block.
	 */
	private static function render_coverage_with_follow( int $coverage_id ): string {
		require_once __DIR__ . '/mocks/onesignal.php';
		update_option(
			'OneSignalWPSetting',
			[
				'app_id'           => 'test-app-id',
				'app_rest_api_key' => 'test-rest-api-key',
			]
		);

		$attributes = [ 'coverageId' => $coverage_id ];
		$block      = parse_blocks( '<!-- wp:newspack-rolling-coverage/rolling-coverage ' . wp_json_encode( $attributes ) . ' -->' . self::FOLLOW_MARKUP . self::BUTTONS_MARKUP . '<!-- /wp:newspack-rolling-coverage/rolling-coverage -->' )[0];

		return Rolling_Coverage_Block::render_block( $attributes, '', new WP_Block( $block ) );
	}

	/**
	 * The follow button renders once, above the entries, carrying the
	 * coverage's notification tag for the follow script.
	 */
	public function test_follow_button_renders_once_with_the_coverage_tag() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );
		self::create_entry( $coverage_id );

		$html = self::render_coverage_with_follow( $coverage_id );

		$this->assertSame( 1, substr_count( $html, 'data-rc-follow' ), 'The follow button should render once, not per entry.' );
		$this->assertStringContainsString( 'data-tag="' . esc_attr( Push_Notifications::follow_tag( $coverage_id ) ) . '"', $html );
	}

	/**
	 * An archived coverage can't be followed, so its button doesn't render.
	 */
	public function test_follow_button_is_hidden_for_an_archived_coverage() {
		$coverage_id = self::create_coverage( 'archived' );
		self::create_entry( $coverage_id );

		$this->assertStringNotContainsString( '>Follow<', self::render_coverage_with_follow( $coverage_id ) );
	}
}
