/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type { ApiResult } from '../types';

/**
 * Permanently deletes an entry via the REST API.
 *
 * @param {string} restBaseEntries - Full REST URL for the entries collection (from config.restBaseUrls.entries).
 * @param {number} id              - The entry post ID.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function deleteEntry(
	restBaseEntries: string,
	id: number
): Promise< ApiResult > {
	try {
		await apiFetch( {
			url: `${ restBaseEntries }/${ id }?force=true`, //force=true deletes the entry permanently.
			method: 'DELETE',
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

export { deleteEntry };
