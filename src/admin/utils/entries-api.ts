/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type {
	ApiResult,
	Entry,
	AdminConfig,
	BulkRestoreResult,
	BulkRestoreEntryResult,
} from '../types';

interface CreateEntryResult extends ApiResult {
	id?: number;
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
	bulkRestoreEntries,
	hasBreakout,
	hasTrashedBreakout,
	deleteEntry,
	runEntryBulk,
};
