/**
 * Fired on `document` by the Rolling Coverage block after each poll, with
 * what the Coverage Status block needs to follow it.
 */
const POLL_EVENT = 'newspack-rolling-coverage:poll';

interface PollEventDetail {
	coverageId: number;
	status: string;
	newestEntry: string | null;
}

export { POLL_EVENT };
export type { PollEventDetail };
