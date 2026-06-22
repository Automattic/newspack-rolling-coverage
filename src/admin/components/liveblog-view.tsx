/**
 * WordPress dependencies
 */
import { useState, useCallback, useMemo } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { plus } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { filterSortAndPaginate } from '@wordpress/dataviews/wp';
import type { View } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { useLiveblogs } from '../hooks/useLiveblogs';
import { DataViewsWrapper } from './data-views-wrapper';
import { LiveblogModal } from './liveblog-modal';
import { getLiveblogActions } from '../actions/liveblog-actions';
import { getLiveblogFields, defaultLiveblogView } from '../fields/liveblogs';
import { useAdminContext } from '../hooks/useAdminContext';
import type { Liveblog, LiveblogListViewProps } from '../types';

/**
 * Renders the liveblog list DataViews with create/edit modal and row actions.
 * Clicking a row navigates to its entries.
 *
 * @param {LiveblogListViewProps} props Component props.
 */
function LiveblogView( { onNavigateToEntries }: LiveblogListViewProps ) {
	const config = useAdminContext();
	const fields = useMemo(
		() => getLiveblogFields( config.taxMeta.statusKey ),
		[ config.taxMeta.statusKey ]
	);
	const [ view, setView ] = useState< View >( defaultLiveblogView );
	const [ editingLiveblog, setEditingLiveblog ] = useState< Liveblog | null >(
		null
	);
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ refreshKey, setRefreshKey ] = useState( 0 );

	const { records, isResolving, error } = useLiveblogs( {
		perPage: -1,
		page: 1,
		search: view.search,
		orderBy: view.sort?.field,
		order: view.sort?.direction,
		refreshKey,
	} );

	const { data: filteredData, paginationInfo } = useMemo( () => {
		return filterSortAndPaginate( records ?? [], view, fields );
	}, [ records, view, fields ] );

	const handleOpenCreate = useCallback( () => {
		setEditingLiveblog( null );
		setIsModalOpen( true );
	}, [] );

	const handleOpenEdit = useCallback( ( liveblog: Liveblog ) => {
		setEditingLiveblog( liveblog );
		setIsModalOpen( true );
	}, [] );

	const handleCloseModal = useCallback( () => {
		setIsModalOpen( false );
		setEditingLiveblog( null );
	}, [] );

	const handleSaved = useCallback( () => {
		setRefreshKey( ( k ) => k + 1 );
	}, [] );

	const actions = useMemo(
		() =>
			getLiveblogActions(
				config,
				onNavigateToEntries,
				handleOpenEdit,
				handleSaved
			),
		[ config, onNavigateToEntries, handleOpenEdit, handleSaved ]
	);

	return (
		<>
			{ error && (
				<div className="newspack-rolling-coverage-error">{ error }</div>
			) }
			<DataViewsWrapper
				data={ filteredData }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ isResolving }
				onClickItem={ ( item ) =>
					onNavigateToEntries( item as Liveblog )
				}
				header={
					<Button
						variant="primary"
						icon={ plus }
						onClick={ handleOpenCreate }
					>
						{ __( 'New Liveblog', 'newspack-rolling-coverage' ) }
					</Button>
				}
			/>

			{ isModalOpen && (
				<LiveblogModal
					liveblog={ editingLiveblog }
					onClose={ handleCloseModal }
					onSaved={ handleSaved }
				/>
			) }
		</>
	);
}

export default LiveblogView;
