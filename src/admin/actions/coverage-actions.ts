/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Coverage, Action } from '../types';

/**
 * Returns DataViews action definitions for coverage rows.
 *
 * @param {(coverage: Coverage) => void} onNavigateToEntries Callback to navigate to the entry list.
 * @param {(coverage: Coverage) => void} onEdit              Callback to open the edit modal.
 *
 * @return {Action<Coverage>[]} Array of DataViews actions for coverages.
 */
function getCoverageActions(
	onNavigateToEntries: ( coverage: Coverage ) => void,
	onEdit: ( coverage: Coverage ) => void
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
	];
}

export { getCoverageActions };
