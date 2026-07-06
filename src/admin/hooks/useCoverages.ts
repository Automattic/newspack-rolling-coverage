/**
 * External dependencies
 */
import { useEntityRecords } from '@wordpress/core-data';

/**
 * Internal dependencies
 */
import type { Coverage, UseCoveragesOptions } from '../types';
import { useAdminContext } from './useAdminContext';

/**
 * Fetches coverage terms via the WordPress core-data layer (useEntityRecords).
 * Derives an error state from a null records result after resolution, since
 * useEntityRecords doesn't expose one directly.
 *
 * @param {UseCoveragesOptions} options Query options including pagination, search, and refresh.
 *
 * @return {{ records: Coverage[] | null, isResolving: boolean, hasResolved: boolean, error: string | null, totalItems: number, totalPages: number }} Coverage data and request state.
 */
function useCoverages( options: UseCoveragesOptions = {} ) {
	const { perPage = 100, page = 1, search = '', refreshKey } = options;

	const query: Record< string, unknown > = {
		per_page: perPage,
		page,
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

	const error = hasResolved && ! records ? 'Failed to load coverages.' : null;

	return {
		records: records as Coverage[] | null,
		isResolving,
		hasResolved,
		error,
		totalItems: totalItems ?? 0,
		totalPages: totalPages ?? 0,
	};
}

export { useCoverages };
