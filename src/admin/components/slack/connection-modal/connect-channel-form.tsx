/**
 * External dependencies
 */
import {
	TextControl,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConnectChannelFormProps } from '../../../types';
import { SlackError } from './slack-error';

/**
 * Renders the body of the Slack connection modal when no channel is yet
 * connected: an instruction paragraph, the channel input, the auto-publish
 * toggle, and any error notice.
 *
 * @param {ConnectChannelFormProps} props Component props.
 */
function ConnectChannelForm( {
	channel,
	onChannelChange,
	autopublish,
	onAutopublishChange,
	isConnecting,
	error,
}: ConnectChannelFormProps ) {
	return (
		<VStack spacing={ 4 } alignment="stretch">
			<p>
				{ __(
					'Enter a channel name (e.g., #general) or channel ID (e.g., C12345678). The bot must be invited to the channel first.',
					'newspack-rolling-coverage'
				) }
			</p>
			<TextControl
				value={ channel }
				onChange={ onChannelChange }
				placeholder="#general or C12345678"
				disabled={ isConnecting }
			/>
			<ToggleControl
				label={ __(
					'Auto-publish entries',
					'newspack-rolling-coverage'
				) }
				checked={ autopublish }
				onChange={ onAutopublishChange }
				disabled={ isConnecting }
			/>
			<SlackError message={ error } />
		</VStack>
	);
}

export { ConnectChannelForm };
