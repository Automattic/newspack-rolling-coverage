<?php
/**
 * Tests for storing AI prompt settings.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\AI_Settings;

/**
 * A prompt left on its default is not stored, so the site follows the
 * default when a release improves it.
 */
class Test_AI_Settings extends Rolling_Coverage_TestCase {

	/**
	 * Act as an administrator, the only role that can manage AI settings.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Dispatch a request to the settings route.
	 *
	 * @param string $method HTTP method.
	 * @return WP_REST_Response
	 */
	private static function request_settings( $method ) {
		return rest_get_server()->dispatch( new WP_REST_Request( $method, '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . AI_Settings::REST_ROUTE ) );
	}

	/**
	 * Save a Key Takeaways prompt through the REST route.
	 *
	 * @param string $prompt Prompt text.
	 * @return WP_REST_Response
	 */
	private static function save_prompt( $prompt ) {
		$request = new WP_REST_Request( 'POST', '/' . NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE . AI_Settings::REST_ROUTE );
		$request->set_param( 'key_takeaways_prompt', $prompt );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Saving the default text, as Reset to Defaults does, removes the stored
	 * prompt instead of pinning today's default.
	 */
	public function test_saving_the_default_clears_the_stored_prompt() {
		$defaults = AI_Settings::get_defaults();
		self::save_prompt( 'List up to {max_takeaways} takeaways.' );

		$response = self::save_prompt( $defaults['key_takeaways_prompt'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( get_option( AI_Settings::OPTION_KEY ), 'The option should be deleted once every prompt is back to its default.' );
		$this->assertSame( $defaults, $response->get_data() );
	}

	/**
	 * Editors can neither read nor change the settings.
	 */
	public function test_editors_are_forbidden() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 403, self::request_settings( 'GET' )->get_status() );
		$this->assertSame( 403, self::save_prompt( 'List up to {max_takeaways} takeaways.' )->get_status() );
		$this->assertFalse( get_option( AI_Settings::OPTION_KEY ), 'A forbidden save should not store anything.' );
	}

	/**
	 * Administrators can read the settings.
	 */
	public function test_administrators_can_read_settings() {
		$response = self::request_settings( 'GET' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( AI_Settings::get_defaults(), $response->get_data() );
	}
}
