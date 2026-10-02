<?php
/**
 * Site-wide labels for the Rolling Coverage block's status indicator.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The text the status indicator shows for each coverage status when a block
 * sets none: the site's own label, or the built-in one.
 */
class Status_Labels {

	// Option holding the site's labels, keyed by coverage status.
	const OPTION_KEY = 'rolling_coverage_status_labels';

	// REST route for reading and saving the labels.
	const REST_ROUTE = '/settings/status-labels';

	// Longest label kept, in characters.
	const MAX_LENGTH = 40;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register REST routes for the labels.
	 */
	public static function register_routes() {
		$args = [];

		foreach ( array_keys( self::get_defaults() ) as $status ) {
			$args[ $status ] = [
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => [ __CLASS__, 'sanitize_label' ],
			];
		}

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
					'args'                => $args,
				],
			]
		);
	}

	/**
	 * Permission check: Editor or higher, the same as the AI prompt settings.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'edit_others_posts' );
	}

	/**
	 * Sanitize and length-cap a label.
	 *
	 * @param mixed $value Raw label.
	 * @return string
	 */
	public static function sanitize_label( $value ): string {
		$clean = trim( sanitize_text_field( (string) $value ) );

		return mb_substr( $clean, 0, self::MAX_LENGTH );
	}

	/**
	 * The built-in label for each coverage status.
	 *
	 * @return array<string, string>
	 */
	public static function get_defaults(): array {
		return [
			Taxonomy::STATUS_ACTIVE   => _x( 'Live', 'coverage status', 'newspack-rolling-coverage' ),
			Taxonomy::STATUS_PAUSED   => _x( 'Paused', 'coverage status', 'newspack-rolling-coverage' ),
			Taxonomy::STATUS_ARCHIVED => _x( 'Ended', 'coverage status', 'newspack-rolling-coverage' ),
		];
	}

	/**
	 * The labels the site has set, keyed by status. A status without one is
	 * left out.
	 *
	 * @return array<string, string>
	 */
	public static function get_saved(): array {
		$saved = get_option( self::OPTION_KEY, [] );

		if ( ! is_array( $saved ) ) {
			return [];
		}

		$labels = [];

		foreach ( array_keys( self::get_defaults() ) as $status ) {
			if ( isset( $saved[ $status ] ) && is_string( $saved[ $status ] ) && '' !== trim( $saved[ $status ] ) ) {
				$labels[ $status ] = $saved[ $status ];
			}
		}

		return $labels;
	}

	/**
	 * The label for each status when a block sets none: the site's, or the
	 * built-in one.
	 *
	 * @return array<string, string>
	 */
	public static function get_all(): array {
		return array_merge( self::get_defaults(), self::get_saved() );
	}

	/**
	 * REST handler: get the site's labels.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings(): WP_REST_Response {
		return new WP_REST_Response( self::response_data(), 200 );
	}

	/**
	 * REST handler: save the site's labels. A label left empty goes back to
	 * the built-in one.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$labels = self::get_saved();

		foreach ( array_keys( self::get_defaults() ) as $status ) {
			$value = $request->get_param( $status );

			if ( null === $value ) {
				continue;
			}

			if ( '' === $value ) {
				unset( $labels[ $status ] );
			} else {
				$labels[ $status ] = $value;
			}
		}

		if ( empty( $labels ) ) {
			delete_option( self::OPTION_KEY );
		} else {
			update_option( self::OPTION_KEY, $labels );
		}

		return new WP_REST_Response( self::response_data(), 200 );
	}

	/**
	 * The labels as the settings screen reads them: an entry for every
	 * status, empty where the site sets none.
	 *
	 * @return array<string, string>
	 */
	private static function response_data(): array {
		return array_merge( array_fill_keys( array_keys( self::get_defaults() ), '' ), self::get_saved() );
	}
}
