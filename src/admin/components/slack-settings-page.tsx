/**
 * External dependencies
 */
import { Notice, TabPanel } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { useSlackSettings } from '../hooks/useSlackSettings';
import { CredentialsTab } from './slack/settings/credentials-tab';
import { ChannelsTab } from './slack/settings/channels-tab';
import { IngestionSettingsTab } from './slack/settings/ingestion-settings-tab';
import { SetupGuideTab } from './slack/settings/setup-guide-tab';

/**
 * Renders the Slack settings admin page. Mounted to the
 * #newspack-rolling-coverage-slack container on the Slack submenu.
 * Presents three tabs — Credentials, Channel Mappings, and Setup Guide —
 * dispatching each tab's render to a dedicated sub-component. All state and
 * business logic lives in the useSlackSettings hook.
 */
function SlackSettingsPage() {
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
		tabs,
		editUserUrl,
		isConfigured,
		handleUnlinkChannel,
		handleAutopublishChange,
		handleVerify,
		handleDisconnect,
		handleSaveSettings,
	} = useSlackSettings();

	return (
		<div className="newspack-rolling-coverage-slack-settings">
			{ notice && (
				<Notice status={ notice.type } onRemove={ clearNotice }>
					{ notice.message }
				</Notice>
			) }
			<TabPanel tabs={ tabs } initialTabName="credentials">
				{ ( tab ) => {
					if ( tab.name === 'credentials' ) {
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
								editUserUrl={ editUserUrl }
								onVerify={ handleVerify }
								onDisconnect={ handleDisconnect }
							/>
						);
					}

					if ( tab.name === 'channels' ) {
						return (
							<ChannelsTab
								isConfigured={ isConfigured }
								channels={ channels }
								disconnectingChannelId={
									disconnectingChannelId
								}
								updatingAutopublishChannelId={
									updatingAutopublishChannelId
								}
								onUnlink={ handleUnlinkChannel }
								onAutopublishChange={ handleAutopublishChange }
							/>
						);
					}

					if ( tab.name === 'settings' ) {
						return (
							<IngestionSettingsTab
								isConfigured={ isConfigured }
								ignorePrefix={ ignorePrefix }
								setIgnorePrefix={ setIgnorePrefix }
								isSavingSettings={ isSavingSettings }
								onSaveSettings={ handleSaveSettings }
							/>
						);
					}

					return <SetupGuideTab manifestJson={ manifestJson } />;
				} }
			</TabPanel>
		</div>
	);
}

export default SlackSettingsPage;
