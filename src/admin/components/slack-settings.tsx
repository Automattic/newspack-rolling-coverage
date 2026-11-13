/**
 * WordPress dependencies
 */
import { useCallback, useMemo, useRef, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { useHeader } from '../hooks/useHeader';
import { useReportDirty } from '../hooks/useReportDirty';
import { useSlackIngestionSettings } from '../hooks/useSlackIngestionSettings';
import { IngestionSettingsTab } from './slack/settings/ingestion-settings-tab';
import { SlackSetup } from './slack/settings/connect-slack';
import { LoadingState } from '../shared/loading-state';
import type { ReactNode } from 'react';

/**
 * The Slack tab of Settings: which Slack messages become entries, how they
 * are formatted, and the WordPress user they are credited to. Save lives in
 * the page header. Until Slack is connected, the tab shows the Slack setup
 * screen, and the form once the setup connects.
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
	const [ isConnected, setIsConnected ] = useState(
		config.slack.isConfigured
	);
	const handleConnected = useCallback( () => {
		config.slack.isConfigured = true;
		setIsConnected( true );
	}, [ config ] );
	const settings = useSlackIngestionSettings( isConnected );
	const { isDirty, isSaving, hasLoaded, workspaceInfo, error } = settings;
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
				<SlackSetup onConnected={ handleConnected } />
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
