/**
 * External dependencies
 */
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Coverage, Action, AdminConfig } from '../types';
import {
	trashCoverage,
	restoreCoverage,
	deleteCoverage,
	runCoverageBulk,
} from '../utils/coverage-api';
import { notifySuccess, notifyError, pluralize } from '../utils/notices';
import { ConfirmModal } from '../components/confirm-modal';

/**
 * Returns DataViews action definitions for coverage rows.
 *
 * @param {AdminConfig}                  config              Admin config with REST URLs.
 * @param {() => void}                   onActionPerformed   Callback to refresh data after an action.
 * @param {(coverage: Coverage) => void} onNavigateToEntries Callback to navigate to the entry list.
 * @param {(coverage: Coverage) => void} onEdit              Callback to open the edit modal.
 * @param {(coverage: Coverage) => void} onSlackConnect      Callback to open the Slack connection modal.
 *
 * @return {Action<Coverage>[]} Array of DataViews actions for coverages.
 */
function getCoverageActions(
	config: AdminConfig,
	onActionPerformed: () => void,
	onNavigateToEntries: ( coverage: Coverage ) => void,
	onEdit: ( coverage: Coverage ) => void,
	onSlackConnect: ( coverage: Coverage ) => void
): Action< Coverage >[] {
	const restNamespace = config.restBaseUrls.restNamespace;

	return [
		{
			id: 'edit-coverage',
			label: __( 'Edit', 'newspack-rolling-coverage' ),
			isEligible: ( coverage: Coverage ) =>
				coverage.meta?.[ config.taxMeta.statusKey ] !== 'trash',
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
		{
			id: 'trash-coverage',
			label: __( 'Trash', 'newspack-rolling-coverage' ),
			supportsBulk: true,
			isEligible: ( coverage: Coverage ) =>
				coverage.meta?.[ config.taxMeta.statusKey ] !== 'trash',
			RenderModal: ( { items, closeModal, onActionPerformed: notify } ) =>
				createElement( ConfirmModal, {
					message: pluralize(
						items.length,
						__(
							'Are you sure you want to trash this coverage? Its entries will be hidden from the frontend until the coverage is restored.',
							'newspack-rolling-coverage'
						),
						__(
							'Are you sure you want to trash these coverages? Their entries will be hidden from the frontend until the coverages are restored.',
							'newspack-rolling-coverage'
						)
					),
					confirmLabel: __( 'Trash', 'newspack-rolling-coverage' ),
					isDestructive: true,
					onConfirm: async () => {
						const { failed, succeeded } = await runCoverageBulk(
							items,
							( id ) => trashCoverage( restNamespace, id )
						);

						if ( succeeded ) {
							notifySuccess(
								pluralize(
									items.length,
									__(
										'Coverage trashed.',
										'newspack-rolling-coverage'
									),
									__(
										'Coverages trashed.',
										'newspack-rolling-coverage'
									)
								)
							);
							notify?.( items );
							onActionPerformed();
						} else {
							notifyError(
								failed[ 0 ].error ||
									__(
										'Failed to trash coverage.',
										'newspack-rolling-coverage'
									)
							);
						}
					},
					onClose: closeModal ?? ( () => {} ),
				} ),
			callback: async ( items: Coverage[] ) => {
				const { failed, succeeded } = await runCoverageBulk(
					items,
					( id ) => trashCoverage( restNamespace, id )
				);

				if ( succeeded ) {
					notifySuccess(
						pluralize(
							items.length,
							__(
								'Coverage trashed.',
								'newspack-rolling-coverage'
							),
							__(
								'Coverages trashed.',
								'newspack-rolling-coverage'
							)
						)
					);
					onActionPerformed();
				} else {
					notifyError(
						failed[ 0 ].error ||
							__(
								'Failed to trash coverage.',
								'newspack-rolling-coverage'
							)
					);
				}
			},
		},
		{
			id: 'restore-coverage',
			label: __( 'Restore', 'newspack-rolling-coverage' ),
			supportsBulk: true,
			isEligible: ( coverage: Coverage ) =>
				coverage.meta?.[ config.taxMeta.statusKey ] === 'trash',
			callback: async ( items: Coverage[] ) => {
				const { failed, succeeded } = await runCoverageBulk(
					items,
					( id ) => restoreCoverage( restNamespace, id )
				);

				if ( succeeded ) {
					notifySuccess(
						pluralize(
							items.length,
							__(
								'Coverage restored.',
								'newspack-rolling-coverage'
							),
							__(
								'Coverages restored.',
								'newspack-rolling-coverage'
							)
						)
					);
					onActionPerformed();
				} else {
					notifyError(
						failed[ 0 ].error ||
							__(
								'Failed to restore coverage.',
								'newspack-rolling-coverage'
							)
					);
				}
			},
		},
		{
			id: 'delete-coverage',
			label: __( 'Delete Permanently', 'newspack-rolling-coverage' ),
			supportsBulk: true,
			isEligible: ( coverage: Coverage ) =>
				coverage.meta?.[ config.taxMeta.statusKey ] === 'trash',
			RenderModal: ( { items, closeModal, onActionPerformed: notify } ) =>
				createElement( ConfirmModal, {
					message: pluralize(
						items.length,
						__(
							'Are you sure you want to permanently delete this coverage? This cannot be undone. Associated entries (excluding trashed entries) will be purged',
							'newspack-rolling-coverage'
						),
						__(
							'Are you sure you want to permanently delete these coverages? This cannot be undone. Associated entries (excluding trashed entries) will be purged',
							'newspack-rolling-coverage'
						)
					),
					confirmLabel: __(
						'Delete Permanently',
						'newspack-rolling-coverage'
					),
					isDestructive: true,
					onConfirm: async () => {
						const { failed, succeeded } = await runCoverageBulk(
							items,
							( id ) => deleteCoverage( restNamespace, id )
						);

						if ( succeeded ) {
							notifySuccess(
								pluralize(
									items.length,
									__(
										'Coverage permanently deleted.',
										'newspack-rolling-coverage'
									),
									__(
										'Coverages permanently deleted.',
										'newspack-rolling-coverage'
									)
								)
							);
							notify?.( items );
							onActionPerformed();
						} else {
							notifyError(
								failed[ 0 ].error ||
									__(
										'Failed to delete coverage.',
										'newspack-rolling-coverage'
									)
							);
						}
					},
					onClose: closeModal ?? ( () => {} ),
				} ),
			callback: async ( items: Coverage[] ) => {
				const { failed, succeeded } = await runCoverageBulk(
					items,
					( id ) => deleteCoverage( restNamespace, id )
				);

				if ( succeeded ) {
					notifySuccess(
						pluralize(
							items.length,
							__(
								'Coverage permanently deleted.',
								'newspack-rolling-coverage'
							),
							__(
								'Coverages permanently deleted.',
								'newspack-rolling-coverage'
							)
						)
					);
					onActionPerformed();
				} else {
					notifyError(
						failed[ 0 ].error ||
							__(
								'Failed to delete coverage.',
								'newspack-rolling-coverage'
							)
					);
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
