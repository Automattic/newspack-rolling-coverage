/**
 * External dependencies
 */
import { useMemo, useState, useCallback, useEffect } from '@wordpress/element';
import { useOutletContext } from 'react-router';
import { __ } from '@wordpress/i18n';
import { useEntityRecords } from '@wordpress/core-data';
import { filterSortAndPaginate } from '@wordpress/dataviews/wp';
import type { View } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { DataViewsWrapper } from './data-views-wrapper';
import { getEntryActions } from '../actions/entry-actions';
import { getEntryFields, defaultEntryView } from '../fields/entries';
import { ContextExports, Entry } from '../types';

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

	// Reset to page 1 when filters or search change.
	const handleChangeView = useCallback(
		( newView: View ) => {
			const filtersChanged =
				JSON.stringify( newView.filters ?? [] ) !==
				JSON.stringify( view.filters ?? [] );
			const searchChanged = newView.search !== view.search;
			if ( filtersChanged || searchChanged ) {
				setView( { ...newView, page: 1 } );
			} else {
				setView( newView );
			}
		},
		[ view.filters, view.search ]
	);

	const handleActionPerformed = useCallback( () => {
		refresh();
	}, [ refresh ] );

	const query: Record< string, unknown > = {
		per_page: 100,
		page: 1,
		status: 'trash',
		orderby: view.sort?.field ?? 'date',
		order: view.sort?.direction ?? 'desc',
		context: 'edit',
		_fields:
			'id,title,date,modified,author,status,pinned,meta,categories,tags,_links,_embedded,rolling_coverage_breakout_status',
		_embed: 'author,wp:term',
		_ts: refreshKey,
	};

	// Debounce search to prevent server overload on rapid typing.
	const [ debouncedSearch, setDebouncedSearch ] = useState( view.search );
	useEffect( () => {
		const timer = setTimeout(
			() => setDebouncedSearch( view.search ),
			300
		);
		return () => clearTimeout( timer );
	}, [ view.search ] );

	if ( debouncedSearch ) {
		query.search = debouncedSearch;
	}

	const { records, isResolving, hasResolved } = useEntityRecords< Entry >(
		'postType',
		config.postType,
		query
	);

	const error =
		hasResolved && ! records ? 'Failed to load trashed entries.' : null;

	const entryFields = useMemo( () => getEntryFields( config ), [ config ] );

	const { data: filteredData, paginationInfo } = useMemo( () => {
		return filterSortAndPaginate( records ?? [], view, entryFields );
	}, [ records, view, entryFields ] );

	const actions = useMemo(
		() =>
			getEntryActions( config, () => {}, handleActionPerformed ).filter(
				( action ) =>
					action.id === 'restore-entry' ||
					action.id === 'delete-entry'
			),
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
				onChangeView={ handleChangeView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ isResolving }
			/>
		</>
	);
}

export default TrashedEntriesView;
