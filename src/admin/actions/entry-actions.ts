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
import {
	bulkRestoreEntries,
	hasBreakout,
	hasTrashedBreakout,
	runEntryBulk,
} from '../utils/entries-api';
import { notifySuccess, notifyError, pluralize } from '../utils/notices';
import { BreakoutModal } from '../components/breakout-modal';
import { ConfirmModal } from '../components/confirm-modal';
import type { Entry, Action, AdminConfig } from '../types';

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
						'_blank',
						'noopener=yes'
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
		{
			id: 'trash-entry',
			label: __( 'Trash', 'newspack-rolling-coverage' ),
			supportsBulk: true,
			isEligible: ( entry: Entry ) => entry.status !== 'trash',
			RenderModal: ( { items, closeModal, onActionPerformed: notify } ) =>
				createElement( ConfirmModal, {
					message: pluralize(
						items.length,
						__(
							'Are you sure you want to trash this entry?',
							'newspack-rolling-coverage'
						),
						__(
							'Are you sure you want to trash these entries?',
							'newspack-rolling-coverage'
						)
					),
					confirmLabel: __( 'Trash', 'newspack-rolling-coverage' ),
					isDestructive: true,
					onConfirm: async () => {
						const { failed, succeeded } = await runEntryBulk(
							config,
							items,
							false
						);

						if ( succeeded ) {
							notifySuccess(
								pluralize(
									items.length,
									__(
										'Entry trashed.',
										'newspack-rolling-coverage'
									),
									__(
										'Entries trashed.',
										'newspack-rolling-coverage'
									)
								)
							);
							notify?.( items );
							onActionPerformed?.();
						} else {
							notifyError(
								failed[ 0 ].error ||
									__(
										'Failed to trash entry.',
										'newspack-rolling-coverage'
									)
							);
						}
					},
					onClose: closeModal ?? ( () => {} ),
				} ),
			callback: async ( items: Entry[] ) => {
				const { failed, succeeded } = await runEntryBulk(
					config,
					items,
					false
				);

				if ( succeeded ) {
					notifySuccess(
						pluralize(
							items.length,
							__( 'Entry trashed.', 'newspack-rolling-coverage' ),
							__(
								'Entries trashed.',
								'newspack-rolling-coverage'
							)
						)
					);
					onActionPerformed?.();
				} else {
					notifyError(
						failed[ 0 ].error ||
							__(
								'Failed to trash entry.',
								'newspack-rolling-coverage'
							)
					);
				}
			},
		},
		{
			id: 'restore-entry',
			label: __( 'Restore', 'newspack-rolling-coverage' ),
			supportsBulk: true,
			isEligible: ( entry: Entry ) => entry.status === 'trash',
			callback: async ( items: Entry[] ) => {
				const entryIds = items.map( ( entry ) => entry.id );
				const result = await bulkRestoreEntries(
					config.restBaseUrls.restNamespace,
					entryIds
				);

				if ( result.success && result.results ) {
					const failed = result.results.filter(
						( r ) => ! r.restored
					);

					if ( failed.length === 0 ) {
						const anyCreated = result.results.some(
							( r ) => r.coverageCreated
						);
						const isSingle = items.length === 1;

						if ( anyCreated && isSingle ) {
							notifySuccess(
								__(
									'Entry restored. A recovery coverage was created.',
									'newspack-rolling-coverage'
								)
							);
						} else if ( anyCreated ) {
							notifySuccess(
								__(
									'Entries restored. Recovery coverages were created where needed.',
									'newspack-rolling-coverage'
								)
							);
						} else if ( isSingle ) {
							notifySuccess(
								__(
									'Entry restored.',
									'newspack-rolling-coverage'
								)
							);
						} else {
							notifySuccess(
								__(
									'Entries restored.',
									'newspack-rolling-coverage'
								)
							);
						}
						onActionPerformed?.();
					} else {
						notifyError(
							failed[ 0 ].error ||
								__(
									'Failed to restore some entries.',
									'newspack-rolling-coverage'
								)
						);
					}
				} else {
					notifyError(
						result.error ||
							__(
								'Failed to restore entries.',
								'newspack-rolling-coverage'
							)
					);
				}
			},
		},
		{
			id: 'delete-entry',
			label: __( 'Delete Permanently', 'newspack-rolling-coverage' ),
			supportsBulk: true,
			isEligible: ( entry: Entry ) => entry.status === 'trash',
			RenderModal: ( { items, closeModal, onActionPerformed: notify } ) =>
				createElement( ConfirmModal, {
					message: pluralize(
						items.length,
						__(
							'Are you sure you want to permanently delete this entry? This cannot be undone.',
							'newspack-rolling-coverage'
						),
						__(
							'Are you sure you want to permanently delete these entries? This cannot be undone.',
							'newspack-rolling-coverage'
						)
					),
					confirmLabel: __(
						'Delete Permanently',
						'newspack-rolling-coverage'
					),
					isDestructive: true,
					onConfirm: async () => {
						const { failed, succeeded } = await runEntryBulk(
							config,
							items,
							true
						);

						if ( succeeded ) {
							notifySuccess(
								pluralize(
									items.length,
									__(
										'Entry permanently deleted.',
										'newspack-rolling-coverage'
									),
									__(
										'Entries permanently deleted.',
										'newspack-rolling-coverage'
									)
								)
							);
							notify?.( items );
							onActionPerformed?.();
						} else {
							notifyError(
								failed[ 0 ].error ||
									__(
										'Failed to delete entry.',
										'newspack-rolling-coverage'
									)
							);
						}
					},
					onClose: closeModal ?? ( () => {} ),
				} ),
			callback: async ( items: Entry[] ) => {
				const { failed, succeeded } = await runEntryBulk(
					config,
					items,
					true
				);

				if ( succeeded ) {
					notifySuccess(
						pluralize(
							items.length,
							__(
								'Entry permanently deleted.',
								'newspack-rolling-coverage'
							),
							__(
								'Entries permanently deleted.',
								'newspack-rolling-coverage'
							)
						)
					);
					onActionPerformed?.();
				} else {
					notifyError(
						failed[ 0 ].error ||
							__(
								'Failed to delete entry.',
								'newspack-rolling-coverage'
							)
					);
				}
			},
		},
	];
}

export { getEntryActions };
