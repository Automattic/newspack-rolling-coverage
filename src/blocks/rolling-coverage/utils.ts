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
} from './config';

/**
 * Searches coverage terms by name.
 *
 * @param {string} search Search string.
 * @return {Promise<CoverageOption[]>} Matching coverages.
 */
async function searchCoverages( search: string ): Promise< CoverageOption[] > {
	const terms = await apiFetch< Array< Record< string, unknown > > >( {
		url: `${ COVERAGES_REST_BASE }?per_page=50&search=${ encodeURIComponent(
			search
		) }`,
	} );

	return terms.map( ( term ) => ( {
		value: String( term.id ),
		label: String( term.name ),
		status:
			( ( term.meta as Record< string, unknown > )?.[
				STATUS_META_KEY
			] as string ) || 'active',
	} ) );
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

export {
	searchCoverages,
	getCoverage,
	updateCoverageStatus,
	fetchEntryPreviewContexts,
};
