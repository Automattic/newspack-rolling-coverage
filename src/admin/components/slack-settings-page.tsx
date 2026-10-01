/**
 * External dependencies
 */
import { useParams, Navigate } from 'react-router';
import {
	Button,
	ExternalLink,
	Modal,
	Notice,
	VisuallyHidden,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	createInterpolateElement,
	useCallback,
	useMemo,
	useState,
} from '@wordpress/element';
import TabbedNavigation from 'newspack-components/dist/esm/tabbed-navigation';

/**
 * Internal dependencies
 */
import { SLACK_TABS } from '../utils/slack-tabs';
import { useHeader } from '../hooks/useHeader';
import { useSlackSettings } from '../hooks/useSlackSettings';
import { ConnectionStatusDrawer } from './slack/settings/connection-status-drawer';
import { ConfirmModal } from './confirm-modal';
import { ChannelsTab } from './slack/settings/channels-tab';
import { IngestionSettingsTab } from './slack/settings/ingestion-settings-tab';
import { ConnectSlack } from './slack/settings/connect-slack';
import { MonitorTab } from './slack/settings/monitor-tab';
import { LoadingState } from '../shared/loading-state';
import type { TabHeader } from '../types';

const VALID_TABS = SLACK_TABS.map( ( t ) => t.name );

/** Route for a site not yet connected to Slack, which has no tabs. */
const SETUP_TAB = 'setup';

/**
 * Renders the Slack connection page under /connection/{tab}. A site that
 * isn't connected gets the untabbed setup page at /connection/setup; a
 * connected one gets the tabs, driven by the :tab URL parameter so each is
 * bookmarkable. Connection status and Disconnect open from the header menu.
 * Slack data and handlers come from the useSlackSettings hook.
 */
function SlackSettingsPage() {
	const { tab } = useParams();
	const {
		botToken,
		setBotToken,
		signingSecret,
		setSigningSecret,
		ignorePrefix,
		setIgnorePrefix,
		isSettingsDirty,
		channels,
		hasLoadedChannels,
		hasLoadedSettings,
		isVerifying,
		isSavingSettings,
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

	const tabbedNavigation = useMemo(
		() =>
			isConfigured ? (
				<TabbedNavigation
					items={ SLACK_TABS.map( ( t ) => ( {
						label: t.title,
						href: `#/connection/${ t.name }`,
						selected: t.name === tab,
					} ) ) }
				/>
			) : null,
		[ isConfigured, tab ]
	);
	const [ isStatusOpen, setIsStatusOpen ] = useState( false );
	const [ isDisconnectOpen, setIsDisconnectOpen ] = useState( false );

	// The Disconnect button that opened this modal left with the drawer, so
	// focus goes back to the button the drawer was opened from.
	const closeDisconnect = useCallback( () => {
		setIsDisconnectOpen( false );
		window.requestAnimationFrame( () =>
			document
				.querySelector< HTMLElement >(
					'.newspack-rolling-coverage-connection-status-button'
				)
				?.focus()
		);
	}, [] );

	const headerActions = useMemo( () => {
		if ( ! isConfigured ) {
			return null;
		}
		return (
			<>
				<Button
					variant="tertiary"
					className="newspack-rolling-coverage-status-button newspack-rolling-coverage-connection-status-button"
					onClick={ () => setIsStatusOpen( true ) }
				>
					<span
						className="newspack-rolling-coverage-status-dot"
						aria-hidden="true"
					/>
					{ __( 'Connection Status', 'newspack-rolling-coverage' ) }{ ' ' }
					<VisuallyHidden>
						{ __( '(connected)', 'newspack-rolling-coverage' ) }
					</VisuallyHidden>
				</Button>
				{ tab === 'settings' && (
					<Button
						variant="primary"
						onClick={ handleSaveSettings }
						isBusy={ isSavingSettings }
						disabled={ isSavingSettings || ! isSettingsDirty }
					>
						{ __( 'Save', 'newspack-rolling-coverage' ) }
					</Button>
				) }
			</>
		);
	}, [
		isConfigured,
		tab,
		handleSaveSettings,
		isSavingSettings,
		isSettingsDirty,
	] );
	const [ channelsHeader, setChannelsHeader ] = useState< TabHeader >( {} );
	const [ monitorHeader, setMonitorHeader ] = useState< TabHeader >( {} );

	useHeader( {
		tabbedNavigation,
		actions: headerActions,
		...( tab === 'channels' ? channelsHeader : {} ),
		...( tab === 'monitor' ? monitorHeader : {} ),
	} );

	if ( ! isConfigured ) {
		if ( tab !== SETUP_TAB ) {
			return <Navigate to={ `/connection/${ SETUP_TAB }` } replace />;
		}
		return (
			<div className="newspack-rolling-coverage-slack-settings">
				{ notice && (
					<Notice status={ notice.type } onRemove={ clearNotice }>
						{ notice.message }
					</Notice>
				) }
				<ConnectSlack
					manifestJson={ manifestJson }
					botToken={ botToken }
					setBotToken={ setBotToken }
					signingSecret={ signingSecret }
					setSigningSecret={ setSigningSecret }
					isVerifying={ isVerifying }
					onVerify={ handleVerify }
				/>
			</div>
		);
	}

	// Guard: invalid tab param redirects to the default tab.
	if ( ! tab || ! VALID_TABS.includes( tab ) ) {
		return <Navigate to="/connection/channels" replace />;
	}

	const renderTabContent = () => {
		switch ( tab ) {
			case 'channels':
				return (
					<ChannelsTab
						channels={ channels }
						hasLoadedChannels={ hasLoadedChannels }
						onUnlink={ handleUnlinkChannel }
						onAutopublishChange={ handleAutopublishChange }
						onHeaderChange={ setChannelsHeader }
					/>
				);
			case 'settings':
				if ( hasLoadedSettings && ! workspaceInfo ) {
					return (
						<Notice status="error" isDismissible={ false }>
							{ __(
								'The Slack settings could not be loaded. Reload the page to try again.',
								'newspack-rolling-coverage'
							) }
						</Notice>
					);
				}
				return ! hasLoadedSettings ? (
					<LoadingState
						label={ __(
							'Fetching settings…',
							'newspack-rolling-coverage'
						) }
					/>
				) : (
					<IngestionSettingsTab
						ignorePrefix={ ignorePrefix }
						setIgnorePrefix={ setIgnorePrefix }
						workspaceInfo={ workspaceInfo }
						editUserUrl={ editUserUrl }
					/>
				);
			case 'monitor':
				return (
					<MonitorTab
						channels={ channels }
						onHeaderChange={ setMonitorHeader }
					/>
				);
			default:
				return null;
		}
	};

	return (
		<div
			className={
				tab === 'channels' || tab === 'monitor'
					? undefined
					: 'newspack-rolling-coverage-slack-settings'
			}
		>
			{ notice && (
				<Notice status={ notice.type } onRemove={ clearNotice }>
					{ notice.message }
				</Notice>
			) }
			{ workspaceInfo?.can_read_files === false && (
				<Notice status="warning" isDismissible={ false }>
					{ createInterpolateElement(
						/* translators: <a> wraps the link to the site's Slack apps, <code> wraps the name of a Slack permission. */
						__(
							'Images posted in Slack are left out of entries, because the Slack app is not allowed to read files. At <a>api.slack.com/apps</a>, open the app, add the <code>files:read</code> scope under OAuth & Permissions, and reinstall the app to your workspace.',
							'newspack-rolling-coverage'
						),
						{
							a: (
								<ExternalLink href="https://api.slack.com/apps">
									{ null }
								</ExternalLink>
							),
							code: <code />,
						}
					) }
				</Notice>
			) }
			{ renderTabContent() }
			<ConnectionStatusDrawer
				isOpen={ isStatusOpen }
				onClose={ () => setIsStatusOpen( false ) }
				workspaceInfo={ workspaceInfo }
				manifestJson={ manifestJson }
				onDisconnect={ () => {
					setIsStatusOpen( false );
					setIsDisconnectOpen( true );
				} }
			/>
			{ isDisconnectOpen && (
				<Modal
					title={ __(
						'Disconnect Slack',
						'newspack-rolling-coverage'
					) }
					onRequestClose={ closeDisconnect }
				>
					<ConfirmModal
						message={ __(
							'Are you sure you want to disconnect Slack? All channel mappings will be removed.',
							'newspack-rolling-coverage'
						) }
						confirmLabel={ __(
							'Disconnect',
							'newspack-rolling-coverage'
						) }
						isDestructive
						onConfirm={ handleDisconnect }
						onClose={ closeDisconnect }
					/>
				</Modal>
			) }
		</div>
	);
}

export default SlackSettingsPage;
