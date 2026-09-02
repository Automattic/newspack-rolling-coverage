/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type {
	ApiResult,
	Entry,
	EntryEditWarning,
	AdminConfig,
	BulkRestoreResult,
	BulkRestoreEntryResult,
} from '../types';

interface CreateEntryResult extends ApiResult {
	id?: number;
}

interface TogglePinResult extends ApiResult {
	pinned?: boolean;
}

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
 * Returns true when the entry is individually archived.
 *
 * @param {Entry} entry The entry to check.
 * @return {boolean} Whether the entry is archived.
 */
function isEntryArchived( entry: Entry ): boolean {
	return entry.status === 'archived';
}

/**
 * Returns true when the entry is locked by Archive Mode: individually
 * archived, or assigned to an archived coverage.
 *
 * @param {Entry} entry The entry to check.
 * @return {boolean} Whether the entry is locked.
 */
function isEntryLocked( entry: Entry ): boolean {
	return isEntryArchived( entry ) || entry.coverageStatus === 'archived';
}

/**
 * Returns why editing this entry needs confirmation, or null when
 * unrestricted.
 *
 * @param {Entry} entry The entry to check.
 * @return {EntryEditWarning} The reason, or null.
 */
function getEntryEditWarning( entry: Entry ): EntryEditWarning {
	if ( isEntryArchived( entry ) ) {
		return 'entry-archived';
	}

	if ( entry.coverageStatus === 'archived' ) {
		return 'coverage-archived';
	}

	if ( entry.coverageStatus === 'paused' ) {
		return 'coverage-paused';
	}

	return null;
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

/**
 * Archives or unarchives a single entry via the dedicated Archive Mode
 * REST route.
 *
 * @param {string}  restNamespace - REST namespace URL.
 * @param {number}  entryId       - Entry post ID.
 * @param {boolean} archived      - Whether to archive (true) or unarchive (false).
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function setEntryArchived(
	restNamespace: string,
	entryId: number,
	archived: boolean
): Promise< ApiResult > {
	try {
		await apiFetch( {
			url: `${ restNamespace }entries/${ entryId }/archive`,
			method: 'POST',
			data: { archived },
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Runs a bulk archive or unarchive against the supplied entries,
 * aggregating per-item results.
 *
 * @param {string}  restNamespace - REST namespace URL.
 * @param {Entry[]} items         - The selected entry rows.
 * @param {boolean} archived      - Whether to archive (true) or unarchive (false).
 * @return {Promise<{ failed: ApiResult[], succeeded: boolean }>} Aggregated outcome.
 */
async function runArchiveBulk(
	restNamespace: string,
	items: Entry[],
	archived: boolean
): Promise< { failed: ApiResult[]; succeeded: boolean } > {
	const results = await Promise.all(
		items.map( ( entry ) =>
			setEntryArchived( restNamespace, entry.id, archived )
		)
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
	isEntryArchived,
	isEntryLocked,
	getEntryEditWarning,
	deleteEntry,
	runEntryBulk,
	setEntryArchived,
	runArchiveBulk,
};
