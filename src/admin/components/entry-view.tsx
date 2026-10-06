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
import { Button, VisuallyHidden } from '@wordpress/components';
import { postContent } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
import type { View } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { ErrorNotice } from '../shared/error-notice';
import { useEntries } from '../hooks/useEntries';
import { useAdminContext } from '../hooks/useAdminContext';
import { useStatusLabels } from '../utils/status-labels';
import { EmptyState } from 'newspack-components/dist/esm/empty-state';
import { LoadingState } from '../shared/loading-state';
import { useHeader } from '../hooks/useHeader';
import {
	buildPageUrl,
	createEntry,
	isEntryLocked,
	toEntry,
} from '../utils/entries-api';
import { getCoverage } from '../utils/coverage-api';
import { DataViewsWrapper } from './data-views-wrapper';
import { QuickEditModal } from './quick-edit-modal';
import { SlackConnectionDrawer } from './slack-connection-drawer';
import { useConfirmDialog } from './confirm-dialog';
import { getEntryActions } from '../actions/entry-actions';
import { getEntryNoticeMessage } from '../utils/notices';
import {
	applyEntryFilters,
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

/**
 * Renders the entry list DataViews for a single coverage, backed by the
 * custom entries-view endpoint with server-side pagination/sorting/search
 * and a 10-second real-time sync poll. `source` and `status` filters are
 * applied client-side; sync deltas surface as snackbar notices.
 *
 * The coverage is resolved from the route's :coverageId param and the
 * selected coverage passed via <Outlet context> by AdminLayout.
 *
 * The "Add Entry" header action creates a draft entry via the REST API with the
 * coverage term pre-assigned, then redirects to the classic editor.
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
	const [ isCreatingEntry, setIsCreatingEntry ] = useState( false );
	const [ createError, setCreateError ] = useState< string | null >( null );
	const [ quickEditEntry, setQuickEditEntry ] = useState< Entry | null >(
		null
	);

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
			numericCoverageId as number
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
			// Skip filters with no value yet — don't narrow the query until the
			// user actually picks something.
			if (
				f.value === undefined ||
				f.value === null ||
				f.value === '' ||
				( Array.isArray( f.value ) && f.value.length === 0 )
			) {
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

	const handleQuickEdit = useCallback( ( entry: Entry ) => {
		setQuickEditEntry( entry );
	}, [] );

	const handleQuickEditClose = useCallback( () => {
		setQuickEditEntry( null );
	}, [] );

	const { clearEntityRecordEdits } = useDispatch( coreStore );
	const quickEditIdRef = useRef< number | null >( null );
	quickEditIdRef.current = quickEditEntry?.id ?? null;
	const quickEditRefreshRef = useRef( 0 );

	// Refetches the Quick Edit entry by ID after a save or a menu action:
	// the list's current page may not hold it, and the menu and status
	// controls depend on its fresh state.
	const refreshQuickEditEntry = useCallback( async () => {
		refresh();
		const id = quickEditIdRef.current;
		if ( ! id || numericCoverageId === null ) {
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
					String( id )
				),
			} );
		} catch {
			return;
		}

		if (
			quickEditIdRef.current !== id ||
			quickEditRefreshRef.current !== requestNumber
		) {
			return;
		}

		const row = response.entries.find( ( entry ) => entry.id === id );
		if ( ! row || row.status === 'trash' ) {
			clearEntityRecordEdits( 'postType', config.postType, id );
			setQuickEditEntry( null );
			return;
		}

		setQuickEditEntry( toEntry( row ) );
	}, [ refresh, numericCoverageId, config, clearEntityRecordEdits ] );

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

	const { requestConfirm, dialog: confirmDialog } = useConfirmDialog();
	const actions = useMemo(
		() =>
			getEntryActions(
				config,
				handleQuickEdit,
				requestConfirm,
				handleActionPerformed
			),
		[ config, handleQuickEdit, requestConfirm, handleActionPerformed ]
	);
	const quickEditActions = useMemo(
		() =>
			getEntryActions(
				config,
				handleQuickEdit,
				requestConfirm,
				refreshQuickEditEntry
			),
		[ config, handleQuickEdit, requestConfirm, refreshQuickEditEntry ]
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
	// previous coverage's channel.
	useEffect( () => {
		setIsSlackDrawerOpen( false );
	}, [ numericCoverageId ] );
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
			numericCoverageId as number
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
		setContext,
	] );

	// Archived coverages keep a disabled Add Entry so the reason stays visible.
	const showArchivedAddEntry = isArchived && canCreateEntries;
	const showNewEntry =
		( ! disableNewEntry || showArchivedAddEntry ) &&
		! isFirstLoad &&
		! isEmpty;
	const canShowSlack = canConnectSlack && routeCoverage !== null;
	const showSlackInHeader = canShowSlack && ! isFirstLoad && ! isEmpty;

	const slackButton = useMemo(
		() =>
			canShowSlack ? (
				<Button
					variant="tertiary"
					className="newspack-rolling-coverage-status-button"
					isBusy={ isRefreshingSlack }
					disabled={ isRefreshingSlack }
					accessibleWhenDisabled
					onClick={ () => {
						setSlackCoverage( routeCoverage );
						setIsSlackDrawerOpen( true );
					} }
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
		[ canShowSlack, slackChannelLabel, routeCoverage, isRefreshingSlack ]
	);

	const pageUrl = routeCoverage?.pageUrl ?? '';
	const showViewPage =
		! isFirstLoad &&
		routeCoverage !== null &&
		( pageUrl !== '' || ! isEmpty );

	const addEntryButton = useMemo(
		() =>
			isArchived ? (
				<Button
					variant="primary"
					disabled
					accessibleWhenDisabled
					showTooltip
					tooltipPosition="bottom"
					label={ __( 'Add Entry', 'newspack-rolling-coverage' ) }
					describedBy={ sprintf(
						/* translators: 1: The coverage's status, e.g. "Ended". 2: The status that allows new entries, e.g. "Live". */
						__(
							'This coverage is set to “%1$s”. Set it to “%2$s” to add entries.',
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
					variant="primary"
					onClick={ handleNewEntry }
					isBusy={ isCreatingEntry }
					disabled={ isCreatingEntry }
				>
					{ __( 'Add Entry', 'newspack-rolling-coverage' ) }
				</Button>
			),
		[ isArchived, handleNewEntry, isCreatingEntry, statusLabels ]
	);

	const viewPageButton = useMemo(
		() =>
			pageUrl ? (
				<Button
					variant="secondary"
					href={ pageUrl }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'View Page', 'newspack-rolling-coverage' ) }
					<VisuallyHidden>
						{
							/* translators: Accessibility text. */
							__(
								'(opens in a new tab)',
								'newspack-rolling-coverage'
							)
						}
					</VisuallyHidden>
				</Button>
			) : (
				<Button
					variant="secondary"
					disabled
					accessibleWhenDisabled
					showTooltip
					tooltipPosition="bottom"
					label={ __( 'View Page', 'newspack-rolling-coverage' ) }
					describedBy={ __(
						'No published page shows this coverage yet. Add the Rolling Coverage block to a page and publish it.',
						'newspack-rolling-coverage'
					) }
				>
					{ __( 'View Page', 'newspack-rolling-coverage' ) }
				</Button>
			),
		[ pageUrl ]
	);

	const headerActions = useMemo(
		() =>
			showNewEntry || showSlackInHeader || showViewPage ? (
				<>
					{ showSlackInHeader && slackButton }
					{ showViewPage && viewPageButton }
					{ showNewEntry && addEntryButton }
				</>
			) : null,
		[
			showNewEntry,
			showSlackInHeader,
			showViewPage,
			viewPageButton,
			slackButton,
			addEntryButton,
		]
	);
	useHeader( {
		actions: headerActions,
		count: isFirstLoad ? undefined : totalItems,
		isEmpty,
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
			<ErrorNotice
				className="newspack-rolling-coverage-view-notice"
				message={ createError }
			/>
			{ isFirstLoad && (
				<LoadingState
					label={ __(
						'Fetching entries…',
						'newspack-rolling-coverage'
					) }
				/>
			) }
			{ ! isFirstLoad && isEmpty && (
				<EmptyState.Root>
					<EmptyState.Header
						icon={ postContent }
						title={ __(
							'No entries yet',
							'newspack-rolling-coverage'
						) }
						description={ __(
							'Entries are the short updates readers follow in this coverage, newest first.',
							'newspack-rolling-coverage'
						) }
					/>
					{ ( ! disableNewEntry || canShowSlack ) && (
						<EmptyState.Actions>
							{ slackButton }
							{ ! disableNewEntry && addEntryButton }
						</EmptyState.Actions>
					) }
				</EmptyState.Root>
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
				/>
			) }
			{ quickEditEntry && (
				<QuickEditModal
					entry={ quickEditEntry }
					actions={ quickEditActions }
					canPublish={ ! isEntryLocked( quickEditEntry ) }
					onClose={ handleQuickEditClose }
					onSaved={ refreshQuickEditEntry }
				/>
			) }
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
