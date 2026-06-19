/**
 * WordPress dependencies
 */
import { useMemo, useState, useCallback } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { plus } from '@wordpress/icons';
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
import { entryFields, defaultEntryView } from '../fields/entries';
import type { EntryListViewProps } from '../types';

/**
 * Renders the entry list DataViews for a single liveblog, with client-side
 * filtering/sorting and a "New Entry" button (hidden when archived).
 *
 * @param {EntryListViewProps} props Component props.
 */
function EntryView( { liveblog }: EntryListViewProps ) {
	const config = useAdminContext();
	const isArchived = liveblog.meta?.rolling_coverage_status === 'archived';
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const [ view, setView ] = useState< View >( defaultEntryView );

	const { records, isResolving, error } = useEntries( {
		liveblogId: liveblog.id,
		perPage: 100,
		page: 1,
		search: view.search,
		orderBy: view.sort?.field,
		order: view.sort?.direction,
		refreshKey,
	} );

	const { data: filteredData, paginationInfo } = useMemo( () => {
		return filterSortAndPaginate( records ?? [], view, entryFields );
	}, [ records, view ] );

	const handleActionPerformed = useCallback( () => {
		setRefreshKey( ( k ) => k + 1 );
	}, [] );

	const actions = useMemo(
		() => getEntryActions( config, handleActionPerformed ),
		[ config, handleActionPerformed ]
	);

	const newEntryUrl = `${ config.adminUrls.newEntry }&rolling_coverage=${ liveblog.id }`;

	return (
		<>
			{ error && (
				<div className="newspack-rolling-coverage-error">{ error }</div>
			) }
			<DataViewsWrapper
				data={ filteredData }
				fields={ entryFields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ isResolving }
				header={
					! isArchived ? (
						<Button
							variant="primary"
							icon={ plus }
							href={ newEntryUrl }
						>
							{ __( 'New Entry', 'newspack-rolling-coverage' ) }
						</Button>
					) : undefined
				}
			/>
		</>
	);
}

export default EntryView;
