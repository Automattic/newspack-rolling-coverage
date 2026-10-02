/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { STATUS_LABELS } from '../config';

/**
 * The badge for each coverage status, matching the front end's, its text
 * when the block sets none, and the label of the field that sets it.
 */
export const STATUS_BADGES: Record<
	string,
	{ className: string; label: string; field: string }
> = {
	active: {
		className:
			'newspack-ui__badge--success newspack-ui__badge--dot newspack-ui__badge--pulse',
		label: STATUS_LABELS.active,
		field: __( 'Live label', 'newspack-rolling-coverage' ),
	},
	paused: {
		className: 'newspack-ui__badge--secondary',
		label: STATUS_LABELS.paused,
		field: __( 'Paused label', 'newspack-rolling-coverage' ),
	},
	archived: {
		className: 'newspack-ui__badge--error',
		label: STATUS_LABELS.archived,
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
