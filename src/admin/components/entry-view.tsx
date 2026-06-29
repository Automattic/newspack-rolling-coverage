/**
 * External dependencies
 */
import { useOutletContext, useParams } from 'react-router';
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
import { createEntry } from '../utils/entries-api';
import { DataViewsWrapper } from './data-views-wrapper';
import { getEntryActions } from '../actions/entry-actions';
import { entryFields, defaultEntryView } from '../fields/entries';
import type { Coverage } from '../types';

/**
 * Renders the entry list DataViews for a single coverage, with client-side
 * filtering/sorting and a "New Entry" button (hidden when archived).
 *
 * The coverage is resolved from the route's :coverageId param and the
 * selected coverage passed via <Outlet context> by AdminLayout.
 *
 * The "New Entry" button creates a draft entry via the REST API with the
 * coverage term pre-assigned, then redirects to the classic editor.
 */
function EntryView() {
	const config = useAdminContext();
	const { coverageId } = useParams< { coverageId?: string } >();
	const selectedCoverage = useOutletContext< Coverage | null >();

	const numericCoverageId = coverageId ? Number( coverageId ) : null;
	const isValidCoverageId =
		numericCoverageId !== null && ! Number.isNaN( numericCoverageId );

	const isArchived =
		selectedCoverage?.meta?.[ config.taxMeta.statusKey ] === 'archived';
	const [ view, setView ] = useState< View >( defaultEntryView );
	const [ isCreatingEntry, setIsCreatingEntry ] = useState( false );
	const [ createError, setCreateError ] = useState< string | null >( null );

	const { records, isResolving, error } = useEntries( {
		coverageId: isValidCoverageId ? numericCoverageId : null,
		perPage: 100,
		page: 1,
		search: view.search,
		orderBy: view.sort?.field,
		order: view.sort?.direction,
	} );

	const { data: filteredData, paginationInfo } = useMemo( () => {
		return filterSortAndPaginate( records ?? [], view, entryFields );
	}, [ records, view ] );

	const handleNewEntry = useCallback( async () => {
		if ( ! isValidCoverageId || numericCoverageId === null ) {
			return;
		}
		setIsCreatingEntry( true );
		setCreateError( null );

		const result = await createEntry(
			config.restBaseUrls.entries,
			config.restBase.coverages,
			numericCoverageId
		);

		if ( result.success && result.id ) {
			window.location.assign(
				`${ config.adminUrls.editEntry }&post=${ result.id }`
			);
		} else {
			setCreateError(
				result.error ||
					__( 'Failed to create entry', 'newspack-rolling-coverage' )
			);
			setIsCreatingEntry( false );
		}
	}, [ config, isValidCoverageId, numericCoverageId ] );

	const actions = useMemo( () => getEntryActions( config ), [ config ] );

	return (
		<>
			{ error && (
				<div className="newspack-rolling-coverage-error">{ error }</div>
			) }
			{ createError && (
				<div className="newspack-rolling-coverage-error">
					{ createError }
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
				header={
					selectedCoverage && ! isArchived ? (
						<Button
							variant="primary"
							icon={ plus }
							onClick={ handleNewEntry }
							isBusy={ isCreatingEntry }
							disabled={ isCreatingEntry }
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
