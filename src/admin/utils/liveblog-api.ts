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
 * @param {string} restBaseLiveblogs - Full REST URL for the liveblogs collection (from config.restBaseUrls.liveblogs).
 * @param {number} id                - The liveblog term ID.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function deleteLiveblog(
	restBaseLiveblogs: string,
	id: number
): Promise< ApiResult > {
	try {
		await apiFetch( {
			url: `${ restBaseLiveblogs }/${ id }?force=true`, //force=true deletes the liveblog term permanently.
			method: 'DELETE',
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Updates the rolling_coverage_status meta of a liveblog term.
 *
 * @param {string} restBaseLiveblogs - Full REST URL for the liveblogs collection (from config.restBaseUrls.liveblogs).
 * @param {string} statusKey         - The meta key used to store the liveblog status.
 * @param {number} id                - The liveblog term ID.
 * @param {string} status            - The new status value (active, paused, or archived).
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function updateLiveblogStatus(
	restBaseLiveblogs: string,
	statusKey: string,
	id: number,
	status: string
): Promise< ApiResult > {
	try {
		await apiFetch( {
			url: `${ restBaseLiveblogs }/${ id }`,
			method: 'POST',
			data: { meta: { [ statusKey ]: status } },
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Creates a new liveblog term, or updates an existing one if `id` is provided.
 *
 * @param {string}           restBaseLiveblogs - Full REST URL for the liveblogs collection (from config.restBaseUrls.liveblogs).
 * @param {string}           statusKey         - The meta key used to store the liveblog status.
 * @param {SaveLiveblogData} data              - The liveblog name, description, and status.
 * @param {number}           id                - Optional term ID for updates.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function saveLiveblog(
	restBaseLiveblogs: string,
	statusKey: string,
	data: SaveLiveblogData,
	id?: number
): Promise< ApiResult > {
	try {
		const url = id ? `${ restBaseLiveblogs }/${ id }` : restBaseLiveblogs;

		await apiFetch( {
			url,
			method: 'POST',
			data: {
				name: data.name,
				description: data.description,
				meta: { [ statusKey ]: data.status },
			},
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

export { deleteLiveblog, updateLiveblogStatus, saveLiveblog };
