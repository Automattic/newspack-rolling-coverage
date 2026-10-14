/**
 * External dependencies
 */
import { TextControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConnectChannelFormProps } from '../../../types';
import { ErrorNotice } from '../../../shared/error-notice';
import { PublishModeSelect } from '../publish-mode-select';

/**
 * Renders the body of the Slack connection drawer when no channel is yet
 * connected: the labelled channel input with its instructions as help text,
 * the Publish Mode dropdown, and any error notice.
 *
 * @param {ConnectChannelFormProps} props Component props.
 */
function ConnectChannelForm( {
	channel,
	onChannelChange,
	publishMode,
	onPublishModeChange,
	isConnecting,
	error,
}: ConnectChannelFormProps ) {
	return (
		<Stack direction="column" gap="lg">
			<TextControl
				label={ __( 'Channel', 'newspack-rolling-coverage' ) }
				help={ createInterpolateElement(
					__(
						'Enter a channel name (e.g., #general) or channel ID (e.g., <code>C12345678</code>). The bot must be invited to the channel first.',
						'newspack-rolling-coverage'
					),
					{ code: <code /> }
				) }
				value={ channel }
				onChange={ onChannelChange }
				placeholder={ __(
					'#general or C12345678',
					'newspack-rolling-coverage'
				) }
				disabled={ isConnecting }
			/>
			<PublishModeSelect
				label={ __( 'Publish Mode', 'newspack-rolling-coverage' ) }
				value={ publishMode }
				onChange={ onPublishModeChange }
				disabled={ isConnecting }
			/>
			<ErrorNotice message={ error } />
		</Stack>
	);
}

export { ConnectChannelForm };
