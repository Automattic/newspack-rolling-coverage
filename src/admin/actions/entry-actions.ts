/**
 * WordPress dependencies
 */
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	createBreakout,
	restoreBreakout,
	deleteBreakoutPermanently,
} from '../utils/breakout-api';
import { notifySuccess, notifyError } from '../utils/notices';
import { BreakoutModal } from '../components/breakout-modal';
import type { Entry, Action, AdminConfig } from '../types';

/**
 * Returns true when the entry has an active (non-trashed) breakout post.
 *
 * @param {Entry} entry The entry to check.
 * @return {boolean} Whether the entry has an active breakout post.
 */
function hasBreakout( entry: Entry ): boolean {
	return (
		Boolean( entry.rolling_coverage_breakout_status ) &&
		entry.rolling_coverage_breakout_status !== 'trash'
	);
}

/**
 * Returns true when the entry has a breakout post that is in the trash.
 *
 * @param {Entry} entry The entry to check.
 * @return {boolean} Whether the entry has a trashed breakout post.
 */
function hasTrashedBreakout( entry: Entry ): boolean {
	return entry.rolling_coverage_breakout_status === 'trash';
}

/**
 * Returns DataViews action definitions for entry rows.
 *
 * Includes "Edit" (opens the classic editor in a new tab) and "Quick Edit"
 * (opens the block editor in a modal on the current page).
 *
 * @param {AdminConfig}            config            Admin config containing edit URLs.
 * @param {(entry: Entry) => void} onQuickEdit       Handler for the Quick Edit action.
 * @param {() => void}             onActionPerformed Callback invoked after a successful create, or setting save, to refresh data.
 *
 * @return {Action<Entry>[]} Array of DataViews actions for entries.
 */
function getEntryActions(
	config: AdminConfig,
	onQuickEdit: ( entry: Entry ) => void,
	onActionPerformed?: () => void
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
		{
			id: 'create-breakout',
			label: __( 'Breakout', 'newspack-rolling-coverage' ),
			isEligible: ( entry: Entry ) =>
				! hasBreakout( entry ) && ! hasTrashedBreakout( entry ),
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
			id: 'restore-breakout',
			label: __( 'Restore Breakout Post', 'newspack-rolling-coverage' ),
			isEligible: ( entry: Entry ) => hasTrashedBreakout( entry ),
			callback: async ( items: Entry[] ) => {
				if ( items.length !== 1 ) {
					return;
				}

				const breakoutPostId =
					items[ 0 ].meta?.rolling_coverage_breakout_post_id;
				if ( ! breakoutPostId ) {
					return;
				}

				const result = await restoreBreakout( breakoutPostId );
				if ( result.success ) {
					notifySuccess(
						__(
							'Breakout post restored.',
							'newspack-rolling-coverage'
						)
					);
					onActionPerformed?.();
				} else {
					notifyError(
						result.error ||
							__(
								'Failed to restore breakout post.',
								'newspack-rolling-coverage'
							)
					);
				}
			},
		},
		{
			id: 'delete-breakout',
			label: __(
				'Permanently Delete Breakout Post',
				'newspack-rolling-coverage'
			),
			isEligible: ( entry: Entry ) => hasTrashedBreakout( entry ),
			callback: async ( items: Entry[] ) => {
				if ( items.length !== 1 ) {
					return;
				}

				const breakoutPostId =
					items[ 0 ].meta?.rolling_coverage_breakout_post_id;
				if ( ! breakoutPostId ) {
					return;
				}

				const result =
					await deleteBreakoutPermanently( breakoutPostId );
				if ( result.success ) {
					notifySuccess(
						__(
							'Breakout post permanently deleted.',
							'newspack-rolling-coverage'
						)
					);
					onActionPerformed?.();
				} else {
					notifyError(
						result.error ||
							__(
								'Failed to permanently delete breakout post.',
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
