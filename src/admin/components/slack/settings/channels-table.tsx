/**
 * External dependencies
 */
import { Button, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ChannelsTableProps, ChannelMapping } from '../../../types';
import { safeFormatSlackTimestamp } from '../../../utils/fields';

/**
 * Renders the channels mapping table: one row per linked Slack channel with
 * the channel name/id, the mapped liveblog term name (hyperlinked to the
 * term edit screen, or "(deleted)" when the term no longer exists), an inline
 * auto-publish toggle, the last sync timestamp (or "Never"), and a per-row
 * Disconnect button. The Disconnect button is busy and disabled for the row
 * currently being disconnected, and disabled entirely while any other
 * disconnect is in flight. The auto-publish toggle is disabled for the row
 * whose update is in flight.
 *
 * @param {Object}                                            props                              - Component props.
 * @param {ChannelMapping[]}                                  props.channels                     - Linked channel mappings to render.
 * @param {string | null}                                     props.disconnectingChannelId       - The id of the channel currently being disconnected, or null.
 * @param {string | null}                                     props.updatingAutopublishChannelId - The id of the channel whose auto-publish toggle is in flight, or null.
 * @param {(channelId: string) => void}                       props.onUnlink                     - Handler invoked when a row's Disconnect button is clicked.
 * @param {(channelId: string, autopublish: boolean) => void} props.onAutopublishChange          - Handler invoked when a row's auto-publish toggle changes.
 */
function ChannelsTable( {
	channels,
	disconnectingChannelId,
	updatingAutopublishChannelId,
	onUnlink,
	onAutopublishChange,
}: ChannelsTableProps ) {
	return (
		<table className="newspack-rolling-coverage-slack-settings__channels-table">
			<thead>
				<tr>
					<th>{ __( 'Channel', 'newspack-rolling-coverage' ) }</th>
					<th>
						{ __( 'Connected to', 'newspack-rolling-coverage' ) }
					</th>
					<th>
						{ __( 'Auto-publish', 'newspack-rolling-coverage' ) }
					</th>
					<th>{ __( 'Last Sync', 'newspack-rolling-coverage' ) }</th>
					<th>{ __( 'Actions', 'newspack-rolling-coverage' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ channels.map( ( ch ) => (
					<tr key={ ch.channel_id }>
						<td>
							<strong>{ ch.channel_name }</strong>{ ' ' }
							<code>{ ch.channel_id }</code>
						</td>
						<td>
							{ ch.term_name ? (
								<strong>{ ch.term_name }</strong>
							) : (
								<em>
									{ __(
										'(deleted)',
										'newspack-rolling-coverage'
									) }
								</em>
							) }
						</td>
						<td>
							<ToggleControl
								label={
									<span className="screen-reader-text">
										{ sprintf(
											/* translators: %s: channel name */
											__(
												'Auto-publish entries from %s',
												'newspack-rolling-coverage'
											),
											ch.channel_name || ch.channel_id
										) }
									</span>
								}
								checked={ ch.autopublish }
								onChange={ ( next ) =>
									onAutopublishChange( ch.channel_id, next )
								}
								disabled={
									updatingAutopublishChannelId ===
									ch.channel_id
								}
							/>
						</td>
						<td>
							{ ch.last_sync_ts
								? safeFormatSlackTimestamp( ch.last_sync_ts )
								: __( 'Never', 'newspack-rolling-coverage' ) }
						</td>
						<td>
							<Button
								variant="secondary"
								isDestructive
								isBusy={
									disconnectingChannelId === ch.channel_id
								}
								disabled={
									disconnectingChannelId === ch.channel_id ||
									disconnectingChannelId !== null
								}
								onClick={ () => onUnlink( ch.channel_id ) }
							>
								{ __(
									'Disconnect',
									'newspack-rolling-coverage'
								) }
							</Button>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

export { ChannelsTable };
