/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Drawer } from 'newspack-components/dist/esm/drawer';

/**
 * Internal dependencies
 */
import { useSlackConnection } from '../hooks/useSlackConnection';
import type { SlackConnectionDrawerProps } from '../types';
import { ConnectedChannelView } from './slack/connection-drawer/connected-channel-view';
import { ConnectChannelForm } from './slack/connection-drawer/connect-channel-form';

/**
 * Drawer for connecting or disconnecting a coverage term to a Slack channel.
 * Detects connected vs. disconnected mode based on the term's slack channel
 * meta. In connected mode, fetches the current publish mode from the
 * channel map and lets the admin change it inline. Stays mounted so the
 * drawer can play its exit animation.
 *
 * @param {SlackConnectionDrawerProps} props Component props.
 */
function SlackConnectionDrawer( {
	isOpen,
	coverage,
	onClose,
	onSaved,
}: SlackConnectionDrawerProps ) {
	const {
		channelId,
		channelName,
		channel,
		setChannel,
		publishMode,
		setPublishMode,
		lastSyncTs,
		isConnecting,
		isDisconnecting,
		isUpdatingPublishMode,
		error,
		handleConnect,
		handleDisconnect,
		handlePublishModeChange,
	} = useSlackConnection( isOpen, coverage, onSaved, onClose );

	const isBusy = isConnecting || isDisconnecting;

	// Closing mid-request would hide its outcome, including an error, so the
	// drawer stays open until a connect or disconnect settles.
	const handleRequestClose = () => {
		if ( ! isBusy ) {
			onClose();
		}
	};

	return (
		<Drawer.Root isOpen={ isOpen } onRequestClose={ handleRequestClose }>
			<Drawer.Header>
				<Drawer.Title>
					{ __( 'Slack Connection', 'newspack-rolling-coverage' ) }
				</Drawer.Title>
				<Drawer.CloseIcon />
			</Drawer.Header>
			<Drawer.Content>
				{ channelId ? (
					<ConnectedChannelView
						channelName={ channelName }
						channelId={ channelId }
						lastSyncTs={ lastSyncTs }
						publishMode={ publishMode }
						onPublishModeChange={ handlePublishModeChange }
						isUpdatingPublishMode={ isUpdatingPublishMode }
						error={ error }
					/>
				) : (
					<ConnectChannelForm
						channel={ channel }
						onChannelChange={ setChannel }
						publishMode={ publishMode }
						onPublishModeChange={ setPublishMode }
						isConnecting={ isConnecting }
						error={ error }
					/>
				) }
			</Drawer.Content>
			<Drawer.Footer>
				<Drawer.Action variant="secondary" closes disabled={ isBusy }>
					{ channelId
						? __( 'Close', 'newspack-rolling-coverage' )
						: __( 'Cancel', 'newspack-rolling-coverage' ) }
				</Drawer.Action>
				{ channelId ? (
					<Button
						variant="secondary"
						isDestructive
						onClick={ handleDisconnect }
						isBusy={ isDisconnecting }
						disabled={ isDisconnecting }
					>
						{ __( 'Disconnect', 'newspack-rolling-coverage' ) }
					</Button>
				) : (
					<Drawer.Action
						variant="primary"
						onClick={ handleConnect }
						isBusy={ isConnecting }
						disabled={ isConnecting || channel.trim() === '' }
					>
						{ __( 'Connect', 'newspack-rolling-coverage' ) }
					</Drawer.Action>
				) }
			</Drawer.Footer>
		</Drawer.Root>
	);
}

export { SlackConnectionDrawer };
