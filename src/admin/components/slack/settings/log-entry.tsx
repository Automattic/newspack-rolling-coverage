/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { SlackMonitorLogEntry } from '../../../types';
import { formatContext } from '../../../utils/slack-api';

/**
 * Renders a single log entry with an expandable context section.
 *
 * @param {Object}               props       Component props.
 * @param {SlackMonitorLogEntry} props.entry Log entry data.
 */
function LogEntry( { entry }: { entry: SlackMonitorLogEntry } ) {
	const [ expanded, setExpanded ] = useState( false );
	const hasContext = entry.context && Object.keys( entry.context ).length > 0;

	return (
		<div
			className={ `newspack-rolling-coverage-slack-monitor__entry newspack-rolling-coverage-slack-monitor__entry--${ entry.level }` }
		>
			<div className="newspack-rolling-coverage-slack-monitor__entry-main">
				<span className="newspack-rolling-coverage-slack-monitor__time">
					{ entry.timestamp }
				</span>
				<span className="newspack-rolling-coverage-slack-monitor__level">
					{ entry.level }
				</span>
				<span className="newspack-rolling-coverage-slack-monitor__message">
					{ entry.message }
				</span>
				{ hasContext && (
					<button
						type="button"
						className="newspack-rolling-coverage-slack-monitor__toggle"
						onClick={ () => setExpanded( ( v ) => ! v ) }
						aria-expanded={ expanded }
					>
						{ expanded ? '−' : '+' }
					</button>
				) }
			</div>
			{ hasContext && expanded && (
				<div className="newspack-rolling-coverage-slack-monitor__context">
					{ formatContext( entry.context ) }
				</div>
			) }
		</div>
	);
}

export { LogEntry };
