/**
 * WordPress dependencies
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
 * @param {number} id - The entry post ID.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function deleteEntry( id: number ): Promise< ApiResult > {
	try {
		await apiFetch( {
			path: `/wp/v2/rolling-coverage-entries/${ id }?force=true`,
			method: 'DELETE',
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error ) };
	}
}

export { deleteEntry };
