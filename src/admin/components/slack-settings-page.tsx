/**
 * External dependencies
 */
import { useParams, Navigate } from 'react-router';
import { Notice } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import TabbedNavigation from 'newspack-components/dist/esm/tabbed-navigation';

/**
 * Internal dependencies
 */
import { SLACK_TABS } from '../utils/slack-tabs';
import { useHeader } from '../hooks/useHeader';
import { useSlackSettings } from '../hooks/useSlackSettings';
import { CredentialsTab } from './slack/settings/credentials-tab';
import { ChannelsTab } from './slack/settings/channels-tab';
import { IngestionSettingsTab } from './slack/settings/ingestion-settings-tab';
import { SetupGuideTab } from './slack/settings/setup-guide-tab';
import { MonitorTab } from './slack/settings/monitor-tab';

const VALID_TABS = SLACK_TABS.map( ( t ) => t.name );

/**
 * Renders the Slack settings admin page as a nested route under
 * /connection/{tab}. The active tab is driven by the :tab URL parameter,
 * making each tab directly bookmarkable and back/forward navigable. A
 * custom tab bar replaces TabPanel so tab state is always in sync with the
 * URL. All state and business logic lives in the useSlackSettings hook.
 */
function SlackSettingsPage() {
	const { tab } = useParams();

	const tabbedNavigation = useMemo(
		() => (
			<TabbedNavigation
				items={ SLACK_TABS.map( ( t ) => ( {
					label: t.title,
					href: `#/connection/${ t.name }`,
					selected: t.name === tab,
				} ) ) }
			/>
		),
		[ tab ]
	);
	useHeader( { tabbedNavigation } );

	const {
		botToken,
		setBotToken,
		signingSecret,
		setSigningSecret,
		ignorePrefix,
		setIgnorePrefix,
		channels,
		isVerifying,
		isDisconnecting,
		isSavingSettings,
		disconnectingChannelId,
		updatingAutopublishChannelId,
		workspaceInfo,
		notice,
		clearNotice,
		manifestJson,
		editUserUrl,
		isConfigured,
		handleUnlinkChannel,
		handleAutopublishChange,
		handleVerify,
		handleDisconnect,
		handleSaveSettings,
	} = useSlackSettings();

	// Guard: invalid tab param redirects to the default tab.
	if ( ! tab || ! VALID_TABS.includes( tab ) ) {
		return <Navigate to="/connection/credentials" replace />;
	}

	const renderTabContent = () => {
		switch ( tab ) {
			case 'credentials':
				return (
					<CredentialsTab
						isConfigured={ isConfigured }
						botToken={ botToken }
						setBotToken={ setBotToken }
						signingSecret={ signingSecret }
						setSigningSecret={ setSigningSecret }
						isVerifying={ isVerifying }
						isDisconnecting={ isDisconnecting }
						workspaceInfo={ workspaceInfo }
						onVerify={ handleVerify }
						onDisconnect={ handleDisconnect }
					/>
				);
			case 'channels':
				return (
					<ChannelsTab
						isConfigured={ isConfigured }
						channels={ channels }
						disconnectingChannelId={ disconnectingChannelId }
						updatingAutopublishChannelId={
							updatingAutopublishChannelId
						}
						onUnlink={ handleUnlinkChannel }
						onAutopublishChange={ handleAutopublishChange }
					/>
				);
			case 'settings':
				return (
					<IngestionSettingsTab
						isConfigured={ isConfigured }
						ignorePrefix={ ignorePrefix }
						setIgnorePrefix={ setIgnorePrefix }
						isSavingSettings={ isSavingSettings }
						onSaveSettings={ handleSaveSettings }
						workspaceInfo={ workspaceInfo }
						editUserUrl={ editUserUrl }
					/>
				);
			case 'setup':
				return <SetupGuideTab manifestJson={ manifestJson } />;
			case 'monitor':
				return <MonitorTab />;
			default:
				return null;
		}
	};

	return (
		<div className="newspack-rolling-coverage-slack-settings">
			{ notice && (
				<Notice status={ notice.type } onRemove={ clearNotice }>
					{ notice.message }
				</Notice>
			) }
			{ renderTabContent() }
		</div>
	);
}

export default SlackSettingsPage;
