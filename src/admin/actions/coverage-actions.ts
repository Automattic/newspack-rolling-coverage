/**
 * External dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { backup, trash } from '@wordpress/icons';

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
 * @param {(coverage: Coverage) => void} onViewPlacements    Callback to open the drawer listing the coverage's placements.
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
	onViewPlacements: ( coverage: Coverage ) => void
): Action< Coverage >[] {
	const restNamespace = config.restBaseUrls.restNamespace;

	// Coverage management requires the `manage_categories` capability
	// (Editors and above). Lower roles get a read-only coverage list and
	// may still navigate into the entries they can access.
	const canManage = config.capabilities.canManageTerms;

	// Shared by the Restore action and the Undo on the trash notice.
	const restoreCoverages = async ( items: Coverage[] ) => {
		const { failed, succeeded } = await runCoverageBulk( items, ( id ) =>
			restoreCoverage( restNamespace, id )
		);

		if ( succeeded ) {
			notifySuccess(
				pluralize(
					items.length,
					__( 'Liveblog restored.', 'newspack-rolling-coverage' ),
					__( 'Liveblogs restored.', 'newspack-rolling-coverage' )
				)
			);
			onActionPerformed();
		} else {
			notifyError(
				failed[ 0 ].error ||
					__(
						'Failed to restore liveblog.',
						'newspack-rolling-coverage'
					)
			);
			// Some went through, so refresh the list to show them.
			if ( failed.length < items.length ) {
				onActionPerformed();
			}
		}
	};

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
			id: 'placements',
			label: __( 'Placements', 'newspack-rolling-coverage' ),
			isEligible: ( coverage: Coverage ) =>
				( coverage.placements ?? [] ).length > 0,
			callback: ( items: Coverage[] ) => {
				if ( items.length === 1 ) {
					onViewPlacements( items[ 0 ] );
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
			icon: trash,
			supportsBulk: true,
			isEligible: ( coverage: Coverage ) =>
				canManage &&
				coverage.meta?.[ config.taxMeta.statusKey ] !== 'trash',
			callback: ( items: Coverage[] ) =>
				requestConfirm( {
					title: pluralize(
						items.length,
						__(
							'Trash this liveblog?',
							'newspack-rolling-coverage'
						),
						sprintf(
							/* translators: %d: number of liveblogs. */
							_n(
								'Trash %d liveblog?',
								'Trash %d liveblogs?',
								items.length,
								'newspack-rolling-coverage'
							),
							items.length
						)
					),
					description: [
						pluralize(
							items.length,
							__(
								'Its entries will be hidden from the frontend until the liveblog is restored.',
								'newspack-rolling-coverage'
							),
							__(
								'Their entries will be hidden from the frontend until the liveblogs are restored.',
								'newspack-rolling-coverage'
							)
						),
						// A trashed coverage takes no messages from its Slack channel.
						items.some(
							( coverage ) =>
								!! coverage.meta
									?.rolling_coverage_slack_channel_id
						)
							? pluralize(
									items.length,
									__(
										'Messages posted in its linked Slack channel while it’s in the trash won’t become entries, and restoring it won’t bring them in.',
										'newspack-rolling-coverage'
									),
									__(
										'Messages posted in their linked Slack channels while they’re in the trash won’t become entries, and restoring them won’t bring them in.',
										'newspack-rolling-coverage'
									)
								)
							: '',
					]
						.filter( Boolean )
						.join( ' ' ),
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
									'Failed to trash liveblog.',
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
									'Liveblog trashed.',
									'newspack-rolling-coverage'
								),
								__(
									'Liveblogs trashed.',
									'newspack-rolling-coverage'
								)
							),
							[
								{
									label: __(
										'Undo',
										'newspack-rolling-coverage'
									),
									onClick: () => restoreCoverages( items ),
								},
							]
						);
						onActionPerformed();
					},
				} ),
		},
		{
			id: 'restore-coverage',
			label: __( 'Restore', 'newspack-rolling-coverage' ),
			icon: backup,
			supportsBulk: true,
			isEligible: ( coverage: Coverage ) =>
				canManage &&
				coverage.meta?.[ config.taxMeta.statusKey ] === 'trash',
			callback: restoreCoverages,
		},
		{
			id: 'delete-coverage',
			label: __( 'Delete Permanently', 'newspack-rolling-coverage' ),
			icon: trash,
			supportsBulk: true,
			isEligible: ( coverage: Coverage ) =>
				canManage &&
				coverage.meta?.[ config.taxMeta.statusKey ] === 'trash',
			callback: ( items: Coverage[] ) =>
				requestConfirm( {
					title: pluralize(
						items.length,
						__(
							'Permanently delete this liveblog?',
							'newspack-rolling-coverage'
						),
						sprintf(
							/* translators: %d: number of liveblogs. */
							_n(
								'Permanently delete %d liveblog?',
								'Permanently delete %d liveblogs?',
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
									'Failed to delete liveblog.',
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
									'Liveblog permanently deleted.',
									'newspack-rolling-coverage'
								),
								__(
									'Liveblogs permanently deleted.',
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
