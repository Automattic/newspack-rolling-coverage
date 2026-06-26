/**
 * External dependencies
 */
import { useEntityRecords } from '@wordpress/core-data';

/**
 * Internal dependencies
 */
import type { Liveblog, UseLiveblogsOptions } from '../types';
import { useAdminContext } from './useAdminContext';

/**
 * Fetches liveblog terms via the WordPress core-data layer (useEntityRecords).
 * Derives an error state from a null records result after resolution, since
 * useEntityRecords doesn't expose one directly.
 *
 * @param {UseLiveblogsOptions} options Query options including pagination, search, and sorting.
 *
 * @return {{ records: Liveblog[] | null, isResolving: boolean, hasResolved: boolean, error: string | null, totalItems: number, totalPages: number }} Liveblog data and request state.
 */
function useLiveblogs( options: UseLiveblogsOptions = {} ) {
	const {
		perPage = 20,
		page = 1,
		search = '',
		orderBy = 'name',
		order = 'asc',
		refreshKey,
	} = options;

	const query: Record< string, unknown > = {
		per_page: perPage,
		page,
		// WP REST API taxonomy endpoint does not support ordering by date;
		// fall back to ID ordering which approximates creation order.
		orderby: orderBy === 'date' ? 'id' : orderBy,
		order,
		_fields: 'id,name,slug,description,meta,count',
		context: 'edit',
		_ts: refreshKey,
	};

	if ( search ) {
		query.search = search;
	}

	const config = useAdminContext();

	const { records, isResolving, hasResolved, totalItems, totalPages } =
		useEntityRecords( 'taxonomy', config.taxonomy, query );

	const error = hasResolved && ! records ? 'Failed to load liveblogs.' : null;

	return {
		records: records as Liveblog[] | null,
		isResolving,
		hasResolved,
		error,
		totalItems: totalItems ?? 0,
		totalPages: totalPages ?? 0,
	};
}

export { useLiveblogs };
