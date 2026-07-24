/**
 * External dependencies
 */
import {
	Card,
	CardHeader,
	CardBody,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ChannelsTabProps, ChannelMapping } from '../../../types';
import { ChannelsTable } from './channels-table';

/**
 * Renders the Channel Mappings tab. Shows a "Please connect to Slack first."
 * prompt when Slack is not configured, a "No channels are currently linked."
 * prompt when configured but empty, and the ChannelsTable when one or more
 * channels are linked.
 *
 * @param {Object}                                            props                              - Component props.
 * @param {boolean}                                           props.isConfigured                 - Whether Slack is currently connected.
 * @param {ChannelMapping[]}                                  props.channels                     - Linked channel mappings to render.
 * @param {string | null}                                     props.disconnectingChannelId       - The id of the channel currently being disconnected, or null.
 * @param {string | null}                                     props.updatingAutopublishChannelId - The id of the channel whose auto-publish toggle is in flight, or null.
 * @param {(channelId: string) => void}                       props.onUnlink                     - Handler invoked when a row's Disconnect button is clicked.
 * @param {(channelId: string, autopublish: boolean) => void} props.onAutopublishChange          - Handler invoked when a row's auto-publish toggle changes.
 */
function ChannelsTab( {
	isConfigured,
	channels,
	disconnectingChannelId,
	updatingAutopublishChannelId,
	onUnlink,
	onAutopublishChange,
}: ChannelsTabProps ) {
	return (
		<Card className="newspack-rolling-coverage-slack-settings__card">
			<CardHeader>
				<HStack alignment="space-between" justify="space-between">
					<h2>
						{ __(
							'Channel Mappings',
							'newspack-rolling-coverage'
						) }
					</h2>
				</HStack>
			</CardHeader>
			<CardBody>
				{ ! isConfigured && (
					<p>
						{ __(
							'Please connect to Slack first.',
							'newspack-rolling-coverage'
						) }
					</p>
				) }
				{ isConfigured && channels.length === 0 && (
					<p>
						{ __(
							'No channels are currently linked.',
							'newspack-rolling-coverage'
						) }
					</p>
				) }
				{ isConfigured && channels.length > 0 && (
					<ChannelsTable
						channels={ channels }
						disconnectingChannelId={ disconnectingChannelId }
						updatingAutopublishChannelId={
							updatingAutopublishChannelId
						}
						onUnlink={ onUnlink }
						onAutopublishChange={ onAutopublishChange }
					/>
				) }
			</CardBody>
		</Card>
	);
}

export { ChannelsTab };
