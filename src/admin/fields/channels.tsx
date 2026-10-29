/**
 * External dependencies.
 */
import { Button, VisuallyHidden } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies.
 */
import { formatSlackChannel, safeFormatSlackTimestamp } from '../utils/fields';
import { getPublishModeOptions } from '../utils/publish-modes';
import { PublishModeSelect } from '../components/slack/publish-mode-select';
import type { Field, ViewState, ChannelRow, PublishMode } from '../types';

/**
 * Field definitions for the Slack channel mappings DataViews table.
 *
 * @param {(channelId: string, publishMode: PublishMode) => void} onPublishModeChange Sets a channel's publish mode.
 * @param {Set<string>}                                           updatingChannelIds  Channels whose publish mode change is in flight.
 * @param {(channel: ChannelRow) => void}                         onDisconnect        Asks to disconnect a channel.
 * @param {string}                                                coveragesUrl        Admin URL of the All Liveblogs page.
 * @return {Field< ChannelRow >[]} Field definitions for the channel table.
 */
function getChannelFields(
	onPublishModeChange: (
		channelId: string,
		publishMode: PublishMode
	) => void,
	updatingChannelIds: Set< string >,
	onDisconnect: ( channel: ChannelRow ) => void,
	coveragesUrl: string
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
			render: ( { item } ) =>
				formatSlackChannel( item.channel_name, item.channel_id ),
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
			label: __( 'Liveblog', 'newspack-rolling-coverage' ),
			enableSorting: true,
			enableGlobalSearch: true,
			getValue: ( { item } ) => decodeEntities( item.term_name ),
			render: ( { item } ) =>
				item.term_name ? (
					<Button
						variant="link"
						href={ `${ coveragesUrl }#/coverages/${ item.term_id }` }
					>
						{ decodeEntities( item.term_name ) }
					</Button>
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
			id: 'publish_mode',
			type: 'text',
			label: __( 'Publish Mode', 'newspack-rolling-coverage' ),
			enableSorting: true,
			elements: getPublishModeOptions(),
			filterBy: { operators: [ 'is' ] },
			getValue: ( { item } ) => item.publish_mode,
			render: ( { item } ) => (
				<PublishModeSelect
					isCompact
					label={ sprintf(
						/* translators: %s: Slack channel name, or its ID when the name is unknown. */
						__(
							'Publish mode for %s',
							'newspack-rolling-coverage'
						),
						item.channel_name || item.channel_id
					) }
					value={ item.publish_mode }
					disabled={ updatingChannelIds.has( item.channel_id ) }
					onChange={ ( next ) =>
						onPublishModeChange( item.channel_id, next )
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
	'publish_mode',
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
	fields: [ 'term_name', 'last_sync_ts', 'publish_mode', 'disconnect' ],
	titleField: 'channel_name',
	layout: {},
};

export { getChannelFields, defaultChannelView, orderChannelFields };
