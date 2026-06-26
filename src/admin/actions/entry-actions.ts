/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { deleteEntry } from '../utils/entry-api';
import { createDeleteAction } from '../utils/actions';
import type { Entry, Action, AdminConfig } from '../types';

/**
 * Returns DataViews action definitions for entry rows.
 *
 * @param {AdminConfig} config            Admin config containing edit URLs.
 * @param {() => void}  onActionPerformed Callback invoked after a successful delete to refresh data.
 *
 * @return {Action<Entry>[]} Array of DataViews actions for entries.
 */
function getEntryActions(
	config: AdminConfig,
	onActionPerformed?: () => void
): Action< Entry >[] {
	return [
		{
			id: 'edit',
			label: __( 'Edit', 'newspack-rolling-coverage' ),
			isPrimary: true,
			callback: ( items: Entry[] ) => {
				if ( items.length === 1 ) {
					window.open(
						`${ config.adminUrls.editEntry }&post=${ items[ 0 ].id }`,
						'_blank'
					);
				}
			},
		},
		createDeleteAction(
			( id: number ) => deleteEntry( config.restBaseUrls.entries, id ),
			{
				singular: __(
					'Are you sure you want to delete this entry?',
					'newspack-rolling-coverage'
				),
				// translators: %d is the number of entries to delete.
				plural: __( 'Delete %d entries?', 'newspack-rolling-coverage' ),
			},
			{
				singular: __( 'Delete', 'newspack-rolling-coverage' ),
				plural: __( 'Delete Entries', 'newspack-rolling-coverage' ),
			},
			onActionPerformed
		),
	];
}

export { getEntryActions };
