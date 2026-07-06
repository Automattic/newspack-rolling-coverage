/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Entry, Action, AdminConfig } from '../types';

/**
 * Returns DataViews action definitions for entry rows.
 *
 * Includes "Edit" (opens the classic editor in a new tab) and "Quick Edit"
 * (opens the block editor in a modal on the current page).
 *
 * @param {AdminConfig}            config      Admin config containing edit URLs.
 * @param {(entry: Entry) => void} onQuickEdit Handler for the Quick Edit action.
 *
 * @return {Action<Entry>[]} Array of DataViews actions for entries.
 */
function getEntryActions(
	config: AdminConfig,
	onQuickEdit: ( entry: Entry ) => void
): Action< Entry >[] {
	return [
		{
			id: 'quick-edit',
			label: __( 'Quick Edit', 'newspack-rolling-coverage' ),
			isPrimary: true,
			callback: ( items: Entry[] ) => {
				if ( items.length === 1 ) {
					onQuickEdit( items[ 0 ] );
				}
			},
		},
		{
			id: 'edit',
			label: __( 'Edit', 'newspack-rolling-coverage' ),
			callback: ( items: Entry[] ) => {
				if ( items.length === 1 ) {
					window.open(
						`${ config.adminUrls.editEntry }&post=${ items[ 0 ].id }`,
						'_blank'
					);
				}
			},
		},
	];
}

export { getEntryActions };
