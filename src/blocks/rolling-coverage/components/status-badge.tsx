/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';

/**
 * The badge for each coverage status, matching the front end's, and the
 * label of the field that sets its text.
 */
export const STATUS_BADGES: Record<
	string,
	{ className: string; label: string; field: string }
> = {
	active: {
		className:
			'newspack-ui__badge--success newspack-ui__badge--dot newspack-ui__badge--pulse',
		label: _x( 'Live', 'coverage status', 'newspack-rolling-coverage' ),
		field: __( 'Live label', 'newspack-rolling-coverage' ),
	},
	paused: {
		className: 'newspack-ui__badge--secondary',
		label: _x( 'Paused', 'coverage status', 'newspack-rolling-coverage' ),
		field: __( 'Paused label', 'newspack-rolling-coverage' ),
	},
	archived: {
		className: 'newspack-ui__badge--error',
		label: _x( 'Ended', 'coverage status', 'newspack-rolling-coverage' ),
		field: __( 'Ended label', 'newspack-rolling-coverage' ),
	},
};

/**
 * The badge that opens the Feed with the coverage's status. A status it
 * doesn't know shows as live.
 *
 * @param {Object} props        Component props.
 * @param {string} props.status Coverage status.
 * @param {Object} props.labels The block's labels, by status.
 */
export default function StatusBadge( {
	status,
	labels,
}: {
	status?: string;
	labels?: Partial< Record< string, string > >;
} ) {
	const key = status && STATUS_BADGES[ status ] ? status : 'active';
	const badge = STATUS_BADGES[ key ];

	return (
		<div className="newspack-rolling-coverage-status-indicator">
			<span className={ `newspack-ui__badge ${ badge.className }` }>
				{ ( typeof labels?.[ key ] === 'string' &&
					labels[ key ]?.trim() ) ||
					badge.label }
			</span>
		</div>
	);
}
