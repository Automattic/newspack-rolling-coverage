/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type { ApiResult, Coverage, SaveCoverageData } from '../types';

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
 * @param {string}           canonicalUrlKey   - The meta key used to store the coverage's canonical URL.
 * @param {SaveCoverageData} data              - The coverage name, description, status, and canonical URL.
 * @param {number}           id                - Optional term ID for updates.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function saveCoverage(
	restBaseCoverages: string,
	statusKey: string,
	canonicalUrlKey: string,
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
				meta: {
					[ statusKey ]: data.status,
					[ canonicalUrlKey ]: data.canonicalUrl,
				},
			},
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Fetches a single coverage term by ID via the REST API. Used as a fallback
 * when the coverage is not already present in context state (e.g. on a
 * direct deep-link to the entries view).
 *
 * @param {string} restBaseCoverages - Full REST URL for the coverages collection (from config.restBaseUrls.coverages).
 * @param {number} id                - The coverage term ID.
 * @return {Promise<Coverage | null>} The coverage term, or null on failure.
 */
async function getCoverage(
	restBaseCoverages: string,
	id: number
): Promise< Coverage | null > {
	try {
		return await apiFetch< Coverage >( {
			url: `${ restBaseCoverages }/${ id }`,
			method: 'GET',
		} );
	} catch ( error ) {
		return null;
	}
}

/**
 * Soft-deletes a coverage by setting its status to 'trash' and trashing
 * all its non-trashed entries.
 *
 * @param {string} restNamespace - REST namespace URL (from config.restBaseUrls.restNamespace).
 * @param {number} id            - The coverage term ID.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function trashCoverage(
	restNamespace: string,
	id: number
): Promise< ApiResult > {
	try {
		await apiFetch( {
			url: `${ restNamespace }coverages/${ id }/trash`,
			method: 'POST',
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Restores a coverage from trash, setting its status back to 'active'.
 * Entries remain trashed and must be restored individually.
 *
 * @param {string} restNamespace - REST namespace URL.
 * @param {number} id            - The coverage term ID.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function restoreCoverage(
	restNamespace: string,
	id: number
): Promise< ApiResult > {
	try {
		await apiFetch( {
			url: `${ restNamespace }coverages/${ id }/restore`,
			method: 'POST',
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Permanently deletes a coverage term. Entries remain in trash
 * with their post-meta context intact.
 *
 * @param {string} restNamespace - REST namespace URL.
 * @param {number} id            - The coverage term ID.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function deleteCoverage(
	restNamespace: string,
	id: number
): Promise< ApiResult > {
	try {
		await apiFetch( {
			url: `${ restNamespace }coverages/${ id }`,
			method: 'DELETE',
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Runs a bulk operation against a list of coverage IDs, collecting per-item
 * results. Used by both the RenderModal `onConfirm` and the action `callback`
 * so the two code paths stay in lock-step.
 *
 * @param {Coverage[]}                         items     The selected coverage rows.
 * @param {(id: number) => Promise<ApiResult>} operation Single-item API call.
 * @return {Promise<{ failed: ApiResult[], succeeded: boolean }>} Aggregated outcome.
 */
async function runCoverageBulk(
	items: Coverage[],
	operation: ( id: number ) => Promise< ApiResult >
): Promise< { failed: ApiResult[]; succeeded: boolean } > {
	const results = await Promise.all(
		items.map( ( coverage ) => operation( coverage.id ) )
	);
	const failed = results.filter( ( r ) => ! r.success );
	return { failed, succeeded: failed.length === 0 };
}

export {
	updateCoverageStatus,
	saveCoverage,
	getCoverage,
	trashCoverage,
	restoreCoverage,
	deleteCoverage,
	runCoverageBulk,
};
