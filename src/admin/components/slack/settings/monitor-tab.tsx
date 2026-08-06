/**
 * WordPress dependencies
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../../../hooks/useAdminContext';
import {
	startSlackMonitor,
	stopSlackMonitor,
	getSlackMonitorLogs,
} from '../../../utils/slack-api';
import { LogEntry } from './log-entry';
import type { SlackMonitorLogEntry } from '../../../types';

const POLL_INTERVAL = 5000;
const MAX_LOGS = 1000;

/**
 * Real-time Slack event monitor tab.
 *
 * On mount, starts monitoring (creates the log file). Polls every 5s
 * for new log entries. On unmount, stops monitoring (deletes the log
 * file).
 */
function MonitorTab() {
	const config = useAdminContext();
	const namespace = config.restBase.slack;
	const nonce = config.nonce;

	const [ logs, setLogs ] = useState< SlackMonitorLogEntry[] >( [] );
	const [ isActive, setIsActive ] = useState( false );
	const [ isStarting, setIsStarting ] = useState( true );
	const [ startError, setStartError ] = useState< string | null >( null );
	const offsetRef = useRef( 0 );
	const containerRef = useRef< HTMLDivElement | null >( null );
	const isAtBottomRef = useRef( true );
	const inFlightRef = useRef( false );

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

	const start = useCallback( async () => {
		setIsStarting( true );
		const result = await startSlackMonitor( namespace );

		if ( ! result.success ) {
			setStartError(
				result.error ||
					__(
						'Failed to start monitor.',
						'newspack-rolling-coverage'
					)
			);
			setIsStarting( false );
			return;
		}

		setIsActive( true );
		setIsStarting( false );
	}, [ namespace ] );

	const stop = useCallback( () => {
		stopSlackMonitor( namespace, nonce );
	}, [ namespace, nonce ] );

	const poll = useCallback( async () => {
		if ( inFlightRef.current ) {
			return;
		}
		inFlightRef.current = true;

		try {
			const result = await getSlackMonitorLogs(
				namespace,
				offsetRef.current
			);

			if ( ! result.success || ! result.lines ) {
				return;
			}

			if ( result.lines.length > 0 ) {
				setLogs( ( prev ) => {
					const next = [ ...prev, ...result.lines! ];
					return next.length > MAX_LOGS
						? next.slice( -MAX_LOGS )
						: next;
				} );
			}

			if ( result.offset !== undefined ) {
				offsetRef.current = result.offset;
			}
		} finally {
			inFlightRef.current = false;
		}
	}, [ namespace ] );

	// Start/stop monitoring lifecycle.
	useEffect( () => {
		let cancelled = false;

		start().then( () => {
			if ( cancelled ) {
			}
		} );

		const handlePageHide = () => {
			cancelled = true;
			stop();
		};

		window.addEventListener( 'pagehide', handlePageHide );

		return () => {
			cancelled = true;
			stop();
			window.removeEventListener( 'pagehide', handlePageHide );
		};
	}, [ start, stop ] );

	// Poll for new logs.
	useEffect( () => {
		if ( ! isActive ) {
			return;
		}

		let cancelled = false;

		const runPoll = async () => {
			if ( cancelled ) {
				return;
			}
			await poll();
		};

		runPoll();
		const interval = setInterval( runPoll, POLL_INTERVAL );

		return () => {
			cancelled = true;
			clearInterval( interval );
		};
	}, [ isActive, poll ] );

	useEffect( () => {
		scrollToBottom();
	}, [ logs, scrollToBottom ] );

	if ( isStarting ) {
		return <Spinner />;
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
					{ logs.length }{ ' ' }
					{ __( 'events', 'newspack-rolling-coverage' ) }
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
