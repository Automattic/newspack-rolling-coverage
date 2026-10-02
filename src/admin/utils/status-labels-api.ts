/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type { StatusLabels, StatusLabelsResult } from '../types';

/**
 * Fetches the site's status indicator labels.
 *
 * @param {string} restUrl Full REST URL for the status labels endpoint.
 * @return {Promise<StatusLabelsResult>} Result with the labels on success or an error on failure.
 */
async function fetchStatusLabels(
	restUrl: string
): Promise< StatusLabelsResult > {
	try {
		const labels = await apiFetch< StatusLabels >( {
			url: restUrl,
			method: 'GET',
		} );
		return { success: true, data: labels };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Saves the site's status indicator labels. An empty label goes back to the
 * built-in one.
 *
 * @param {string}       restUrl Full REST URL for the status labels endpoint.
 * @param {StatusLabels} labels  The labels to save.
 * @return {Promise<StatusLabelsResult>} Result with the saved labels on success or an error on failure.
 */
async function saveStatusLabels(
	restUrl: string,
	labels: StatusLabels
): Promise< StatusLabelsResult > {
	try {
		const saved = await apiFetch< StatusLabels >( {
			url: restUrl,
			method: 'POST',
			data: labels,
		} );
		return { success: true, data: saved };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

export { fetchStatusLabels, saveStatusLabels };
