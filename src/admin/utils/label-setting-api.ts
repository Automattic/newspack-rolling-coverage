/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type { LabelSetting, LabelSettingResult } from '../types';

/**
 * Fetches one of the site's labels, such as the "Jump to Latest" button's.
 *
 * @param {string} restUrl Full REST URL for the label's endpoint.
 * @return {Promise<LabelSettingResult>} Result with the label on success or an error on failure.
 */
async function fetchLabelSetting(
	restUrl: string
): Promise< LabelSettingResult > {
	try {
		const label = await apiFetch< LabelSetting >( {
			url: restUrl,
			method: 'GET',
		} );
		return { success: true, data: label };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Saves one of the site's labels. An empty label goes back to the built-in
 * one.
 *
 * @param {string}       restUrl Full REST URL for the label's endpoint.
 * @param {LabelSetting} label   The label to save.
 * @return {Promise<LabelSettingResult>} Result with the saved label on success or an error on failure.
 */
async function saveLabelSetting(
	restUrl: string,
	label: LabelSetting
): Promise< LabelSettingResult > {
	try {
		const saved = await apiFetch< LabelSetting >( {
			url: restUrl,
			method: 'POST',
			data: label,
		} );
		return { success: true, data: saved };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

export { fetchLabelSetting, saveLabelSetting };
