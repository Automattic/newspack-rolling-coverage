<?php
/**
 * Tests for the Scoreboard block's server rendering and score bindings.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Coverage_Status_Block;
use Newspack_Rolling_Coverage\Entry_Bindings;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Score;
use Newspack_Rolling_Coverage\Scoreboard_Block;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * The block shows the score of the coverage it resolves, through inner
 * blocks bound to the score, and nothing without one.
 */
class Test_Scoreboard_Block extends Rolling_Coverage_TestCase {

	/**
	 * Whether this test registered the Rolling Coverage block itself.
	 *
	 * @var bool
	 */
	private $registered_feed = false;

	/**
	 * Register the block from its metadata when the build isn't there, and
	 * the Rolling Coverage block, so the feeds the tests use render.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Rolling_Coverage_Block::BLOCK_NAME ) ) {
			register_block_type( Rolling_Coverage_Block::BLOCK_NAME, Rolling_Coverage_Block::block_type_args() );
			$this->registered_feed = true;
		}

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( Scoreboard_Block::BLOCK_NAME ) ) {
			$metadata = wp_json_file_decode( NEWSPACK_ROLLING_COVERAGE_PLUGIN_DIR . 'src/blocks/scoreboard/block.json', [ 'associative' => true ] );

			register_block_type(
				Scoreboard_Block::BLOCK_NAME,
				[
					'attributes'      => $metadata['attributes'],
					'supports'        => $metadata['supports'],
					'uses_context'    => $metadata['usesContext'],
					'render_callback' => [ Scoreboard_Block::class, 'render_block' ],
				]
			);
		}
	}

	/**
	 * Forget the Rolling Coverage block if the test registered it.
	 */
	public function tear_down() {
		if ( $this->registered_feed ) {
			unregister_block_type( Rolling_Coverage_Block::BLOCK_NAME );
			$this->registered_feed = false;
		}

		parent::tear_down();
	}

	/**
	 * Give a coverage a score.
	 *
	 * @param int    $coverage_id Coverage term ID.
	 * @param string $status      Status line.
	 * @param string $badge       The status line's badge style.
	 */
	private static function set_score( int $coverage_id, string $status = 'Second half', string $badge = Taxonomy::STATUS_ACTIVE ): void {
		update_term_meta(
			$coverage_id,
			Score::META_KEY,
			[
				'teams'  => [
					[
						'name'  => 'Riverbend FC',
						'score' => 2,
					],
					[
						'name'  => 'Toledo Beacon U<23 & Co',
						'score' => 1,
					],
				],
				'status' => $status,
				'badge'  => $badge,
			]
		);
	}

	/**
	 * A paragraph bound to one part of the score.
	 *
	 * @param string $key The part.
	 * @return string
	 */
	private static function part( string $key ): string {
		$attributes = [
			'metadata' => [
				'bindings' => [
					'content' => [
						'source' => Scoreboard_Block::SOURCE_NAME,
						'args'   => [ 'key' => $key ],
					],
				],
			],
		];

		return '<!-- wp:paragraph ' . wp_json_encode( $attributes ) . ' --><p></p><!-- /wp:paragraph -->';
	}

	/**
	 * The dash between the scores, as the editor inserts it.
	 *
	 * @return string
	 */
	private static function dash(): string {
		return '<!-- wp:paragraph {"className":"' . Scoreboard_Block::DASH_CLASS . '"} --><p class="' . Scoreboard_Block::DASH_CLASS . '">–</p><!-- /wp:paragraph -->';
	}

	/**
	 * The block's markup, its parts in a group as the editor inserts them.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	private static function scoreboard( array $attributes = [] ): string {
		return '<!-- wp:newspack-rolling-coverage/scoreboard ' . wp_json_encode( (object) $attributes ) . ' -->'
			. '<!-- wp:group --><div class="wp-block-group">' . self::part( 'team1Name' ) . self::part( 'team1Score' ) . self::dash() . self::part( 'team2Score' ) . self::part( 'team2Name' ) . '</div><!-- /wp:group -->'
			. self::part( 'status' )
			. '<!-- /wp:newspack-rolling-coverage/scoreboard -->';
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
	 * The text of each bound part, by key.
	 *
	 * @param string $html Rendered block.
	 * @return array<string, string>
	 */
	private static function parts( string $html ): array {
		preg_match_all( '/<p [^>]*data-rc-score="([a-zA-Z0-9]+)"[^>]*>([^<]*)<\/p>/', $html, $matches );

		return array_combine( $matches[1], $matches[2] );
	}

	/**
	 * A chosen coverage's score fills the parts, escaped, in order.
	 */
	public function test_renders_the_chosen_coverage_score() {
		$coverage_id = self::create_coverage();
		self::set_score( $coverage_id );

		$html = do_blocks( self::scoreboard( [ 'coverageId' => $coverage_id ] ) );

		$this->assertSame(
			[
				'team1Name'  => 'Riverbend FC',
				'team1Score' => '2',
				'team2Score' => '1',
				'team2Name'  => 'Toledo Beacon U&lt;23 &amp; Co',
				'status'     => 'Second half',
			],
			self::parts( $html )
		);
		$this->assertMatchesRegularExpression( '/<div [^>]*data-coverage-id="' . $coverage_id . '"/', $html );
		$this->assertStringContainsString( 'data-status="active"', $html );
		$this->assertStringContainsString( 'data-score-url="' . esc_url( rest_url( 'rolling-coverage/v1/coverages/' . $coverage_id . '/score' ) ) . '"', $html );
		$this->assertStringContainsString( '<div class="screen-reader-text" aria-live="polite" aria-atomic="true" data-rc-score-announce></div>', $html );
	}

	/**
	 * The dash between the scores stays on screen and out of what screen
	 * readers read, which is the names and scores alone.
	 */
	public function test_hides_the_dash_from_screen_readers() {
		$coverage_id = self::create_coverage();
		self::set_score( $coverage_id );

		$html = do_blocks( self::scoreboard( [ 'coverageId' => $coverage_id ] ) );

		$tags = new WP_HTML_Tag_Processor( $html );

		$this->assertTrue( $tags->next_tag( [ 'class_name' => Scoreboard_Block::DASH_CLASS ] ) );
		$this->assertSame( 'true', $tags->get_attribute( 'aria-hidden' ) );
		$this->assertMatchesRegularExpression( '/data-rc-score="team1Score"[^>]*>2<\/p><p [^>]*' . Scoreboard_Block::DASH_CLASS . '[^>]*>–<\/p><p [^>]*data-rc-score="team2Score"/', $html );
		$this->assertSame( [ 'team1Name', 'team1Score', 'team2Score', 'team2Name', 'status' ], array_keys( self::parts( $html ) ) );
	}

	/**
	 * Without a coverage the block renders nothing.
	 */
	public function test_renders_nothing_without_a_coverage() {
		$this->assertSame( '', trim( do_blocks( self::scoreboard() ) ) );
	}

	/**
	 * A coverage without a score yet renders the scoreboard hidden, ready
	 * for its script to show a score added later; an ended one renders
	 * nothing, as it won't check.
	 */
	public function test_renders_hidden_until_a_score_is_added() {
		$coverage_id = self::create_coverage();
		$ended_id    = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		$html        = do_blocks( self::scoreboard( [ 'coverageId' => $coverage_id ] ) );

		$this->assertMatchesRegularExpression( '/<div [^>]*data-coverage-id="' . $coverage_id . '"[^>]*data-score-url="[^"]+"[^>]* hidden>/', $html );
		$this->assertStringContainsString( 'data-rc-score-announce', $html );
		$this->assertSame( '', trim( do_blocks( self::scoreboard( [ 'coverageId' => $ended_id ] ) ) ) );
	}

	/**
	 * An empty status line renders hidden, for a poll to fill.
	 */
	public function test_hides_an_empty_status_line() {
		$coverage_id = self::create_coverage();
		self::set_score( $coverage_id, '' );

		$html = do_blocks( self::scoreboard( [ 'coverageId' => $coverage_id ] ) );

		$this->assertMatchesRegularExpression( '/<p [^>]*data-rc-score="status" hidden><\/p>/', $html );
		$this->assertDoesNotMatchRegularExpression( '/data-rc-score="team1Name" hidden/', $html );
	}

	/**
	 * With Show status as a badge, the status line is the Coverage Status
	 * badge of the score's badge style, and the wrapper tells the view
	 * script to keep it one.
	 */
	public function test_shows_the_status_line_as_a_badge() {
		$coverage_id = self::create_coverage();
		self::set_score( $coverage_id, 'Full time & done', Taxonomy::STATUS_ARCHIVED );

		$html = do_blocks(
			self::scoreboard(
				[
					'coverageId'        => $coverage_id,
					'showStatusAsBadge' => true,
				]
			)
		);

		$this->assertMatchesRegularExpression( '/<p [^>]*data-rc-score="status"[^>]*><span class="newspack-ui__badge newspack-ui__badge--error">Full time &amp; done<\/span><\/p>/', $html );
		$this->assertMatchesRegularExpression( '/<div [^>]*data-coverage-id="' . $coverage_id . '"[^>]*data-status-badge="true"/', $html );

		self::set_score( $coverage_id, 'Second half' );

		$this->assertStringContainsString(
			'<span class="newspack-ui__badge newspack-ui__badge--success newspack-ui__badge--dot newspack-ui__badge--pulse">Second half</span>',
			do_blocks(
				self::scoreboard(
					[
						'coverageId'        => $coverage_id,
						'showStatusAsBadge' => true,
					]
				)
			)
		);
	}

	/**
	 * With the dot hidden, the live badge drops its dot and pulse, as the
	 * Coverage Status block's does, and the wrapper tells the view script.
	 */
	public function test_badge_without_the_dot() {
		$coverage_id = self::create_coverage();
		self::set_score( $coverage_id );

		$html = do_blocks(
			self::scoreboard(
				[
					'coverageId'        => $coverage_id,
					'showStatusAsBadge' => true,
					'showDot'           => false,
				]
			)
		);

		$this->assertStringContainsString( '<span class="newspack-ui__badge newspack-ui__badge--success">Second half</span>', $html );
		$this->assertMatchesRegularExpression( '/<div [^>]*data-coverage-id="' . $coverage_id . '"[^>]*data-hide-dot="true"/', $html );
	}

	/**
	 * A custom background gives the badge the Coverage Status block's style
	 * for that color, and the wrapper carries each status's style for the
	 * view script to swap in.
	 */
	public function test_badge_with_a_custom_background() {
		$coverage_id = self::create_coverage();
		self::set_score( $coverage_id, 'Full time', Taxonomy::STATUS_ARCHIVED );

		$colors = [
			Taxonomy::STATUS_ARCHIVED => '#cc0000',
			Taxonomy::STATUS_PAUSED   => 'accent',
		];
		$styles = Coverage_Status_Block::badge_styles( [ 'backgroundColors' => $colors ] );
		$html   = do_blocks(
			self::scoreboard(
				[
					'coverageId'        => $coverage_id,
					'showStatusAsBadge' => true,
					'backgroundColors'  => $colors,
				]
			)
		);

		$this->assertStringContainsString( '--newspack-ui-badge-dot-color', $styles[ Taxonomy::STATUS_ARCHIVED ] );
		$tags = new WP_HTML_Tag_Processor( $html );

		$this->assertTrue( $tags->next_tag( [ 'class_name' => 'newspack-ui__badge' ] ) );
		$this->assertSame( 'newspack-ui__badge newspack-ui__badge--error', $tags->get_attribute( 'class' ) );
		$this->assertSame( $styles[ Taxonomy::STATUS_ARCHIVED ], $tags->get_attribute( 'style' ) );
		$this->assertStringContainsString( 'data-style-archived="' . esc_attr( $styles[ Taxonomy::STATUS_ARCHIVED ] ) . '"', $html );
		$this->assertStringContainsString( 'data-style-paused="' . esc_attr( $styles[ Taxonomy::STATUS_PAUSED ] ) . '"', $html );
		$this->assertStringNotContainsString( 'data-style-active', $html );
	}

	/**
	 * Without the option, the status line stays text whatever the badge
	 * style, as does an empty one with it.
	 */
	public function test_status_line_is_text_without_the_badge_option() {
		$coverage_id = self::create_coverage();
		self::set_score( $coverage_id, 'Full time', Taxonomy::STATUS_ARCHIVED );

		$html = do_blocks( self::scoreboard( [ 'coverageId' => $coverage_id ] ) );

		$this->assertSame( 'Full time', self::parts( $html )['status'] );
		$this->assertStringNotContainsString( 'newspack-ui__badge', $html );
		$this->assertStringNotContainsString( 'data-status-badge', $html );
		$this->assertStringNotContainsString( 'data-hide-dot', $html );

		self::set_score( $coverage_id, '' );

		$html = do_blocks(
			self::scoreboard(
				[
					'coverageId'        => $coverage_id,
					'showStatusAsBadge' => true,
				]
			)
		);

		$this->assertMatchesRegularExpression( '/<p [^>]*data-rc-score="status" hidden><\/p>/', $html );
	}

	/**
	 * Automatic shows the score of the page's feed.
	 */
	public function test_automatic_follows_the_page_feed() {
		$coverage_id = self::create_coverage();
		self::set_score( $coverage_id );

		$page_id = self::page( '<!-- wp:newspack-rolling-coverage/rolling-coverage {"coverageId":' . $coverage_id . '} /-->' );
		$this->go_to( get_permalink( $page_id ) );

		$this->assertSame( 'Riverbend FC', self::parts( do_blocks( self::scoreboard() ) )['team1Name'] );
	}

	/**
	 * Inside a feed, the feed's coverage wins over the block's own choice.
	 */
	public function test_feed_context_wins() {
		$feed_coverage = self::create_coverage();
		$chosen        = self::create_coverage();
		$add_context   = static fn( $context, $parsed_block ) => Scoreboard_Block::BLOCK_NAME === ( $parsed_block['blockName'] ?? '' ) ? array_merge( (array) $context, [ Entry_Bindings::COVERAGE_ID_CONTEXT => $feed_coverage ] ) : $context;
		self::set_score( $feed_coverage, 'Feed' );
		self::set_score( $chosen, 'Chosen' );

		add_filter( 'render_block_context', $add_context, 10, 2 );
		$html = do_blocks( self::scoreboard( [ 'coverageId' => $chosen ] ) );
		remove_filter( 'render_block_context', $add_context );

		$this->assertSame( 'Feed', self::parts( $html )['status'] );
		$this->assertStringContainsString( 'data-coverage-id="' . $feed_coverage . '"', $html );
	}

	/**
	 * Lite Site pages get the score as text, with no live region or poll URL.
	 */
	public function test_lite_page_gets_the_score_without_the_script_hooks() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_ARCHIVED );
		self::set_score( $coverage_id );

		require_once __DIR__ . '/mocks/class-lite-site.php';

		$html = \Newspack_Lite_Site\Lite_Site::clean_content( self::scoreboard( [ 'coverageId' => $coverage_id ] ) );

		$this->assertStringContainsString( '>Riverbend FC</p>', $html );
		$this->assertStringContainsString( '>2</p>', $html );
		$this->assertStringNotContainsString( 'aria-live', $html );
		$this->assertStringNotContainsString( 'data-score-url', $html );
	}

	/**
	 * In a feed's layout the block renders once, at the coverage level.
	 */
	public function test_is_a_coverage_item() {
		$this->assertTrue( Entry_Bindings::is_coverage_item( [ 'blockName' => Scoreboard_Block::BLOCK_NAME ] ) );
	}
}
