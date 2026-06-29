/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type { ApiResult, SaveCoverageData } from '../types';

/**
 * Updates the rolling_coverage_status meta of a coverage term.
 *
 * @param {string} restBaseCoverages - Full REST URL for the coverages collection (from config.restBaseUrls.coverages).
 * @param {string} statusKey         - The meta key used to store the coverage status.
 * @param {number} id                - The coverage term ID.
 * @param {string} status            - The new status value (active, paused, or archived).
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function updateCoverageStatus(
	restBaseCoverages: string,
	statusKey: string,
	id: number,
	status: string
): Promise< ApiResult > {
	try {
		await apiFetch( {
			url: `${ restBaseCoverages }/${ id }`,
			method: 'POST',
			data: { meta: { [ statusKey ]: status } },
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Creates a new coverage term, or updates an existing one if `id` is provided.
 *
 * @param {string}           restBaseCoverages - Full REST URL for the coverages collection (from config.restBaseUrls.coverages).
 * @param {string}           statusKey         - The meta key used to store the coverage status.
 * @param {SaveCoverageData} data              - The coverage name, description, and status.
 * @param {number}           id                - Optional term ID for updates.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function saveCoverage(
	restBaseCoverages: string,
	statusKey: string,
	data: SaveCoverageData,
	id?: number
): Promise< ApiResult > {
	try {
		const url = id ? `${ restBaseCoverages }/${ id }` : restBaseCoverages;

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

export { updateCoverageStatus, saveCoverage };
