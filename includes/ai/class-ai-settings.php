<?php
/**
 * AI prompt settings: system prompt and key takeaways prompt configuration.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Manages AI prompt settings stored as WordPress options.
 *
 * Provides REST endpoints for retrieving and updating the system prompt
 * and key takeaways prompt used by the key takeaways generation feature.
 */
class AI_Settings {

	// Option key where AI prompt settings are stored.
	const OPTION_KEY = 'rolling_coverage_ai_settings';

	// REST route for AI settings CRUD.
	const REST_ROUTE = '/ai/settings';

	/**
	 * Default prompt values.
	 *
	 * @var array
	 */
	private static $defaults = [
		'system_prompt'        => 'You are a news editor extracting key takeaways from live coverage entries. For each takeaway, provide a concise 1-2 sentence summary of the most important development. Focus on facts, not opinion. Order by importance.',
		'key_takeaways_prompt' => 'Extract up to {max_takeaways} key takeaways from the following live coverage entries. Each takeaway should be a concise 1-2 sentence summary of the most important development.',
	];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register REST routes for AI settings.
	 */
	public static function register_routes() {
		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			self::REST_ROUTE,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'get_settings' ],
					'permission_callback' => [ __CLASS__, 'can_manage_settings' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'update_settings' ],
					'permission_callback' => [ __CLASS__, 'can_manage_settings' ],
					'args'                => [
						'system_prompt'        => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_textarea_field',
						],
						'key_takeaways_prompt' => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_textarea_field',
						],
					],
				],
			]
		);
	}

	/**
	 * Permission check: user must be able to manage options.
	 *
	 * @return bool
	 */
	public static function can_manage_settings(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get all AI prompt settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function get_all(): array {
		$saved = get_option( self::OPTION_KEY, [] );

		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return array_merge( self::$defaults, $saved );
	}

	/**
	 * Get the hardcoded default prompt values (without saved overrides).
	 *
	 * @return array
	 */
	public static function get_defaults(): array {
		return self::$defaults;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key Setting key.
	 * @return string|null Setting value, or null if key does not exist.
	 */
	public static function get( string $key ): ?string {
		$settings = self::get_all();
		return $settings[ $key ] ?? null;
	}

	/**
	 * REST handler: get AI settings.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings(): WP_REST_Response {
		return new WP_REST_Response( self::get_all(), 200 );
	}

	/**
	 * REST handler: update AI settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_settings( WP_REST_Request $request ) {
		$settings = self::get_all();

		$system_prompt        = $request->get_param( 'system_prompt' );
		$key_takeaways_prompt = $request->get_param( 'key_takeaways_prompt' );

		if ( null !== $system_prompt ) {
			$settings['system_prompt'] = $system_prompt;
		}

		if ( null !== $key_takeaways_prompt ) {
			$settings['key_takeaways_prompt'] = $key_takeaways_prompt;
		}

		update_option( self::OPTION_KEY, $settings );
		// update_option returns false when values are unchanged — treat as success.
		$new_settings = self::get_all();
		return new WP_REST_Response( $new_settings, 200 );
	}
}
