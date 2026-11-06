/**
 * When an overflow reply reloads the page.
 *
 * A reload can land on a page cache copy from before the burst, which starts
 * from the same cursor and overflows again on its next poll. Session storage
 * keeps a record of the last reload across the reload, so a page reloads into
 * one cursor at most once per OVERFLOW_RELOAD_RETRY_MS. Without storage the
 * page's own age stands in for that record: a feed polls as it starts, so a
 * page that reloaded every time would reload again on every load until the
 * copy expires.
 */

// How long an overflow holds back another reload into the same cursor, and
// also how long the stale-template guard in view.ts waits before reloading a
// page again for the same key.
export const OVERFLOW_RELOAD_RETRY_MS = 60 * 1000;

/**
 * Whether an overflow reloads the page now, recording the reload when it
 * does.
 *
 * @param {Function} storage Returns the page's session storage. Reading it throws where the browser blocks it.
 * @param {string}   key     Storage key for the last reload.
 * @param {string}   cursor  The page's cursor.
 * @param {number}   now     The time, in epoch milliseconds.
 * @param {number}   pageAge How long the page has been open, in milliseconds.
 * @return {boolean} True if the page should reload now.
 */
export function allowsOverflowReload(
	storage: () => Pick< Storage, 'getItem' | 'setItem' >,
	key: string,
	cursor: string,
	now: number,
	pageAge: number
): boolean {
	try {
		const lastReload = JSON.parse( storage().getItem( key ) || 'null' );

		if (
			lastReload?.cursor === cursor &&
			now - lastReload.time < OVERFLOW_RELOAD_RETRY_MS
		) {
			return false;
		}

		storage().setItem( key, JSON.stringify( { cursor, time: now } ) );
	} catch {
		return pageAge >= OVERFLOW_RELOAD_RETRY_MS;
	}

	return true;
}
