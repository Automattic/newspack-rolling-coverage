/**
 * External dependencies
 */
import { useOutletContext, useParams } from 'react-router';
import {
	useEffect,
	useMemo,
	useState,
	useCallback,
	useRef,
} from '@wordpress/element';
import { Button, DropdownMenu, VisuallyHidden } from '@wordpress/components';
import { moreVertical, postContent } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch, useRegistry } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import apiFetch from '@wordpress/api-fetch';
import { store as noticesStore } from '@wordpress/notices';
import type { View } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { ErrorNotice } from '../shared/error-notice';
import { useEntries } from '../hooks/useEntries';
import { useAdminContext } from '../hooks/useAdminContext';
import { useStatusLabels } from '../utils/status-labels';
import { EmptyState, Stack } from '@wordpress/ui';
import { LoadingState } from '../shared/loading-state';
import { useHeader } from '../hooks/useHeader';
import { buildPageUrl, toEntry } from '../utils/entries-api';
import { getCoverage } from '../utils/coverage-api';
import { DataViewsWrapper } from './data-views-wrapper';
import { QuickEditModal } from './quick-edit-modal';
import { EntryDetailsDrawer } from './entry-details-drawer';
import { SlackConnectionDrawer } from './slack-connection-drawer';
import { PlacementsDrawer } from './placements-drawer';
import { useScorePanel, type MenuControl } from './score-panel';
import { useConfirmDialog } from './confirm-dialog';
import { getEntryActions } from '../actions/entry-actions';
import { getEntryNoticeMessage } from '../utils/notices';
import {
	applyEntryFilters,
	hasFilterValue,
	ARCHIVED_VALUE,
	getSlackChannelLabel,
	NOT_ARCHIVED_VALUE,
} from '../utils/fields';
import type {
	ContextExports,
	Coverage,
	Entry,
	EntryPageResponse,
	SyncNotice,
} from '../types';
import { getEntryFields, defaultEntryView } from '../fields/entries';

/**
 * Threshold at which individual sync notices collapse into a single grouped
 * snackbar. Matches the spec §4.3 "group >5 changes into one notice" rule.
 */
const GROUP_NOTICE_THRESHOLD = 5;
const FIT_COLUMNS = [ 'modified', 'date' ];

/**
 * Renders the entry list DataViews for a single coverage, backed by the
 * custom entries-view endpoint with server-side pagination/sorting/search
 * and a 10-second real-time sync poll. `source` and `status` filters are
 * applied client-side; sync deltas surface as snackbar notices.
 *
 * The coverage is resolved from the route's :coverageId param and the
 * selected coverage passed via <Outlet context> by AdminLayout.
 *
 * The "Add Entry" header action opens Quick Edit on a new entry in the
 * coverage, which the modal starts itself; see `QuickEditModal`. While the
 * coverage has no Summary, "Add Summary" in the header's More actions menu
 * does the same for its Summary, for those who can pin entries.
 */
function EntryView() {
	const config = useAdminContext();
	const statusLabels = useStatusLabels();
	const { coverageId } = useParams< { coverageId?: string } >();
	const { createInfoNotice } = useDispatch( noticesStore );
	const [ context, setContext, refresh ] =
		useOutletContext< ContextExports >();
	const { selectedCoverage, refreshKey } = context;

	const numericCoverageId = coverageId ? Number( coverageId ) : null;
	const isValidCoverageId =
		numericCoverageId !== null && ! Number.isNaN( numericCoverageId );

	const routeCoverage =
		selectedCoverage?.id === numericCoverageId ? selectedCoverage : null;
	const isArchived =
		routeCoverage?.meta?.[ config.taxMeta.statusKey ] === 'archived';
	const isTrashed =
		routeCoverage?.meta?.[ config.taxMeta.statusKey ] === 'trash';
	// Any user who can create posts may add an entry (contributors create
	// drafts); publishing is gated per-entry by the row capabilities.
	const canCreateEntries = config.capabilities.canEditPosts;
	const disableNewEntry =
		! routeCoverage || isArchived || isTrashed || ! canCreateEntries;
	const [ view, setView ] = useState< View >( defaultEntryView );

	// Reset to page 1 when filters or search change (server paginates the filtered set).
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
	const [ isAddingEntry, setIsAddingEntry ] = useState( false );
	const [ isAddingSummary, setIsAddingSummary ] = useState( false );
	const [ quickEditEntry, setQuickEditEntry ] = useState< Entry | null >(
		null
	);
	// The open Quick Edit's entry as the list last returned it, for the
	// sidebar's actions menu. A new entry has none until its first save.
	const [ quickEditRow, setQuickEditRow ] = useState< Entry | null >( null );
	const quickEditIdRef = useRef< number | null >( null );
	const quickEditRefreshRef = useRef( 0 );

	const [ detailsItems, setDetailsItems ] = useState< Entry[] >( [] );
	const [ isDetailsOpen, setIsDetailsOpen ] = useState( false );
	const handleEditDetails = useCallback( ( items: Entry[] ) => {
		setDetailsItems( items );
		setIsDetailsOpen( true );
	}, [] );

	const handleActionPerformed = useCallback( () => {
		refresh();
	}, [ refresh ] );

	useEffect( () => {
		if (
			! isValidCoverageId ||
			selectedCoverage?.id === numericCoverageId
		) {
			return;
		}
		// Prevents updating context if the component is unmounted.
		let cancelled = false;
		getCoverage(
			config.restBaseUrls.coverages,
			numericCoverageId as number,
			config.capabilities.canManageTerms
		).then( ( coverage ) => {
			if ( cancelled || ! coverage ) {
				return;
			}
			setContext( ( prev ) => ( {
				...prev,
				selectedCoverage: coverage,
			} ) );
		} );
		return () => {
			cancelled = true;
		};
	}, [
		isValidCoverageId,
		selectedCoverage,
		numericCoverageId,
		config.restBaseUrls.coverages,
		config.capabilities.canManageTerms,
		setContext,
	] );

	// Extract all DataViews filters into server-side params.
	const serverFilters = useMemo( () => {
		const filters = ( view.filters ?? [] ) as Array< {
			field: string;
			operator: string;
			value: string | string[];
		} >;

		const params: Record< string, string > = {};

		for ( const f of filters ) {
			if ( ! hasFilterValue( f.value ) ) {
				continue;
			}

			const val = Array.isArray( f.value )
				? f.value.join( ',' )
				: f.value;

			switch ( f.field ) {
				case 'status':
					params[
						f.operator === 'isNot' ? 'statusExclude' : 'status'
					] = val;
					break;
				case 'source':
					params[
						f.operator === 'isNot' ? 'sourceExclude' : 'source'
					] = val;
					break;
				case 'author':
					if ( f.operator === 'contains' ) {
						params.author = val;
					}
					break;
				case 'title':
					if ( f.operator === 'contains' ) {
						params.title = val;
					}
					break;
				case 'id':
					if ( f.operator === 'is' ) {
						params.postId = val;
					}
					break;
				case 'breakout':
					params[
						f.operator === 'isNot'
							? 'breakoutStatusExclude'
							: 'breakoutStatus'
					] = val;
					break;
				case 'archived': {
					const wantsArchived =
						( f.operator === 'is' && val === ARCHIVED_VALUE ) ||
						( f.operator === 'isNot' &&
							val === NOT_ARCHIVED_VALUE );
					params.archived = wantsArchived ? '1' : '0';
					break;
				}
				case 'categories':
					if ( f.operator === 'contains' ) {
						params.categorySearch = val;
					}
					break;
				case 'tags':
					if ( f.operator === 'contains' ) {
						params.tagSearch = val;
					}
					break;
				case 'date':
					params.dateFilter = JSON.stringify( {
						operator: f.operator,
						value: f.value,
					} );
					break;
				case 'modified':
					params.modifiedFilter = JSON.stringify( {
						operator: f.operator,
						value: f.value,
					} );
					break;
			}
		}

		return params;
	}, [ view.filters ] );

	const {
		rows,
		isResolving,
		hasResolved,
		error,
		totalItems,
		totalPages,
		syncNotices,
		summaryId,
	} = useEntries( {
		coverageId: isValidCoverageId ? numericCoverageId : null,
		page: view.page ?? 1,
		perPage: view.perPage,
		search: view.search,
		orderBy: view.sort?.field,
		order: view.sort?.direction,
		...serverFilters,
		refreshKey,
	} );

	const registry = useRegistry();
	const { clearEntityRecordEdits } = useDispatch( coreStore );

	// Quick Edit clears an entry's edits when it closes, so edits an entry
	// carries when it opens came through the page's shared undo history:
	// another entry's Undo or Redo reached this one. Starting from the saved
	// entry keeps Save to what is typed in this Quick Edit. Clearing throws
	// until core-data has loaded the post type's config, and an entry has no
	// edits before then, so only an entry with edits is cleared.
	const handleQuickEdit = useCallback(
		( entry: Entry ) => {
			const edits = registry
				.select( coreStore )
				.getEntityRecordEdits( 'postType', config.postType, entry.id );
			if ( edits ) {
				clearEntityRecordEdits( 'postType', config.postType, entry.id );
			}
			quickEditIdRef.current = entry.id;
			setQuickEditRow( entry );
			setQuickEditEntry( entry );
		},
		[ registry, clearEntityRecordEdits, config.postType ]
	);

	const handleQuickEditSaved = useCallback( () => {
		refresh();
	}, [ refresh ] );

	const closeQuickEdit = useCallback( () => {
		quickEditIdRef.current = null;
		setQuickEditRow( null );
		setQuickEditEntry( null );
		setIsAddingEntry( false );
	}, [] );

	// Refetches the open entry's row by ID after a save or a menu action:
	// the list's current page may not hold it, and the actions menu and
	// status controls depend on its fresh state. An entry that is gone from
	// the coverage or trashed closes Quick Edit.
	const refreshQuickEditEntry = useCallback(
		async ( id?: number ) => {
			refresh();
			if ( id !== undefined ) {
				quickEditIdRef.current = id;
			}
			const entryId = quickEditIdRef.current;
			if ( ! entryId || numericCoverageId === null ) {
				return;
			}
			const requestNumber = ++quickEditRefreshRef.current;

			let response: EntryPageResponse;
			try {
				response = await apiFetch< EntryPageResponse >( {
					url: buildPageUrl(
						config.restBaseUrls.entriesView,
						numericCoverageId,
						1,
						1,
						'date',
						'desc',
						'',
						undefined,
						undefined,
						undefined,
						undefined,
						undefined,
						undefined,
						String( entryId )
					),
				} );
			} catch {
				return;
			}

			if (
				quickEditIdRef.current !== entryId ||
				quickEditRefreshRef.current !== requestNumber
			) {
				return;
			}

			const row = response.entries.find(
				( candidate ) => candidate.id === entryId
			);
			if ( ! row || row.status === 'trash' ) {
				clearEntityRecordEdits( 'postType', config.postType, entryId );
				closeQuickEdit();
				return;
			}

			setQuickEditRow( toEntry( row ) );
		},
		[
			refresh,
			numericCoverageId,
			config,
			clearEntityRecordEdits,
			closeQuickEdit,
		]
	);

	const handleQuickEditActionPerformed = useCallback(
		() => refreshQuickEditEntry(),
		[ refreshQuickEditEntry ]
	);

	const handleNewEntry = useCallback( () => {
		quickEditIdRef.current = null;
		setQuickEditRow( null );
		setIsAddingEntry( true );
	}, [] );

	const handleNewSummary = useCallback( () => {
		setIsAddingSummary( true );
	}, [] );

	const handleNewSummaryClose = useCallback( () => {
		setIsAddingSummary( false );
	}, [] );

	const entryFields = useMemo( () => getEntryFields( config ), [ config ] );

	const { data: mappedData, paginationInfo } = useMemo( () => {
		const mapped = ( rows ?? [] ).map( toEntry );
		const filters = ( view.filters ?? [] ) as Array< {
			field: string;
			operator: string;
			value: string | string[];
		} >;

		// Server applies the same filters, so totals stay accurate.  Client filter guards against unfiltered sync deltas.
		return {
			data: applyEntryFilters( mapped, filters ),
			paginationInfo: { totalItems, totalPages },
		};
	}, [ rows, view.filters, totalItems, totalPages ] );

	const { requestConfirm, dialog: confirmDialog } = useConfirmDialog();
	const quickEditActions = useMemo(
		() =>
			getEntryActions(
				config,
				handleQuickEdit,
				requestConfirm,
				handleQuickEditActionPerformed,
				handleEditDetails
			),
		[
			config,
			handleQuickEdit,
			requestConfirm,
			handleQuickEditActionPerformed,
			handleEditDetails,
		]
	);
	const actions = useMemo(
		() =>
			getEntryActions(
				config,
				handleQuickEdit,
				requestConfirm,
				handleActionPerformed,
				handleEditDetails
			),
		[
			config,
			handleQuickEdit,
			requestConfirm,
			handleActionPerformed,
			handleEditDetails,
		]
	);

	const hasNoLiveEntries =
		rows !== null &&
		! isResolving &&
		totalItems === 0 &&
		mappedData.length === 0 &&
		! view.search &&
		JSON.stringify( view.filters ?? [] ) ===
			JSON.stringify( defaultEntryView.filters );

	// The empty state replaces the table and its filters, so it must not
	// show while trashed entries exist: that filter is their only way back.
	const [ trashed, setTrashed ] = useState< {
		coverageId: number;
		count: number;
	} | null >( null );

	useEffect( () => {
		setTrashed( null );
		if ( ! hasNoLiveEntries || numericCoverageId === null ) {
			return;
		}
		let cancelled = false;
		apiFetch< EntryPageResponse >( {
			url: buildPageUrl(
				config.restBaseUrls.entriesView,
				numericCoverageId,
				1,
				1,
				'date',
				'desc',
				'',
				'trash'
			),
			method: 'GET',
		} )
			.then( ( response ) => response.totalItems )
			.catch( () => 1 )
			.then( ( count ) => {
				if ( ! cancelled ) {
					setTrashed( { coverageId: numericCoverageId, count } );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [
		hasNoLiveEntries,
		numericCoverageId,
		refreshKey,
		config.restBaseUrls.entriesView,
	] );

	const isEmpty =
		hasNoLiveEntries &&
		trashed?.coverageId === numericCoverageId &&
		trashed.count === 0;

	const isTrashCheckPending =
		hasNoLiveEntries && trashed?.coverageId !== numericCoverageId;

	// Until the first fetch and its trash check settle, neither the table nor
	// the empty state is known to be right. Later refetches keep the table.
	const hasSettledOnce = useRef( false );
	if ( hasResolved && ! isTrashCheckPending ) {
		hasSettledOnce.current = true;
	}
	const isFirstLoad =
		isValidCoverageId && ! hasSettledOnce.current && ! error;

	const canConnectSlack =
		config.slack.isConfigured && config.capabilities.canManageOptions;
	const [ isSlackDrawerOpen, setIsSlackDrawerOpen ] = useState( false );
	const [ slackCoverage, setSlackCoverage ] = useState< Coverage | null >(
		null
	);

	// Moving to another coverage (for example with the browser's Back button)
	// keeps this view mounted, so a drawer left open would still show the
	// previous coverage's channel, and a Quick Edit left open would save its
	// entry into the previous coverage.
	useEffect( () => {
		setIsSlackDrawerOpen( false );
		closeQuickEdit();
		setIsAddingSummary( false );
	}, [ numericCoverageId, closeQuickEdit ] );
	const slackChannelLabel = routeCoverage
		? getSlackChannelLabel( routeCoverage )
		: '';

	// Connecting or disconnecting changes the coverage's channel meta, so the
	// coverage in context is refetched to keep the Slack button current.
	const [ isRefreshingSlack, setIsRefreshingSlack ] = useState( false );
	const handleSlackSaved = useCallback( () => {
		if ( ! isValidCoverageId ) {
			return;
		}
		setIsRefreshingSlack( true );
		getCoverage(
			config.restBaseUrls.coverages,
			numericCoverageId as number,
			config.capabilities.canManageTerms
		)
			.then( ( coverage ) => {
				if ( ! coverage ) {
					return;
				}
				// The admin may have moved to another coverage meanwhile.
				setContext( ( prev ) =>
					prev.selectedCoverage?.id === coverage.id
						? { ...prev, selectedCoverage: coverage }
						: prev
				);
			} )
			.finally( () => setIsRefreshingSlack( false ) );
	}, [
		isValidCoverageId,
		numericCoverageId,
		config.restBaseUrls.coverages,
		config.capabilities.canManageTerms,
		setContext,
	] );

	// Archived coverages keep a disabled Add Entry so the reason stays visible.
	const showArchivedAddEntry = isArchived && canCreateEntries;
	const showNewEntry =
		( ! disableNewEntry || showArchivedAddEntry ) &&
		! isFirstLoad &&
		! isEmpty;
	const canAddSummary =
		! disableNewEntry &&
		config.capabilities.canEditEntries &&
		summaryId === 0;
	const showNewSummary = showNewEntry && canAddSummary;
	const showEmptyStateSummary = canAddSummary && isEmpty;
	const canShowSlack = canConnectSlack && routeCoverage !== null;
	const showSlackInHeader =
		canShowSlack && ! isFirstLoad && ( ! isEmpty || !! slackChannelLabel );
	const showSlackInEmptyState = canShowSlack && ! slackChannelLabel;

	const openSlackDrawer = useCallback( () => {
		setSlackCoverage( routeCoverage );
		setIsSlackDrawerOpen( true );
	}, [ routeCoverage ] );

	// On an empty list, connecting or disconnecting moves the Slack button
	// between the empty state and the header, so focus would drop to the page.
	const slackButtonRef = useRef< HTMLButtonElement | null >( null );
	const emptyStateSlackButtonRef = useRef< HTMLButtonElement | null >( null );
	const wasRefreshingSlack = useRef( false );
	useEffect( () => {
		if ( wasRefreshingSlack.current && ! isRefreshingSlack ) {
			window.requestAnimationFrame( () => {
				const button = [
					slackButtonRef.current,
					emptyStateSlackButtonRef.current,
				].find( ( element ) => element?.isConnected );
				const active = button?.ownerDocument.activeElement;
				if (
					button &&
					( ! active || active === button.ownerDocument.body )
				) {
					button.focus();
				}
			} );
		}
		wasRefreshingSlack.current = isRefreshingSlack;
	}, [ isRefreshingSlack ] );

	const slackButton = useMemo(
		() =>
			canShowSlack ? (
				<Button
					ref={ slackButtonRef }
					variant="tertiary"
					className="newspack-rolling-coverage-status-button"
					isBusy={ isRefreshingSlack }
					disabled={ isRefreshingSlack }
					accessibleWhenDisabled
					onClick={ openSlackDrawer }
				>
					{ slackChannelLabel ? (
						<>
							<span
								className="newspack-rolling-coverage-status-dot"
								aria-hidden="true"
							/>
							<VisuallyHidden>
								{
									/* translators: Read by screen readers before the linked Slack channel's name. */
									__(
										'Slack channel:',
										'newspack-rolling-coverage'
									)
								}{ ' ' }
							</VisuallyHidden>
							{ slackChannelLabel }
						</>
					) : (
						__( 'Connect Slack', 'newspack-rolling-coverage' )
					) }
				</Button>
			) : null,
		[ canShowSlack, slackChannelLabel, openSlackDrawer, isRefreshingSlack ]
	);

	const emptyStateSlackButton = useMemo(
		() =>
			showSlackInEmptyState ? (
				<Button
					ref={ emptyStateSlackButtonRef }
					variant="secondary"
					isBusy={ isRefreshingSlack }
					disabled={ isRefreshingSlack }
					accessibleWhenDisabled
					onClick={ openSlackDrawer }
				>
					{ __( 'Connect Slack', 'newspack-rolling-coverage' ) }
				</Button>
			) : null,
		[ showSlackInEmptyState, openSlackDrawer, isRefreshingSlack ]
	);

	const showPlacements =
		! isFirstLoad && ( routeCoverage?.placements ?? [] ).length > 0;
	const [ isPlacementsOpen, setIsPlacementsOpen ] = useState( false );

	const addEntryButtonRef = useRef< HTMLButtonElement | null >( null );
	const addEntryButton = useMemo(
		() =>
			isArchived ? (
				<Button
					ref={ addEntryButtonRef }
					variant="primary"
					disabled
					accessibleWhenDisabled
					showTooltip
					tooltipPosition="bottom"
					label={ __( 'Add Entry', 'newspack-rolling-coverage' ) }
					describedBy={ sprintf(
						/* translators: 1: The liveblog's status, e.g. "Ended". 2: The status that allows new entries, e.g. "Live". */
						__(
							'This liveblog is set to “%1$s”. Set it to “%2$s” to add entries.',
							'newspack-rolling-coverage'
						),
						statusLabels.archived,
						statusLabels.active
					) }
				>
					{ __( 'Add Entry', 'newspack-rolling-coverage' ) }
				</Button>
			) : (
				<Button
					ref={ addEntryButtonRef }
					variant="primary"
					onClick={ handleNewEntry }
				>
					{ __( 'Add Entry', 'newspack-rolling-coverage' ) }
				</Button>
			),
		[ isArchived, handleNewEntry, statusLabels ]
	);

	const addSummaryButton = useMemo(
		() => (
			<Button variant="secondary" onClick={ handleNewSummary }>
				{ __( 'Add Summary', 'newspack-rolling-coverage' ) }
			</Button>
		),
		[ handleNewSummary ]
	);

	const placementsButton = useMemo(
		() => (
			<Button
				variant="secondary"
				onClick={ () => setIsPlacementsOpen( true ) }
			>
				{ __( 'Placements', 'newspack-rolling-coverage' ) }
			</Button>
		),
		[]
	);

	const { addControl: addScoreControl, panel: scorePanel } = useScorePanel(
		isFirstLoad ? null : routeCoverage,
		setContext
	);

	const menuUsedFor = useRef< number | null >( null );
	const menuControls = useMemo( () => {
		const controls: MenuControl[] = [];
		if ( showNewSummary ) {
			controls.push( {
				title: __( 'Add Summary', 'newspack-rolling-coverage' ),
				onClick: handleNewSummary,
			} );
		}
		if ( addScoreControl ) {
			controls.push( addScoreControl );
		}
		return controls.map( ( control ) => ( {
			...control,
			onClick: () => {
				menuUsedFor.current = numericCoverageId;
				control.onClick();
			},
		} ) );
	}, [
		showNewSummary,
		handleNewSummary,
		addScoreControl,
		numericCoverageId,
	] );
	const hasMenu = menuControls.length > 0;

	// Adding the last item through the menu takes the menu away, and focus
	// with it, so focus moves to Add Entry instead of dropping to the page.
	useEffect( () => {
		if (
			menuUsedFor.current === null ||
			menuUsedFor.current !== numericCoverageId
		) {
			menuUsedFor.current = null;
			return;
		}
		if ( hasMenu ) {
			return;
		}
		menuUsedFor.current = null;
		window.requestAnimationFrame( () => {
			const button = addEntryButtonRef.current;
			const active = button?.ownerDocument.activeElement;
			if (
				button?.isConnected &&
				( ! active || active === button.ownerDocument.body )
			) {
				button.focus();
			}
		} );
	}, [ hasMenu, numericCoverageId ] );

	const headerActions = useMemo(
		() =>
			showNewEntry || showSlackInHeader || showPlacements || hasMenu ? (
				<>
					{ showSlackInHeader && slackButton }
					{ showPlacements && placementsButton }
					{ showNewEntry && addEntryButton }
					{ hasMenu && (
						<DropdownMenu
							icon={ moreVertical }
							label={ __(
								'More actions',
								'newspack-rolling-coverage'
							) }
							controls={ menuControls }
						/>
					) }
				</>
			) : null,
		[
			showNewEntry,
			showSlackInHeader,
			showPlacements,
			placementsButton,
			slackButton,
			addEntryButton,
			hasMenu,
			menuControls,
		]
	);
	useHeader( {
		actions: headerActions,
		count: isFirstLoad ? undefined : totalItems,
		isEmpty,
		subTitle: config.capabilities.canEditEntries
			? __(
					'Add, edit, and pin the updates readers see in this liveblog.',
					'newspack-rolling-coverage'
				)
			: __(
					'Add and edit the updates readers see in this liveblog.',
					'newspack-rolling-coverage'
				),
	} );

	// Render sync notices as snackbars. A sync cycle with more than
	// GROUP_NOTICE_THRESHOLD total changes collapses into a single grouped
	// notice. The `syncNotices` array is replaced each cycle by the hook with
	// only the latest delta, so this effect fires once per cycle.
	const prevNoticesRef = useRef< SyncNotice[] | null >( null );

	useEffect( () => {
		if ( prevNoticesRef.current === syncNotices ) {
			return;
		}
		prevNoticesRef.current = syncNotices;

		if ( ! syncNotices || syncNotices.length === 0 ) {
			return;
		}

		const totalCount = syncNotices.reduce( ( sum, n ) => sum + n.count, 0 );

		if ( totalCount > GROUP_NOTICE_THRESHOLD ) {
			createInfoNotice(
				sprintf(
					/* translators: %d: number of updates. */
					__(
						'%d updates in the last 10s',
						'newspack-rolling-coverage'
					),
					totalCount
				),
				{ type: 'snackbar' }
			);
			return;
		}

		// 5 or fewer: one snackbar per individual entry.
		for ( const notice of syncNotices ) {
			for ( const entry of notice.entries ) {
				const message = getEntryNoticeMessage( notice.type, entry );
				if ( message ) {
					createInfoNotice( message, { type: 'snackbar' } );
				}
			}
		}
	}, [ syncNotices, createInfoNotice ] );

	return (
		<>
			<ErrorNotice
				className="newspack-rolling-coverage-view-notice"
				message={ error }
			/>
			{ scorePanel }
			{ isFirstLoad && (
				<LoadingState
					label={ __(
						'Fetching entries…',
						'newspack-rolling-coverage'
					) }
				/>
			) }
			{ ! isFirstLoad && isEmpty && (
				<Stack direction="column" align="center">
					<EmptyState.Root>
						<EmptyState.Icon icon={ postContent } />
						<EmptyState.Title>
							{ __(
								'No entries yet',
								'newspack-rolling-coverage'
							) }
						</EmptyState.Title>
						<EmptyState.Description>
							{ __(
								'Entries are the short updates readers follow in this liveblog, newest first.',
								'newspack-rolling-coverage'
							) }
						</EmptyState.Description>
						{ ( ! disableNewEntry || showSlackInEmptyState ) && (
							<EmptyState.Actions>
								{ emptyStateSlackButton }
								{ showEmptyStateSummary && addSummaryButton }
								{ ! disableNewEntry && addEntryButton }
							</EmptyState.Actions>
						) }
					</EmptyState.Root>
				</Stack>
			) }
			{ ! isFirstLoad && ! isEmpty && (
				<DataViewsWrapper
					data={ mappedData }
					fields={ entryFields }
					view={ view }
					onChangeView={ handleChangeView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					isLoading={ isResolving || isTrashCheckPending }
					fitColumns={ FIT_COLUMNS }
				/>
			) }
			{ quickEditEntry && (
				<QuickEditModal
					entryId={ quickEditEntry.id }
					entry={ quickEditRow ?? quickEditEntry }
					actions={ quickEditActions }
					onClose={ closeQuickEdit }
					onSaved={ refreshQuickEditEntry }
				/>
			) }
			{ isAddingEntry && numericCoverageId !== null && (
				<QuickEditModal
					entryId={ null }
					coverageId={ numericCoverageId }
					entry={ quickEditRow }
					actions={ quickEditActions }
					onClose={ closeQuickEdit }
					onSaved={ refreshQuickEditEntry }
				/>
			) }
			{ isAddingSummary && numericCoverageId !== null && (
				<QuickEditModal
					entryId={ null }
					coverageId={ numericCoverageId }
					isSummary
					onClose={ handleNewSummaryClose }
					onSaved={ handleQuickEditSaved }
				/>
			) }
			<EntryDetailsDrawer
				isOpen={ isDetailsOpen }
				items={ detailsItems }
				onClose={ () => setIsDetailsOpen( false ) }
				onChanged={ handleActionPerformed }
			/>
			<PlacementsDrawer
				isOpen={ isPlacementsOpen }
				coverage={ routeCoverage }
				onClose={ () => setIsPlacementsOpen( false ) }
			/>
			{ canConnectSlack && (
				<SlackConnectionDrawer
					isOpen={ isSlackDrawerOpen }
					coverage={ slackCoverage }
					onClose={ () => setIsSlackDrawerOpen( false ) }
					onSaved={ handleSlackSaved }
				/>
			) }
			{ confirmDialog }
		</>
	);
}

export default EntryView;
