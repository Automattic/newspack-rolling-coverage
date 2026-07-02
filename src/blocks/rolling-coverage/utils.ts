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
import type { LiveblogOption, EntryContext } from './types';
import {
	LIVEBLOGS_REST_BASE,
	STATUS_META_KEY,
	ENTRIES_PREVIEW_REST_BASE,
} from './config';

/**
 * Searches liveblog terms by name.
 *
 * @param {string} search Search string.
 * @return {Promise<LiveblogOption[]>} Matching liveblogs.
 */
async function searchLiveblogs( search: string ): Promise< LiveblogOption[] > {
	const terms = await apiFetch< Array< Record< string, unknown > > >( {
		url: `${ LIVEBLOGS_REST_BASE }?per_page=50&search=${ encodeURIComponent(
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
 * Fetches a single liveblog term by ID.
 *
 * @param {number} id Liveblog term ID.
 * @return {Promise<LiveblogOption|null>} The liveblog, or null if not found.
 */
async function fetchLiveblog( id: number ): Promise< LiveblogOption | null > {
	if ( ! id ) {
		return null;
	}

	try {
		const term = await apiFetch< Record< string, unknown > >( {
			url: `${ LIVEBLOGS_REST_BASE }/${ id }`,
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
 * Updates a liveblog term's status.
 *
 * @param {number} id     Liveblog term ID.
 * @param {string} status New status value.
 * @return {Promise<boolean>} Whether the update succeeded.
 */
async function updateLiveblogStatus(
	id: number,
	status: string
): Promise< boolean > {
	try {
		await apiFetch( {
			url: `${ LIVEBLOGS_REST_BASE }/${ id }`,
			method: 'POST',
			data: { meta: { [ STATUS_META_KEY ]: status } },
		} );
		return true;
	} catch ( error ) {
		return false;
	}
}

/**
 * Fetches the IDs of a liveblog's current published entries, newest first,
 * for the editor's per-entry template preview.
 *
 * @param {number} liveblogId Liveblog term ID.
 * @param {number} perPage    Maximum number of entries to fetch.
 * @return {Promise<EntryContext[]>} Up to `perPage` entries, newest first.
 */
async function fetchEntryPreviewContexts(
	liveblogId: number,
	perPage: number
): Promise< EntryContext[] > {
	if ( ! liveblogId ) {
		return [];
	}

	try {
		const entries = await apiFetch< Array< { id: number; type: string } > >(
			{
				url: `${ ENTRIES_PREVIEW_REST_BASE }/${ liveblogId }/entries-preview?per_page=${ perPage }`,
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
	searchLiveblogs,
	fetchLiveblog,
	updateLiveblogStatus,
	fetchEntryPreviewContexts,
};
