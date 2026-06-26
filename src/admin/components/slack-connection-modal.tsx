/**
 * External dependencies
 */
import {
	Modal,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useSlackConnection } from '../hooks/useSlackConnection';
import type { SlackConnectionModalProps } from '../types';
import { ConnectedChannelView } from './slack/connection-modal/connected-channel-view';
import { ConnectChannelForm } from './slack/connection-modal/connect-channel-form';
import { ConnectionModalFooter } from './slack/connection-modal/connection-modal-footer';

/**
 * Modal for connecting or disconnecting a liveblog term to a Slack channel.
 * Detects connected vs. disconnected mode based on the term's slack channel
 * meta. In connected mode, fetches the current autopublish setting from the
 * channel map and lets the admin toggle it inline.
 *
 * @param {SlackConnectionModalProps} props Component props.
 */
function SlackConnectionModal( {
	liveblog,
	onClose,
	onSaved,
}: SlackConnectionModalProps ) {
	const {
		channelId,
		channelName,
		channel,
		setChannel,
		autopublish,
		setAutopublish,
		isConnecting,
		isDisconnecting,
		isUpdatingAutopublish,
		error,
		handleConnect,
		handleDisconnect,
		handleAutopublishChange,
	} = useSlackConnection( liveblog, onSaved, onClose );

	const mode = channelId ? 'connected' : 'connect';
	const canConnect = channel.trim() !== '';

	return (
		<Modal
			title={ __( 'Slack Connection', 'newspack-rolling-coverage' ) }
			onRequestClose={ onClose }
			size="medium"
		>
			<VStack spacing={ 6 }>
				{ channelId ? (
					<ConnectedChannelView
						channelName={ channelName }
						channelId={ channelId }
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

				<ConnectionModalFooter
					mode={ mode }
					isConnecting={ isConnecting }
					isDisconnecting={ isDisconnecting }
					canConnect={ canConnect }
					onClose={ onClose }
					onConnect={ handleConnect }
					onDisconnect={ handleDisconnect }
				/>
			</VStack>
		</Modal>
	);
}

export { SlackConnectionModal };
