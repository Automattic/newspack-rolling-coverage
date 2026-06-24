/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { handleApiError } from './api-error';
import type {
	ApiResult,
	CreateBreakoutResponse,
	CreateBreakoutResult,
} from '../types';

/**
 * Creates a breakout post from an entry.
 *
 * @param {string} restBaseBreakout - Full REST URL for the breakout route (from config.restBaseUrls.breakout).
 * @param {number} entryId          - The entry post ID to break out.
 * @return {Promise<CreateBreakoutResult>} Result indicating success or failure, with the new post's info on success.
 */
async function createBreakout(
	restBaseBreakout: string,
	entryId: number
): Promise< CreateBreakoutResult > {
	try {
		const response = await apiFetch< CreateBreakoutResponse >( {
			url: `${ restBaseBreakout }/${ entryId }/breakout`,
			method: 'POST',
		} );
		return { success: true, data: response };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

/**
 * Saves the breakout "read more" text on an entry.
 *
 * @param {string} restBaseEntries - Full REST URL for the entries collection (from config.restBaseUrls.entries).
 * @param {number} entryId         - The entry post ID.
 * @param {string} readMoreText    - The configured "read more" link text.
 * @return {Promise<ApiResult>} Result indicating success or failure.
 */
async function saveBreakoutSettings(
	restBaseEntries: string,
	entryId: number,
	readMoreText: string
): Promise< ApiResult > {
	try {
		await apiFetch( {
			url: `${ restBaseEntries }/${ entryId }`,
			method: 'POST',
			data: {
				meta: {
					rolling_coverage_breakout_read_more_text: readMoreText,
				},
			},
		} );
		return { success: true };
	} catch ( error ) {
		return { success: false, error: handleApiError( error as Error ) };
	}
}

export { createBreakout, saveBreakoutSettings };
