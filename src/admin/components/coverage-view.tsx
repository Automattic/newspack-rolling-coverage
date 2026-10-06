/**
 * External dependencies
 */
import { useNavigate, useOutletContext } from 'react-router';
import { useState, useCallback, useMemo } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';
import type { View } from '@wordpress/dataviews';
import { filterSortAndPaginate } from '@wordpress/dataviews/wp';

/**
 * Internal dependencies
 */
import { ErrorNotice } from '../shared/error-notice';
import { useCoverages } from '../hooks/useCoverages';
import { DataViewsWrapper } from './data-views-wrapper';
import { CoverageDrawer } from './coverage-drawer';
import { SlackConnectionDrawer } from './slack-connection-drawer';
import SettingsModal from './settings-modal';
import { useConfirmDialog } from './confirm-dialog';
import { getCoverageActions } from '../actions/coverage-actions';
import { getCoverageFields, defaultCoverageView } from '../fields/coverages';
import { useAdminContext } from '../hooks/useAdminContext';
import { useStatusLabels } from '../utils/status-labels';
import { EmptyState } from 'newspack-components/dist/esm/empty-state';
import { LoadingState } from '../shared/loading-state';
import { activity } from 'newspack-icons';
import { useHeader } from '../hooks/useHeader';
import type { Context, ContextExports, Coverage } from '../types';

/**
 * Renders the coverage list DataViews with create/edit drawer, Slack connection
 * drawer, and row actions. Clicking a row navigates to its entries via the hash
 * router.
 */
function CoverageView() {
	const config = useAdminContext();
	const navigate = useNavigate();
	const [ context, setContext, refresh ] =
		useOutletContext< ContextExports >();
	const { refreshKey } = context;
	const [ isSlackDrawerOpen, setIsSlackDrawerOpen ] = useState( false );
	const [ isSettingsOpen, setIsSettingsOpen ] = useState( false );
	const [ isSettingsLoading, setIsSettingsLoading ] = useState( false );
	const [ slackCoverage, setSlackCoverage ] = useState< Coverage | null >(
		null
	);

	const handleOpenSlackConnect = useCallback( ( coverage: Coverage ) => {
		setSlackCoverage( coverage );
		setIsSlackDrawerOpen( true );
	}, [] );

	const canConnectSlack =
		config.slack.isConfigured && config.capabilities.canManageOptions;
	const statusLabels = useStatusLabels();
	const fields = useMemo(
		() =>
			getCoverageFields(
				config.taxMeta.statusKey,
				config.taxMeta.lastModifiedKey,
				statusLabels,
				canConnectSlack ? handleOpenSlackConnect : undefined
			),
		[
			config.taxMeta.statusKey,
			config.taxMeta.lastModifiedKey,
			statusLabels,
			canConnectSlack,
			handleOpenSlackConnect,
		]
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

	// Fetch the full coverage list: sorting, filtering, and pagination are  applied client-side via filterSortAndPaginate
	const { records, isResolving, hasResolved, hasLoadedOnce, error } =
		useCoverages( {
			search: view.search,
			refreshKey,
		} );
	const isFirstLoad = ! hasLoadedOnce;
	const isEmpty =
		hasResolved && ! isResolving && records?.length === 0 && ! view.search;

	const { data: filteredData, paginationInfo } = useMemo( () => {
		return filterSortAndPaginate( records ?? [], view, fields );
	}, [ records, view, fields ] );

	const handleSettingsReady = useCallback(
		() => setIsSettingsLoading( false ),
		[]
	);

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

	const handleCloseSlackDrawer = useCallback( () => {
		setIsSlackDrawerOpen( false );
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

	const canAddCoverage =
		config.capabilities.canManageTerms && ! isFirstLoad && ! isEmpty;
	const canOpenSettings =
		config.capabilities.canManageSettings && ! isFirstLoad;
	const headerActions = useMemo(
		() =>
			canAddCoverage || canOpenSettings ? (
				<Stack direction="row" gap="sm">
					{ canOpenSettings && (
						<Button
							variant="secondary"
							isBusy={ isSettingsLoading }
							onClick={ () => {
								setIsSettingsOpen( true );
								setIsSettingsLoading( true );
							} }
						>
							{ __( 'Settings', 'newspack-rolling-coverage' ) }
						</Button>
					) }
					{ canAddCoverage && (
						<Button variant="primary" onClick={ handleOpenCreate }>
							{ __(
								'Add Coverage',
								'newspack-rolling-coverage'
							) }
						</Button>
					) }
				</Stack>
			) : null,
		[ canAddCoverage, canOpenSettings, handleOpenCreate, isSettingsLoading ]
	);
	useHeader( {
		actions: headerActions,
		count: isFirstLoad ? undefined : paginationInfo.totalItems,
		isEmpty,
	} );

	const { requestConfirm, dialog: confirmDialog } = useConfirmDialog();
	const actions = useMemo(
		() =>
			getCoverageActions(
				config,
				handleSaved,
				handleNavigateToEntries,
				handleOpenEdit,
				handleOpenSlackConnect,
				requestConfirm
			),
		[
			config,
			handleSaved,
			handleNavigateToEntries,
			handleOpenEdit,
			handleOpenSlackConnect,
			requestConfirm,
		]
	);

	return (
		<>
			<ErrorNotice
				className="newspack-rolling-coverage-view-notice"
				message={ error }
			/>
			{ isFirstLoad && (
				<LoadingState
					label={ __(
						'Fetching coverages…',
						'newspack-rolling-coverage'
					) }
				/>
			) }
			{ ! isFirstLoad && isEmpty && (
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
							<Button
								variant="primary"
								onClick={ handleOpenCreate }
							>
								{ __(
									'Add Coverage',
									'newspack-rolling-coverage'
								) }
							</Button>
						</EmptyState.Actions>
					) }
				</EmptyState.Root>
			) }
			{ ! isFirstLoad && ! isEmpty && (
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
			<SlackConnectionDrawer
				isOpen={ isSlackDrawerOpen }
				coverage={ slackCoverage }
				onClose={ handleCloseSlackDrawer }
				onSaved={ handleSaved }
			/>
			{ isSettingsOpen && (
				<SettingsModal
					onClose={ () => {
						setIsSettingsOpen( false );
						setIsSettingsLoading( false );
					} }
					onReady={ handleSettingsReady }
				/>
			) }
			{ confirmDialog }
		</>
	);
}

export default CoverageView;
