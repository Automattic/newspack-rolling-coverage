/**
 * Shared polling: every reader of a page polls one URL, which the edge cache
 * keeps answering right after a change. The reply carries the coverage's
 * recent changes, and each page picks what it is missing by the rules a
 * cursor poll applies on the server (`Poll_Cursor::holds()` and
 * `Poll_Cursor::is_new()`, plus the cursor query's date bounds); change both
 * copies together. PHPUnit runs the server's rules against the cases in
 * tests/fixtures/poll-cursor-cases.json (tests/test-shared-poll.php, which CI
 * runs). This copy is checked against the same file by
 * tests/js/shared-poll-check.ts, which CI doesn't run; run it by hand from
 * the repository root with
 * `node --experimental-strip-types tests/js/shared-poll-check.ts`.
 */

/**
 * Internal dependencies
 */
import type {
	PollEntry,
	PollResponse,
	SharedPollChange,
	SharedPollResponse,
} from './types';

/**
 * A page's position, as its cursor string carries it.
 */
export interface PollPosition {
	modified: string;
	ids: number[];
	marker: string | null;
}

export type ChangeOutcome = 'skip' | 'new' | 'edit' | 'remove';

/**
 * What a page needs to apply a shared reply.
 */
export interface SharedPollState {
	cursor: string;
	polledCount: number;
	isCapped: boolean;
}

/**
 * Reads a cursor string, `ids:modified@marker`, as `Poll_Cursor::parse()`
 * does.
 *
 * @param {string} cursor Cursor string.
 * @return {PollPosition} The position it names.
 */
export function parseCursor( cursor: string ): PollPosition {
	const at = cursor.lastIndexOf( '@' );
	const marker = at === -1 ? null : cursor.slice( at + 1 );
	const rest = at === -1 ? cursor : cursor.slice( 0, at );
	const colon = rest.indexOf( ':' );
	const ids = ( colon === -1 ? rest : rest.slice( 0, colon ) )
		.split( ',' )
		.map( ( id ) => parseInt( id, 10 ) )
		.filter( ( id ) => id > 0 );

	return {
		modified: colon === -1 ? '' : rest.slice( colon + 1 ),
		ids: Array.from( new Set( ids ) ).sort( ( a, b ) => a - b ),
		marker,
	};
}

/**
 * Writes a position as a cursor string, as `Poll_Cursor::__toString()` does.
 *
 * @param {PollPosition} position Position.
 * @return {string} Cursor string.
 */
export function formatCursor( position: PollPosition ): string {
	const ids = position.ids.length ? position.ids.join( ',' ) : '0';
	const marker = position.marker === null ? '' : `@${ position.marker }`;

	return `${ ids }:${ position.modified }${ marker }`;
}

/**
 * What a change means to a page at a position.
 *
 * @param {PollPosition}     position The page's position.
 * @param {SharedPollChange} change   A change from a shared reply.
 * @return {ChangeOutcome} Skip it, or apply it as new, an edit, or a removal.
 */
export function classifyChange(
	position: PollPosition,
	change: SharedPollChange
): ChangeOutcome {
	const isHeld = position.ids.includes( change.id );

	if (
		change.modified < position.modified ||
		( change.modified === position.modified && isHeld )
	) {
		return 'skip';
	}

	if ( change.type === 'remove' ) {
		return ( change.unpublished ?? '' ) < position.modified
			? 'skip'
			: 'remove';
	}

	const published = change.published ?? '';

	return published > position.modified ||
		( published === position.modified && ! isHeld )
		? 'new'
		: 'edit';
}

/**
 * The later of two cursors. A cached reply can be older than a page that
 * just loaded, and taking its cursor would make the page's next cursor
 * request send entries again. In the same second the ids merge.
 *
 * @param {string} own   The page's cursor.
 * @param {string} reply The reply's cursor.
 * @return {string} The cursor to keep.
 */
export function laterCursor( own: string, reply: string ): string {
	const mine = parseCursor( own );
	const theirs = parseCursor( reply );

	if ( theirs.modified > mine.modified ) {
		return reply;
	}

	if ( theirs.modified < mine.modified ) {
		return own;
	}

	return formatCursor( {
		modified: theirs.modified,
		ids: Array.from( new Set( [ ...mine.ids, ...theirs.ids ] ) ).sort(
			( a, b ) => a - b
		),
		marker: theirs.marker,
	} );
}

/**
 * Whether the body of a reply to the shared URL is a shared reply. A server
 * without shared polling, such as a build from before it, answers the
 * shared URL with something else, and the page then polls its cursor.
 *
 * @param {unknown} body The reply's body, parsed from JSON.
 * @return {boolean} True for a shared reply.
 */
export function isSharedReply( body: unknown ): body is SharedPollResponse {
	if ( typeof body !== 'object' || body === null ) {
		return false;
	}

	const reply = body as { changes?: unknown; cursor?: unknown };

	return Array.isArray( reply.changes ) && typeof reply.cursor === 'string';
}

/**
 * Turns a shared reply into the reply a cursor poll from the page would
 * have got, or null when the page needs its own cursor reply: it is behind
 * the window, the window overflowed, or a capped feed lost an entry, which
 * only the server can backfill.
 *
 * New entries are counted newest first from the page's own ad count, and
 * every `adsInterval`th keeps its ad, as the server places them on a cursor
 * poll.
 *
 * @param {SharedPollResponse} reply Shared reply.
 * @param {SharedPollState}    state The page's cursor, ad count and cap.
 * @return {PollResponse|null} The reply to apply, or null for the cursor path.
 */
export function readSharedReply(
	reply: SharedPollResponse,
	state: SharedPollState
): PollResponse | null {
	const idle: PollResponse = {
		entries: [],
		cursor: state.cursor,
		overflow: false,
		polledCount: state.polledCount,
		minPollInterval: reply.minPollInterval,
		status: reply.status,
		newestEntry: reply.newestEntry,
		latestBreakoutUrl: reply.latestBreakoutUrl,
	};

	if ( reply.staleTemplate ) {
		return { ...idle, staleTemplate: true };
	}

	const position = parseCursor( state.cursor );

	if (
		position.marker !== null &&
		position.marker === parseCursor( reply.cursor ).marker
	) {
		return idle;
	}

	if ( reply.overflow || position.modified < reply.since ) {
		return null;
	}

	const entries: PollEntry[] = [];
	let added = 0;

	for ( const change of reply.changes ) {
		const outcome = classifyChange( position, change );

		if ( outcome === 'skip' ) {
			continue;
		}

		if ( outcome === 'remove' ) {
			if ( state.isCapped ) {
				return null;
			}

			entries.push( {
				id: change.id,
				html: '',
				type: 'remove',
				adHtml: null,
				adSlot: null,
			} );
			continue;
		}

		const isNew = outcome === 'new';
		let hasAd = false;

		if ( isNew && reply.adsInterval ) {
			added++;
			hasAd = ( state.polledCount + added ) % reply.adsInterval === 0;
		}

		entries.push( {
			id: change.id,
			html: change.html ?? '',
			type: isNew ? 'insert' : 'update',
			adHtml: hasAd ? ( change.adHtml ?? null ) : null,
			adSlot: hasAd ? ( change.adSlot ?? null ) : null,
		} );
	}

	return {
		...idle,
		entries,
		cursor: laterCursor( state.cursor, reply.cursor ),
		polledCount: reply.adsInterval
			? ( state.polledCount + added ) % reply.adsInterval
			: state.polledCount,
	};
}
