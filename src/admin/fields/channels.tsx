/**
 * External dependencies.
 */
import { Button, ToggleControl, VisuallyHidden } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies.
 */
import { safeFormatSlackTimestamp } from '../utils/fields';
import type { Field, ViewState, ChannelRow } from '../types';

/**
 * Field definitions for the Slack channel mappings DataViews table.
 *
 * @param {(channelId: string, autopublish: boolean) => void} onAutopublishChange Turns auto-publish on or off for a channel.
 * @param {Set<string>}                                       updatingChannelIds  Channels whose auto-publish change is in flight.
 * @param {(channel: ChannelRow) => void}                     onDisconnect        Asks to disconnect a channel.
 * @return {Field< ChannelRow >[]} Field definitions for the channel table.
 */
function getChannelFields(
	onAutopublishChange: ( channelId: string, autopublish: boolean ) => void,
	updatingChannelIds: Set< string >,
	onDisconnect: ( channel: ChannelRow ) => void
): Field< ChannelRow >[] {
	return [
		{
			id: 'channel_name',
			type: 'text',
			label: __( 'Channel', 'newspack-rolling-coverage' ),
			enableSorting: true,
			enableGlobalSearch: true,
			enableHiding: false,
			getValue: ( { item } ) => item.channel_name || item.channel_id,
		},
		{
			id: 'channel_id',
			type: 'text',
			label: __( 'Channel ID', 'newspack-rolling-coverage' ),
			enableSorting: false,
			enableGlobalSearch: true,
			getValue: ( { item } ) => item.channel_id,
			render: ( { item } ) => <code>{ item.channel_id }</code>,
		},
		{
			id: 'term_name',
			type: 'text',
			label: __( 'Coverage', 'newspack-rolling-coverage' ),
			enableSorting: true,
			enableGlobalSearch: true,
			getValue: ( { item } ) => decodeEntities( item.term_name ),
			render: ( { item } ) =>
				item.term_name ? (
					decodeEntities( item.term_name )
				) : (
					<em>{ __( '(deleted)', 'newspack-rolling-coverage' ) }</em>
				),
		},
		{
			id: 'last_sync_ts',
			type: 'text',
			label: __( 'Last Sync', 'newspack-rolling-coverage' ),
			enableSorting: true,
			getValue: ( { item } ) => item.last_sync_ts,
			render: ( { item } ) =>
				item.last_sync_ts
					? safeFormatSlackTimestamp( item.last_sync_ts )
					: __( 'Never', 'newspack-rolling-coverage' ),
		},
		{
			id: 'autopublish',
			type: 'text',
			label: __( 'Auto-publish', 'newspack-rolling-coverage' ),
			enableSorting: true,
			elements: [
				{ value: 'on', label: __( 'On', 'newspack-rolling-coverage' ) },
				{
					value: 'off',
					label: __( 'Off', 'newspack-rolling-coverage' ),
				},
			],
			filterBy: { operators: [ 'is' ] },
			getValue: ( { item } ) => ( item.autopublish ? 'on' : 'off' ),
			render: ( { item } ) => (
				<ToggleControl
					label={
						<VisuallyHidden>
							{ sprintf(
								/* translators: %s: Slack channel name. */
								__(
									'Auto-publish entries from %s',
									'newspack-rolling-coverage'
								),
								item.channel_name || item.channel_id
							) }
						</VisuallyHidden>
					}
					checked={ item.autopublish }
					disabled={ updatingChannelIds.has( item.channel_id ) }
					onChange={ ( next ) =>
						onAutopublishChange( item.channel_id, next )
					}
				/>
			),
		},
		{
			id: 'disconnect',
			type: 'text',
			label: __( 'Disconnect', 'newspack-rolling-coverage' ),
			header: (
				<VisuallyHidden>
					{ __( 'Disconnect', 'newspack-rolling-coverage' ) }
				</VisuallyHidden>
			),
			enableSorting: false,
			enableHiding: false,
			getValue: () => '',
			render: ( { item } ) => (
				<Button
					variant="tertiary"
					size="compact"
					isDestructive
					onClick={ () => onDisconnect( item ) }
				>
					{ __( 'Disconnect', 'newspack-rolling-coverage' ) }
				</Button>
			),
		},
	];
}

const CHANNEL_FIELD_ORDER = [
	'channel_name',
	'channel_id',
	'term_name',
	'last_sync_ts',
	'autopublish',
	'disconnect',
];

/**
 * Keeps the table's columns in a fixed order. DataViews appends a field the
 * reader switches on to the end, which would put Channel ID after Disconnect.
 *
 * @param {string[]} fields Visible field ids, in the order DataViews gives them.
 * @return {string[]} The same ids in the table's canonical order.
 */
function orderChannelFields( fields: string[] ): string[] {
	return [ ...fields ].sort(
		( a, b ) =>
			CHANNEL_FIELD_ORDER.indexOf( a ) - CHANNEL_FIELD_ORDER.indexOf( b )
	);
}

const defaultChannelView: ViewState = {
	type: 'table',
	perPage: 20,
	page: 1,
	sort: { field: 'channel_name', direction: 'asc' },
	search: '',
	filters: [],
	fields: [ 'term_name', 'last_sync_ts', 'autopublish', 'disconnect' ],
	titleField: 'channel_name',
	layout: {},
};

export { getChannelFields, defaultChannelView, orderChannelFields };
