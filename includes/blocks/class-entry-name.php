<?php
/**
 * Site-wide name readers see for coverage entries.
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
 * What readers see entries called, e.g. "update" and "updates", in labels
 * such as "3 New Updates" and "No updates yet.". The site sets both words as
 * they read mid-sentence, or neither, and the labels then use their own
 * translated wording.
 */
class Entry_Name {

	/**
	 * Option holding the site's singular and plural.
	 */
	const OPTION_KEY = 'rolling_coverage_entry_name';

	/**
	 * REST route for reading and saving the name.
	 */
	const REST_ROUTE = '/settings/entry-name';

	/**
	 * Longest word kept, in characters.
	 */
	const MAX_LENGTH = 30;

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register REST routes for the name.
	 */
	public static function register_routes(): void {
		$word_arg = [
			'type'              => 'string',
			'required'          => false,
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => [ __CLASS__, 'sanitize_word' ],
		];

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
						'singular' => $word_arg,
						'plural'   => $word_arg,
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
	 * Sanitize and length-cap a word. It's stored as plain text, escaped
	 * where it's shown, so a "&" reaches the settings screen as typed rather
	 * than as an entity.
	 *
	 * @param mixed $value Raw word.
	 * @return string
	 */
	public static function sanitize_word( $value ): string {
		$clean = trim( wp_specialchars_decode( sanitize_text_field( (string) $value ), ENT_QUOTES ) );

		return mb_substr( $clean, 0, self::MAX_LENGTH );
	}

	/**
	 * The built-in words, shown as placeholders in the settings.
	 *
	 * @return array{singular: string, plural: string}
	 */
	public static function get_defaults(): array {
		return [
			'singular' => _x( 'entry', 'name readers see for one coverage entry', 'newspack-rolling-coverage' ),
			'plural'   => _x( 'entries', 'name readers see for several coverage entries', 'newspack-rolling-coverage' ),
		];
	}

	/**
	 * The words the site has set, both empty when it sets none.
	 *
	 * @return array{singular: string, plural: string}
	 */
	public static function get_saved(): array {
		$saved    = get_option( self::OPTION_KEY, [] );
		$singular = is_array( $saved ) && is_string( $saved['singular'] ?? null ) ? trim( $saved['singular'] ) : '';
		$plural   = is_array( $saved ) && is_string( $saved['plural'] ?? null ) ? trim( $saved['plural'] ) : '';

		if ( '' === $singular || '' === $plural ) {
			return [
				'singular' => '',
				'plural'   => '',
			];
		}

		return [
			'singular' => $singular,
			'plural'   => $plural,
		];
	}

	/**
	 * The site's word for a count of entries, as it reads mid-sentence, or an
	 * empty string when the site sets none.
	 *
	 * @param int $count How many entries the label speaks of.
	 * @return string
	 */
	public static function word( int $count ): string {
		$saved = self::get_saved();

		return 1 === $count ? $saved['singular'] : $saved['plural'];
	}

	/**
	 * The site's word for a count of entries as a button shows it, or an
	 * empty string when the site sets none.
	 *
	 * @param int $count How many entries the label speaks of.
	 * @return string
	 */
	public static function title_word( int $count ): string {
		return self::title_case( self::word( $count ) );
	}

	/**
	 * Capitalizes each all-lowercase word where the site's language writes
	 * button labels in title case, leaving words with capitals of their own,
	 * such as "iPhone", as typed. Elsewhere the words stay as typed: most
	 * languages use sentence case, and some capitalize every noun already.
	 *
	 * @param string $text Text as it reads mid-sentence.
	 * @return string
	 */
	public static function title_case( string $text ): string {
		if ( ! str_starts_with( determine_locale(), 'en' ) ) {
			return $text;
		}

		return (string) preg_replace_callback(
			'/(^|\s)(\p{Ll})(?=\p{Ll}*(?:\s|$))/u',
			static fn( array $matches ): string => $matches[1] . mb_strtoupper( $matches[2] ),
			$text
		);
	}

	/**
	 * The words for the view script to build its labels with, as JSON, or an
	 * empty string when the site sets none.
	 *
	 * @return string
	 */
	public static function for_script(): string {
		$saved = self::get_saved();

		if ( '' === $saved['singular'] ) {
			return '';
		}

		return (string) wp_json_encode(
			[
				'singular'      => $saved['singular'],
				'plural'        => $saved['plural'],
				'singularTitle' => self::title_case( $saved['singular'] ),
				'pluralTitle'   => self::title_case( $saved['plural'] ),
			]
		);
	}

	/**
	 * REST handler: get the site's words.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings(): WP_REST_Response {
		return new WP_REST_Response( self::get_saved(), 200 );
	}

	/**
	 * REST handler: save the site's words. Both empty go back to the built-in
	 * wording; one without the other, or a request naming only one, is
	 * refused.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$singular = $request->get_param( 'singular' );
		$plural   = $request->get_param( 'plural' );

		if ( null === $singular && null === $plural ) {
			return new WP_REST_Response( self::get_saved(), 200 );
		}

		if ( null === $singular || null === $plural ) {
			return self::incomplete_error();
		}

		if ( '' === $singular && '' === $plural ) {
			delete_option( self::OPTION_KEY );
		} elseif ( '' === $singular || '' === $plural ) {
			return self::incomplete_error();
		} else {
			update_option(
				self::OPTION_KEY,
				[
					'singular' => $singular,
					'plural'   => $plural,
				],
				true
			);
		}

		return new WP_REST_Response( self::get_saved(), 200 );
	}

	/**
	 * The error for a save that sets one word without the other.
	 *
	 * @return WP_Error
	 */
	private static function incomplete_error(): WP_Error {
		return new WP_Error(
			'rolling_coverage_entry_name_incomplete',
			__( 'Set both the singular and the plural, or leave both empty.', 'newspack-rolling-coverage' ),
			[ 'status' => 400 ]
		);
	}
}
