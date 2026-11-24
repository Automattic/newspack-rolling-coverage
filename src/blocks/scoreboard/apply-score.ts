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
import { badgeClasses, badgeStatus } from '../shared/status-badges';

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
 * Shows the status line as the Coverage Status badge of the score's badge
 * style, adding the badge to a line rendered empty. The dot and the custom
 * background come from the scoreboard's `data-hide-dot` and
 * `data-style-{status}`, as on the Coverage Status block.
 *
 * @param {HTMLElement} block   The scoreboard's wrapper.
 * @param {HTMLElement} element The status line's element.
 * @param {Score}       score   The score.
 */
function applyBadge(
	block: HTMLElement,
	element: HTMLElement,
	score: Score
): void {
	const text = scorePart( score, 'status' );

	if ( text === '' ) {
		element.replaceChildren();
		return;
	}

	let badge = element.querySelector< HTMLElement >( '.newspack-ui__badge' );

	if ( ! badge ) {
		badge = element.ownerDocument.createElement( 'span' );
		element.replaceChildren( badge );
	}

	const status = badgeStatus( score.badge );
	const style = block.getAttribute( `data-style-${ status }` );

	badge.className = `newspack-ui__badge ${ badgeClasses(
		status,
		! block.hasAttribute( 'data-hide-dot' )
	) }`;

	if ( style ) {
		badge.setAttribute( 'style', style );
	} else {
		badge.removeAttribute( 'style' );
	}

	if ( badge.textContent !== text ) {
		badge.textContent = text;
	}
}

/**
 * Shows a score in a scoreboard, and tells screen readers when the two
 * scores change. A change of names or status line updates silently. Without
 * a score the scoreboard hides, as a fresh render does. A scoreboard that
 * shows the status line as a badge (`data-status-badge`) keeps it one.
 * With several scoreboards of one coverage on a page, only one announces,
 * so screen readers hear each change once.
 *
 * @param {HTMLElement} block    The scoreboard's wrapper.
 * @param {Score|null}  score    The coverage's score, or null for none.
 * @param {boolean}     announce Whether this scoreboard announces changes.
 * @return {boolean} Whether the change was announced.
 */
function applyScore(
	block: HTMLElement,
	score: Score | null,
	announce = true
): boolean {
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

	const asBadge = block.dataset.statusBadge === 'true';

	parts( block ).forEach( ( [ element, key ] ) => {
		const text = scorePart( score, key );

		if ( key === 'status' && asBadge ) {
			applyBadge( block, element, score );
		} else if ( element.textContent !== text ) {
			element.textContent = text;
		}

		element.hidden = text === '';
	} );

	const region = block.querySelector< HTMLElement >( ANNOUNCE_SELECTOR );

	if ( ! announce || ! region || previous === null || previous === line ) {
		return false;
	}

	region.textContent = scoreAnnouncement( score );

	return true;
}

export { applyScore };
