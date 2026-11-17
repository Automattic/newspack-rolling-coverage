<?php
/**
 * A coverage's score: two teams, a score for each, and a status line.
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
 * Keeps a coverage's score in term meta, which the coverages route reads
 * and writes with the coverage's own permissions, and serves it to open
 * pages: in every poll reply, and from a route of its own for pages with a
 * Scoreboard block and no feed of the same coverage.
 *
 * A coverage without the meta has no score. Most coverages aren't matches.
 */
class Score {

	// Term meta key holding the score.
	const META_KEY = 'rolling_coverage_score';

	// Longest team name and status line, in characters.
	const MAX_TEXT_LENGTH = 40;

	// Highest score a team can have.
	const MAX_SCORE = 999;

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Registers the score term meta. With no `auth_callback`, whoever can
	 * edit the coverage can change it, as with the status.
	 */
	public static function register_meta(): void {
		register_term_meta(
			Taxonomy::TAXONOMY_SLUG,
			self::META_KEY,
			[
				'single'            => true,
				'type'              => 'object',
				'sanitize_callback' => [ __CLASS__, 'sanitize' ],
				'show_in_rest'      => [
					'schema' => self::schema(),
				],
			]
		);
	}

	/**
	 * The score's JSON schema: two teams, each a name and a score, and a
	 * status line, which can be empty.
	 *
	 * @return array
	 */
	public static function schema(): array {
		$text = [
			'type'      => 'string',
			'maxLength' => self::MAX_TEXT_LENGTH,
		];

		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'teams'  => [
					'type'     => 'array',
					'minItems' => 2,
					'maxItems' => 2,
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'name'  => array_merge( $text, [ 'minLength' => 1 ] ),
							'score' => [
								'type'    => 'integer',
								'minimum' => 0,
								'maximum' => self::MAX_SCORE,
							],
						],
						'required'             => [ 'name', 'score' ],
					],
				],
				'status' => $text,
			],
			'required'             => [ 'teams' ],
		];
	}

	/**
	 * Normalizes a score: plain-text names and status cut to
	 * MAX_TEXT_LENGTH, and whole scores from 0 to MAX_SCORE.
	 *
	 * @param mixed $value Raw value.
	 * @return array|null The score, or null when it isn't one: anything but two named teams.
	 */
	public static function sanitize( $value ): ?array {
		if ( ! is_array( $value ) || ! isset( $value['teams'] ) || ! is_array( $value['teams'] ) || 2 !== count( $value['teams'] ) ) {
			return null;
		}

		$teams = [];

		foreach ( array_values( $value['teams'] ) as $team ) {
			$name = is_array( $team ) ? self::text( $team['name'] ?? '' ) : '';

			if ( '' === $name ) {
				return null;
			}

			$teams[] = [
				'name'  => $name,
				'score' => min( self::MAX_SCORE, max( 0, (int) ( $team['score'] ?? 0 ) ) ),
			];
		}

		return [
			'teams'  => $teams,
			'status' => self::text( $value['status'] ?? '' ),
		];
	}

	/**
	 * A coverage's score.
	 *
	 * @param int $coverage_id Coverage term ID.
	 * @return array|null The score, or null when the coverage has none.
	 */
	public static function get( int $coverage_id ): ?array {
		if ( $coverage_id <= 0 ) {
			return null;
		}

		return self::sanitize( get_term_meta( $coverage_id, self::META_KEY, true ) );
	}

	/**
	 * Registers the public route a page with a Scoreboard block and no feed
	 * of the same coverage polls.
	 */
	public static function register_routes(): void {
		register_rest_route(
			NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE,
			'/coverages/(?P<term_id>\d+)/score',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_score' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'term_id' => [
						'required'          => true,
						'validate_callback' => [ Rolling_Coverage_Block::class, 'validate_term_id' ],
					],
				],
			]
		);
	}

	/**
	 * The coverage's score and status, cached as a poll reply is: every
	 * reader asks the same URL, so the edge answers most of them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_score( WP_REST_Request $request ) {
		$coverage_id = (int) $request['term_id'];

		if ( ! Page_Coverages::is_followable( $coverage_id ) ) {
			return new WP_Error(
				'rolling_coverage_coverage_not_found',
				__( 'Liveblog not found.', 'newspack-rolling-coverage' ),
				[ 'status' => 404 ]
			);
		}

		$min_poll_interval = Rolling_Coverage_Block::get_min_poll_interval();
		$response          = new WP_REST_Response(
			[
				'score'           => self::get( $coverage_id ),
				'status'          => Rolling_Coverage_Block::coverage_status( $coverage_id ),
				'minPollInterval' => $min_poll_interval,
			]
		);
		$response->header( 'Cache-Control', 'public, max-age=' . Rolling_Coverage_Block::poll_max_age( $min_poll_interval ) );

		return $response;
	}

	/**
	 * Plain text, cut to MAX_TEXT_LENGTH characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, self::MAX_TEXT_LENGTH ) : '';
	}
}
