<?php
/**
 * Tests for breaking an entry out into a standalone post.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout;
use Newspack_Rolling_Coverage\Post_Type;
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
	 * A breakout post's title, built from an untitled entry's content, leaves
	 * out what Newspack hides from the public: the post's title shows
	 * wherever it's listed.
	 */
	public function test_breakout_title_leaves_out_members_only_text() {
		$this->use_block_visibility_stub();
		self::log_in_as( 'editor' );
		$entry_id = self::create_entry(
			self::create_coverage(),
			[
				'post_title'   => '',
				'post_content' => self::members_only_paragraph( 'Members hear the result first.' ) . '<!-- wp:paragraph --><p>Doors open at 7pm.</p><!-- /wp:paragraph -->',
			]
		);

		$breakout_post = get_post( self::break_out( $entry_id )->get_data()['breakoutPostId'] );

		$this->assertSame( 'Doors open at 7pm.', $breakout_post->post_title );
	}

	/**
	 * A title built from content keeps words apart across line breaks, and
	 * keeps backslashes in the copied content.
	 */
	public function test_breakout_title_and_content_read_as_written() {
		self::log_in_as( 'editor' );
		$content  = '<p>Counting starts<br>at 9pm \\o/</p>';
		$entry_id = self::create_entry(
			self::create_coverage(),
			[
				'post_title'   => '',
				'post_content' => wp_slash( $content ),
			]
		);

		$breakout_post = get_post( self::break_out( $entry_id )->get_data()['breakoutPostId'] );

		$this->assertSame( 'Counting starts at 9pm \\o/', $breakout_post->post_title );
		$this->assertSame( $content, $breakout_post->post_content );
	}

	/**
	 * Markup typed as text in an untitled entry stays text in the breakout
	 * title, even for editors who may save unfiltered HTML.
	 */
	public function test_breakout_title_never_carries_markup_from_entry_text() {
		self::log_in_as( 'editor' );
		$entry_id = self::create_entry(
			self::create_coverage(),
			[
				'post_title'   => '',
				'post_content' => '<p>Results &lt;img src=x onerror=alert(1)&gt; won&#039;t wait</p>',
			]
		);

		$breakout_post = get_post( self::break_out( $entry_id )->get_data()['breakoutPostId'] );

		$this->assertStringNotContainsString( '<img', $breakout_post->post_title );
		$this->assertSame( "Results &lt;img src=x onerror=alert(1)&gt; won't wait", $breakout_post->post_title, 'Only &, < and > should be encoded, as the editor stores titles.' );
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
	 * Unpublishing or deleting a published breakout touches the entry again,
	 * so live readers lose the links to it.
	 */
	public function test_unpublishing_or_deleting_the_breakout_post_touches_the_entry() {
		self::log_in_as( 'editor' );
		$entry_id    = self::create_entry( self::create_coverage() );
		$breakout_id = self::break_out( $entry_id )->get_data()['breakoutPostId'];
		wp_publish_post( $breakout_id );

		self::backdate_modified( $entry_id );
		wp_update_post(
			[
				'ID'          => $breakout_id,
				'post_status' => 'draft',
			]
		);

		$this->assertGreaterThan( '2026-01-01 12:00:00', get_post( $entry_id )->post_modified_gmt, 'Unpublishing should mark the entry as modified.' );

		wp_publish_post( $breakout_id );
		self::backdate_modified( $entry_id );
		wp_delete_post( $breakout_id, true );

		$this->assertGreaterThan( '2026-01-01 12:00:00', get_post( $entry_id )->post_modified_gmt, 'Deleting should mark the entry as modified.' );
	}

	/**
	 * Touching the entry isn't an edit: an author without unfiltered HTML who
	 * trashes their published breakout leaves the entry's embed in place.
	 */
	public function test_touching_the_entry_keeps_its_stored_content() {
		$content  = '<!-- wp:html --><iframe src="https://example.com/embed"></iframe><!-- /wp:html -->';
		$entry_id = self::create_entry( self::create_coverage() );
		$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, [ 'post_content' => $content ], [ 'ID' => $entry_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $entry_id );
		$breakout_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META, $breakout_id );
		update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $entry_id );
		self::log_in_as( 'author' );
		kses_init();

		wp_trash_post( $breakout_id );

		$this->assertSame( $content, get_post( $entry_id )->post_content );
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

	/**
	 * The editor can read which entry a post was broken out from, to show
	 * that entry's coverage while readers can see the entry, but the link
	 * can't be rewritten over REST.
	 */
	public function test_source_entry_is_readable_but_not_writable_over_rest() {
		self::log_in_as( 'editor' );
		$entry_id    = self::create_entry( self::create_coverage() );
		$breakout_id = self::factory()->post->create();
		update_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, $entry_id );

		$read = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $breakout_id );
		$read->set_param( 'context', 'edit' );
		$this->assertSame( $entry_id, rest_get_server()->dispatch( $read )->get_data()[ Breakout::BREAKOUT_SOURCE_ENTRY_FIELD ] ?? null );

		$write = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $breakout_id );
		$write->set_body_params( [ Breakout::BREAKOUT_SOURCE_ENTRY_FIELD => self::create_entry() ] );
		rest_get_server()->dispatch( $write );
		$this->assertSame( $entry_id, (int) get_post_meta( $breakout_id, Breakout::BREAKOUT_SOURCE_ENTRY_META, true ) );

		wp_trash_post( $entry_id );
		$this->assertSame( 0, rest_get_server()->dispatch( $read )->get_data()[ Breakout::BREAKOUT_SOURCE_ENTRY_FIELD ], 'A trashed entry gives the post no coverage, as on the site.' );
	}

	/**
	 * The block editor sends a post's whole meta object back once any of it
	 * is edited; that must not stop an editor saving a post that was never
	 * broken out.
	 */
	public function test_editor_can_save_a_post_with_what_it_read_echoed_back() {
		self::log_in_as( 'editor' );
		$post_id = self::factory()->post->create();

		$read = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$read->set_param( 'context', 'edit' );
		$data = rest_get_server()->dispatch( $read )->get_data();

		$save = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$save->set_body_params(
			[
				'title'                               => 'Recount ordered',
				'meta'                                => $data['meta'],
				Breakout::BREAKOUT_SOURCE_ENTRY_FIELD => $data[ Breakout::BREAKOUT_SOURCE_ENTRY_FIELD ] ?? 0,
			]
		);

		$this->assertSame( 200, rest_get_server()->dispatch( $save )->get_status() );
	}

	/**
	 * The same holds for an entry: the editor sends its whole meta object
	 * back, and an entry that was never broken out must still save.
	 */
	public function test_editor_can_save_an_entry_with_what_it_read_echoed_back() {
		self::log_in_as( 'editor' );
		$entry_id = self::create_entry( self::create_coverage() );
		$path     = '/wp/v2/' . Post_Type::REST_BASE . '/' . $entry_id;

		$read = new WP_REST_Request( 'GET', $path );
		$read->set_param( 'context', 'edit' );
		$data = rest_get_server()->dispatch( $read )->get_data();

		$save = new WP_REST_Request( 'POST', $path );
		$save->set_body_params(
			[
				'title' => 'Recount ordered',
				'meta'  => $data['meta'],
			]
		);

		$this->assertSame( 200, rest_get_server()->dispatch( $save )->get_status() );
	}

	/**
	 * The breakout links and cached status drive writes to other posts, so
	 * they can't be edited as custom fields, even by an administrator.
	 */
	public function test_breakout_meta_cannot_be_edited_as_custom_fields() {
		self::log_in_as( 'administrator' );
		$entry_id = self::create_entry( self::create_coverage() );
		$post_id  = self::factory()->post->create();

		$this->assertFalse( current_user_can( 'edit_post_meta', $entry_id, Breakout::ENTRY_BREAKOUT_POST_ID_META ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $entry_id, Breakout::BREAKOUT_STATUS_FIELD ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $post_id, Breakout::BREAKOUT_SOURCE_ENTRY_META ) );
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
