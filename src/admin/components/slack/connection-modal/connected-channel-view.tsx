/**
 * External dependencies
 */
import {
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConnectedChannelViewProps } from '../../../types';
import { SlackError } from './slack-error';

/**
 * Renders the body of the Slack connection modal when the liveblog is
 * already connected to a channel: the channel name and Slack ID, a muted
 * description, the inline auto-publish toggle, and any error notice.
 *
 * @param {ConnectedChannelViewProps} props Component props.
 */
function ConnectedChannelView( {
	channelName,
	channelId,
	autopublish,
	onAutopublishChange,
	isUpdatingAutopublish,
	error,
}: ConnectedChannelViewProps ) {
	return (
		<VStack spacing={ 4 } alignment="stretch">
			<div>
				<strong>
					{ __( 'Channel:', 'newspack-rolling-coverage' ) }
				</strong>{ ' ' }
				<span>
					{ channelName ||
						__( '(unknown name)', 'newspack-rolling-coverage' ) }
				</span>
			</div>
			<div>
				<strong>
					{ __( 'Slack ID:', 'newspack-rolling-coverage' ) }
				</strong>{ ' ' }
				<code>{ channelId }</code>
			</div>
			<p className="newspack-rolling-coverage-muted">
				{ __(
					'Messages from this Slack channel will be ingested as entries.',
					'newspack-rolling-coverage'
				) }
			</p>
			<ToggleControl
				label={ __(
					'Auto-publish entries',
					'newspack-rolling-coverage'
				) }
				checked={ autopublish }
				onChange={ onAutopublishChange }
				disabled={ isUpdatingAutopublish }
			/>
			<SlackError message={ error } />
		</VStack>
	);
}

export { ConnectedChannelView };
