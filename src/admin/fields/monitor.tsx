/**
 * External dependencies.
 */
import { Badge } from '@wordpress/ui';
import { dateI18n } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { formatSlackChannel } from '../utils/fields';
import type {
	Field,
	ViewState,
	ChannelMapping,
	SlackMonitorEvent,
} from '../types';

const MAX_MONITOR_EVENTS = 1000;

type BadgeIntent = 'high' | 'medium' | 'stable' | 'draft';

const LEVELS: Record< string, { label: string; intent: BadgeIntent } > = {
	error: {
		label: __( 'Error', 'newspack-rolling-coverage' ),
		intent: 'high',
	},
	warning: {
		label: __( 'Warning', 'newspack-rolling-coverage' ),
		intent: 'medium',
	},
	success: {
		label: __( 'Success', 'newspack-rolling-coverage' ),
		intent: 'stable',
	},
	info: { label: __( 'Info', 'newspack-rolling-coverage' ), intent: 'draft' },
};

/**
 * Returns the Slack channel ID a monitor event refers to, if any.
 *
 * @param {SlackMonitorEvent} event Monitor event.
 * @return {string} Channel ID, or '' when the event has none.
 */
function getEventChannelId( event: SlackMonitorEvent ): string {
	const id = event.context?.channel ?? event.context?.channel_id;
	return typeof id === 'string' ? id : '';
}

/**
 * Formats a monitor event's channel as `#name` when it is mapped to a
 * coverage, or its raw ID otherwise.
 *
 * @param {SlackMonitorEvent} event    Monitor event.
 * @param {ChannelMapping[]}  channels Linked channels.
 * @return {string} '#name', the channel ID, or '' when the event has none.
 */
function formatEventChannel(
	event: SlackMonitorEvent,
	channels: ChannelMapping[]
): string {
	const channelId = getEventChannelId( event );
	const mapping = channels.find( ( ch ) => ch.channel_id === channelId );
	return formatSlackChannel( mapping?.channel_name ?? '', channelId );
}

/**
 * Renders a monitor event's channel: `#name` when it is mapped to a coverage,
 * the raw ID as code otherwise, or an em dash when the event has no channel.
 *
 * @param {Object}            props          Component props.
 * @param {SlackMonitorEvent} props.event    Monitor event.
 * @param {ChannelMapping[]}  props.channels Linked channels.
 */
function EventChannel( {
	event,
	channels,
}: {
	event: SlackMonitorEvent;
	channels: ChannelMapping[];
} ) {
	const label = formatEventChannel( event, channels );
	if ( ! label ) {
		return <>—</>;
	}
	return label === getEventChannelId( event ) ? (
		<code>{ label }</code>
	) : (
		<>{ label }</>
	);
}

/**
 * Formats a monitor event's time in the site's timezone.
 *
 * @param {SlackMonitorEvent} event Monitor event.
 * @return {string} Formatted date and time.
 */
function formatEventTime( event: SlackMonitorEvent ): string {
	return dateI18n(
		/* translators: Monitor event time format, see https://www.php.net/manual/datetime.format.php */
		__( 'M j, Y g:i:s a', 'newspack-rolling-coverage' ),
		new Date( event.timestamp )
	);
}

/**
 * Renders a monitor event's level as a badge.
 *
 * @param {Object} props       Component props.
 * @param {string} props.level Event level.
 */
function LevelBadge( { level }: { level: string } ) {
	const known = LEVELS[ level ];
	return (
		<Badge intent={ known?.intent ?? 'draft' }>
			{ known?.label ?? level }
		</Badge>
	);
}

/**
 * Field definitions for the Slack monitor DataViews table.
 *
 * @param {ChannelMapping[]} channels Linked channels, to name the channel an event refers to.
 * @return {Field< SlackMonitorEvent >[]} Field definitions for the monitor table.
 */
function getMonitorFields(
	channels: ChannelMapping[]
): Field< SlackMonitorEvent >[] {
	return [
		{
			id: 'message',
			type: 'text',
			label: __( 'Event', 'newspack-rolling-coverage' ),
			enableSorting: false,
			enableGlobalSearch: true,
			enableHiding: false,
			filterBy: false,
			getValue: ( { item } ) => item.message,
		},
		{
			id: 'level',
			type: 'text',
			label: __( 'Level', 'newspack-rolling-coverage' ),
			enableSorting: false,
			elements: Object.entries( LEVELS ).map(
				( [ value, { label } ] ) => ( {
					value,
					label,
				} )
			),
			filterBy: { operators: [ 'isAny' ] },
			getValue: ( { item } ) => item.level,
			render: ( { item } ) => <LevelBadge level={ item.level } />,
		},
		{
			id: 'channel',
			type: 'text',
			label: __( 'Channel', 'newspack-rolling-coverage' ),
			enableSorting: false,
			enableGlobalSearch: true,
			filterBy: false,
			getValue: ( { item } ) => formatEventChannel( item, channels ),
			render: ( { item } ) => (
				<EventChannel event={ item } channels={ channels } />
			),
		},
		{
			id: 'time',
			type: 'integer',
			label: __( 'Time', 'newspack-rolling-coverage' ),
			filterBy: false,
			getValue: ( { item } ) => item.id,
			render: ( { item } ) => formatEventTime( item ),
		},
	];
}

const defaultMonitorView: ViewState = {
	type: 'table',
	perPage: MAX_MONITOR_EVENTS,
	page: 1,
	sort: { field: 'time', direction: 'desc' },
	search: '',
	filters: [],
	fields: [ 'level', 'channel', 'time' ],
	titleField: 'message',
	layout: { styles: { time: { align: 'start' } } },
};

export {
	getMonitorFields,
	defaultMonitorView,
	formatEventChannel,
	formatEventTime,
	getEventChannelId,
	EventChannel,
	LevelBadge,
	MAX_MONITOR_EVENTS,
};
