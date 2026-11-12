/**
 * WordPress dependencies
 */
import { useMemo, useRef } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';
import { EmptyState } from 'newspack-components/dist/esm/empty-state';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { useHeader } from '../hooks/useHeader';
import { useReportDirty } from '../hooks/useReportDirty';
import { useSlackIngestionSettings } from '../hooks/useSlackIngestionSettings';
import { IngestionSettingsTab } from './slack/settings/ingestion-settings-tab';
import { LoadingState } from '../shared/loading-state';
import { SlackIcon } from '../shared/icons/slack-icon';
import type { ReactNode } from 'react';

/**
 * The Slack tab of Settings: which Slack messages become entries, how they
 * are formatted, and the WordPress user they are credited to. Save lives in
 * the page header. Until Slack is connected, the tab points to Slack
 * Connection instead.
 *
 * @param {Object}    props                  Component props.
 * @param {ReactNode} props.tabbedNavigation The Settings tabs.
 * @param {Function}  props.onDirtyChange    Receives whether the tab has unsaved changes.
 */
function SlackSettings( {
	tabbedNavigation,
	onDirtyChange,
}: {
	tabbedNavigation: ReactNode;
	onDirtyChange: ( isDirty: boolean ) => void;
} ) {
	const config = useAdminContext();
	const settings = useSlackIngestionSettings();
	const { isDirty, isSaving, hasLoaded, workspaceInfo, error } = settings;
	const isConnected = config.slack.isConfigured;
	const canEdit = isConnected && hasLoaded && Boolean( workspaceInfo );

	useReportDirty( onDirtyChange, canEdit && isDirty && ! isSaving );

	const saveRef = useRef( settings.handleSave );
	saveRef.current = settings.handleSave;

	const headerActions = useMemo(
		() =>
			canEdit ? (
				<Button
					variant="primary"
					onClick={ () => saveRef.current() }
					isBusy={ isSaving }
					disabled={ isSaving || ! isDirty }
					accessibleWhenDisabled
				>
					{ __( 'Save', 'newspack-rolling-coverage' ) }
				</Button>
			) : null,
		[ canEdit, isSaving, isDirty ]
	);

	useHeader( {
		tabbedNavigation,
		actions: headerActions,
		subTitle: __(
			'Set how Slack messages become liveblog entries.',
			'newspack-rolling-coverage'
		),
	} );

	if ( ! isConnected ) {
		return (
			<div className="newspack-rolling-coverage-settings">
				<EmptyState.Root>
					<EmptyState.Header
						icon={ <SlackIcon size={ 36 } /> }
						title={ __(
							'Connect Slack first',
							'newspack-rolling-coverage'
						) }
						description={ __(
							'These settings apply once Slack is connected. Connect a Slack workspace to post liveblog entries from Slack.',
							'newspack-rolling-coverage'
						) }
					/>
					<EmptyState.Actions>
						<Button
							variant="primary"
							href={ config.adminUrls.slackConnection }
						>
							{ __(
								'Go to Slack Connection',
								'newspack-rolling-coverage'
							) }
						</Button>
					</EmptyState.Actions>
				</EmptyState.Root>
			</div>
		);
	}

	return (
		<Stack
			direction="column"
			gap="xl"
			className="newspack-rolling-coverage-settings"
		>
			{ error && (
				<Notice
					status="error"
					politeness={ canEdit ? 'assertive' : 'polite' }
					isDismissible={ canEdit }
					onRemove={ settings.clearError }
				>
					{ error }
				</Notice>
			) }
			{ ! hasLoaded && (
				<LoadingState
					label={ __(
						'Fetching settings…',
						'newspack-rolling-coverage'
					) }
				/>
			) }
			{ canEdit && (
				<div>
					<IngestionSettingsTab
						ignorePrefix={ settings.ignorePrefix }
						setIgnorePrefix={ settings.setIgnorePrefix }
						publishReaction={ settings.publishReaction }
						setPublishReaction={ settings.setPublishReaction }
						draftReaction={ settings.draftReaction }
						setDraftReaction={ settings.setDraftReaction }
						topHeadingLevel={ settings.topHeadingLevel }
						setTopHeadingLevel={ settings.setTopHeadingLevel }
						workspaceInfo={ workspaceInfo }
						editUserUrl={ config.adminUrls.editUser }
					/>
				</div>
			) }
		</Stack>
	);
}

export default SlackSettings;
