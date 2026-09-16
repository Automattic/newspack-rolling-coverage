/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';

/** Debounce delay (ms) for text-input filters (title, author, etc.). */
const DEBOUNCE_MS = 500;

/**
 * Internal dependencies
 */
import type {
	EntryPageResponse,
	EntryViewRow,
	SyncNotice,
	UseEntriesOptions,
	UseEntriesResult,
} from '../types';
import { useAdminContext } from './useAdminContext';
import { handleApiError } from '../utils/api-error';
import { buildPageUrl, pollSync, SYNC_INTERVAL_MS } from '../utils/entries-api';

/**
 * Fetches entries for a given coverage via the custom entries-view REST
 * endpoint, with server-side pagination and a 10-second real-time sync poll.
 *
 * Page mode fetches a full page whenever coverageId, page, search, sort, or
 * refreshKey changes, and stores the response cursor for subsequent sync
 * polls. Sync mode polls every 10 seconds using the latest cursor, merging
 * changed rows (upsert by id) and removing trashed IDs, and exposes a
 * syncNotices array describing the last sync cycle for the consumer to
 * render as snackbars.
 *
 * Polling pauses when the tab is hidden and resumes (with an immediate
 * poll) when visible. The interval and listeners are cancelled on unmount
 * and on pagehide.
 *
 * @param {UseEntriesOptions} options Query options including coverageId, pagination, search, sort, and refreshKey.
 *
 * @return {UseEntriesResult} Entry rows, request state, pagination totals, sync notices, and error.
 */
function useEntries( options: UseEntriesOptions ): UseEntriesResult {
	const {
		coverageId,
		page,
		perPage = 100,
		search = '',
		orderBy = 'date',
		order = 'desc',
		status,
		statusExclude,
		source,
		sourceExclude,
		author,
		title,
		postId,
		breakoutStatus,
		breakoutStatusExclude,
		categorySearch,
		tagSearch,
		dateFilter,
		modifiedFilter,
		refreshKey,
	} = options;

	const config = useAdminContext();
	const baseUrl = config.restBaseUrls.entriesView;

	const [ rows, setRows ] = useState< EntryViewRow[] | null >( null );
	const [ isResolving, setIsResolving ] = useState( false );
	const [ hasResolved, setHasResolved ] = useState( false );
	const [ totalItems, setTotalItems ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 0 );
	const [ syncNotices, setSyncNotices ] = useState< SyncNotice[] >( [] );
	const [ error, setError ] = useState< string | null >( null );

	const cursorRef = useRef< string | null >( null );
	const rowsRef = useRef< EntryViewRow[] | null >( null );
	const coverageIdRef = useRef< number | null >( coverageId );
	const pageRef = useRef< number >( page );
	const isMountedRef = useRef( true );

	useEffect( () => {
		coverageIdRef.current = coverageId;
		// Reset the sync cursor so a poll for the new coverage never reuses
		// the previous coverage's cursor.
		cursorRef.current = null;
	}, [ coverageId ] );

	useEffect( () => {
		pageRef.current = page;
	}, [ page ] );

	useEffect( () => {
		isMountedRef.current = true;
		return () => {
			isMountedRef.current = false;
		};
	}, [] );

	// Page fetch: loads a full page on coverageId/page/search/sort/filter change.
	useEffect( () => {
		if ( coverageId === null ) {
			setRows( null );
			rowsRef.current = null;
			setIsResolving( false );
			setHasResolved( false );
			setError( null );
			setTotalItems( 0 );
			setTotalPages( 0 );
			cursorRef.current = null;
			setSyncNotices( [] );
			return;
		}

		let cancelled = false;

		// Debounce so rapid filter/search changes don't fire per keystroke.
		const timer = setTimeout( () => {
			if ( cancelled || ! isMountedRef.current ) {
				return;
			}

			setIsResolving( true );
			setError( null );

			const url = buildPageUrl(
				baseUrl,
				coverageId,
				page,
				perPage,
				orderBy,
				order,
				search,
				status,
				statusExclude,
				source,
				sourceExclude,
				author,
				title,
				postId,
				breakoutStatus,
				breakoutStatusExclude,
				categorySearch,
				tagSearch,
				dateFilter,
				modifiedFilter
			);

			apiFetch< EntryPageResponse >( { url, method: 'GET' } )
				.then( ( response ) => {
					if ( cancelled || ! isMountedRef.current ) {
						return;
					}
					setRows( response.entries );
					rowsRef.current = response.entries;
					setTotalItems( response.totalItems );
					setTotalPages( response.totalPages );
					cursorRef.current = response.cursor;
					setHasResolved( true );
					setError( null );
					setSyncNotices( [] );
				} )
				.catch( ( err ) => {
					if ( cancelled || ! isMountedRef.current ) {
						return;
					}
					setError( handleApiError( err as Error ) );
				} )
				.finally( () => {
					if ( cancelled || ! isMountedRef.current ) {
						return;
					}
					setIsResolving( false );
				} );
		}, DEBOUNCE_MS );

		return () => {
			cancelled = true;
			clearTimeout( timer );
		};
	}, [
		coverageId,
		page,
		perPage,
		search,
		orderBy,
		order,
		status,
		statusExclude,
		source,
		sourceExclude,
		author,
		title,
		postId,
		breakoutStatus,
		breakoutStatusExclude,
		categorySearch,
		tagSearch,
		dateFilter,
		modifiedFilter,
		refreshKey,
		baseUrl,
	] );

	// Sync poll: 10s interval, pauses when hidden, resumes on visible.
	useEffect( () => {
		if ( coverageId === null ) {
			return;
		}

		const ctx = {
			baseUrl,
			perPage,
			cursorRef,
			coverageIdRef,
			rowsRef,
			pageRef,
			isMountedRef,
			setRows,
			setSyncNotices,
		};

		let intervalId = window.setInterval(
			() => void pollSync( ctx ),
			SYNC_INTERVAL_MS
		);

		const onVisibilityChange = () => {
			if ( document.hidden ) {
				window.clearInterval( intervalId );
			} else {
				void pollSync( ctx );
				intervalId = window.setInterval(
					() => void pollSync( ctx ),
					SYNC_INTERVAL_MS
				);
			}
		};

		const onPageHide = () => {
			window.clearInterval( intervalId );
		};

		document.addEventListener( 'visibilitychange', onVisibilityChange );
		window.addEventListener( 'pagehide', onPageHide );

		return () => {
			window.clearInterval( intervalId );
			document.removeEventListener(
				'visibilitychange',
				onVisibilityChange
			);
			window.removeEventListener( 'pagehide', onPageHide );
		};
	}, [ coverageId, perPage, baseUrl ] );

	return {
		rows,
		isResolving,
		hasResolved,
		totalItems,
		totalPages,
		syncNotices,
		error,
	};
}

export { useEntries };
