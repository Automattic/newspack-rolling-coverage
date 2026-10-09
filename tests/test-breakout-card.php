<?php
/**
 * Tests for entries shown as a card for their published breakout post.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Archive_Mode;
use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Breakout_Card;
use Newspack_Rolling_Coverage\Lite_Feed;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;

/**
 * Once an entry's breakout post is published, every layout shows the entry
 * as a card for that post: the post's title and summary in place of the
 * entry's own, in the layout's own blocks.
 */
class Test_Breakout_Card extends Rolling_Coverage_TestCase {

	const TITLE_MARKUP = '<!-- wp:post-title {"level":4} /-->';

	const CONTENT_MARKUP = '<!-- wp:post-content {"fontSize":"small"} /-->';

	const FLASH_EXCERPT_MARKUP = '<!-- wp:post-excerpt {"excerptLength":100,"moreText":""} /-->';

	const WIRE_EXCERPT_MARKUP = '<!-- wp:post-excerpt {"excerptLength":15,"moreText":""} /-->';

	const READ_MORE_MARKUP = '<!-- wp:paragraph {"className":"newspack-rolling-coverage-read-more"} --><p class="newspack-rolling-coverage-read-more"><a href="#">Read more</a></p><!-- /wp:paragraph -->';

	const SHARE_MARKUP = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"url":{"source":"newspack-rolling-coverage/entry","args":{"key":"shareUrl"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Share</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';

	/**
	 * A header row with the title stacked under the date, Share opposite.
	 */
	const HEADER_MARKUP = '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"top"}} --><div class="wp-block-group">'
		. '<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} --><div class="wp-block-group"><!-- wp:post-date /--><!-- wp:post-title /--></div><!-- /wp:group -->'
		. '<!-- wp:paragraph --><p>Share</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->';

	const ENTRY_TEXT = 'What the entry said.';

	/**
	 * Create an entry and its breakout post, linked both ways.
	 *
	 * @param string $status     The breakout post's status.
	 * @param array  $post_args  The breakout post's factory arguments.
	 * @param array  $entry_args The entry's factory arguments.
	 * @return int[] The entry ID and the breakout post ID.
	 */
	private static function create_breakout( string $status = 'publish', array $post_args = [], array $entry_args = [] ): array {
		$entry_id    = self::create_entry(
			self::create_coverage(),
			array_merge(
				[
					'post_title'   => 'Entry headline',
					'post_content' => '<!-- wp:paragraph --><p>' . self::ENTRY_TEXT . '</p><!-- /wp:paragraph -->',
				],
				$entry_args
			)
		);
		$breakout_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => $status,
					'post_title'   => 'Post & headline',
					'post_excerpt' => 'What the post sums up.',
					'post_content' => '<!-- wp:paragraph --><p>The whole post.</p><!-- /wp:paragraph -->',
				],
				$post_args
			)
		);
		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );
		update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $entry_id );

		return [ $entry_id, $breakout_id ];
	}

	/**
	 * Render an entry through a template.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param string $markup   Template markup.
	 * @return string
	 */
	private static function render( int $entry_id, string $markup ): string {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( $markup ) );
	}

	/**
	 * A titled layout shows the post's title, linked to it, and its summary
	 * in the entry's content, which keeps its own wrapper and font size.
	 * Read more still links to the post.
	 */
	public function test_titled_layout_shows_the_post_title_and_summary() {
		[ $entry_id, $breakout_id ] = self::create_breakout();
		$url                        = get_permalink( $breakout_id );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::READ_MORE_MARKUP );

		$this->assertStringContainsString( '<a href="' . esc_url( $url ) . '">Post &amp; headline</a></h4>', $html );
		$this->assertMatchesRegularExpression( '#<div class="[^"]*wp-block-post-content[^"]*has-small-font-size[^"]*"><p>What the post sums up\.</p></div>#', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( $url ) . '">Read more</a>', $html );
		$this->assertStringNotContainsString( 'Entry headline', $html );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
		$this->assertFalse( has_filter( 'the_title', [ Breakout_Card::class, 'filter_title' ] ), 'The card filters go once the entry is rendered.' );
		$this->assertFalse( has_filter( 'render_block_core/post-content', [ Breakout_Card::class, 'render_content' ] ) );
	}

	/**
	 * Only the card's Post Title takes the post's title: Share still shares
	 * the entry and is named after it.
	 */
	public function test_share_keeps_the_entry_name() {
		[ $entry_id ] = self::create_breakout();

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::SHARE_MARKUP );

		$this->assertStringContainsString( 'Post &amp; headline</a></h4>', $html );
		$this->assertStringContainsString( 'aria-label="Share: Entry headline"', $html );
	}

	/**
	 * A layout without a title names the post in the content, linked, then
	 * gives its summary.
	 */
	public function test_untitled_layout_names_the_post_in_the_content() {
		[ $entry_id, $breakout_id ] = self::create_breakout();

		$html = self::render( $entry_id, self::CONTENT_MARKUP );

		$this->assertStringContainsString( '><p><strong><a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Post &amp; headline</a></strong></p><p>What the post sums up.</p></div>', $html );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
	}

	/**
	 * An excerpt-only layout, like Flash, shows the post's title.
	 */
	public function test_excerpt_only_layout_shows_the_post_title() {
		[ $entry_id ] = self::create_breakout();

		$html = self::render( $entry_id, self::FLASH_EXCERPT_MARKUP );

		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">Post &amp; headline', $html );
		$this->assertStringNotContainsString( 'sums up', $html );
	}

	/**
	 * A layout with a title and an excerpt, like Wire, shows the summary in
	 * the excerpt, cut to the block's own length.
	 */
	public function test_titled_excerpt_layout_shows_the_summary() {
		[ $entry_id ] = self::create_breakout( 'publish', [ 'post_excerpt' => 'One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen.' ] );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertStringContainsString( 'Post &amp; headline</a></h4>', $html );
		$this->assertStringContainsString( 'wp-block-post-excerpt__excerpt">One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen&hellip;', $html );
	}

	/**
	 * A post without a hand-written excerpt is summed up by WordPress's
	 * generated one, at the site's excerpt length.
	 */
	public function test_summary_is_the_generated_excerpt_at_the_site_length() {
		[ $entry_id ] = self::create_breakout(
			'publish',
			[
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>Alpha beta gamma delta epsilon zeta eta theta.</p><!-- /wp:paragraph -->',
			]
		);
		add_filter( 'excerpt_length', fn() => 4, 999 );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertStringContainsString( '<p>Alpha beta gamma delta […]</p>', $html );
	}

	/**
	 * A password protected post gets its title, marked protected as
	 * WordPress lists it, and no summary: the content and excerpt render
	 * nothing under the title, and say only the title without one.
	 */
	public function test_protected_post_shows_its_title_alone() {
		[ $entry_id, $breakout_id ] = self::create_breakout( 'publish', [ 'post_password' => 'secret' ] );

		$titled = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::WIRE_EXCERPT_MARKUP );

		$this->assertStringContainsString( '>Protected: Post &amp; headline</a></h4>', $titled );
		$this->assertStringNotContainsString( 'sums up', $titled );
		$this->assertStringNotContainsString( 'wp-block-post-content', $titled );
		$this->assertStringNotContainsString( 'wp-block-post-excerpt', $titled );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $titled );

		$untitled = self::render( $entry_id, self::CONTENT_MARKUP );

		$this->assertStringContainsString( '><p><strong><a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Protected: Post &amp; headline</a></strong></p></div>', $untitled );
	}

	/**
	 * Until the post is published, the entry shows its own title and text.
	 */
	public function test_draft_breakout_leaves_the_entry_as_it_is() {
		[ $entry_id ] = self::create_breakout( 'draft' );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP . self::FLASH_EXCERPT_MARKUP );

		$this->assertStringContainsString( 'Entry headline', $html );
		$this->assertStringContainsString( self::ENTRY_TEXT, $html );
		$this->assertStringNotContainsString( 'Post &amp; headline', $html );
		$this->assertStringNotContainsString( 'sums up', $html );
	}

	/**
	 * Unpublishing the post brings the entry's own text back.
	 */
	public function test_unpublishing_the_post_restores_the_entry() {
		[ $entry_id, $breakout_id ] = self::create_breakout();

		$this->assertStringNotContainsString( self::ENTRY_TEXT, self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP ), 'Precondition: the card shows.' );

		wp_update_post(
			[
				'ID'          => $breakout_id,
				'post_status' => 'draft',
			]
		);

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertStringContainsString( 'Entry headline', $html );
		$this->assertStringContainsString( self::ENTRY_TEXT, $html );
		$this->assertStringContainsString( self::ENTRY_TEXT, get_post( $entry_id )->post_content, 'The entry is never changed.' );
	}

	/**
	 * An untitled entry's card is titled, so its header keeps the titled
	 * alignment, and an entry whose content renders nothing still gets the
	 * summary in the content's wrapper.
	 */
	public function test_untitled_entry_renders_as_a_titled_card() {
		WP_Style_Engine_CSS_Rules_Store::remove_all_stores();
		[ $entry_id ] = self::create_breakout(
			'publish',
			[],
			[
				'post_title'   => '',
				'post_content' => '',
			]
		);

		$html   = self::render( $entry_id, self::HEADER_MARKUP . self::CONTENT_MARKUP );
		$styles = wp_style_engine_get_stylesheet_from_context( 'block-supports' );

		$this->assertSame( 1, preg_match( '/class="[^"]*is-content-justification-space-between[^"]*(wp-container-core-group-is-layout-[0-9a-f]+)/', $html, $matches ) );
		$this->assertStringContainsString( '.' . $matches[1] . '{', $styles );
		$this->assertMatchesRegularExpression( '/\.' . preg_quote( $matches[1], '/' ) . '\s*\{[^}]*align-items:flex-start/', $styles );
		$this->assertStringContainsString( 'Post &amp; headline</a></h2>', $html );
		$this->assertMatchesRegularExpression( '#<div class="[^"]*wp-block-post-content[^"]*"><p>What the post sums up\.</p></div>#', $html );
	}

	/**
	 * An archived entry's card keeps the archived notice above the summary.
	 */
	public function test_archived_entry_card_keeps_the_notice() {
		[ $entry_id ] = self::create_breakout();
		update_post_meta( $entry_id, Archive_Mode::ENTRY_ARCHIVED_META_KEY, time() );

		$html = self::render( $entry_id, self::TITLE_MARKUP . self::CONTENT_MARKUP );

		$this->assertMatchesRegularExpression( '#<p class="newspack-rolling-coverage-entry-archived-notice">.*</p><div [^>]*><p>What the post sums up\.</p></div>#s', $html );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
	}

	/**
	 * A lite page shows the post's title, linked to it, and its summary.
	 */
	public function test_lite_entry_shows_the_post_title_and_summary() {
		require_once __DIR__ . '/mocks/class-lite-site.php';
		[ $entry_id, $breakout_id ] = self::create_breakout();

		$html = Lite_Feed::render_entry( get_post( $entry_id ), 'initial' );

		$this->assertStringContainsString( '</p><h3><a href="' . esc_url( get_permalink( $breakout_id ) ) . '">Post &amp; headline</a></h3><p>What the post sums up.</p></article>', $html );
		$this->assertStringNotContainsString( 'Entry headline', $html );
		$this->assertStringNotContainsString( self::ENTRY_TEXT, $html );
	}

	/**
	 * Editing a published post's title, excerpt or content touches its
	 * entry, so open pages get the new card; other edits, and edits to a
	 * draft, don't.
	 */
	public function test_editing_the_published_post_touches_the_entry() {
		[ $entry_id, $breakout_id ] = self::create_breakout();

		foreach ( [ 'post_title', 'post_excerpt', 'post_content' ] as $field ) {
			self::backdate_modified( $entry_id );
			wp_update_post(
				[
					'ID'   => $breakout_id,
					$field => 'Edited ' . $field,
				]
			);

			$this->assertGreaterThan( '2026-01-01 12:00:00', get_post( $entry_id )->post_modified_gmt, "Editing {$field} should touch the entry." );
		}

		self::backdate_modified( $entry_id );
		wp_update_post(
			[
				'ID'         => $breakout_id,
				'menu_order' => 3,
			]
		);

		$this->assertSame( '2026-01-01 12:00:00', get_post( $entry_id )->post_modified_gmt, 'An edit the card does not show leaves the entry alone.' );

		[ $draft_entry_id, $draft_id ] = self::create_breakout( 'draft' );
		self::backdate_modified( $draft_entry_id );
		wp_update_post(
			[
				'ID'         => $draft_id,
				'post_title' => 'Edited draft',
			]
		);

		$this->assertSame( '2026-01-01 12:00:00', get_post( $draft_entry_id )->post_modified_gmt, 'Editing a draft leaves the entry alone.' );

		$touches = 0;
		add_action(
			'post_updated',
			static function ( $post_id ) use ( $draft_entry_id, &$touches ) {
				$touches += $draft_entry_id === $post_id ? 1 : 0;
			}
		);
		wp_update_post(
			[
				'ID'          => $draft_id,
				'post_title'  => 'Published at last',
				'post_status' => 'publish',
			]
		);

		$this->assertSame( 1, $touches, 'Publishing with an edit touches the entry once, for the publish.' );
	}

	/**
	 * Set an entry's modified date back, as if it was last touched long ago.
	 *
	 * @param int $entry_id Entry post ID.
	 */
	private static function backdate_modified( int $entry_id ): void {
		global $wpdb;

		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => '2026-01-01 12:00:00' ], [ 'ID' => $entry_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $entry_id );
	}
}
