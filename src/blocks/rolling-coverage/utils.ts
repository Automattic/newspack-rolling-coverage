/**
 * REST helpers for the Rolling Coverage block.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import type { CoverageOption, EntryContext } from './types';
import {
	COVERAGES_REST_BASE,
	STATUS_META_KEY,
	ENTRIES_PREVIEW_REST_BASE,
	AI_ENDPOINT,
} from './config';

/**
 * Searches coverage terms by name.
 *
 * @param {string} search Search string.
 * @return {Promise<CoverageOption[]>} Matching coverages.
 */
async function searchCoverages( search: string ): Promise< CoverageOption[] > {
	try {
		const terms = await apiFetch< Array< Record< string, unknown > > >( {
			url: `${ COVERAGES_REST_BASE }?per_page=50&search=${ encodeURIComponent(
				search
			) }`,
		} );

		return terms
			.map( ( term ) => ( {
				value: String( term.id ),
				label: String( term.name ),
				status:
					( ( term.meta as Record< string, unknown > )?.[
						STATUS_META_KEY
					] as string ) || 'active',
			} ) )
			.filter( ( term ) => term.status !== 'trash' );
	} catch ( error ) {
		return [];
	}
}

/**
 * Fetches a single coverage term by ID.
 *
 * @param {number} id Coverage term ID.
 * @return {Promise<CoverageOption|null>} The coverage, or null if not found.
 */
async function getCoverage( id: number ): Promise< CoverageOption | null > {
	if ( ! id ) {
		return null;
	}

	try {
		const term = await apiFetch< Record< string, unknown > >( {
			url: `${ COVERAGES_REST_BASE }/${ id }`,
		} );

		return {
			value: String( term.id ),
			label: String( term.name ),
			status:
				( ( term.meta as Record< string, unknown > )?.[
					STATUS_META_KEY
				] as string ) || 'active',
		};
	} catch ( error ) {
		return null;
	}
}

/**
 * Updates a coverage term's status.
 *
 * @param {number} id     Coverage term ID.
 * @param {string} status New status value.
 * @return {Promise<boolean>} Whether the update succeeded.
 */
async function updateCoverageStatus(
	id: number,
	status: string
): Promise< boolean > {
	try {
		await apiFetch( {
			url: `${ COVERAGES_REST_BASE }/${ id }`,
			method: 'POST',
			data: { meta: { [ STATUS_META_KEY ]: status } },
		} );
		return true;
	} catch ( error ) {
		return false;
	}
}

/**
 * Fetches the IDs of a coverage's current published entries, newest first,
 * for the editor's per-entry template preview.
 *
 * @param {number} coverageId Coverage term ID.
 * @param {number} perPage    Maximum number of entries to fetch.
 * @return {Promise<EntryContext[]>} Up to `perPage` entries, newest first.
 */
async function fetchEntryPreviewContexts(
	coverageId: number,
	perPage: number
): Promise< EntryContext[] > {
	if ( ! coverageId ) {
		return [];
	}

	try {
		const entries = await apiFetch< Array< { id: number; type: string } > >(
			{
				url: `${ ENTRIES_PREVIEW_REST_BASE }/${ coverageId }/entries-preview?per_page=${ perPage }`,
			}
		);

		return entries.map( ( entry ) => ( {
			postId: entry.id,
			postType: entry.type,
			queryId: 0,
		} ) );
	} catch ( error ) {
		return [];
	}
}

/**
 * Generates key takeaways for a coverage via the AI REST endpoint.
 *
 * Prompts are read from the server-side AI_Settings (manage_options);
 * the client does not send or override them.
 *
 * @param {number} coverageId Coverage term ID.
 * @return {Promise<{success: boolean, result?: string, error?: string}>} Result with generated text or error.
 */
async function generateKeyTakeaways(
	coverageId: number
): Promise< { success: boolean; result?: string; error?: string } > {
	try {
		const response = await apiFetch< { result: string } >( {
			url: `${ AI_ENDPOINT }/${ coverageId }/generate-key-takeaways`,
			method: 'POST',
		} );
		return { success: true, result: response.result };
	} catch ( error ) {
		const err = error as {
			message?: string;
			data?: { message?: string };
		};
		const message = err?.message ?? err?.data?.message ?? 'Unknown error';
		return { success: false, error: message };
	}
}

export {
	searchCoverages,
	getCoverage,
	updateCoverageStatus,
	fetchEntryPreviewContexts,
	generateKeyTakeaways,
};
