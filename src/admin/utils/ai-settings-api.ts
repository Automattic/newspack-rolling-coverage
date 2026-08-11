/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type { AiSettings, ApiResult } from '../types';

interface AiSettingsResult extends ApiResult {
	data?: AiSettings;
}

/**
 * Fetches AI prompt settings from the REST API.
 *
 * @param {string} restUrl - Full REST URL for the AI settings endpoint.
 * @return {Promise<AiSettingsResult>} Result with settings on success or error on failure.
 */
async function fetchAiSettings( restUrl: string ): Promise< AiSettingsResult > {
	try {
		const settings = await apiFetch< AiSettings >( {
			url: restUrl,
			method: 'GET',
		} );
		return { success: true, data: settings };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Saves AI prompt settings via the REST API.
 *
 * @param {string}     restUrl  - Full REST URL for the AI settings endpoint.
 * @param {AiSettings} settings - The settings to save.
 * @return {Promise<AiSettingsResult>} Result with updated settings on success or error on failure.
 */
async function saveAiSettings(
	restUrl: string,
	settings: AiSettings
): Promise< AiSettingsResult > {
	try {
		const updated = await apiFetch< AiSettings >( {
			url: restUrl,
			method: 'POST',
			data: settings,
		} );
		return { success: true, data: updated };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

export { fetchAiSettings, saveAiSettings };
