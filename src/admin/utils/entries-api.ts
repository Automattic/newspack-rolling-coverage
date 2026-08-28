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
	ApiResult,
	AdminConfig,
	BulkRestoreResult,
	BulkRestoreEntryResult,
} from '../types';

const SYNC_INTERVAL_MS = 10000;

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
		pinned: row.pinned,
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
 * @param {string} baseUrl                 The entries-view base URL.
 * @param {number} coverageId              The coverage term ID.
 * @param {number} page                    The page number.
 * @param {number} perPage                 Items per page.
 * @param {string} orderBy                 Sort field.
 * @param {string} order                   Sort direction.
 * @param {string} search                  Optional search term.
 * @param {string} [status]                Optional CSV of post statuses to include.
 * @param {string} [statusExclude]         Optional CSV of post statuses to exclude.
 * @param {string} [source]                Optional source slug to include.
 * @param {string} [sourceExclude]         Optional source slug to exclude.
 * @param {string} [author]                Optional author name to search.
 * @param {string} [title]                 Optional title substring to search.
 * @param {string} [postId]                Optional post ID to match.
 * @param {string} [breakoutStatus]        Optional breakout status to include.
 * @param {string} [breakoutStatusExclude] Optional breakout status to exclude.
 * @param {string} [categorySearch]        Optional category name substring to match.
 * @param {string} [tagSearch]             Optional tag name substring to match.
 * @return {string} The full REST URL.
 */
function buildPageUrl(
	baseUrl: string,
	coverageId: number,
	page: number,
	perPage: number,
	orderBy: string,
	order: string,
	search: string,
	status?: string,
	statusExclude?: string,
	source?: string,
	sourceExclude?: string,
	author?: string,
	title?: string,
	postId?: string,
	breakoutStatus?: string,
	breakoutStatusExclude?: string,
	categorySearch?: string,
	tagSearch?: string
): string {
	const params = new URLSearchParams();
	params.set( 'page', String( page ) );
	params.set( 'per_page', String( perPage ) );
	params.set( 'orderby', orderBy );
	params.set( 'order', order );

	// Optional filters: only set params for present values.
	const optionalParams: Array< [ string, string | undefined ] > = [
		[ 'search', search ],
		[ 'status', status ],
		[ 'status_exclude', statusExclude ],
		[ 'source', source ],
		[ 'source_exclude', sourceExclude ],
		[ 'author', author ],
		[ 'title', title ],
		[ 'post_id', postId ],
		[ 'breakout_status', breakoutStatus ],
		[ 'breakout_status_exclude', breakoutStatusExclude ],
		[ 'category_search', categorySearch ],
		[ 'tag_search', tagSearch ],
	];

	for ( const [ key, value ] of optionalParams ) {
		if ( value ) {
			params.set( key, value );
		}
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
 * Classifies sync delta into added/updated notice groups.
 *
 * The server stamps each row with `change_type: 'new'|'update'`.
 *
 * @param {EntrySyncDelta} delta The sync delta from the endpoint.
 * @return {SyncNotice[]} Notice groups with zero-count types omitted.
 */
function buildSyncNotices( delta: EntrySyncDelta ): SyncNotice[] {
	const added: SyncNoticeEntry[] = [];
	const updated: SyncNoticeEntry[] = [];
	for ( const row of delta.changed ) {
		if ( row.change_type === 'new' ) {
			added.push( toNoticeEntry( row ) );
		} else {
			updated.push( toNoticeEntry( row ) );
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
	return notices;
}

/**
 * Merges a sync delta into existing rows: replaces updated entries in place,
 * and prepends new entries on page 1 only.
 *
 * On page > 1, new entries are dropped from the result (they belong on
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
	let changedOnThisPage = false;
	for ( const row of delta.changed ) {
		if ( mergedById.has( row.id ) ) {
			mergedById.set( row.id, row );
			changedOnThisPage = true;
		} else {
			added.push( row );
		}
	}

	// On deep pages, don't mutate visible rows for changes on other pages.
	if ( page > 1 ) {
		return changedOnThisPage ? [ ...mergedById.values() ] : prev;
	}
	if ( added.length === 0 && ! changedOnThisPage ) {
		return prev;
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

		// Reload the page when too many entries changed since the last poll.
		if ( delta.overflow ) {
			window.location.reload();
			return;
		}

		if ( delta.changed.length === 0 ) {
			return;
		}

		const notices = buildSyncNotices( delta );

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
 * Bulk restores trashed entries in a single request so that recovery
 * term creation is atomic — entries from the same deleted coverage
 * share one recovery term.
 *
 * @param {string}   restNamespace - REST namespace URL.
 * @param {number[]} entryIds      - Array of entry post IDs.
 * @return {Promise<BulkRestoreResult>} Result with per-entry outcomes or error.
 */
async function bulkRestoreEntries(
	restNamespace: string,
	entryIds: number[]
): Promise< BulkRestoreResult > {
	try {
		const response = await apiFetch< {
			results: BulkRestoreEntryResult[];
		} >( {
			url: `${ restNamespace }entries/restore`,
			method: 'POST',
			data: { entry_ids: entryIds },
		} );

		return { success: true, results: response.results };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
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

/**
 * Returns true when the entry has an active (non-trashed) breakout post.
 *
 * @param {Entry} entry The entry to check.
 * @return {boolean} Whether the entry has an active breakout post.
 */
function hasBreakout( entry: Entry ): boolean {
	return (
		Boolean( entry.rolling_coverage_breakout_status ) &&
		entry.rolling_coverage_breakout_status !== 'trash'
	);
}

/**
 * Returns true when the entry has a breakout post that is in the trash.
 *
 * @param {Entry} entry The entry to check.
 * @return {boolean} Whether the entry has a trashed breakout post.
 */
function hasTrashedBreakout( entry: Entry ): boolean {
	return entry.rolling_coverage_breakout_status === 'trash';
}

/**
 * Sends a DELETE request for a single entry, returning a normalised
 * ApiResult. When `force` is truthy the entry is permanently deleted;
 * otherwise it is moved to the trash.
 *
 * @param {AdminConfig} config Admin config providing the entries REST base.
 * @param {Entry}       entry  The entry row being operated on.
 * @param {boolean}     force  Whether to bypass the trash (permanent delete).
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function deleteEntry(
	config: AdminConfig,
	entry: Entry,
	force: boolean
): Promise< ApiResult > {
	const query = force ? '?force=true' : '';
	try {
		await apiFetch( {
			path: `/wp/v2/${ config.restBase.entries }/${ entry.id }${ query }`,
			method: 'DELETE',
		} );
		return { success: true };
	} catch ( error ) {
		return {
			success: false,
			error: handleApiError( error as Error ),
		};
	}
}

/**
 * Runs a bulk trash or permanent-delete against the supplied entries,
 * aggregating per-item results. Used by both the RenderModal `onConfirm`
 * and the action `callback` so the two code paths stay in lock-step.
 *
 * @param {AdminConfig} config Admin config providing the entries REST base.
 * @param {Entry[]}     items  The selected entry rows.
 * @param {boolean}     force  Whether to bypass the trash (permanent delete).
 * @return {Promise<{ failed: ApiResult[], succeeded: boolean }>} Aggregated outcome.
 */
async function runEntryBulk(
	config: AdminConfig,
	items: Entry[],
	force: boolean
): Promise< { failed: ApiResult[]; succeeded: boolean } > {
	const results = await Promise.all(
		items.map( ( entry ) => deleteEntry( config, entry, force ) )
	);
	const failed = results.filter( ( r ) => ! r.success );
	return { failed, succeeded: failed.length === 0 };
}

export {
	createEntry,
	togglePinEntry,
	bulkRestoreEntries,
	hasBreakout,
	hasTrashedBreakout,
	deleteEntry,
	runEntryBulk,
	toEntry,
	buildPageUrl,
	buildSyncUrl,
	buildSyncNotices,
	mergeSyncDelta,
	pollSync,
	SYNC_INTERVAL_MS,
};
