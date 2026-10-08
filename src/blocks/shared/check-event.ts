/**
 * Fired on `document` by the Rolling Coverage block whenever its check for
 * new entries is scheduled, starts, or stops, with what the Update Timer
 * block needs to follow it. The feed also keeps the state on its root, so a
 * timer that starts after the feed reads it from there.
 */
const CHECK_EVENT = 'newspack-rolling-coverage:check';

type CheckState = 'waiting' | 'checking' | 'idle';

type CheckResult = { outcome: 'ok'; added: number } | { outcome: 'failed' };

interface CheckEventDetail {
	coverageId: number;
	feed: HTMLElement;
	state: CheckState;
	nextCheckAt?: number;
	result?: CheckResult;
}

/**
 * Reads a feed's check state from its root.
 *
 * @param {HTMLElement} feed The feed's root.
 * @return {Object} The state, and when waiting, when the next check is due, in milliseconds.
 */
function readCheck( feed: HTMLElement ): {
	state: CheckState;
	nextCheckAt: number;
} {
	const state = feed.dataset.checkState;

	return {
		state: state === 'waiting' || state === 'checking' ? state : 'idle',
		nextCheckAt: Number( feed.dataset.nextCheckAt ) || 0,
	};
}

export { CHECK_EVENT, readCheck };
export type { CheckEventDetail, CheckResult, CheckState };
