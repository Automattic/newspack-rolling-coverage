<?php
/**
 * Site-wide label for entries shown as a card for their full story.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The label a broken-out entry's card shows with the title of its published
 * post (see Breakout_Card), so readers can tell a written-up story from an
 * ordinary update: the site's own label, or the built-in one.
 */
class Breakout_Label {

	/**
	 * Option holding the site's label.
	 */
	const OPTION_KEY = 'rolling_coverage_breakout_label';

	/**
	 * REST route for reading and saving the label.
	 */
	const REST_ROUTE = '/settings/breakout-label';

	/**
	 * Longest label kept, in characters.
	 */
	const MAX_LENGTH = 40;

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register REST routes for the label.
	 */
	public static function register_routes(): void {
		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			self::REST_ROUTE,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'get_settings' ],
					'permission_callback' => [ __CLASS__, 'can_manage' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'update_settings' ],
					'permission_callback' => [ __CLASS__, 'can_manage' ],
					'args'                => [
						'label' => [
							'type'              => 'string',
							'required'          => false,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => [ __CLASS__, 'sanitize_label' ],
						],
					],
				],
			]
		);
	}

	/**
	 * Permission check: the same as the status labels.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return Status_Labels::can_manage();
	}

	/**
	 * Sanitize and length-cap the label. It's stored as plain text, escaped
	 * where it's shown, so a "<" reaches the settings screen as typed rather
	 * than as an entity.
	 *
	 * @param mixed $value Raw label.
	 * @return string
	 */
	public static function sanitize_label( $value ): string {
		$clean = trim( wp_specialchars_decode( sanitize_text_field( (string) $value ), ENT_QUOTES ) );

		return mb_substr( $clean, 0, self::MAX_LENGTH );
	}

	/**
	 * The built-in label.
	 *
	 * @return string
	 */
	public static function get_default(): string {
		return __( 'Full story', 'newspack-rolling-coverage' );
	}

	/**
	 * The label the site has set, or an empty string when it sets none.
	 *
	 * @return string
	 */
	public static function get_saved(): string {
		$saved = get_option( self::OPTION_KEY, '' );

		return is_string( $saved ) && '' !== trim( $saved ) ? $saved : '';
	}

	/**
	 * The label cards show: the site's, or the built-in one.
	 *
	 * @return string
	 */
	public static function get(): string {
		$saved = self::get_saved();

		return '' !== $saved ? $saved : self::get_default();
	}

	/**
	 * REST handler: get the site's label.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings(): WP_REST_Response {
		return new WP_REST_Response( self::response_data(), 200 );
	}

	/**
	 * REST handler: save the site's label. A label left empty goes back to
	 * the built-in one.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$value = $request->get_param( 'label' );

		if ( '' === $value ) {
			delete_option( self::OPTION_KEY );
		} elseif ( null !== $value ) {
			update_option( self::OPTION_KEY, $value, true );
		}

		return new WP_REST_Response( self::response_data(), 200 );
	}

	/**
	 * The label as the settings screen reads it, empty where the site sets
	 * none.
	 *
	 * @return array{label: string}
	 */
	private static function response_data(): array {
		return [ 'label' => self::get_saved() ];
	}
}
