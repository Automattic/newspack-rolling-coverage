<?php
/**
 * Tests for a coverage's score: the term meta, its REST access and the
 * routes that serve it to open pages.
 *
 * @package Newspack_Rolling_Coverage
 */

use Newspack_Rolling_Coverage\Rolling_Coverage_Block;
use Newspack_Rolling_Coverage\Score;
use Newspack_Rolling_Coverage\Taxonomy;

/**
 * The score is term meta whoever can edit the coverage can change, and
 * every page can read.
 */
class Test_Score extends Rolling_Coverage_TestCase {

	/**
	 * A valid score.
	 *
	 * @param int    $first  First team's score.
	 * @param int    $second Second team's score.
	 * @param string $status Status line.
	 * @param string $badge  The status line's badge style.
	 * @return array
	 */
	private static function score( int $first = 2, int $second = 1, string $status = 'Full time', string $badge = Taxonomy::STATUS_ACTIVE ): array {
		return [
			'teams'  => [
				[
					'name'  => 'Riverbend FC',
					'score' => $first,
				],
				[
					'name'  => 'Toledo Beacon',
					'score' => $second,
				],
			],
			'status' => $status,
			'badge'  => $badge,
		];
	}

	/**
	 * Update a coverage through the core coverages route.
	 *
	 * @param int   $coverage_id Coverage term ID.
	 * @param mixed $score       The score to send; null removes it.
	 * @return WP_REST_Response
	 */
	private static function save( int $coverage_id, $score ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Taxonomy::REST_BASE . '/' . $coverage_id );
		$request->set_body_params( [ 'meta' => [ Score::META_KEY => $score ] ] );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A coverage has no score until one is set.
	 */
	public function test_a_coverage_has_no_score_by_default() {
		$coverage_id = self::create_coverage();

		$this->assertNull( Score::get( $coverage_id ) );
		$this->assertNull( Score::get( 0 ) );
	}

	/**
	 * Names and status are plain text cut to length, and scores whole
	 * numbers from 0 to the maximum.
	 */
	public function test_sanitize_normalizes_a_score() {
		$score = Score::sanitize(
			[
				'teams'  => [
					[
						'name'  => '<b>Riverbend</b> FC',
						'score' => '3',
					],
					[
						'name'  => str_repeat( 'x', 60 ),
						'score' => -4,
					],
				],
				'status' => 5000,
			]
		);

		$this->assertSame( 'Riverbend FC', $score['teams'][0]['name'] );
		$this->assertSame( 3, $score['teams'][0]['score'] );
		$this->assertSame( Score::MAX_TEXT_LENGTH, mb_strlen( $score['teams'][1]['name'] ) );
		$this->assertSame( 0, $score['teams'][1]['score'] );
		$this->assertSame( '5000', $score['status'] );
		$this->assertSame( Score::MAX_SCORE, Score::sanitize( self::score( 5000 ) )['teams'][0]['score'] );
	}

	/**
	 * Text is stored as plain text, never encoded: a lone `<` and an `&`
	 * stay as typed, so pages that set it as text show what was typed.
	 */
	public function test_text_is_stored_as_typed() {
		$coverage_id = self::create_coverage();
		$score       = self::score();

		$score['teams'][1]['name'] = 'Toledo   U<23 & Co';
		$score['status']           = "Half\ttime <i>(late)</i>";

		self::log_in_as( 'editor' );
		$response = self::save( $coverage_id, $score );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Toledo U<23 & Co', Score::get( $coverage_id )['teams'][1]['name'] );
		$this->assertSame( 'Half time (late)', Score::get( $coverage_id )['status'] );
		$this->assertSame( 'Toledo U<23 & Co', $response->get_data()['meta'][ Score::META_KEY ]['teams'][1]['name'] );
	}

	/**
	 * A name made only of tags keeps its text, so it never empties the
	 * score; one with nothing but spaces and angle brackets is turned away.
	 */
	public function test_names_never_empty_the_score() {
		$coverage_id = self::create_coverage();
		$tag_only    = self::score();
		$blank       = self::score();

		$tag_only['teams'][0]['name'] = '<b></b>';
		$blank['teams'][0]['name']    = ' <> ';

		self::log_in_as( 'editor' );

		$this->assertSame( 200, self::save( $coverage_id, $tag_only )->get_status() );
		$this->assertSame( 'b/b', Score::get( $coverage_id )['teams'][0]['name'] );
		$this->assertSame( 400, self::save( $coverage_id, $blank )->get_status() );
		$this->assertSame( 'b/b', Score::get( $coverage_id )['teams'][0]['name'] );
	}

	/**
	 * The status line's badge style is one of the Coverage Status badges,
	 * the live one when it is missing or unknown.
	 */
	public function test_sanitize_keeps_a_known_badge_and_falls_back_to_active() {
		$missing = self::score();
		unset( $missing['badge'] );

		$this->assertSame( Taxonomy::STATUS_ARCHIVED, Score::sanitize( self::score( 2, 1, 'Full time', Taxonomy::STATUS_ARCHIVED ) )['badge'] );
		$this->assertSame( Taxonomy::STATUS_PAUSED, Score::sanitize( self::score( 2, 1, 'Half time', Taxonomy::STATUS_PAUSED ) )['badge'] );
		$this->assertSame( Taxonomy::STATUS_ACTIVE, Score::sanitize( $missing )['badge'] );
		$this->assertSame( Taxonomy::STATUS_ACTIVE, Score::sanitize( self::score( 2, 1, 'Full time', 'trash' ) )['badge'] );
		$this->assertSame( Taxonomy::STATUS_ACTIVE, Score::sanitize( array_merge( self::score(), [ 'badge' => [ 'archived' ] ] ) )['badge'] );
	}

	/**
	 * The route saves a known badge style and turns away an unknown one.
	 */
	public function test_route_saves_the_badge() {
		$coverage_id = self::create_coverage();
		$ended       = self::score( 2, 1, 'Full time', Taxonomy::STATUS_ARCHIVED );

		self::log_in_as( 'editor' );

		$this->assertSame( $ended, self::save( $coverage_id, $ended )->get_data()['meta'][ Score::META_KEY ] );
		$this->assertSame( 400, self::save( $coverage_id, self::score( 2, 1, 'Full time', 'final' ) )->get_status() );
		$this->assertSame( Taxonomy::STATUS_ARCHIVED, Score::get( $coverage_id )['badge'] );
	}

	/**
	 * Anything but two named teams isn't a score.
	 */
	public function test_sanitize_rejects_what_isnt_a_score() {
		$one_team = self::score();
		array_pop( $one_team['teams'] );

		$unnamed                     = self::score();
		$unnamed['teams'][1]['name'] = ' ';

		$this->assertNull( Score::sanitize( '' ) );
		$this->assertNull( Score::sanitize( [] ) );
		$this->assertNull( Score::sanitize( $one_team ) );
		$this->assertNull( Score::sanitize( $unnamed ) );
	}

	/**
	 * Editors set, read and remove the score through the coverages route.
	 */
	public function test_editors_save_and_remove_the_score() {
		$coverage_id = self::create_coverage();

		self::log_in_as( 'editor' );

		$response = self::save( $coverage_id, self::score() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::score(), $response->get_data()['meta'][ Score::META_KEY ] );
		$this->assertSame( self::score(), Score::get( $coverage_id ) );

		$response = self::save( $coverage_id, null );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['meta'][ Score::META_KEY ] );
		$this->assertNull( Score::get( $coverage_id ) );
	}

	/**
	 * Changing the score needs the same capability as editing the coverage.
	 */
	public function test_authors_cannot_change_the_score() {
		$coverage_id = self::create_coverage();

		self::log_in_as( 'author' );

		$this->assertSame( 403, self::save( $coverage_id, self::score() )->get_status() );
		$this->assertNull( Score::get( $coverage_id ) );
	}

	/**
	 * The route rejects a negative score.
	 */
	public function test_route_rejects_a_negative_score() {
		$coverage_id = self::create_coverage();

		self::log_in_as( 'editor' );

		$this->assertSame( 400, self::save( $coverage_id, self::score( -1 ) )->get_status() );
	}

	/**
	 * The score route is public and cached like a poll reply.
	 */
	public function test_score_route_serves_the_score() {
		$coverage_id = self::create_coverage( Taxonomy::STATUS_PAUSED );
		update_term_meta( $coverage_id, Score::META_KEY, self::score( 1, 1, 'Half time', Taxonomy::STATUS_PAUSED ) );

		wp_set_current_user( 0 );
		$response = self::dispatch( 'GET', "/coverages/{$coverage_id}/score" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::score( 1, 1, 'Half time', Taxonomy::STATUS_PAUSED ), $response->get_data()['score'] );
		$this->assertSame( Taxonomy::STATUS_PAUSED, $response->get_data()['status'] );
		$this->assertSame( 0, $response->get_data()['minPollInterval'] );
		$this->assertSame( 'public, max-age=' . Rolling_Coverage_Block::POLL_MAX_AGE, $response->get_headers()['Cache-Control'] );
	}

	/**
	 * A coverage without a score answers null; a trashed one, not at all.
	 */
	public function test_score_route_without_a_score_or_coverage() {
		$coverage_id = self::create_coverage();
		$trashed_id  = self::create_coverage( 'trash' );
		update_term_meta( $trashed_id, Score::META_KEY, self::score() );

		$this->assertNull( self::dispatch( 'GET', "/coverages/{$coverage_id}/score" )->get_data()['score'] );
		$this->assertSame( 404, self::dispatch( 'GET', "/coverages/{$trashed_id}/score" )->get_status() );
	}

	/**
	 * Every poll reply carries the score, for the Scoreboard blocks on the
	 * feed's page.
	 */
	public function test_poll_replies_carry_the_score() {
		$coverage_id = self::create_coverage();
		self::create_entry( $coverage_id );

		$poll = fn() => self::dispatch(
			'GET',
			"/coverages/{$coverage_id}/entries",
			[
				'recent'       => 1,
				'template_key' => '',
			]
		)->get_data();

		$this->assertArrayHasKey( 'score', $poll() );
		$this->assertNull( $poll()['score'] );

		update_term_meta( $coverage_id, Score::META_KEY, self::score( 2, 1, 'Full time', Taxonomy::STATUS_ARCHIVED ) );

		$this->assertSame( self::score( 2, 1, 'Full time', Taxonomy::STATUS_ARCHIVED ), $poll()['score'] );
	}
}
