/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Coverage, Action, AdminConfig } from '../types';

/**
 * Returns DataViews action definitions for coverage rows.
 *
 * @param {AdminConfig}                  config              The admin config providing REST URLs and other server-provided settings.
 * @param {(coverage: Coverage) => void} onNavigateToEntries Callback to navigate to the entry list.
 * @param {(coverage: Coverage) => void} onEdit              Callback to open the edit modal.
 * @param {(coverage: Coverage) => void} onSlackConnect      Callback to open the Slack connection modal.
 *
 * @return {Action<Coverage>[]} Array of DataViews actions for coverages.
 */
function getCoverageActions(
	config: AdminConfig,
	onNavigateToEntries: ( coverage: Coverage ) => void,
	onEdit: ( coverage: Coverage ) => void,
	onSlackConnect: ( coverage: Coverage ) => void
): Action< Coverage >[] {
	return [
		{
			id: 'edit-coverage',
			label: __( 'Edit', 'newspack-rolling-coverage' ),
			callback: ( items: Coverage[] ) => {
				if ( items.length === 1 ) {
					onEdit( items[ 0 ] );
				}
			},
		},
		{
			id: 'entries',
			label: __( 'Entries', 'newspack-rolling-coverage' ),
			isPrimary: true,
			callback: ( items: Coverage[] ) => {
				if ( items.length === 1 ) {
					onNavigateToEntries( items[ 0 ] );
				}
			},
		},
		...( config.slack.isConfigured
			? [
					{
						id: 'connect-slack',
						label: __( 'Connection', 'newspack-rolling-coverage' ),
						callback: ( items: Coverage[] ) => {
							if ( items.length === 1 ) {
								onSlackConnect( items[ 0 ] );
							}
						},
					},
			  ]
			: [] ),
	];
}

export { getCoverageActions };
