<?php
/**
 * Tests for the byline the Byline layout shows.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Post_Type;
use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Slack_Config;

/**
 * Entries show their author's avatar and name, except while the Slack bot
 * is the author, and the avatar column goes when the site hides avatars.
 */
class Test_Byline extends Rolling_Coverage_TestCase {

	const BYLINE_MARKUP = '<!-- wp:avatar /--><!-- wp:post-author-name /-->';

	const ROW_MARKUP = '<!-- wp:columns {"isStackedOnMobile":false} --><div class="wp-block-columns"><!-- wp:column {"width":"40px"} --><div class="wp-block-column"><!-- wp:avatar {"size":40} /--></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:post-author-name /--><!-- wp:post-date /--></div><!-- /wp:column --></div><!-- /wp:columns -->';

	/**
	 * Render an entry by the given author through the given template markup.
	 *
	 * @param int    $author_id Author user ID.
	 * @param string $markup    Template markup.
	 * @return string Rendered entry.
	 */
	private static function render( int $author_id, string $markup ): string {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_author' => $author_id ] );

		return self::render_existing( $entry_id, $markup );
	}

	/**
	 * Render an existing entry through the given template markup.
	 *
	 * @param int    $entry_id Entry post ID.
	 * @param string $markup   Template markup.
	 * @return string Rendered entry.
	 */
	private static function render_existing( int $entry_id, string $markup ): string {
		return Rolling_Coverage_Block::render_entry( get_post( $entry_id ), parse_blocks( $markup ) );
	}

	/**
	 * How many columns a rendered row has.
	 *
	 * @param string $html Rendered HTML.
	 * @return int
	 */
	private static function count_columns( string $html ): int {
		return preg_match_all( '/class="wp-block-column[ "]/', $html );
	}

	/**
	 * The Slack bot's entries carry no byline.
	 */
	public function test_a_slack_bot_entry_shows_no_avatar_or_name() {
		$html = self::render( Slack_Config::get_or_create_bot_user_id(), self::BYLINE_MARKUP );

		$this->assertStringNotContainsString( 'wp-block-avatar', $html );
		$this->assertStringNotContainsString( 'wp-block-post-author-name', $html );
	}

	/**
	 * The rule follows the author, not the entry's origin.
	 */
	public function test_a_slack_entry_reassigned_to_a_reporter_shows_the_reporter() {
		$entry_id = self::create_entry( self::create_coverage(), [ 'post_author' => Slack_Config::get_or_create_bot_user_id() ] );
		update_post_meta( $entry_id, Post_Type::META_ENTRY_SOURCE, 'slack' );
		update_post_meta( $entry_id, Post_Type::META_SLACK_TS, '1700000000.000100' );
		$author_id = self::factory()->user->create( [ 'display_name' => 'Jane Reporter' ] );

		wp_update_post(
			[
				'ID'          => $entry_id,
				'post_author' => $author_id,
			]
		);

		$html = self::render_existing( $entry_id, self::BYLINE_MARKUP );

		$this->assertStringContainsString( 'Jane Reporter', $html );
		$this->assertStringContainsString( 'wp-block-avatar', $html );
	}

	/**
	 * A disconnect clears the stored bot ID, but the bot's entries stay hidden.
	 */
	public function test_a_slack_bot_entry_stays_hidden_after_a_disconnect() {
		$bot_id = Slack_Config::get_or_create_bot_user_id();
		delete_option( Slack_Config::OPTION_BOT_USER_ID );

		$html = self::render( $bot_id, self::BYLINE_MARKUP );

		$this->assertStringNotContainsString( 'wp-block-avatar', $html );
		$this->assertStringNotContainsString( 'wp-block-post-author-name', $html );
		$this->assertFalse( get_option( Slack_Config::OPTION_BOT_USER_ID ), 'Reading must not store the ID again.' );
	}

	/**
	 * Hiding the bot's avatar leaves its column in place.
	 */
	public function test_a_slack_bot_entry_keeps_its_avatar_column() {
		$html = self::render( Slack_Config::get_or_create_bot_user_id(), self::ROW_MARKUP );

		$this->assertSame( 2, self::count_columns( $html ), 'The empty column keeps the text aligned with other entries.' );
	}

	/**
	 * Turning avatars off removes the column that holds only the avatar.
	 */
	public function test_the_avatar_column_goes_when_avatars_are_off() {
		$author_id = self::factory()->user->create( [ 'display_name' => 'Jane Reporter' ] );

		$this->assertSame( 2, self::count_columns( self::render( $author_id, self::ROW_MARKUP ) ) );

		update_option( 'show_avatars', 0 );
		$html = self::render( $author_id, self::ROW_MARKUP );

		$this->assertSame( 1, self::count_columns( $html ) );
		$this->assertStringContainsString( 'Jane Reporter', $html );
	}

	/**
	 * Both rules apply together.
	 */
	public function test_a_slack_bot_entry_with_avatars_off_shows_only_the_rest() {
		update_option( 'show_avatars', 0 );

		$html = self::render( Slack_Config::get_or_create_bot_user_id(), self::ROW_MARKUP );

		$this->assertSame( 1, self::count_columns( $html ) );
		$this->assertStringNotContainsString( 'wp-block-post-author-name', $html );
		$this->assertStringContainsString( 'wp-block-post-date', $html );
	}

	/**
	 * The rule only applies inside Rolling Coverage entries.
	 */
	public function test_bot_authored_posts_outside_a_feed_keep_their_byline() {
		$bot_id  = Slack_Config::get_or_create_bot_user_id();
		$post_id = self::factory()->post->create( [ 'post_author' => $bot_id ] );

		$block = new WP_Block(
			parse_blocks( '<!-- wp:post-author-name /-->' )[0],
			[
				'postId'   => $post_id,
				'postType' => 'post',
			]
		);

		$this->assertStringContainsString( 'wp-block-post-author-name', $block->render() );
	}

	/**
	 * The block's own size is written onto the image, over theme avatar sizing.
	 */
	public function test_an_entry_avatar_is_sized_from_the_block_setting() {
		$author_id = self::factory()->user->create();

		$html = self::render( $author_id, '<!-- wp:avatar {"size":40,"style":{"border":{"radius":"50%"}}} /-->' );

		$this->assertMatchesRegularExpression( '/<img [^>]*style="[^"]*border-radius:50%;[^"]*width:40px;height:40px;/', $html );
	}

	/**
	 * A missing or zero size falls back to core's default rather than collapsing the image.
	 *
	 * @dataProvider unset_size_provider
	 *
	 * @param string $markup Avatar block markup.
	 */
	public function test_an_entry_avatar_without_a_usable_size_gets_the_core_default( $markup ) {
		$html = self::render( self::factory()->user->create(), $markup );

		$this->assertStringContainsString( 'width:96px;height:96px;', $html );
	}

	/**
	 * Avatar markup with no usable size.
	 *
	 * @return array[]
	 */
	public function unset_size_provider() {
		return [
			'no size'   => [ '<!-- wp:avatar /-->' ],
			'zero size' => [ '<!-- wp:avatar {"size":0} /-->' ],
		];
	}

	/**
	 * With avatars off, the empty avatar wrapper is dropped, leaving the name.
	 */
	public function test_an_entry_drops_the_empty_avatar_when_avatars_are_off() {
		update_option( 'show_avatars', 0 );

		$html = self::render( self::factory()->user->create( [ 'display_name' => 'Jane Reporter' ] ), self::BYLINE_MARKUP );

		$this->assertStringNotContainsString( 'wp-block-avatar', $html );
		$this->assertStringContainsString( 'Jane Reporter', $html );
	}

	/**
	 * The editor preview knows which entries the Slack bot wrote, so it hides
	 * their byline as the site does.
	 */
	public function test_the_editor_preview_flags_bot_authored_entries() {
		$coverage_id = self::create_coverage();
		$bot_entry   = self::create_entry( $coverage_id, [ 'post_author' => Slack_Config::get_or_create_bot_user_id() ] );
		$reporter    = self::create_entry( $coverage_id, [ 'post_author' => self::factory()->user->create() ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . '/coverages/' . $coverage_id . '/entries-preview' ) );
		$flags    = wp_list_pluck( $response->get_data(), 'hidesByline', 'id' );

		$this->assertTrue( $flags[ $bot_entry ], "The bot's entry should be flagged." );
		$this->assertFalse( $flags[ $reporter ], "A reporter's entry should not be." );
	}

	/**
	 * Avatars outside an entry are left as core renders them.
	 */
	public function test_an_avatar_outside_a_feed_gets_no_inline_size() {
		$post_id = self::factory()->post->create( [ 'post_author' => self::factory()->user->create() ] );

		$block = new WP_Block(
			parse_blocks( '<!-- wp:avatar {"size":40} /-->' )[0],
			[
				'postId'   => $post_id,
				'postType' => 'post',
			]
		);

		$html = $block->render();

		$this->assertStringContainsString( 'wp-block-avatar', $html );
		$this->assertStringNotContainsString( 'width:40px', $html );
	}
}
