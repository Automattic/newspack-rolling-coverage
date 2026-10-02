/**
 * WordPress dependencies
 */
import { _x } from '@wordpress/i18n';

/**
 * The badge for each coverage status, matching the front end's.
 */
const BADGES: Record< string, { className: string; label: string } > = {
	active: {
		className: 'newspack-ui__badge--success newspack-ui__badge--pulse',
		label: _x( 'Live', 'coverage status', 'newspack-rolling-coverage' ),
	},
	paused: {
		className: '',
		label: _x( 'Paused', 'coverage status', 'newspack-rolling-coverage' ),
	},
	archived: {
		className: 'newspack-ui__badge--error',
		label: _x( 'Ended', 'coverage status', 'newspack-rolling-coverage' ),
	},
};

/**
 * The status the badge shows for a coverage status, treating one it doesn't
 * know as live.
 *
 * @param {string} status Coverage status.
 * @return {string} A status with a badge.
 */
export function badgeStatus( status?: string ): string {
	return status && BADGES[ status ] ? status : 'active';
}

/**
 * The default label for a coverage status.
 *
 * @param {string} status Coverage status.
 * @return {string} The label.
 */
export function defaultStatusLabel( status?: string ): string {
	return BADGES[ badgeStatus( status ) ].label;
}

/**
 * The badge that opens the Feed with the coverage's status.
 *
 * @param {Object} props        Component props.
 * @param {string} props.status Coverage status.
 * @param {string} props.label  The block's label for the status, if any.
 */
export default function StatusIndicator( {
	status,
	label,
}: {
	status?: string;
	label?: string;
} ) {
	const badge = BADGES[ badgeStatus( status ) ];

	return (
		<div className="newspack-rolling-coverage-status-indicator">
			<span
				className={ [
					'newspack-ui__badge',
					'newspack-ui__badge--dot',
					badge.className,
				]
					.filter( Boolean )
					.join( ' ' ) }
			>
				{ label?.trim() || badge.label }
			</span>
		</div>
	);
}
