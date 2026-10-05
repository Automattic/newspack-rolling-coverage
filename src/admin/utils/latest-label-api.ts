/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type { LatestLabel, LatestLabelResult } from '../types';

/**
 * Fetches the site's "Jump to Latest" label.
 *
 * @param {string} restUrl Full REST URL for the latest label endpoint.
 * @return {Promise<LatestLabelResult>} Result with the label on success or an error on failure.
 */
async function fetchLatestLabel(
	restUrl: string
): Promise< LatestLabelResult > {
	try {
		const label = await apiFetch< LatestLabel >( {
			url: restUrl,
			method: 'GET',
		} );
		return { success: true, data: label };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Saves the site's "Jump to Latest" label. An empty label goes back to the
 * built-in one.
 *
 * @param {string}      restUrl Full REST URL for the latest label endpoint.
 * @param {LatestLabel} label   The label to save.
 * @return {Promise<LatestLabelResult>} Result with the saved label on success or an error on failure.
 */
async function saveLatestLabel(
	restUrl: string,
	label: LatestLabel
): Promise< LatestLabelResult > {
	try {
		const saved = await apiFetch< LatestLabel >( {
			url: restUrl,
			method: 'POST',
			data: label,
		} );
		return { success: true, data: saved };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

export { fetchLatestLabel, saveLatestLabel };
