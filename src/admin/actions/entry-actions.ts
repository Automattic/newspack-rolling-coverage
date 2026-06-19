/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Entry, Action, AdminConfig } from '../types';

/**
 * Returns DataViews action definitions for entry rows.
 *
 * @param {AdminConfig} config Admin config containing edit URLs.
 *
 * @return {Action<Entry>[]} Array of DataViews actions for entries.
 */
function getEntryActions( config: AdminConfig ): Action< Entry >[] {
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
	];
}

export { getEntryActions };
