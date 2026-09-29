/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { View } from '@wordpress/dataviews';
import { filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { EmptyState } from 'newspack-components/dist/esm/empty-state';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../../../hooks/useAdminContext';
import { getSlackMonitorLogs } from '../../../utils/slack-api';
import { LoadingState } from '../../../shared/loading-state';
import { ErrorNotice } from '../../../shared/error-notice';
import { SlackIcon } from '../../../shared/icons/slack-icon';
import { DataViewsWrapper } from '../../data-views-wrapper';
import { MonitorEventDrawer } from './monitor-event-drawer';
import {
	getMonitorFields,
	defaultMonitorView,
	MAX_MONITOR_EVENTS,
} from '../../../fields/monitor';
import type {
	ChannelMapping,
	SlackMonitorEvent,
	TabHeader,
} from '../../../types';

const POLL_INTERVAL = 5000;

/**
 * The events and read position survive the tab unmounting, so returning to
 * Monitor shows what was already fetched and resumes from there. The server
 * resets a stale position itself once it cleans up an idle log. Each event
 * gets an arrival number because the server's timestamps only go to the
 * second, and several events share one.
 */
const session: {
	hasStarted: boolean;
	inFlight: boolean;
	events: SlackMonitorEvent[];
	offset: number;
	nextId: number;
} = { hasStarted: false, inFlight: false, events: [], offset: 0, nextId: 1 };

/**
 * Real-time Slack event monitor tab.
 *
 * Polls every 5s for new events. Each poll doubles as the server-side
 * keep-alive signal: the log file is created on the first poll and
 * automatically cleaned up once polling stops, so no explicit start/stop
 * lifecycle is needed.
 *
 * @param {Object}           props                Component props.
 * @param {ChannelMapping[]} props.channels       Linked channels, to name the channel an event refers to.
 * @param {Function}         props.onHeaderChange Receives the breadcrumb count and whether the empty state shows.
 */
function MonitorTab( {
	channels,
	onHeaderChange,
}: {
	channels: ChannelMapping[];
	onHeaderChange: ( header: TabHeader ) => void;
} ) {
	const config = useAdminContext();
	const namespace = config.restBase.slack;

	const [ events, setEvents ] = useState< SlackMonitorEvent[] >(
		session.events
	);
	const [ isStarting, setIsStarting ] = useState( ! session.hasStarted );
	const [ startError, setStartError ] = useState< string | null >( null );
	const [ view, setView ] = useState< View >( defaultMonitorView );
	const [ selectedEvent, setSelectedEvent ] =
		useState< SlackMonitorEvent | null >( null );
	const [ isDrawerOpen, setIsDrawerOpen ] = useState( false );

	const poll = useCallback(
		async ( isCancelled: () => boolean ): Promise< boolean > => {
			if ( session.inFlight ) {
				return false;
			}
			session.inFlight = true;

			try {
				const result = await getSlackMonitorLogs(
					namespace,
					session.offset
				);

				if ( ! result.success || ! result.lines ) {
					throw new Error( result.error );
				}

				// The cache is written here rather than inside a state updater, so
				// a poll that lands after the tab unmounts still keeps its events
				// in step with the offset it advances.
				if ( result.lines.length > 0 ) {
					const next = [
						...session.events,
						...result.lines.map( ( line ) => ( {
							...line,
							id: session.nextId++,
						} ) ),
					];
					session.events =
						next.length > MAX_MONITOR_EVENTS
							? next.slice( -MAX_MONITOR_EVENTS )
							: next;
				}
				if ( result.offset !== undefined ) {
					session.offset = result.offset;
				}
				if ( ! isCancelled() ) {
					setEvents( session.events );
				}
			} finally {
				session.inFlight = false;
			}
			return true;
		},
		[ namespace ]
	);

	// The first poll boots the monitor; the poll cadence itself keeps it
	// alive on the server. Only a failed first poll surfaces an error, and
	// the next successful poll clears it.
	useEffect( () => {
		let cancelled = false;
		let isFirstPoll = ! session.hasStarted;
		const isCancelled = () => cancelled;

		const runPoll = async () => {
			if ( cancelled ) {
				return;
			}
			await poll( isCancelled )
				.then( ( didPoll ) => {
					if ( cancelled || ! didPoll ) {
						return;
					}
					isFirstPoll = false;
					session.hasStarted = true;
					setStartError( null );
					setIsStarting( false );
				} )
				.catch( () => {
					if ( ! cancelled && isFirstPoll ) {
						isFirstPoll = false;
						const message = __(
							'The monitor could not be started. Reload the page to try again.',
							'newspack-rolling-coverage'
						);
						setStartError( message );
						setIsStarting( false );
					}
				} );
		};

		runPoll();
		const interval = setInterval( runPoll, POLL_INTERVAL );

		return () => {
			cancelled = true;
			clearInterval( interval );
		};
	}, [ poll ] );

	const fields = useMemo( () => getMonitorFields( channels ), [ channels ] );
	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( events, view, fields ),
		[ events, view, fields ]
	);

	const isReady = ! isStarting && ! startError;
	useEffect( () => {
		onHeaderChange(
			isReady
				? {
						count: paginationInfo.totalItems,
						isEmpty: events.length === 0,
					}
				: {}
		);
	}, [ isReady, paginationInfo.totalItems, events.length, onHeaderChange ] );

	const openEvent = useCallback( ( event: SlackMonitorEvent ) => {
		setSelectedEvent( event );
		setIsDrawerOpen( true );
	}, [] );

	if ( isStarting ) {
		return (
			<LoadingState
				label={ __(
					'Starting the monitor…',
					'newspack-rolling-coverage'
				) }
			/>
		);
	}

	if ( startError ) {
		return (
			<ErrorNotice
				className="newspack-rolling-coverage-view-notice"
				message={ startError }
			/>
		);
	}

	return (
		<>
			{ events.length === 0 ? (
				<EmptyState.Root>
					<EmptyState.Header
						icon={ <SlackIcon size={ 36 } /> }
						title={ __(
							'No events yet',
							'newspack-rolling-coverage'
						) }
						description={ __(
							'Events appear here while this page is open. Post in a linked Slack channel or run a slash command to see activity.',
							'newspack-rolling-coverage'
						) }
					/>
				</EmptyState.Root>
			) : (
				<DataViewsWrapper
					data={ data }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ [] }
					paginationInfo={ paginationInfo }
					isLoading={ false }
					onClickItem={ openEvent }
					config={ { perPageSizes: [] } }
				/>
			) }
			<MonitorEventDrawer
				isOpen={ isDrawerOpen }
				onClose={ () => setIsDrawerOpen( false ) }
				event={ selectedEvent }
				channels={ channels }
			/>
		</>
	);
}

export { MonitorTab };
