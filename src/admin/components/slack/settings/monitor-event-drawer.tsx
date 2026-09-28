/**
 * External dependencies
 */
import { Stack } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';
import { Drawer } from 'newspack-components/dist/esm/drawer';

/**
 * Internal dependencies
 */
import type { ChannelMapping, SlackMonitorEvent } from '../../../types';
import { DetailRow } from '../../detail-row';
import { getStatusLabel } from '../../../utils/fields';
import {
	EventChannel,
	formatEventTime,
	getEventChannelId,
	LevelBadge,
} from '../../../fields/monitor';

const CONTEXT_LABELS: Record< string, string > = {
	ts: __( 'Message timestamp', 'newspack-rolling-coverage' ),
	post_id: __( 'Entry ID', 'newspack-rolling-coverage' ),
	term_id: __( 'Coverage ID', 'newspack-rolling-coverage' ),
	status: __( 'Status', 'newspack-rolling-coverage' ),
	reason: __( 'Reason', 'newspack-rolling-coverage' ),
	error: __( 'Error code', 'newspack-rolling-coverage' ),
	message: __( 'Error message', 'newspack-rolling-coverage' ),
	workspace: __( 'Workspace', 'newspack-rolling-coverage' ),
	payload_team: __( 'Sending workspace ID', 'newspack-rolling-coverage' ),
	stored_team: __( 'Connected workspace ID', 'newspack-rolling-coverage' ),
	prefix: __( 'Ignore prefix', 'newspack-rolling-coverage' ),
	ignore_prefix: __( 'Ignore prefix', 'newspack-rolling-coverage' ),
	timestamp: __( 'Request timestamp', 'newspack-rolling-coverage' ),
	timestamp_delta: __(
		'Seconds since the request was signed',
		'newspack-rolling-coverage'
	),
	signature_prefix: __( 'Signature prefix', 'newspack-rolling-coverage' ),
	body_length: __( 'Request body length', 'newspack-rolling-coverage' ),
	data: __( 'Data', 'newspack-rolling-coverage' ),
};

const CODE_KEYS = [
	'ts',
	'post_id',
	'term_id',
	'error',
	'payload_team',
	'stored_team',
	'prefix',
	'ignore_prefix',
	'timestamp',
	'signature_prefix',
];

const CHANNEL_KEYS = [ 'channel', 'channel_id' ];

/**
 * Shows everything the monitor recorded about one Slack event.
 *
 * @param {Object}                   props          - Component props.
 * @param {boolean}                  props.isOpen   - Whether the drawer is open.
 * @param {() => void}               props.onClose  - Closes the drawer.
 * @param {SlackMonitorEvent | null} props.event    - The event to show.
 * @param {ChannelMapping[]}         props.channels - Linked channels, to name the event's channel.
 */
function MonitorEventDrawer( {
	isOpen,
	onClose,
	event,
	channels,
}: {
	isOpen: boolean;
	onClose: () => void;
	event: SlackMonitorEvent | null;
	channels: ChannelMapping[];
} ) {
	const details = Object.entries( event?.context ?? {} ).filter(
		( [ key ] ) => ! CHANNEL_KEYS.includes( key )
	);

	return (
		<Drawer.Root isOpen={ isOpen } onRequestClose={ onClose }>
			<Drawer.Header>
				<Drawer.Title>{ event?.message }</Drawer.Title>
				<Drawer.CloseIcon />
			</Drawer.Header>
			<Drawer.Content>
				{ event && (
					<Stack direction="column" gap="lg">
						<DetailRow
							label={ __( 'Level', 'newspack-rolling-coverage' ) }
						>
							<LevelBadge level={ event.level } />
						</DetailRow>
						<DetailRow
							label={ __( 'Time', 'newspack-rolling-coverage' ) }
						>
							<span>{ formatEventTime( event ) }</span>
						</DetailRow>
						{ getEventChannelId( event ) && (
							<DetailRow
								label={ __(
									'Channel',
									'newspack-rolling-coverage'
								) }
							>
								<span>
									<EventChannel
										event={ event }
										channels={ channels }
									/>
								</span>
							</DetailRow>
						) }
						{ details.map( ( [ key, value ] ) => (
							<DetailRow
								key={ key }
								label={ CONTEXT_LABELS[ key ] ?? key }
							>
								{ CODE_KEYS.includes( key ) ? (
									<code>{ String( value ) }</code>
								) : (
									<span>
										{ key === 'status'
											? getStatusLabel( String( value ) )
											: String( value ) }
									</span>
								) }
							</DetailRow>
						) ) }
					</Stack>
				) }
			</Drawer.Content>
		</Drawer.Root>
	);
}

export { MonitorEventDrawer };
