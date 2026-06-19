/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type { ApiResult, SaveLiveblogData } from '../types';

/**
 * Permanently deletes a liveblog term via the REST API.
 *
 * @param {number} id - The liveblog term ID.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function deleteLiveblog( id: number ): Promise< ApiResult > {
	try {
		await apiFetch( {
			path: `/wp/v2/rolling-coverage/${ id }?force=true`,
			method: 'DELETE',
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error ) };
	}
}

/**
 * Updates the rolling_coverage_status meta of a liveblog term.
 *
 * @param {number} id     - The liveblog term ID.
 * @param {string} status - The new status value (active, paused, or archived).
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function updateLiveblogStatus(
	id: number,
	status: string
): Promise< ApiResult > {
	try {
		await apiFetch( {
			path: `/wp/v2/rolling-coverage/${ id }`,
			method: 'POST',
			data: { meta: { rolling_coverage_status: status } },
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error ) };
	}
}

/**
 * Creates a new liveblog term, or updates an existing one if `id` is provided.
 *
 * @param {SaveLiveblogData} data - The liveblog name, description, and status.
 * @param {number}           id   - Optional term ID for updates.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function saveLiveblog(
	data: SaveLiveblogData,
	id?: number
): Promise< ApiResult > {
	try {
		const path = id
			? `/wp/v2/rolling-coverage/${ id }`
			: '/wp/v2/rolling-coverage';

		await apiFetch( {
			path,
			method: 'POST',
			data: {
				name: data.name,
				description: data.description,
				meta: { rolling_coverage_status: data.status },
			},
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error ) };
	}
}

export { deleteLiveblog, updateLiveblogStatus, saveLiveblog };
