/**
 * External dependencies
 */
import { Badge, Stack, Text } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConnectedChannelViewProps } from '../../../types';
import {
	formatSlackChannel,
	safeFormatSlackTimestamp,
} from '../../../utils/fields';
import { DetailRow } from '../../detail-row';
import { ErrorNotice } from '../../../shared/error-notice';
import { PublishModeSelect } from '../publish-mode-select';

/**
 * Renders the body of the Slack connection drawer when the coverage is
 * already connected to a channel: the connection status, the channel name
 * with help text, the channel ID, last sync and the Publish Mode dropdown in the
 * Channels table's order, and any error notice.
 *
 * @param {ConnectedChannelViewProps} props Component props.
 */
function ConnectedChannelView( {
	channelName,
	channelId,
	lastSyncTs,
	publishMode,
	onPublishModeChange,
	isUpdatingPublishMode,
	error,
}: ConnectedChannelViewProps ) {
	return (
		<Stack direction="column" gap="lg">
			<DetailRow label={ __( 'Status', 'newspack-rolling-coverage' ) }>
				<Badge intent="stable">
					{ __( 'Connected', 'newspack-rolling-coverage' ) }
				</Badge>
			</DetailRow>
			<DetailRow label={ __( 'Channel', 'newspack-rolling-coverage' ) }>
				<span>
					{ formatSlackChannel( channelName ) ||
						__( '(unknown name)', 'newspack-rolling-coverage' ) }
				</span>
				<Text
					variant="body-sm"
					className="newspack-rolling-coverage-detail-help"
				>
					{ __(
						'Messages posted in this channel become entries in this coverage, as the publish mode below sets.',
						'newspack-rolling-coverage'
					) }
				</Text>
			</DetailRow>
			<DetailRow
				label={ __( 'Channel ID', 'newspack-rolling-coverage' ) }
			>
				<code>{ channelId }</code>
			</DetailRow>
			{ lastSyncTs !== null && (
				<DetailRow
					label={ __( 'Last Sync', 'newspack-rolling-coverage' ) }
				>
					<span>
						{ lastSyncTs
							? safeFormatSlackTimestamp( lastSyncTs )
							: __( 'Never', 'newspack-rolling-coverage' ) }
					</span>
				</DetailRow>
			) }
			<PublishModeSelect
				label={ __( 'Publish Mode', 'newspack-rolling-coverage' ) }
				value={ publishMode }
				onChange={ onPublishModeChange }
				disabled={ isUpdatingPublishMode || lastSyncTs === null }
			/>
			<ErrorNotice message={ error } />
		</Stack>
	);
}

export { ConnectedChannelView };
