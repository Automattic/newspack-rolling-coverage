<?php
/**
 * Tests for the site-wide Full story label.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Breakout_Label;

/**
 * A site can set the label broken-out entries show with their full story;
 * an empty label falls back to "Full story".
 */
class Test_Breakout_Label extends Rolling_Coverage_TestCase {

	/**
	 * Act as an editor, who can manage the label.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Save the label through the REST route.
	 *
	 * @param array $params Request parameters.
	 * @return WP_REST_Response
	 */
	private static function save( array $params ) {
		$request = new WP_REST_Request( 'POST', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . Breakout_Label::REST_ROUTE );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Read the label through the REST route.
	 *
	 * @return WP_REST_Response
	 */
	private static function read() {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . Breakout_Label::REST_ROUTE ) );
	}

	/**
	 * Without a saved label cards read "Full story", and the
	 * route reports no label of the site's own.
	 */
	public function test_default_label() {
		$this->assertSame( 'Full story', Breakout_Label::get_default() );
		$this->assertSame( 'Full story', Breakout_Label::get() );
		$this->assertSame( '', Breakout_Label::get_saved() );

		$response = self::read();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'label' => '' ], $response->get_data() );
	}

	/**
	 * A saved label is trimmed, stripped of markup and capped in characters,
	 * not bytes, and becomes the label cards show.
	 */
	public function test_saving_the_label() {
		$response = self::save( [ 'label' => '  Written <b>up</b> ' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'label' => 'Written up' ], $response->get_data() );
		$this->assertSame( 'Written up', get_option( Breakout_Label::OPTION_KEY ) );
		$this->assertSame( 'Written up', Breakout_Label::get() );
		$this->assertSame( [ 'label' => 'Written up' ], self::read()->get_data() );

		$response = self::save( [ 'label' => str_repeat( 'é', Breakout_Label::MAX_LENGTH + 10 ) ] );

		$this->assertSame( str_repeat( 'é', Breakout_Label::MAX_LENGTH ), $response->get_data()['label'] );
	}

	/**
	 * A lone "<" or "&" is kept as typed, not stored as an entity that the
	 * settings screen would show literally.
	 */
	public function test_label_keeps_special_characters_as_typed() {
		$this->assertSame( 'Q < A & B', self::save( [ 'label' => 'Q < A & B' ] )->get_data()['label'] );
	}

	/**
	 * A label that isn't text is refused, leaving the stored label alone.
	 */
	public function test_label_that_is_not_text_is_refused() {
		self::save( [ 'label' => 'Written up' ] );

		$this->assertSame( 400, self::save( [ 'label' => [ 'x' ] ] )->get_status() );
		$this->assertSame( 'Written up', get_option( Breakout_Label::OPTION_KEY ) );
	}

	/**
	 * Clearing the label, or saving only spaces, deletes the option and goes
	 * back to the built-in label.
	 *
	 * @dataProvider empty_labels
	 *
	 * @param string $label The label saved.
	 */
	public function test_clearing_the_label_returns_to_the_default( string $label ) {
		self::save( [ 'label' => 'Written up' ] );

		$response = self::save( [ 'label' => $label ] );

		$this->assertSame( [ 'label' => '' ], $response->get_data() );
		$this->assertFalse( get_option( Breakout_Label::OPTION_KEY ), 'The option should be deleted.' );
		$this->assertSame( 'Full story', Breakout_Label::get() );
	}

	/**
	 * Labels that clear the site's own.
	 *
	 * @return array[]
	 */
	public static function empty_labels(): array {
		return [
			'empty'       => [ '' ],
			'only spaces' => [ '   ' ],
		];
	}

	/**
	 * A save without a label keeps the saved one.
	 */
	public function test_save_without_a_label_keeps_it() {
		self::save( [ 'label' => 'Written up' ] );

		$this->assertSame( [ 'label' => 'Written up' ], self::save( [] )->get_data() );
		$this->assertSame( 'Written up', get_option( Breakout_Label::OPTION_KEY ) );
	}

	/**
	 * Contributors can't read or change the label.
	 */
	public function test_contributors_cannot_manage_the_label() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$this->assertSame( 403, self::save( [ 'label' => 'Written up' ] )->get_status() );
		$this->assertSame( 403, self::read()->get_status() );
		$this->assertFalse( get_option( Breakout_Label::OPTION_KEY ) );
	}

	/**
	 * A stored value that isn't text, or is blank, is ignored.
	 *
	 * @dataProvider malformed_options
	 *
	 * @param mixed $value The stored value.
	 */
	public function test_malformed_option_falls_back_to_the_default( $value ) {
		update_option( Breakout_Label::OPTION_KEY, $value );

		$this->assertSame( '', Breakout_Label::get_saved() );
		$this->assertSame( 'Full story', Breakout_Label::get() );
	}

	/**
	 * Stored values that aren't a usable label.
	 *
	 * @return array[]
	 */
	public static function malformed_options(): array {
		return [
			'array' => [ [ 'Written up' ] ],
			'blank' => [ '  ' ],
		];
	}

	/**
	 * Save a post's own label through core's posts route.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $label   The label sent.
	 * @return WP_REST_Response
	 */
	private static function save_post_label( int $post_id, $label ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'meta', [ Breakout_Label::POST_META_KEY => $label ] );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A post's own label is registered for the REST API, read in the edit
	 * context and saved by whoever can edit the post, sanitized like the
	 * site's label.
	 */
	public function test_post_label_is_saved_through_rest() {
		$post_id = self::factory()->post->create();

		$response = self::save_post_label( $post_id, '  Written <b>up</b> & "more" ' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Written up & "more"', get_post_meta( $post_id, Breakout_Label::POST_META_KEY, true ) );
		$this->assertSame( 'Written up & "more"', Breakout_Label::get_post_label( $post_id ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'edit' );

		$this->assertSame( 'Written up & "more"', rest_get_server()->dispatch( $request )->get_data()['meta'][ Breakout_Label::POST_META_KEY ] );
	}

	/**
	 * A user who can't edit the post can't set its label, through REST or
	 * as a custom field.
	 */
	public function test_post_label_needs_a_user_who_can_edit_the_post() {
		$post_id = self::factory()->post->create( [ 'post_author' => get_current_user_id() ] );
		$author  = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author );

		$this->assertFalse( current_user_can( 'edit_post_meta', $post_id, Breakout_Label::POST_META_KEY ) );
		$this->assertSame( 403, self::save_post_label( $post_id, 'Analysis' )->get_status() );
		$this->assertFalse( metadata_exists( 'post', $post_id, Breakout_Label::POST_META_KEY ) );

		$own_post = self::factory()->post->create( [ 'post_author' => $author ] );

		$this->assertTrue( current_user_can( 'edit_post_meta', $own_post, Breakout_Label::POST_META_KEY ) );
		$this->assertSame( 200, self::save_post_label( $own_post, 'Analysis' )->get_status() );
		$this->assertSame( 'Analysis', Breakout_Label::get_post_label( $own_post ) );
	}

	/**
	 * A post's label is capped at the site label's length, in characters,
	 * and keeps a lone "<" or "&" as typed.
	 */
	public function test_post_label_is_capped_and_kept_as_typed() {
		$post_id = self::factory()->post->create();

		update_post_meta( $post_id, Breakout_Label::POST_META_KEY, str_repeat( 'é', Breakout_Label::MAX_LENGTH + 10 ) );

		$this->assertSame( str_repeat( 'é', Breakout_Label::MAX_LENGTH ), get_post_meta( $post_id, Breakout_Label::POST_META_KEY, true ) );

		update_post_meta( $post_id, Breakout_Label::POST_META_KEY, 'Q < A & B' );

		$this->assertSame( 'Q < A & B', Breakout_Label::get_post_label( $post_id ) );
	}

	/**
	 * An empty label, or one of spaces, is never stored: saving one removes
	 * the post's label, and the post falls back to the site's.
	 *
	 * @dataProvider empty_labels
	 *
	 * @param string $label The label saved.
	 */
	public function test_empty_post_label_is_not_stored( string $label ) {
		$post_id = self::factory()->post->create();
		update_option( Breakout_Label::OPTION_KEY, 'Site label' );

		$this->assertSame( 200, self::save_post_label( $post_id, $label )->get_status() );
		$this->assertFalse( metadata_exists( 'post', $post_id, Breakout_Label::POST_META_KEY ), 'A post without a label stores none.' );

		update_post_meta( $post_id, Breakout_Label::POST_META_KEY, 'Analysis' );

		$this->assertSame( 'Analysis', Breakout_Label::for_post( $post_id ) );

		$this->assertSame( 200, self::save_post_label( $post_id, $label )->get_status() );
		$this->assertFalse( metadata_exists( 'post', $post_id, Breakout_Label::POST_META_KEY ), 'Clearing the label deletes it.' );
		$this->assertSame( 'Site label', Breakout_Label::for_post( $post_id ) );

		add_post_meta( $post_id, Breakout_Label::POST_META_KEY, $label );

		$this->assertFalse( metadata_exists( 'post', $post_id, Breakout_Label::POST_META_KEY ) );
	}

	/**
	 * A post's label wins over the site's, which wins over the built-in
	 * one; other posts keep the site's.
	 */
	public function test_post_label_precedence() {
		$post_id = self::factory()->post->create();
		$other   = self::factory()->post->create();

		$this->assertSame( 'Full story', Breakout_Label::for_post( $post_id ) );

		update_option( Breakout_Label::OPTION_KEY, 'Site label' );

		$this->assertSame( 'Site label', Breakout_Label::for_post( $post_id ) );

		update_post_meta( $post_id, Breakout_Label::POST_META_KEY, 'Analysis' );

		$this->assertSame( 'Analysis', Breakout_Label::for_post( $post_id ) );
		$this->assertSame( 'Site label', Breakout_Label::for_post( $other ) );
	}
}
