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
import { Button } from '@wordpress/components';
import { plus } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import type { View } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { useEntries } from '../hooks/useEntries';
import { useAdminContext } from '../hooks/useAdminContext';
import { createEntry, toEntry } from '../utils/entries-api';
import { getCoverage } from '../utils/coverage-api';
import { DataViewsWrapper } from './data-views-wrapper';
import { QuickEditModal } from './quick-edit-modal';
import { getEntryActions } from '../actions/entry-actions';
import { getEntryNoticeMessage } from '../utils/notices';
import { applyEntryFilters } from '../utils/fields';
import type { ContextExports, Entry, SyncNotice } from '../types';
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
 * The "New Entry" button creates a draft entry via the REST API with the
 * coverage term pre-assigned, then redirects to the classic editor.
 */
function EntryView() {
	const config = useAdminContext();
	const { coverageId } = useParams< { coverageId?: string } >();
	const { createInfoNotice } = useDispatch( noticesStore );
	const [ context, setContext, refresh ] =
		useOutletContext< ContextExports >();
	const { selectedCoverage, refreshKey } = context;

	const numericCoverageId = coverageId ? Number( coverageId ) : null;
	const isValidCoverageId =
		numericCoverageId !== null && ! Number.isNaN( numericCoverageId );

	const isArchived =
		selectedCoverage?.meta?.[ config.taxMeta.statusKey ] === 'archived';
	const isTrashed =
		selectedCoverage?.meta?.[ config.taxMeta.statusKey ] === 'trash';
	const disableNewEntry = ! selectedCoverage || isArchived || isTrashed;
	const [ view, setView ] = useState< View >( defaultEntryView );
	const [ isCreatingEntry, setIsCreatingEntry ] = useState( false );
	const [ createError, setCreateError ] = useState< string | null >( null );
	const [ quickEditEntry, setQuickEditEntry ] = useState< Entry | null >(
		null
	);

	const handleActionPerformed = useCallback( () => {
		refresh();
	}, [ refresh ] );

	useEffect( () => {
		if ( ! isValidCoverageId || selectedCoverage ) {
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

	const { rows, isResolving, error, totalItems, totalPages, syncNotices } =
		useEntries( {
			coverageId: isValidCoverageId ? numericCoverageId : null,
			page: view.page ?? 1,
			perPage: view.perPage,
			search: view.search,
			orderBy: view.sort?.field,
			order: view.sort?.direction,
			refreshKey,
		} );

	const handleQuickEdit = useCallback( ( entry: Entry ) => {
		setQuickEditEntry( entry );
	}, [] );

	const handleQuickEditSaved = useCallback( () => {
		refresh();
	}, [ refresh ] );

	const handleQuickEditClose = useCallback( () => {
		setQuickEditEntry( null );
	}, [] );

	const entryFields = useMemo( () => getEntryFields( config ), [ config ] );

	const { data: filteredData, paginationInfo } = useMemo( () => {
		const mapped = ( rows ?? [] ).map( toEntry );
		const filters = ( view.filters ?? [] ) as Array< {
			field: string;
			operator: string;
			value: string | string[];
		} >;

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

	const actions = useMemo(
		() => getEntryActions( config, handleQuickEdit, handleActionPerformed ),
		[ config, handleQuickEdit, handleActionPerformed ]
	);

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
					selectedCoverage && ! disableNewEntry ? (
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
			{ quickEditEntry && (
				<QuickEditModal
					entryId={ quickEditEntry.id }
					onClose={ handleQuickEditClose }
					onSaved={ handleQuickEditSaved }
				/>
			) }
		</>
	);
}

export default EntryView;
