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
 * meta. In connected mode, fetches the current autopublish setting from the
 * channel map and lets the admin toggle it inline. Stays mounted so the
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
		autopublish,
		setAutopublish,
		lastSyncTs,
		isConnecting,
		isDisconnecting,
		isUpdatingAutopublish,
		error,
		handleConnect,
		handleDisconnect,
		handleAutopublishChange,
	} = useSlackConnection( isOpen, coverage, onSaved, onClose );

	const isBusy = isConnecting || isDisconnecting;

	return (
		<Drawer.Root isOpen={ isOpen } onRequestClose={ onClose }>
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
						autopublish={ autopublish }
						onAutopublishChange={ handleAutopublishChange }
						isUpdatingAutopublish={ isUpdatingAutopublish }
						error={ error }
					/>
				) : (
					<ConnectChannelForm
						channel={ channel }
						onChannelChange={ setChannel }
						autopublish={ autopublish }
						onAutopublishChange={ setAutopublish }
						isConnecting={ isConnecting }
						error={ error }
					/>
				) }
			</Drawer.Content>
			<Drawer.Footer>
				<Drawer.Action variant="secondary" closes disabled={ isBusy }>
					{ __( 'Cancel', 'newspack-rolling-coverage' ) }
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
