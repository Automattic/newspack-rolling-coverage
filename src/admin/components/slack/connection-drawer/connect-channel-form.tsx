/**
 * External dependencies
 */
import {
	TextControl,
	ToggleControl,
	VisuallyHidden,
} from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConnectChannelFormProps } from '../../../types';
import { SlackError } from './slack-error';

/**
 * Renders the body of the Slack connection drawer when no channel is yet
 * connected: the labelled channel input with its instructions as help text,
 * the labelled auto-publish toggle, and any error notice.
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
			<Stack direction="column" gap="sm" align="flex-start">
				<span
					className="newspack-rolling-coverage-detail-label"
					aria-hidden="true"
				>
					{ __( 'Auto-publish', 'newspack-rolling-coverage' ) }
				</span>
				<ToggleControl
					className="newspack-rolling-coverage-autopublish-toggle"
					label={
						<VisuallyHidden>
							{ __(
								'Auto-publish entries',
								'newspack-rolling-coverage'
							) }
						</VisuallyHidden>
					}
					help={ __(
						"Publishes messages as soon as they're posted in Slack. When off, messages are saved as drafts for an editor or administrator to publish.",
						'newspack-rolling-coverage'
					) }
					checked={ autopublish }
					onChange={ onAutopublishChange }
					disabled={ isConnecting }
				/>
			</Stack>
			<SlackError message={ error } />
		</Stack>
	);
}

export { ConnectChannelForm };
