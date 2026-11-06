/**
 * How long a page waits between polls while they fail.
 *
 * A site under more load than it can serve answers polls with errors, and
 * every open page asking again at its usual pace adds to that load. So each
 * failure in a row doubles the wait, up to a cap, and the first reply brings
 * the page back to its interval. The block's DEVELOPMENT.md, under Polling,
 * says what counts as a failure.
 */

// The longest a page waits between failing polls, unless its interval is longer.
export const BACKOFF_CAP_MS = 5 * 60 * 1000;

// Past this many failures the wait is at the cap for any interval, and the
// doubling stays a finite number.
const MAX_DOUBLINGS = 16;

/**
 * The wait before a page's next poll.
 *
 * The spread, drawn once per page, shortens a doubled wait so pages that
 * failed together don't all come back in the same moment. It never takes
 * the wait below the interval.
 *
 * @param {number} interval Wait between polls that get replies, in milliseconds.
 * @param {number} failures Polls in a row that failed.
 * @param {number} spread   Share of the doubled wait to keep, from 0.5 to 1.
 * @return {number} Wait before the next poll, in milliseconds.
 */
export function pollWait(
	interval: number,
	failures: number,
	spread: number
): number {
	const doubled = Math.min(
		interval * 2 ** Math.min( failures, MAX_DOUBLINGS ),
		BACKOFF_CAP_MS
	);

	return Math.max( interval, doubled * spread );
}
