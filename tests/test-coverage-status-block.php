<?php
/**
 * Tests for the Coverage Status block.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Coverage_Status_Block;
use Newspack_Rolling_Coverage\Status_Labels;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * The block shows the status of the Rolling Coverage block on its page, and
 * nothing on a page without one.
 */
class Test_Coverage_Status_Block extends Rolling_Coverage_TestCase {

	/**
	 * Register the block from its metadata when the build isn't there, so its
	 * `postId` context reaches the render callback.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Coverage_Status_Block::BLOCK_NAME ) ) {
			register_block_type(
				Coverage_Status_Block::BLOCK_NAME,
				[
					'uses_context'    => [ 'postId' ],
					'render_callback' => [ Coverage_Status_Block::class, 'render_block' ],
				]
			);
		}
	}

	/**
	 * A Rolling Coverage block's markup.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return string
	 */
	private static function feed( int $coverage_id ): string {
		return '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} /-->';
	}

	/**
	 * Create a page holding the given block markup.
	 *
	 * @param string $content Post content.
	 * @return int Page ID.
	 */
	private static function page( string $content ): int {
		return self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_content' => $content,
			]
		);
	}

	/**
	 * Render the block as it would be inside the given post.
	 *
	 * @param array $attributes Block attributes.
	 * @param int   $post_id    Post the block sits in, passed as context; 0 for none.
	 * @return string
	 */
	private static function render( array $attributes = [], int $post_id = 0 ): string {
		$parsed = parse_blocks( '<!-- wp:newspack-rolling-coverage/coverage-status ' . wp_json_encode( (object) $attributes ) . ' /-->' )[0];
		$block  = new WP_Block( $parsed, $post_id ? [ 'postId' => $post_id ] : [] );

		return Coverage_Status_Block::render_block( $block->attributes, '', $block );
	}

	/**
	 * With no choice made, it follows the first feed on the page.
	 */
	public function test_follows_the_first_feed() {
		$first   = self::create_coverage();
		$second  = self::create_coverage();
		$page_id = self::page( self::feed( $first ) . self::feed( $second ) );

		$this->assertStringContainsString( 'data-coverage-id="' . $first . '"', self::render( [], $page_id ) );
	}

	/**
	 * A chosen feed wins; a chosen feed no longer on the page falls back to
	 * the first.
	 */
	public function test_follows_the_chosen_feed_while_it_is_on_the_page() {
		$first   = self::create_coverage();
		$second  = self::create_coverage();
		$gone    = self::create_coverage();
		$page_id = self::page( self::feed( $first ) . self::feed( $second ) );

		$this->assertStringContainsString( 'data-coverage-id="' . $second . '"', self::render( [ 'coverageId' => $second ], $page_id ) );
		$this->assertStringContainsString( 'data-coverage-id="' . $first . '"', self::render( [ 'coverageId' => $gone ], $page_id ) );
	}

	/**
	 * Feeds nested in groups and synced patterns count; a feed with no
	 * coverage chosen yet does not.
	 */
	public function test_finds_nested_feeds_and_skips_unset_ones() {
		$coverage_id = self::create_coverage();
		$pattern_id  = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:group --><div class="wp-block-group">' . self::feed( $coverage_id ) . '</div><!-- /wp:group -->',
			]
		);
		$page_id     = self::page( self::feed( 0 ) . '<!-- wp:block {"ref":' . $pattern_id . '} /-->' );

		$this->assertStringContainsString( 'data-coverage-id="' . $coverage_id . '"', self::render( [], $page_id ) );
	}

	/**
	 * A synced pattern that includes itself is read once.
	 */
	public function test_survives_a_synced_pattern_that_includes_itself() {
		$pattern_id = self::factory()->post->create( [ 'post_type' => 'wp_block' ] );
		wp_update_post(
			[
				'ID'           => $pattern_id,
				'post_content' => '<!-- wp:block {"ref":' . $pattern_id . '} /-->',
			]
		);
		$page_id = self::page( '<!-- wp:block {"ref":' . $pattern_id . '} /-->' );

		$this->assertSame( '', self::render( [], $page_id ) );
	}

	/**
	 * No feed, or only feeds of missing or trashed coverages: nothing.
	 */
	public function test_renders_nothing_without_a_feed() {
		$trashed = self::create_coverage( 'trash' );
		$live    = self::create_coverage();

		$this->assertSame( '', self::render( [], self::page( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ) ) );
		$this->assertSame( '', self::render( [], self::page( self::feed( $trashed ) . self::feed( 999999 ) ) ) );
		$this->assertStringContainsString( 'data-coverage-id="' . $live . '"', self::render( [], self::page( self::feed( $trashed ) . self::feed( $live ) ) ), 'A trashed coverage is skipped.' );
	}

	/**
	 * Outside post content, as in a header template part, it follows the
	 * queried page, and renders nothing on views that aren't a single page.
	 */
	public function test_template_part_follows_the_queried_page_only() {
		$coverage_id = self::create_coverage();
		$page_id     = self::page( self::feed( $coverage_id ) );

		$this->go_to( get_permalink( $page_id ) );
		$this->assertStringContainsString( 'data-coverage-id="' . $coverage_id . '"', self::render() );

		self::factory()->post->create( [ 'post_content' => self::feed( $coverage_id ) ] );
		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', self::render(), 'A list of posts is not a coverage page.' );
	}

	/**
	 * Each status has its badge; a status the plugin doesn't know is live.
	 */
	public function test_badge_follows_the_status() {
		$coverage_id = self::create_coverage();
		$page_id     = self::page( self::feed( $coverage_id ) );

		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--success newspack-ui__badge--dot newspack-ui__badge--pulse">Live</span>', self::render( [], $page_id ) );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_PAUSED );
		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--secondary">Paused</span>', self::render( [], $page_id ) );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, Taxonomy::STATUS_ARCHIVED );
		$html = self::render( [], $page_id );
		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--error">Ended</span>', $html );
		$this->assertStringContainsString( 'data-status="archived"', $html );

		update_term_meta( $coverage_id, Taxonomy::STATUS_META_KEY, 'unknown' );
		$this->assertStringContainsString( 'data-status="active"', self::render( [], $page_id ) );
	}

	/**
	 * The block's label wins, escaped; a blank or non-text one falls back to
	 * the site's, then the built-in one. Every status's label rides on the
	 * wrapper for the view script.
	 */
	public function test_labels() {
		$coverage_id = self::create_coverage();
		$page_id     = self::page( self::feed( $coverage_id ) );
		update_option( Status_Labels::OPTION_KEY, [ 'paused' => 'On hold' ] );

		$html = self::render(
			[
				'labels' => [
					'active'   => 'On <b>air</b>',
					'paused'   => ' ',
					'archived' => [ 'Over' ],
				],
			],
			$page_id
		);

		$this->assertStringContainsString( '>On &lt;b&gt;air&lt;/b&gt;</span>', $html );
		$this->assertStringContainsString( 'data-label-active="On &lt;b&gt;air&lt;/b&gt;"', $html );
		$this->assertStringContainsString( 'data-label-paused="On hold"', $html );
		$this->assertStringContainsString( 'data-label-archived="Ended"', $html );
	}

	/**
	 * "Updated" is off by default.
	 */
	public function test_last_updated_is_off_by_default() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );

		$this->assertStringNotContainsString( 'newspack-rolling-coverage-updated', self::render( [], self::page( self::feed( $coverage_id ) ) ) );
	}

	/**
	 * While live, it shows when the newest entry went out.
	 */
	public function test_last_updated_shows_the_newest_entry_while_live() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id, [ 'post_date' => '2026-01-01 12:00:00' ] );

		$html = self::render( [ 'showLastUpdated' => true ], self::page( self::feed( $coverage_id ) ) );

		$this->assertMatchesRegularExpression( '#<span class="newspack-rolling-coverage-updated">Updated <time datetime="2026-01-01T12:00:00\+00:00" data-rc-relative>[^<]+ ago</time></span>#', $html );
	}

	/**
	 * Not live, or no entries yet: the text is there for the view script but
	 * hidden.
	 */
	public function test_last_updated_is_hidden_when_not_live_or_empty() {
		$empty_id = self::create_coverage();
		$ended_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		self::create_entry( $ended_id, [ 'post_date' => '2026-01-01 12:00:00' ] );

		$this->assertStringContainsString( '<span class="newspack-rolling-coverage-updated" hidden>Updated <time datetime="" data-rc-relative></time></span>', self::render( [ 'showLastUpdated' => true ], self::page( self::feed( $empty_id ) ) ) );
		$this->assertStringContainsString( '<span class="newspack-rolling-coverage-updated" hidden>', self::render( [ 'showLastUpdated' => true ], self::page( self::feed( $ended_id ) ) ) );
	}
}
