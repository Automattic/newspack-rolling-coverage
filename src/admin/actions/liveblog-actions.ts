/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { deleteLiveblog } from '../utils/liveblog-api';
import { createDeleteAction } from '../utils/actions';
import type { Liveblog, Action } from '../types';

/**
 * Returns DataViews action definitions for liveblog rows.
 *
 * @param {(liveblog: Liveblog) => void} onNavigateToEntries Callback to navigate to the entry list.
 * @param {(liveblog: Liveblog) => void} onEdit              Callback to open the edit modal.
 * @param {() => void}                   onActionPerformed   Callback invoked after a successful delete to refresh data.
 *
 * @return {Action<Liveblog>[]} Array of DataViews actions for liveblogs.
 */
function getLiveblogActions(
	onNavigateToEntries: ( liveblog: Liveblog ) => void,
	onEdit: ( liveblog: Liveblog ) => void,
	onActionPerformed?: () => void
): Action< Liveblog >[] {
	return [
		{
			id: 'edit-liveblog',
			label: __( 'Edit', 'newspack-rolling-coverage' ),
			callback: ( items: Liveblog[] ) => {
				if ( items.length === 1 ) {
					onEdit( items[ 0 ] );
				}
			},
		},
		{
			id: 'entries',
			label: __( 'Entries', 'newspack-rolling-coverage' ),
			isPrimary: true,
			callback: ( items: Liveblog[] ) => {
				if ( items.length === 1 ) {
					onNavigateToEntries( items[ 0 ] );
				}
			},
		},
		createDeleteAction(
			deleteLiveblog,
			{
				singular: __(
					'Are you sure you want to delete this liveblog? This will also delete all entries.',
					'newspack-rolling-coverage'
				),
				// translators: %d is the number of liveblogs to delete.
				plural: __(
					'Are you sure you want to delete %d liveblogs? This will also delete all entries.',
					'newspack-rolling-coverage'
				),
			},
			{
				singular: __( 'Delete', 'newspack-rolling-coverage' ),
				plural: __( 'Delete Liveblogs', 'newspack-rolling-coverage' ),
			},
			onActionPerformed
		),
	];
}

export { getLiveblogActions };
