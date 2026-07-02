/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { createBreakout } from '../utils/breakout-api';
import { notifySuccess, notifyError } from '../utils/notices';
import { BreakoutModal } from '../components/breakout-modal';
import type { Entry, Action, AdminConfig } from '../types';

/**
 * An entry "has a breakout" once it has a status for one - a null/undefined
 * rolling_coverage_breakout_status means no breakout post currently exists.
 *
 * @param {Entry} entry The entry to check.
 * @return {boolean} Whether the entry already has a breakout post.
 */
function hasBreakout( entry: Entry ): boolean {
	return Boolean( entry.rolling_coverage_breakout_status );
}

/**
 * Returns DataViews action definitions for entry rows.
 *
 * @param {AdminConfig} config            Admin config containing edit URLs.
 * @param {() => void}  onActionPerformed Callback invoked after a successful create, or setting save, to refresh data.
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
		{
			id: 'create-breakout',
			label: __( 'Breakout', 'newspack-rolling-coverage' ),
			isEligible: ( entry: Entry ) => ! hasBreakout( entry ),
			callback: async ( items: Entry[] ) => {
				if ( items.length !== 1 ) {
					return;
				}

				const result = await createBreakout(
					config.restBaseUrls.breakout,
					items[ 0 ].id
				);
				if ( result.success ) {
					notifySuccess(
						__(
							'Breakout post created.',
							'newspack-rolling-coverage'
						)
					);
					onActionPerformed?.();
				} else {
					notifyError(
						result.error ||
							__(
								'Failed to create breakout post',
								'newspack-rolling-coverage'
							)
					);
				}
			},
		},
		{
			id: 'breakout-setting',
			label: __( 'Breakout Setting', 'newspack-rolling-coverage' ),
			isEligible: ( entry: Entry ) => hasBreakout( entry ),
			RenderModal: ( { items, closeModal, onActionPerformed: notify } ) =>
				createElement( BreakoutModal, {
					entry: items[ 0 ],
					onClose: closeModal ?? ( () => {} ),
					onSaved: () => {
						notify?.( items );
						onActionPerformed?.();
					},
				} ),
		},
	];
}

export { getEntryActions };
