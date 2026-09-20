<?php
/**
 * Tests for breaking an entry out into a standalone post.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * An entry and its breakout post point at each other through meta. These
 * tests cover creating the pair and keeping the entry's side of the link
 * truthful as the post is published or deleted.
 */
class Test_Breakout extends Rolling_Coverage_TestCase {

	/**
	 * Break an entry out through the REST route.
	 *
	 * @param int $entry_id Entry post ID.
	 * @return WP_REST_Response
	 */
	private static function break_out( $entry_id ) {
		return self::dispatch( 'POST', "/entries/{$entry_id}/breakout" );
	}

	/**
	 * The breakout is a draft copy of the entry owned by whoever created it,
	 * and the two are linked in both directions.
	 */
	public function test_breakout_is_a_draft_copy_owned_by_the_current_user() {
		$editor_id   = self::log_in_as( 'editor' );
		$category_id = self::factory()->category->create();
		$entry_id    = self::create_entry(
			self::create_coverage(),
			[
				'post_title'   => 'Recount ordered',
				'post_content' => '<!-- wp:paragraph --><p>The county has ordered a recount.</p><!-- /wp:paragraph -->',
				'post_author'  => self::factory()->user->create( [ 'role' => 'author' ] ),
			]
		);
		wp_set_post_categories( $entry_id, [ $category_id ] );
		wp_set_post_tags( $entry_id, [ 'recount' ] );

		$response      = self::break_out( $entry_id );
		$breakout_post = get_post( $response->get_data()['breakoutPostId'] );

		$this->assertSame( 201, $response->get_status(), 'The route should report a created resource.' );
		$this->assertSame( 'post', $breakout_post->post_type, 'The breakout should be a regular post.' );
		$this->assertSame( 'draft', $breakout_post->post_status, 'The breakout should start as a draft.' );
		$this->assertSame( 'Recount ordered', $breakout_post->post_title, 'The title should be copied.' );
		$this->assertSame( get_post( $entry_id )->post_content, $breakout_post->post_content, 'The content should be copied.' );
		$this->assertSame( $editor_id, (int) $breakout_post->post_author, 'The user who broke it out should own the post.' );
		$this->assertSame( [ $category_id ], wp_get_post_categories( $breakout_post->ID ), 'Categories should be copied.' );
		$this->assertSame( [ 'recount' ], wp_get_post_tags( $breakout_post->ID, [ 'fields' => 'names' ] ), 'Tags should be copied.' );
		$this->assertSame( $breakout_post->ID, Breakout::get_existing_breakout_id( $entry_id ), 'The entry should link to the post.' );
		$this->assertSame( $entry_id, (int) get_post_meta( $breakout_post->ID, Breakout::BREAKOUT_SOURCE_ENTRY_META, true ), 'The post should link back to the entry.' );
	}

	/**
	 * Slack entries often have no title; the breakout gets one from the
	 * opening words of the content.
	 */
	public function test_breakout_of_an_untitled_entry_is_titled_from_its_content() {
		self::log_in_as( 'editor' );
		$entry_id = self::create_entry(
			self::create_coverage(),
			[
				'post_title'   => '',
				'post_content' => '<p>One two three four five six seven eight nine ten eleven twelve.</p>',
			]
		);

		$breakout_post = get_post( self::break_out( $entry_id )->get_data()['breakoutPostId'] );

		$this->assertSame( 'One two three four five six seven eight nine ten…', $breakout_post->post_title );
	}

	/**
	 * An entry has at most one breakout post.
	 */
	public function test_entry_cannot_be_broken_out_twice() {
		self::log_in_as( 'editor' );
		$entry_id          = self::create_entry( self::create_coverage() );
		$first_breakout_id = self::break_out( $entry_id )->get_data()['breakoutPostId'];

		$second_response = self::break_out( $entry_id );

		$this->assertSame( 400, $second_response->get_status(), 'The second request should be refused.' );
		$this->assertSame( $first_breakout_id, Breakout::get_existing_breakout_id( $entry_id ), 'The entry should still link to the first post.' );
	}

	/**
	 * Deleting the breakout post for good frees the entry to be broken out
	 * again.
	 */
	public function test_deleting_the_breakout_post_frees_the_entry() {
		self::log_in_as( 'editor' );
		$entry_id    = self::create_entry( self::create_coverage() );
		$breakout_id = self::break_out( $entry_id )->get_data()['breakoutPostId'];

		wp_delete_post( $breakout_id, true );

		$this->assertFalse( metadata_exists( 'post', $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META ), 'The link should be cleared.' );
		$this->assertFalse( metadata_exists( 'post', $entry_id, Breakout::BREAKOUT_STATUS_FIELD ), 'The cached status should be cleared.' );
		$this->assertSame( 201, self::break_out( $entry_id )->get_status(), 'A new breakout should be possible.' );
	}

	/**
	 * If the post disappeared without the delete hook running, the stale
	 * link is dropped the next time it is read instead of blocking the entry.
	 */
	public function test_link_to_a_post_that_no_longer_exists_heals_itself() {
		$entry_id = self::create_entry( self::create_coverage() );
		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, 999999 );
		update_post_meta( $entry_id, Breakout::BREAKOUT_STATUS_FIELD, 'draft' );

		$this->assertSame( 0, Breakout::get_existing_breakout_id( $entry_id ), 'A dangling link should read as no breakout.' );
		$this->assertFalse( metadata_exists( 'post', $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META ), 'The dangling link should be removed.' );
		$this->assertFalse( metadata_exists( 'post', $entry_id, Breakout::BREAKOUT_STATUS_FIELD ), 'The cached status should be removed with it.' );
	}

	/**
	 * Publishing the breakout post updates the status cached on the entry and
	 * touches the entry, so live readers get it again with the link showing.
	 */
	public function test_publishing_the_breakout_post_updates_and_touches_the_entry() {
		self::log_in_as( 'editor' );
		$entry_id    = self::create_entry( self::create_coverage(), [ 'post_date' => '2026-01-01 12:00:00' ] );
		$breakout_id = self::break_out( $entry_id )->get_data()['breakoutPostId'];

		wp_publish_post( $breakout_id );

		$this->assertSame( 'publish', get_post_meta( $entry_id, Breakout::BREAKOUT_STATUS_FIELD, true ), 'The cached status should follow the post.' );
		$this->assertGreaterThan( '2026-01-01 12:00:00', get_post( $entry_id )->post_modified_gmt, 'The entry should be marked as modified.' );
	}

	/**
	 * A breakout would spin new work off a frozen record.
	 */
	public function test_locked_entry_cannot_be_broken_out() {
		self::log_in_as( 'editor' );
		$locked_entry_id = self::create_entry( self::create_coverage( Taxonomy::STATUS_ARCHIVED ) );

		$response = self::break_out( $locked_entry_id );

		$this->assertSame( 403, $response->get_status(), 'The request should be refused.' );
		$this->assertSame( 0, Breakout::get_existing_breakout_id( $locked_entry_id ), 'No post should be created.' );
	}
}
