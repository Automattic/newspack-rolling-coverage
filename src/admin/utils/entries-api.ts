/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type {
	CreateEntryResult,
	Entry,
	EntrySyncDelta,
	EntryViewRow,
	SyncNotice,
	SyncNoticeEntry,
	SyncPollContext,
	TogglePinResult,
} from '../types';

const SYNC_INTERVAL_MS = 30000;

/**
 * Creates a draft entry assigned to a coverage term, returning the new
 * post ID so the caller can redirect to the classic editor.
 *
 * @param {string} restBaseEntries  - Full REST URL for the entries collection (from config.restBaseUrls.entries).
 * @param {string} coverageRestBase - The coverage taxonomy REST base slug (from config.restBase.coverages), used as the POST body key.
 * @param {number} coverageId       - The coverage term ID to assign.
 * @return {Promise<CreateEntryResult>} Result indicating success (with post ID) or failure.
 */
async function createEntry(
	restBaseEntries: string,
	coverageRestBase: string,
	coverageId: number
): Promise< CreateEntryResult > {
	try {
		const data: Record< string, unknown > = {
			status: 'draft',
		};
		data[ coverageRestBase ] = [ coverageId ];

		const post = await apiFetch< { id: number } >( {
			url: restBaseEntries,
			method: 'POST',
			data,
		} );

		return { success: true, id: post.id };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Maps an `EntryViewRow` (the flat shape returned by the entries-view endpoint)
 * back into the legacy `Entry` shape expected by the field definitions and
 * action handlers, which are not modified for this change.
 *
 * @param {EntryViewRow} row The flat row from the endpoint.
 * @return {Entry} A legacy-shaped entry with embedded/meta fields reconstructed.
 */
function toEntry( row: EntryViewRow ): Entry {
	return {
		id: row.id,
		date: row.date,
		date_gmt: row.date,
		modified: row.modified,
		modified_gmt: row.modified,
		slug: '',
		status: row.status,
		type: 'rolling_cov_entry',
		link: '',
		title: { rendered: row.title },
		content: { rendered: '' },
		author: row.author?.id ?? 0,
		meta: {
			rolling_coverage_breakout_post_id:
				row.breakout_post_id || undefined,
			rolling_coverage_entry_source: row.source,
		},
		rolling_coverage_breakout_status: row.breakout_status,
		_embedded: {
			author: row.author ? [ row.author ] : undefined,
			'wp:term': [
				row.categories.map( ( c ) => ( {
					...c,
					taxonomy: 'category',
				} ) ),
				row.tags.map( ( t ) => ( { ...t, taxonomy: 'post_tag' } ) ),
			],
		},
	};
}

/**
 * Builds the page-mode REST URL with query parameters.
 *
 * @param {string} baseUrl    The entries-view base URL.
 * @param {number} coverageId The coverage term ID.
 * @param {number} page       The page number.
 * @param {number} perPage    Items per page.
 * @param {string} orderBy    Sort field.
 * @param {string} order      Sort direction.
 * @param {string} search     Optional search term.
 * @return {string} The full REST URL.
 */
function buildPageUrl(
	baseUrl: string,
	coverageId: number,
	page: number,
	perPage: number,
	orderBy: string,
	order: string,
	search: string
): string {
	const params = new URLSearchParams();
	params.set( 'page', String( page ) );
	params.set( 'per_page', String( perPage ) );
	params.set( 'orderby', orderBy );
	params.set( 'order', order );
	if ( search ) {
		params.set( 'search', search );
	}
	return `${ baseUrl }/${ coverageId }/entries-view?${ params.toString() }`;
}

/**
 * Builds the sync-mode REST URL with the cursor.
 *
 * @param {string} baseUrl    The entries-view base URL.
 * @param {number} coverageId The coverage term ID.
 * @param {string} cursor     The since cursor (ISO-8601).
 * @param {number} perPage    The per_page limit.
 * @return {string} The full REST URL.
 */
function buildSyncUrl(
	baseUrl: string,
	coverageId: number,
	cursor: string,
	perPage: number
): string {
	const params = new URLSearchParams();
	params.set( 'since', cursor );
	params.set( 'per_page', String( perPage ) );
	return `${ baseUrl }/${ coverageId }/entries-view?${ params.toString() }`;
}

/**
 * Extracts the minimal fields needed for snackbar display from a row.
 *
 * @param {EntryViewRow} row The full row from the endpoint.
 * @return {SyncNoticeEntry} The trimmed notice entry.
 */
function toNoticeEntry( row: EntryViewRow ): SyncNoticeEntry {
	return {
		id: row.id,
		title: row.title,
		status: row.status,
		source: row.source,
	};
}

/**
 * Classifies sync delta into added/updated/removed notice groups.
 *
 * The server includes `trash` in the sync status filter, so trashed entries
 * arrive in `changed` with `status === 'trash'`. Those that previously existed
 * in `previousRows` are classified as "removed"; new entries are "added";
 * existing entries with a non-trash status are "updated".
 *
 * @param {EntrySyncDelta} delta        The sync delta from the endpoint.
 * @param {EntryViewRow[]} previousRows The rows before this sync cycle.
 * @return {SyncNotice[]} Notice groups with zero-count types omitted.
 */
function buildSyncNotices(
	delta: EntrySyncDelta,
	previousRows: EntryViewRow[]
): SyncNotice[] {
	const previousIds = new Set( previousRows.map( ( row ) => row.id ) );

	const added: SyncNoticeEntry[] = [];
	const updated: SyncNoticeEntry[] = [];
	const removed: SyncNoticeEntry[] = [];
	for ( const row of delta.changed ) {
		if ( row.status === 'trash' ) {
			if ( previousIds.has( row.id ) ) {
				removed.push( toNoticeEntry( row ) );
			}
			continue;
		}
		if ( previousIds.has( row.id ) ) {
			updated.push( toNoticeEntry( row ) );
		} else {
			added.push( toNoticeEntry( row ) );
		}
	}

	const notices: SyncNotice[] = [];
	if ( added.length > 0 ) {
		notices.push( { type: 'added', count: added.length, entries: added } );
	}
	if ( updated.length > 0 ) {
		notices.push( {
			type: 'updated',
			count: updated.length,
			entries: updated,
		} );
	}
	if ( removed.length > 0 ) {
		notices.push( {
			type: 'removed',
			count: removed.length,
			entries: removed,
		} );
	}
	return notices;
}

/**
 * Merges a sync delta into existing rows: replaces updated entries in place,
 * drops trashed entries, and prepends new entries on page 1 only.
 *
 * Trashed entries arrive in `changed` with `status === 'trash'` (the server
 * includes `trash` in the sync status filter) and are removed from the row
 * set. On page > 1, new entries are dropped from the result (they belong on
 * an earlier page under date-DESC sort) — the snackbar still informs the user.
 *
 * @param {EntryViewRow[] | null} prev  The current rows (null = not loaded).
 * @param {EntrySyncDelta}        delta The sync delta from the endpoint.
 * @param {number}                page  The current page number.
 * @return {EntryViewRow[] | null} The merged rows.
 */
function mergeSyncDelta(
	prev: EntryViewRow[] | null,
	delta: EntrySyncDelta,
	page: number
): EntryViewRow[] | null {
	if ( prev === null ) {
		return prev;
	}

	const mergedById = new Map< number, EntryViewRow >();
	for ( const row of prev ) {
		mergedById.set( row.id, row );
	}

	const added: EntryViewRow[] = [];
	for ( const row of delta.changed ) {
		if ( row.status === 'trash' ) {
			mergedById.delete( row.id );
			continue;
		}
		if ( mergedById.has( row.id ) ) {
			mergedById.set( row.id, row );
		} else {
			added.push( row );
		}
	}

	if ( page > 1 ) {
		return [ ...mergedById.values() ];
	}
	return [ ...added, ...mergedById.values() ];
}

/**
 * Executes a single sync poll: fetches the delta from the endpoint, merges
 * it into the rows state, and dispatches snackbar notices.
 *
 * @param {SyncPollContext} ctx The shared context for the poll.
 * @return {Promise<void>} Resolves when the poll completes or is skipped.
 */
async function pollSync( ctx: SyncPollContext ): Promise< void > {
	const {
		baseUrl,
		perPage,
		cursorRef,
		coverageIdRef,
		rowsRef,
		pageRef,
		isMountedRef,
		setRows,
		setSyncNotices,
	} = ctx;

	const currentCoverageId = coverageIdRef.current;
	const currentCursor = cursorRef.current;

	if ( currentCoverageId === null || ! currentCursor ) {
		return;
	}

	const url = buildSyncUrl(
		baseUrl,
		currentCoverageId,
		currentCursor,
		perPage
	);

	try {
		const delta = await apiFetch< EntrySyncDelta >( {
			url,
			method: 'GET',
		} );

		if ( ! isMountedRef.current ) {
			return;
		}

		cursorRef.current = delta.cursor;

		if ( delta.changed.length === 0 ) {
			return;
		}

		const previousRows = rowsRef.current ?? [];
		const notices = buildSyncNotices( delta, previousRows );

		setRows( ( prev ) => {
			const next = mergeSyncDelta( prev, delta, pageRef.current );
			rowsRef.current = next;
			return next;
		} );

		setSyncNotices( notices );
	} catch {
		// Silent: transient sync errors are retried on the next interval.
	}
}

/**
 * Toggles the pinned status of an entry.
 *
 * @param {string} restNamespace - REST namespace URL (from config.restBaseUrls.restNamespace).
 * @param {number} entryId       - The entry ID to toggle.
 * @return {Promise<TogglePinResult>} Result indicating success (with new pinned state) or failure.
 */
async function togglePinEntry(
	restNamespace: string,
	entryId: number
): Promise< TogglePinResult > {
	try {
		const response = await apiFetch< { pinned: boolean } >( {
			url: `${ restNamespace }entries/${ entryId }/pin`,
			method: 'POST',
		} );

		return { success: true, pinned: response.pinned };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

export {
	createEntry,
	toEntry,
	buildPageUrl,
	buildSyncUrl,
	buildSyncNotices,
	mergeSyncDelta,
	pollSync,
	togglePinEntry,
	SYNC_INTERVAL_MS,
};
