/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * One side of a score.
 */
interface ScoreTeam {
	name: string;
	score: number;
}

/**
 * The badge style a status line takes when a Scoreboard shows it as a
 * badge: the Coverage Status badge of that coverage status, Score::badges().
 */
const SCORE_BADGES = [ 'active', 'paused', 'archived' ] as const;

type ScoreBadge = ( typeof SCORE_BADGES )[ number ];

/**
 * A coverage's score, as `Score::get()` returns it: two teams, a status
 * line, which can be empty, and its badge style.
 */
interface Score {
	teams: [ ScoreTeam, ScoreTeam ];
	status: string;
	badge: ScoreBadge;
}

// The coverage term meta holding the score, Score::META_KEY.
const SCORE_META_KEY = 'rolling_coverage_score';

/**
 * The parts of a score a block can bind to, mirroring
 * Scoreboard_Block::KEYS.
 */
const SCORE_KEYS = [
	'team1Name',
	'team1Score',
	'team2Score',
	'team2Name',
	'status',
] as const;

type ScoreKey = ( typeof SCORE_KEYS )[ number ];

/**
 * Whether a value is a score: two named teams with whole scores.
 *
 * @param {unknown} value The value.
 * @return {boolean} Whether it is one.
 */
function isScore( value: unknown ): value is Score {
	const teams = ( value as Partial< Score > | null )?.teams;

	return (
		Array.isArray( teams ) &&
		teams.length === 2 &&
		teams.every(
			( team ) =>
				typeof team?.name === 'string' &&
				team.name !== '' &&
				Number.isInteger( team.score ) &&
				team.score >= 0
		)
	);
}

/**
 * One part of a score, as text, mirroring Scoreboard_Block::part().
 *
 * @param {Score}  score The score.
 * @param {string} key   The part.
 * @return {string} The text.
 */
function scorePart( score: Score, key: ScoreKey ): string {
	switch ( key ) {
		case 'team1Name':
			return score.teams[ 0 ].name;
		case 'team1Score':
			return String( score.teams[ 0 ].score );
		case 'team2Score':
			return String( score.teams[ 1 ].score );
		case 'team2Name':
			return score.teams[ 1 ].name;
		case 'status':
			return score.status ?? '';
	}
}

/**
 * The two scores, for telling a change of score from a change of names or
 * status.
 *
 * @param {Score} score The score.
 * @return {string} The two scores, as "2:1".
 */
function scoreLine( score: Score ): string {
	return `${ score.teams[ 0 ].score }:${ score.teams[ 1 ].score }`;
}

/**
 * What screen readers hear when the score changes.
 *
 * @param {Score} score The new score.
 * @return {string} The text, as "Riverbend FC 2, Toledo Beacon 1".
 */
function scoreAnnouncement( score: Score ): string {
	return sprintf(
		/* translators: Read by screen readers when a liveblog's score changes. 1: The first team's name. 2: Its score. 3: The second team's name. 4: Its score. */
		__( '%1$s %2$d, %3$s %4$d', 'newspack-rolling-coverage' ),
		score.teams[ 0 ].name,
		score.teams[ 0 ].score,
		score.teams[ 1 ].name,
		score.teams[ 1 ].score
	);
}

export {
	SCORE_BADGES,
	SCORE_META_KEY,
	SCORE_KEYS,
	isScore,
	scorePart,
	scoreLine,
	scoreAnnouncement,
};
export type { Score, ScoreBadge, ScoreKey, ScoreTeam };
