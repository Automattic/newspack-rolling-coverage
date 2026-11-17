/**
 * Internal dependencies
 */
import { POLL_EVENT, type PollEventDetail } from '../shared/poll-event';
import { readCheck } from '../shared/check-event';
import { isScore, type Score } from '../shared/score';
import { pollWait } from '../rolling-coverage/poll-backoff';
import { applyScore } from './apply-score';

const SCOREBOARD_SELECTOR =
	'.wp-block-newspack-rolling-coverage-scoreboard[data-coverage-id]';
const FEED_SELECTOR = '.wp-block-newspack-rolling-coverage-rolling-coverage';

interface ScoreReply {
	score: Score | null;
	status: string;
	minPollInterval: number;
}

/**
 * The scoreboards in the page. Looked up each time, as for the Coverage
 * Status block, since entries a poll brings can hold one.
 *
 * @param {number} coverageId Only those of this coverage, when given.
 * @return {HTMLElement[]} Their wrappers.
 */
function scoreboards( coverageId?: number ): HTMLElement[] {
	return Array.from(
		document.querySelectorAll< HTMLElement >( SCOREBOARD_SELECTOR )
	).filter(
		( block ) =>
			coverageId === undefined ||
			Number( block.dataset.coverageId ) === coverageId
	);
}

/**
 * Shows a coverage's score in each of its scoreboards. Only the first
 * announces a change.
 *
 * @param {number}     coverageId Coverage ID.
 * @param {Score|null} score      The score, or null for none.
 * @param {string}     status     The coverage's status.
 */
function show( coverageId: number, score: Score | null, status: string ) {
	scoreboards( coverageId ).forEach( ( block, index ) => {
		block.dataset.status = status;
		applyScore( block, score, index === 0 );
	} );
}

/**
 * Whether a Rolling Coverage feed of the coverage on the page is checking
 * for new entries on a timer. Its polls carry the score, so the page needs
 * no request of its own meanwhile.
 *
 * @param {number} coverageId Coverage ID.
 * @return {boolean} Whether one is.
 */
function isFeedPolling( coverageId: number ): boolean {
	return Array.from(
		document.querySelectorAll< HTMLElement >( FEED_SELECTOR )
	).some(
		( feed ) =>
			Number( feed.dataset.coverageId ) === coverageId &&
			readCheck( feed ).state !== 'idle'
	);
}

/**
 * Checks a coverage's score on a timer while no feed of it on the page
 * does, from the URL every reader of the coverage shares, so the edge cache
 * answers most of them. It waits while the tab is hidden, backs off while
 * checks fail, as the feed does, and stops once the coverage has ended.
 *
 * @param {number} coverageId Coverage ID.
 * @param {string} url        The coverage's score URL.
 * @param {number} interval   Seconds between checks.
 */
function followScore( coverageId: number, url: string, interval: number ) {
	const spread = 0.5 + Math.random() * 0.5;
	let failures = 0;
	let minPollInterval = 0;
	let timer: ReturnType< typeof setTimeout > | null = null;

	const hasEnded = () =>
		scoreboards( coverageId ).every(
			( block ) => block.dataset.status === 'archived'
		);

	const schedule = () => {
		if ( timer ) {
			clearTimeout( timer );
		}

		timer = setTimeout(
			check,
			pollWait(
				Math.max( interval, minPollInterval ) * 1000,
				failures,
				spread
			)
		);
	};

	async function check(): Promise< void > {
		timer = null;

		if ( ! scoreboards( coverageId ).length || hasEnded() ) {
			return;
		}

		if ( document.hidden ) {
			return;
		}

		if ( isFeedPolling( coverageId ) ) {
			schedule();
			return;
		}

		try {
			const response = await fetch( url, { credentials: 'omit' } );

			if ( ! response.ok ) {
				throw new Error( String( response.status ) );
			}

			const reply = ( await response.json() ) as ScoreReply;

			minPollInterval = Number( reply.minPollInterval ) || 0;
			failures = 0;
			show(
				coverageId,
				isScore( reply.score ) ? reply.score : null,
				String( reply.status )
			);
		} catch {
			failures++;
		}

		schedule();
	}

	document.addEventListener( 'visibilitychange', () => {
		if ( ! document.hidden && ! timer ) {
			check();
		}
	} );

	// A feed's view script can run after this one, so the first check waits
	// for it to report its state.
	timer = setTimeout( check, 0 );
}

document.addEventListener( POLL_EVENT, ( event ) => {
	const { detail } = event as CustomEvent< PollEventDetail >;

	if ( detail.score === undefined ) {
		return;
	}

	show(
		detail.coverageId,
		isScore( detail.score ) ? detail.score : null,
		detail.status
	);
} );

const followed = new Set< number >();

scoreboards().forEach( ( block ) => {
	const coverageId = Number( block.dataset.coverageId );
	const url = block.dataset.scoreUrl;

	if ( ! coverageId || ! url || followed.has( coverageId ) ) {
		return;
	}

	followed.add( coverageId );
	followScore(
		coverageId,
		url,
		Math.max( 1, Number( block.dataset.pollInterval ) || 30 )
	);
} );
