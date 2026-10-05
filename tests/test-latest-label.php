<?php
/**
 * Tests for the site-wide "Jump to Latest" label.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Latest_Label;

/**
 * A site can set the text of the control that takes readers to the live
 * feed; an empty label falls back to "Jump to Latest".
 */
class Test_Latest_Label extends Rolling_Coverage_TestCase {

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
		$request = new WP_REST_Request( 'POST', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . Latest_Label::REST_ROUTE );

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
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . Latest_Label::REST_ROUTE ) );
	}

	/**
	 * Without a saved label the control reads "Jump to Latest", and the
	 * route reports no label of the site's own.
	 */
	public function test_default_label() {
		$this->assertSame( 'Jump to Latest', Latest_Label::get_default() );
		$this->assertSame( 'Jump to Latest', Latest_Label::get() );
		$this->assertSame( '', Latest_Label::get_saved() );

		$response = self::read();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'label' => '' ], $response->get_data() );
	}

	/**
	 * A saved label is trimmed, stripped of markup and capped in characters,
	 * not bytes, and becomes the control's label.
	 */
	public function test_saving_the_label() {
		$response = self::save( [ 'label' => '  Back <b>to</b> live ' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'label' => 'Back to live' ], $response->get_data() );
		$this->assertSame( 'Back to live', get_option( Latest_Label::OPTION_KEY ) );
		$this->assertSame( 'Back to live', Latest_Label::get() );
		$this->assertSame( [ 'label' => 'Back to live' ], self::read()->get_data() );

		$response = self::save( [ 'label' => str_repeat( 'é', Latest_Label::MAX_LENGTH + 10 ) ] );

		$this->assertSame( str_repeat( 'é', Latest_Label::MAX_LENGTH ), $response->get_data()['label'] );
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
		self::save( [ 'label' => 'Back to live' ] );

		$this->assertSame( 400, self::save( [ 'label' => [ 'x' ] ] )->get_status() );
		$this->assertSame( 'Back to live', get_option( Latest_Label::OPTION_KEY ) );
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
		self::save( [ 'label' => 'Back to live' ] );

		$response = self::save( [ 'label' => $label ] );

		$this->assertSame( [ 'label' => '' ], $response->get_data() );
		$this->assertFalse( get_option( Latest_Label::OPTION_KEY ), 'The option should be deleted.' );
		$this->assertSame( 'Jump to Latest', Latest_Label::get() );
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
		self::save( [ 'label' => 'Back to live' ] );

		$this->assertSame( [ 'label' => 'Back to live' ], self::save( [] )->get_data() );
		$this->assertSame( 'Back to live', get_option( Latest_Label::OPTION_KEY ) );
	}

	/**
	 * Contributors can't read or change the label.
	 */
	public function test_contributors_cannot_manage_the_label() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$this->assertSame( 403, self::save( [ 'label' => 'Back to live' ] )->get_status() );
		$this->assertSame( 403, self::read()->get_status() );
		$this->assertFalse( get_option( Latest_Label::OPTION_KEY ) );
	}

	/**
	 * A stored value that isn't text, or is blank, is ignored.
	 *
	 * @dataProvider malformed_options
	 *
	 * @param mixed $value The stored value.
	 */
	public function test_malformed_option_falls_back_to_the_default( $value ) {
		update_option( Latest_Label::OPTION_KEY, $value );

		$this->assertSame( '', Latest_Label::get_saved() );
		$this->assertSame( 'Jump to Latest', Latest_Label::get() );
	}

	/**
	 * Stored values that aren't a usable label.
	 *
	 * @return array[]
	 */
	public static function malformed_options(): array {
		return [
			'array' => [ [ 'Back to live' ] ],
			'blank' => [ '  ' ],
		];
	}
}
