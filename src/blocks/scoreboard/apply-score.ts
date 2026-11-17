/**
 * Internal dependencies
 */
import {
	SCORE_KEYS,
	scoreAnnouncement,
	scoreLine,
	scorePart,
	type Score,
	type ScoreKey,
} from '../shared/score';

const PART_ATTRIBUTE = 'data-rc-score';
const ANNOUNCE_SELECTOR = '[data-rc-score-announce]';

const shownLines = new WeakMap< HTMLElement, string >();

/**
 * The bound parts inside a scoreboard, by key.
 *
 * @param {HTMLElement} block The scoreboard's wrapper.
 * @return {Array} Each part's element and key.
 */
function parts( block: HTMLElement ): [ HTMLElement, ScoreKey ][] {
	return Array.from(
		block.querySelectorAll< HTMLElement >( `[${ PART_ATTRIBUTE }]` )
	)
		.map( ( element ): [ HTMLElement, ScoreKey ] => [
			element,
			element.getAttribute( PART_ATTRIBUTE ) as ScoreKey,
		] )
		.filter( ( [ , key ] ) => SCORE_KEYS.includes( key ) );
}

/**
 * The two scores a scoreboard showed when the page rendered, as "2:1".
 *
 * @param {HTMLElement} block The scoreboard's wrapper.
 * @return {string|null} The scores, or null when it shows neither.
 */
function renderedLine( block: HTMLElement ): string | null {
	const text = ( key: ScoreKey ) =>
		parts( block )
			.find( ( [ , partKey ] ) => partKey === key )?.[ 0 ]
			.textContent?.trim() ?? null;
	const first = text( 'team1Score' );
	const second = text( 'team2Score' );

	return first === null && second === null
		? null
		: `${ first ?? '' }:${ second ?? '' }`;
}

/**
 * Shows a score in a scoreboard, and tells screen readers when the two
 * scores change. A change of names or status line updates silently. Without
 * a score the scoreboard hides, as a fresh render would leave it out.
 *
 * @param {HTMLElement} block The scoreboard's wrapper.
 * @param {Score|null}  score The coverage's score, or null for none.
 * @return {boolean} Whether the change was announced.
 */
function applyScore( block: HTMLElement, score: Score | null ): boolean {
	if ( ! score ) {
		block.hidden = true;
		return false;
	}

	const previous = shownLines.has( block )
		? shownLines.get( block )
		: renderedLine( block );
	const line = scoreLine( score );

	block.hidden = false;
	shownLines.set( block, line );

	parts( block ).forEach( ( [ element, key ] ) => {
		const text = scorePart( score, key );

		if ( element.textContent !== text ) {
			element.textContent = text;
		}

		element.hidden = text === '';
	} );

	const region = block.querySelector< HTMLElement >( ANNOUNCE_SELECTOR );

	if ( ! region || previous === null || previous === line ) {
		return false;
	}

	region.textContent = scoreAnnouncement( score );

	return true;
}

export { applyScore };
