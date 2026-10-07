/**
 * External dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Coverage, Action, AdminConfig, RequestConfirm } from '../types';
import {
	trashCoverage,
	restoreCoverage,
	deleteCoverage,
	runCoverageBulk,
} from '../utils/coverage-api';
import { notifySuccess, notifyError, pluralize } from '../utils/notices';

/**
 * Returns DataViews action definitions for coverage rows.
 *
 * @param {AdminConfig}                  config              Admin config with REST URLs.
 * @param {() => void}                   onActionPerformed   Callback to refresh data after an action.
 * @param {(coverage: Coverage) => void} onNavigateToEntries Callback to navigate to the entry list.
 * @param {(coverage: Coverage) => void} onEdit              Callback to open the edit modal.
 * @param {(coverage: Coverage) => void} onSlackConnect      Callback to open the Slack connection drawer.
 * @param {RequestConfirm}               requestConfirm      Opens the view's confirmation dialog.
 * @param {(coverage: Coverage) => void} onViewPages         Callback to open the drawer listing the coverage's pages.
 *
 * @return {Action<Coverage>[]} Array of DataViews actions for coverages.
 */
function getCoverageActions(
	config: AdminConfig,
	onActionPerformed: () => void,
	onNavigateToEntries: ( coverage: Coverage ) => void,
	onEdit: ( coverage: Coverage ) => void,
	onSlackConnect: ( coverage: Coverage ) => void,
	requestConfirm: RequestConfirm,
	onViewPages: ( coverage: Coverage ) => void
): Action< Coverage >[] {
	const restNamespace = config.restBaseUrls.restNamespace;

	// Coverage management requires the `manage_categories` capability
	// (Editors and above). Lower roles get a read-only coverage list and
	// may still navigate into the entries they can access.
	const canManage = config.capabilities.canManageTerms;

	return [
		{
			id: 'edit-coverage',
			label: __( 'Edit', 'newspack-rolling-coverage' ),
			isEligible: ( coverage: Coverage ) =>
				canManage &&
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
			callback: ( items: Coverage[] ) => {
				if ( items.length === 1 ) {
					onNavigateToEntries( items[ 0 ] );
				}
			},
		},
		{
			id: 'view-pages',
			label: __( 'View Pages', 'newspack-rolling-coverage' ),
			isEligible: ( coverage: Coverage ) =>
				( coverage.placements ?? [] ).length > 0,
			callback: ( items: Coverage[] ) => {
				if ( items.length === 1 ) {
					onViewPages( items[ 0 ] );
				}
			},
		},
		...( config.slack.isConfigured
			? [
					{
						id: 'connect-slack',
						label: __(
							'Slack Connection',
							'newspack-rolling-coverage'
						),
						isEligible: () => config.capabilities.canManageOptions,
						callback: ( items: Coverage[] ) => {
							if ( items.length === 1 ) {
								onSlackConnect( items[ 0 ] );
							}
						},
					},
				]
			: [] ),
		{
			id: 'trash-coverage',
			label: __( 'Trash', 'newspack-rolling-coverage' ),
			supportsBulk: true,
			isEligible: ( coverage: Coverage ) =>
				canManage &&
				coverage.meta?.[ config.taxMeta.statusKey ] !== 'trash',
			callback: ( items: Coverage[] ) =>
				requestConfirm( {
					title: pluralize(
						items.length,
						__(
							'Trash this coverage?',
							'newspack-rolling-coverage'
						),
						sprintf(
							/* translators: %d: number of coverages. */
							_n(
								'Trash %d coverage?',
								'Trash %d coverages?',
								items.length,
								'newspack-rolling-coverage'
							),
							items.length
						)
					),
					description: pluralize(
						items.length,
						__(
							'Its entries will be hidden from the frontend until the coverage is restored.',
							'newspack-rolling-coverage'
						),
						__(
							'Their entries will be hidden from the frontend until the coverages are restored.',
							'newspack-rolling-coverage'
						)
					),
					confirmLabel: __( 'Trash', 'newspack-rolling-coverage' ),
					intent: 'irreversible',
					onConfirm: async () => {
						const { failed, succeeded } = await runCoverageBulk(
							items,
							( id ) => trashCoverage( restNamespace, id )
						);

						if ( ! succeeded ) {
							const error =
								failed[ 0 ].error ||
								__(
									'Failed to trash coverage.',
									'newspack-rolling-coverage'
								);
							// Some items went through, so a retry would resend those too.
							// Refresh the list and report the failure instead.
							if ( failed.length < items.length ) {
								notifyError( error );
								onActionPerformed();
								return;
							}
							return { error };
						}

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
					},
				} ),
		},
		{
			id: 'restore-coverage',
			label: __( 'Restore', 'newspack-rolling-coverage' ),
			supportsBulk: true,
			isEligible: ( coverage: Coverage ) =>
				canManage &&
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
				canManage &&
				coverage.meta?.[ config.taxMeta.statusKey ] === 'trash',
			callback: ( items: Coverage[] ) =>
				requestConfirm( {
					title: pluralize(
						items.length,
						__(
							'Permanently delete this coverage?',
							'newspack-rolling-coverage'
						),
						sprintf(
							/* translators: %d: number of coverages. */
							_n(
								'Permanently delete %d coverage?',
								'Permanently delete %d coverages?',
								items.length,
								'newspack-rolling-coverage'
							),
							items.length
						)
					),
					description: pluralize(
						items.length,
						__(
							'This cannot be undone. Its entries are deleted too, apart from any already in the trash.',
							'newspack-rolling-coverage'
						),
						__(
							'This cannot be undone. Their entries are deleted too, apart from any already in the trash.',
							'newspack-rolling-coverage'
						)
					),
					confirmLabel: __(
						'Delete Permanently',
						'newspack-rolling-coverage'
					),
					intent: 'irreversible',
					onConfirm: async () => {
						const { failed, succeeded } = await runCoverageBulk(
							items,
							( id ) => deleteCoverage( restNamespace, id )
						);

						if ( ! succeeded ) {
							const error =
								failed[ 0 ].error ||
								__(
									'Failed to delete coverage.',
									'newspack-rolling-coverage'
								);
							// Some items went through, so a retry would resend those too.
							// Refresh the list and report the failure instead.
							if ( failed.length < items.length ) {
								notifyError( error );
								onActionPerformed();
								return;
							}
							return { error };
						}

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
					},
				} ),
		},
	];
}

export { getCoverageActions };
