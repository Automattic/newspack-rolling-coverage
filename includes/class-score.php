<?php
/**
 * A coverage's score: two teams, a score for each, a status line and the
 * badge style the status line takes when a Scoreboard shows it as a badge.
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

	const META_KEY = 'rolling_coverage_score';

	// Longest team name and status line, in characters.
	const MAX_TEXT_LENGTH = 40;

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
	 * The badge styles a status line can take: those of the Coverage Status
	 * block's badges, keyed by the coverage status each one shows.
	 *
	 * @return string[]
	 */
	public static function badges(): array {
		return array_keys( Coverage_Status_Block::BADGE_CLASSES );
	}

	/**
	 * The score's JSON schema: two teams, each a name and a score, a status
	 * line, which can be empty, and its badge style.
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
							'name'  => array_merge(
								$text,
								[
									'minLength' => 1,
									'pattern'   => '[^\\s<>]',
								]
							),
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
				'badge'  => [
					'type' => 'string',
					'enum' => self::badges(),
				],
			],
			'required'             => [ 'teams' ],
		];
	}

	/**
	 * Normalizes a score: plain-text names and status cut to
	 * MAX_TEXT_LENGTH, whole scores from 0 to MAX_SCORE, and a badge style
	 * from badges(), the live one when it is missing or unknown.
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

		$badge = $value['badge'] ?? null;

		return [
			'teams'  => $teams,
			'status' => self::text( $value['status'] ?? '' ),
			'badge'  => is_string( $badge ) && in_array( $badge, self::badges(), true ) ? $badge : Taxonomy::STATUS_ACTIVE,
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
	 * Plain text, cut to MAX_TEXT_LENGTH characters: tags and comments taken
	 * out and whitespace collapsed, but nothing encoded. Every output escapes
	 * it, and pages set it as text, where an entity such as `&lt;` would show
	 * as typed. A lone `<`, as in "U<23", stays. A name made only of tags
	 * keeps its text without the angle brackets, so it never empties the
	 * score; the schema's pattern turns away one with nothing else.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function text( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$raw  = wp_check_invalid_utf8( (string) $value, true );
		$text = (string) preg_replace( '/<!--.*?-->|<\/?[a-zA-Z][^>]*>/s', '', $raw );

		if ( '' === trim( $text ) ) {
			$text = str_replace( [ '<', '>' ], '', $raw );
		}

		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), 0, self::MAX_TEXT_LENGTH );
	}
}
