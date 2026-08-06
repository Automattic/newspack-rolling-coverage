/**
 * External dependencies
 */
import { useParams, useNavigate, Navigate } from 'react-router';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AdminTab } from '../types';
import { useSlackSettings } from '../hooks/useSlackSettings';
import { CredentialsTab } from './slack/settings/credentials-tab';
import { ChannelsTab } from './slack/settings/channels-tab';
import { IngestionSettingsTab } from './slack/settings/ingestion-settings-tab';
import { SetupGuideTab } from './slack/settings/setup-guide-tab';
import { MonitorTab } from './slack/settings/monitor-tab';

const TABS: AdminTab[] = [
	{
		name: 'credentials',
		title: __( 'Credentials', 'newspack-rolling-coverage' ),
	},
	{
		name: 'channels',
		title: __( 'Channel Mappings', 'newspack-rolling-coverage' ),
	},
	{ name: 'settings', title: __( 'Settings', 'newspack-rolling-coverage' ) },
	{ name: 'monitor', title: __( 'Monitor', 'newspack-rolling-coverage' ) },
	{ name: 'setup', title: __( 'Setup Guide', 'newspack-rolling-coverage' ) },
];

const VALID_TABS = TABS.map( ( t ) => t.name );

/**
 * Renders the Slack settings admin page as a nested route under
 * /connection/{tab}. The active tab is driven by the :tab URL parameter,
 * making each tab directly bookmarkable and back/forward navigable. A
 * custom tab bar replaces TabPanel so tab state is always in sync with the
 * URL. All state and business logic lives in the useSlackSettings hook.
 */
function SlackSettingsPage() {
	const { tab } = useParams();
	const navigate = useNavigate();

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
			<div
				className="newspack-rolling-coverage-slack-settings__tabs"
				role="tablist"
				aria-orientation="horizontal"
			>
				{ TABS.map( ( t ) => {
					const isActive = t.name === tab;
					return (
						<Button
							key={ t.name }
							id={ `newspack-rolling-coverage-tab-${ t.name }` }
							className={ `newspack-rolling-coverage-slack-settings__tab${
								isActive ? ' is-active' : ''
							}` }
							variant="tertiary"
							role="tab"
							aria-selected={ isActive }
							aria-controls={ `newspack-rolling-coverage-tabpanel-${ t.name }` }
							tabIndex={ isActive ? 0 : -1 }
							onClick={ () =>
								navigate( `/connection/${ t.name }` )
							}
						>
							{ t.title }
						</Button>
					);
				} ) }
			</div>
			<div
				id={ `newspack-rolling-coverage-tabpanel-${ tab }` }
				role="tabpanel"
				aria-labelledby={ `newspack-rolling-coverage-tab-${ tab }` }
				tabIndex={ 0 }
			>
				{ renderTabContent() }
			</div>
		</div>
	);
}

export default SlackSettingsPage;
