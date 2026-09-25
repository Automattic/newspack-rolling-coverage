/**
 * WordPress dependencies
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../../../hooks/useAdminContext';
import { getSlackMonitorLogs } from '../../../utils/slack-api';
import { LogEntry } from './log-entry';
import { LoadingState } from '../../../shared/loading-state';
import type { SlackMonitorLogEntry } from '../../../types';

const POLL_INTERVAL = 5000;
const MAX_LOGS = 1000;

/**
 * The log and read position survive the tab unmounting, so returning to
 * Monitor shows what was already fetched and resumes from there. The server
 * resets a stale position itself once it cleans up an idle log.
 */
const session: {
	hasStarted: boolean;
	inFlight: boolean;
	logs: SlackMonitorLogEntry[];
	offset: number;
} = { hasStarted: false, inFlight: false, logs: [], offset: 0 };

/**
 * Real-time Slack event monitor tab.
 *
 * Polls every 5s for new log entries. Each poll doubles as the
 * server-side keep-alive signal: the log file is created on the
 * first poll and automatically cleaned up once polling stops, so no
 * explicit start/stop lifecycle is needed.
 */
function MonitorTab() {
	const config = useAdminContext();
	const namespace = config.restBase.slack;

	const [ logs, setLogs ] = useState< SlackMonitorLogEntry[] >(
		session.logs
	);
	const [ isStarting, setIsStarting ] = useState( ! session.hasStarted );
	const [ startError, setStartError ] = useState< string | null >( null );
	const containerRef = useRef< HTMLDivElement | null >( null );
	const isAtBottomRef = useRef( true );
	const isFirstPollRef = useRef( ! session.hasStarted );
	const cancelledRef = useRef( false );

	const scrollToBottom = useCallback( () => {
		if ( containerRef.current && isAtBottomRef.current ) {
			containerRef.current.scrollTop = containerRef.current.scrollHeight;
		}
	}, [] );

	const handleScroll = useCallback( () => {
		if ( ! containerRef.current ) {
			return;
		}
		const { scrollTop, scrollHeight, clientHeight } = containerRef.current;
		isAtBottomRef.current = scrollHeight - scrollTop - clientHeight < 50;
	}, [] );

	const poll = useCallback( async (): Promise< boolean > => {
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
			// a poll that lands after the tab unmounts still keeps its lines in
			// step with the offset it advances.
			if ( result.lines.length > 0 ) {
				const next = [ ...session.logs, ...result.lines ];
				session.logs =
					next.length > MAX_LOGS ? next.slice( -MAX_LOGS ) : next;
			}
			if ( result.offset !== undefined ) {
				session.offset = result.offset;
			}
			if ( ! cancelledRef.current ) {
				setLogs( session.logs );
			}
		} finally {
			session.inFlight = false;
		}
		return true;
	}, [ namespace ] );

	// Poll for new logs. The first poll boots the monitor; the poll
	// cadence itself keeps it alive on the server. Only a failed
	// first poll surfaces an error, and the next successful poll
	// clears it.
	useEffect( () => {
		let cancelled = false;
		cancelledRef.current = false;

		const runPoll = async () => {
			if ( cancelled ) {
				return;
			}
			await poll()
				.then( ( didPoll ) => {
					if ( cancelled || ! didPoll ) {
						return;
					}
					isFirstPollRef.current = false;
					session.hasStarted = true;
					setStartError( null );
					setIsStarting( false );
				} )
				.catch( () => {
					if ( ! cancelled && isFirstPollRef.current ) {
						isFirstPollRef.current = false;
						speak(
							__(
								'Failed to start monitor.',
								'newspack-rolling-coverage'
							),
							'assertive'
						);
						setStartError(
							__(
								'Failed to start monitor.',
								'newspack-rolling-coverage'
							)
						);
						setIsStarting( false );
					}
				} );
		};

		runPoll();
		const interval = setInterval( runPoll, POLL_INTERVAL );

		return () => {
			cancelled = true;
			cancelledRef.current = true;
			clearInterval( interval );
		};
	}, [ poll ] );

	useEffect( () => {
		scrollToBottom();
	}, [ logs, scrollToBottom ] );

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
			<div className="newspack-rolling-coverage-slack-monitor">
				<div className="newspack-rolling-coverage-slack-monitor__error">
					{ startError }
				</div>
			</div>
		);
	}

	return (
		<div className="newspack-rolling-coverage-slack-monitor">
			<div className="newspack-rolling-coverage-slack-monitor__header">
				<span className="newspack-rolling-coverage-slack-monitor__status">
					<span className="newspack-rolling-coverage-slack-monitor__pulse" />
					{ __( 'Monitoring active', 'newspack-rolling-coverage' ) }
				</span>
				<span className="newspack-rolling-coverage-slack-monitor__count">
					{ sprintf(
						/* translators: %d: number of monitor events. */
						_n(
							'%d event',
							'%d events',
							logs.length,
							'newspack-rolling-coverage'
						),
						logs.length
					) }
				</span>
			</div>
			<div
				ref={ containerRef }
				onScroll={ handleScroll }
				className="newspack-rolling-coverage-slack-monitor__log"
			>
				{ logs.length === 0 ? (
					<div className="newspack-rolling-coverage-slack-monitor__empty">
						{ __(
							'No events yet. Send a Slack message or trigger a slash command to see activity here.',
							'newspack-rolling-coverage'
						) }
					</div>
				) : (
					logs.map( ( entry, i ) => (
						<LogEntry key={ i } entry={ entry } />
					) )
				) }
			</div>
		</div>
	);
}

export { MonitorTab };
