/**
 * External dependencies
 */
import { ToggleControl, VisuallyHidden } from '@wordpress/components';
import { Badge, Stack, Text } from '@wordpress/ui';
import { __, sprintf } from '@wordpress/i18n';

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

/**
 * Renders the body of the Slack connection drawer when the coverage is
 * already connected to a channel: the connection status, the channel name
 * with help text, the channel ID, last sync and inline auto-publish toggle in the Channels
 * table's order, and any error notice.
 *
 * @param {ConnectedChannelViewProps} props Component props.
 */
function ConnectedChannelView( {
	channelName,
	channelId,
	lastSyncTs,
	autopublish,
	onAutopublishChange,
	isUpdatingAutopublish,
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
						'New messages posted in this channel are added to this coverage as entries.',
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
			<Stack direction="column" gap="sm" align="flex-start">
				<Text variant="heading-sm" aria-hidden="true">
					{ __( 'Auto-publish', 'newspack-rolling-coverage' ) }
				</Text>
				<ToggleControl
					className="newspack-rolling-coverage-autopublish-toggle"
					label={
						<VisuallyHidden>
							{ sprintf(
								/* translators: %s: Slack channel name, or its ID when the name is unknown. */
								__(
									'Auto-publish entries from %s',
									'newspack-rolling-coverage'
								),
								channelName || channelId
							) }
						</VisuallyHidden>
					}
					help={ __(
						"Publishes messages as soon as they're posted in Slack. When off, messages are saved as drafts for an editor or administrator to publish.",
						'newspack-rolling-coverage'
					) }
					checked={ autopublish }
					onChange={ onAutopublishChange }
					disabled={ isUpdatingAutopublish || lastSyncTs === null }
				/>
			</Stack>
			<ErrorNotice message={ error } />
		</Stack>
	);
}

export { ConnectedChannelView };
