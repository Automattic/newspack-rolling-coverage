/**
 * External dependencies
 */
import { useNavigate, useOutletContext } from 'react-router';
import { useState, useCallback, useMemo } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { View } from '@wordpress/dataviews';
import { filterSortAndPaginate } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import { useCoverages } from '../hooks/useCoverages';
import { DataViewsWrapper } from './data-views-wrapper';
import { CoverageDrawer } from './coverage-drawer';
import { SlackConnectionModal } from './slack-connection-modal';
import { getCoverageActions } from '../actions/coverage-actions';
import { getCoverageFields, defaultCoverageView } from '../fields/coverages';
import { useAdminContext } from '../hooks/useAdminContext';
import { EmptyState } from 'newspack-components/dist/esm/empty-state';
import { activity } from 'newspack-icons';
import { useHeader } from '../hooks/useHeader';
import type { Context, ContextExports, Coverage } from '../types';

/**
 * Renders the coverage list DataViews with create/edit drawer, Slack connection
 * modal, and row actions. Clicking a row navigates to its entries via the hash
 * router.
 */
function CoverageView() {
	const config = useAdminContext();
	const navigate = useNavigate();
	const [ context, setContext, refresh ] =
		useOutletContext< ContextExports >();
	const { refreshKey } = context;
	const fields = useMemo(
		() =>
			getCoverageFields(
				config.taxMeta.statusKey,
				config.taxMeta.lastModifiedKey
			),
		[ config.taxMeta.statusKey, config.taxMeta.lastModifiedKey ]
	);
	const [ view, setView ] = useState< View >( defaultCoverageView );

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
	const [ editingCoverage, setEditingCoverage ] = useState< Coverage | null >(
		null
	);
	const [ isDrawerOpen, setIsDrawerOpen ] = useState( false );
	const [ isSlackModalOpen, setIsSlackModalOpen ] = useState( false );
	const [ slackCoverage, setSlackCoverage ] = useState< Coverage | null >(
		null
	);

	// Fetch the full coverage list: sorting, filtering, and pagination are  applied client-side via filterSortAndPaginate
	const { records, isResolving, hasResolved, error } = useCoverages( {
		search: view.search,
		refreshKey,
	} );
	const isEmpty =
		hasResolved &&
		! isResolving &&
		records?.length === 0 &&
		! view.search;

	const { data: filteredData, paginationInfo } = useMemo( () => {
		return filterSortAndPaginate( records ?? [], view, fields );
	}, [ records, view, fields ] );

	const handleOpenCreate = useCallback( () => {
		setEditingCoverage( null );
		setIsDrawerOpen( true );
	}, [] );

	const handleOpenEdit = useCallback( ( coverage: Coverage ) => {
		setEditingCoverage( coverage );
		setIsDrawerOpen( true );
	}, [] );

	const handleCloseDrawer = useCallback( () => {
		setIsDrawerOpen( false );
	}, [] );

	const handleSaved = useCallback( () => {
		refresh();
	}, [ refresh ] );

	const handleOpenSlackConnect = useCallback( ( coverage: Coverage ) => {
		setSlackCoverage( coverage );
		setIsSlackModalOpen( true );
	}, [] );

	const handleCloseSlackModal = useCallback( () => {
		setIsSlackModalOpen( false );
		setSlackCoverage( null );
	}, [] );

	const handleNavigateToEntries = useCallback(
		( coverage: Coverage ) => {
			setContext( ( prev: Context ) => ( {
				...prev,
				selectedCoverage: coverage,
			} ) );
			navigate( `/coverages/${ coverage.id }` );
		},
		[ navigate, setContext ]
	);

	const headerActions = useMemo(
		() =>
			config.capabilities.canManageTerms && ! isEmpty ? (
				<Button variant="primary" onClick={ handleOpenCreate }>
					{ __( 'Add Coverage', 'newspack-rolling-coverage' ) }
				</Button>
			) : null,
		[ config.capabilities.canManageTerms, isEmpty, handleOpenCreate ]
	);
	useHeader( { actions: headerActions, count: paginationInfo.totalItems } );

	const actions = useMemo(
		() =>
			getCoverageActions(
				config,
				handleSaved,
				handleNavigateToEntries,
				handleOpenEdit,
				handleOpenSlackConnect
			),
		[
			config,
			handleSaved,
			handleNavigateToEntries,
			handleOpenEdit,
			handleOpenSlackConnect,
		]
	);

	return (
		<>
			{ error && (
				<div className="newspack-rolling-coverage-error">{ error }</div>
			) }
			{ isEmpty ? (
				<EmptyState.Root>
					<EmptyState.Header
						icon={ activity }
						title={ __(
							'Get started with rolling coverage',
							'newspack-rolling-coverage'
						) }
						description={ __(
							'Follow a developing story with a live stream of short, timestamped updates.',
							'newspack-rolling-coverage'
						) }
					/>
					{ config.capabilities.canManageTerms && (
						<EmptyState.Actions>
							<Button variant="primary" onClick={ handleOpenCreate }>
								{ __(
									'Add Coverage',
									'newspack-rolling-coverage'
								) }
							</Button>
						</EmptyState.Actions>
					) }
				</EmptyState.Root>
			) : (
				<DataViewsWrapper
					data={ filteredData }
					fields={ fields }
					view={ view }
					onChangeView={ handleChangeView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					isLoading={ isResolving }
					onClickItem={ ( item ) =>
						handleNavigateToEntries( item as Coverage )
					}
				/>
			) }

			<CoverageDrawer
				isOpen={ isDrawerOpen }
				coverage={ editingCoverage }
				onClose={ handleCloseDrawer }
				onSaved={ handleSaved }
			/>
			{ isSlackModalOpen && (
				<SlackConnectionModal
					coverage={ slackCoverage }
					onClose={ handleCloseSlackModal }
					onSaved={ handleSaved }
				/>
			) }
		</>
	);
}

export default CoverageView;
