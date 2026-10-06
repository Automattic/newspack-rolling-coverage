/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type { EntryName, EntryNameResult } from '../types';

/**
 * Fetches the site's name for entries.
 *
 * @param {string} restUrl Full REST URL for the entry name endpoint.
 * @return {Promise<EntryNameResult>} Result with the name on success or an error on failure.
 */
async function fetchEntryName( restUrl: string ): Promise< EntryNameResult > {
	try {
		const name = await apiFetch< EntryName >( {
			url: restUrl,
			method: 'GET',
		} );
		return { success: true, data: name };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Saves the site's name for entries. Both words empty go back to the
 * built-in wording.
 *
 * @param {string}    restUrl Full REST URL for the entry name endpoint.
 * @param {EntryName} name    The name to save.
 * @return {Promise<EntryNameResult>} Result with the saved name on success or an error on failure.
 */
async function saveEntryName(
	restUrl: string,
	name: EntryName
): Promise< EntryNameResult > {
	try {
		const saved = await apiFetch< EntryName >( {
			url: restUrl,
			method: 'POST',
			data: name,
		} );
		return { success: true, data: saved };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

export { fetchEntryName, saveEntryName };
