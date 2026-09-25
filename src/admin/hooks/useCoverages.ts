/**
 * External dependencies
 */
import { useEntityRecords } from '@wordpress/core-data';
import { useState, useEffect, useRef } from '@wordpress/element';

/** Debounce delay (ms) for search input. */
const DEBOUNCE_MS = 500;

/**
 * Upper bound for the coverage list fetch. The coverage view applies
 * sorting, filtering, and pagination client-side, so the full set must be
 * retrieved in one request. Taxonomy terms are a bounded dataset.
 */
const COVERAGE_FETCH_MAX = 100;

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
 * @return {{ records: Coverage[] | null, isResolving: boolean, hasResolved: boolean, hasLoadedOnce: boolean, error: string | null, totalItems: number, totalPages: number }} Coverage data and request state.
 */
function useCoverages( options: UseCoveragesOptions = {} ) {
	const {
		perPage = COVERAGE_FETCH_MAX,
		page = 1,
		search = '',
		refreshKey,
	} = options;

	// Debounce search so rapid typing doesn't fire a request per character.
	const [ debouncedSearch, setDebouncedSearch ] = useState( search );
	useEffect( () => {
		const timer = setTimeout(
			() => setDebouncedSearch( search ),
			DEBOUNCE_MS
		);
		return () => clearTimeout( timer );
	}, [ search ] );

	// Use the public `view` context so every role with menu access can list
	// coverages; `edit` requires the `edit_terms` capability and 403s for
	// authors/contributors. All list meta (status, created/modified, and the
	// Slack channel label) is exposed in `view` for plugin users; only the
	// generic source keys remain manager-only.
	const query: Record< string, unknown > = {
		per_page: perPage,
		page,
		_fields: 'id,name,slug,description,meta,count',
		context: 'view',
		_ts: refreshKey,
	};

	if ( debouncedSearch ) {
		query.search = debouncedSearch;
	}

	const config = useAdminContext();

	// Managers need `edit` context for the Slack/source meta; everyone else
	// uses `view` (edit requires `edit_terms` and 403s for lower roles).
	if ( config.capabilities.canManageTerms ) {
		query.context = 'edit';
	}

	const { records, isResolving, hasResolved, totalItems, totalPages } =
		useEntityRecords( 'taxonomy', config.taxonomy, query );

	// hasResolved resets for every new query (search, refresh), so the first
	// load is tracked separately to keep the table on screen after it.
	const hasLoadedOnce = useRef( false );
	if ( hasResolved ) {
		hasLoadedOnce.current = true;
	}

	const error = hasResolved && ! records ? 'Failed to load coverages.' : null;

	return {
		records: records as Coverage[] | null,
		isResolving,
		hasResolved,
		hasLoadedOnce: hasLoadedOnce.current,
		error,
		totalItems: totalItems ?? 0,
		totalPages: totalPages ?? 0,
	};
}

export { useCoverages };
