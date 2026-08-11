/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type { ApiResult } from '../types';

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

export { createEntry, togglePinEntry };
