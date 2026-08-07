/**
 * WordPress dependencies
 */
import { useEntityRecords } from '@wordpress/core-data';

/**
 * Internal dependencies
 */
import type { Entry, UseEntriesOptions } from '../types';
import { useAdminContext } from './useAdminContext';

/**
 * Fetches entries for a given coverage via the WordPress core-data layer
 * (useEntityRecords), mirroring the pattern used by useCoverages.
 *
 * Passes context=edit to retrieve all statuses, the rolling-coverage taxonomy
 * filter to scope results to a single coverage, and _embed for author/term data.
 *
 * @param {UseEntriesOptions} options Query options including coverageId, pagination, and filtering.
 *
 * @return {{ records: Entry[] | null, isResolving: boolean, hasResolved: boolean, error: string | null, totalItems: number, totalPages: number }} Entry data and request state.
 */
function useEntries( options: UseEntriesOptions ) {
	const {
		coverageId,
		perPage = 100,
		page = 1,
		search = '',
		orderBy = 'date',
		order = 'desc',
		status,
		refreshKey,
	} = options;

	const config = useAdminContext();

	const query: Record< string, unknown > = {
		per_page: perPage,
		page,
		orderby: orderBy,
		order,
		status: status || 'publish,draft,pending,future,private,trash,archived',
		context: 'edit',
		[ config.restBase.coverages ]: coverageId
			? String( coverageId )
			: undefined,
		_fields:
			'id,title,date,modified,author,status,pinned,meta,categories,tags,_links,_embedded,rolling_coverage_breakout_status',
		_embed: 'author,wp:term',
		_ts: refreshKey,
	};

	if ( search ) {
		query.search = search;
	}

	const { records, isResolving, hasResolved, totalItems, totalPages } =
		useEntityRecords< Entry >( 'postType', config.postType, query );

	const error = hasResolved && ! records ? 'Failed to load entries.' : null;

	return {
		records: records ?? null,
		isResolving,
		hasResolved,
		error,
		totalItems: totalItems ?? 0,
		totalPages: totalPages ?? 0,
	};
}

export { useEntries };
