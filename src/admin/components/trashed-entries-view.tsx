/**
 * External dependencies
 */
import { useMemo, useState, useCallback } from '@wordpress/element';
import { useOutletContext } from 'react-router';
import { __ } from '@wordpress/i18n';
import { filterSortAndPaginate } from '@wordpress/dataviews/wp';
import type { View } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { useEntries } from '../hooks/useEntries';
import { useAdminContext } from '../hooks/useAdminContext';
import { DataViewsWrapper } from './data-views-wrapper';
import { getEntryActions } from '../actions/entry-actions';
import { getEntryFields, defaultEntryView } from '../fields/entries';
import { ContextExports } from '../types';

/**
 * Renders all trashed entries across all coverages (including orphaned
 * entries whose coverage term has been permanently deleted). Provides
 * restore and delete-permanently actions for each entry.
 */
function TrashedEntriesView() {
	const config = useAdminContext();
	const [ context, , refresh ] = useOutletContext< ContextExports >();
	const { refreshKey } = context;
	const [ view, setView ] = useState< View >( defaultEntryView );

	const handleActionPerformed = useCallback( () => {
		refresh();
	}, [ refresh ] );

	const { records, isResolving, error } = useEntries( {
		coverageId: null,
		perPage: 100,
		page: 1,
		search: view.search,
		orderBy: view.sort?.field,
		order: view.sort?.direction,
		status: 'trash',
		refreshKey,
	} );

	const entryFields = useMemo( () => getEntryFields( config ), [ config ] );

	const { data: filteredData, paginationInfo } = useMemo( () => {
		return filterSortAndPaginate( records ?? [], view, entryFields );
	}, [ records, view, entryFields ] );

	const actions = useMemo(
		() => getEntryActions( config, handleActionPerformed ),
		[ config, handleActionPerformed ]
	);

	return (
		<>
			{ error && (
				<div className="newspack-rolling-coverage-error">{ error }</div>
			) }
			{ records?.length === 0 && ! isResolving && (
				<div className="newspack-rolling-coverage-error">
					{ __(
						'No trashed entries found.',
						'newspack-rolling-coverage'
					) }
				</div>
			) }
			<DataViewsWrapper
				data={ filteredData }
				fields={ entryFields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ isResolving }
			/>
		</>
	);
}

export default TrashedEntriesView;
