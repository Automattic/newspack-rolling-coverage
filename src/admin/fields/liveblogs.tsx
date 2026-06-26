/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SlackIcon } from '../shared/icons/slack-icon';
import {
	truncate,
	safeFormatUTCDate,
	getSlackChannelLabel,
} from '../utils/fields';
import type { Field, ViewState, Liveblog } from '../types';

/**
 * Field definitions for the liveblog DataViews table.
 * Configures columns: term ID, name, entry count, status, created date, modified date.
 *
 * @param {string} statusKey Meta key for the liveblog status (from AdminConfig).
 * @return {Field< Liveblog >[]} Field definitions for the liveblog table.
 */
function getLiveblogFields( statusKey: string ): Field< Liveblog >[] {
	return [
		{
			id: 'term_id',
			type: 'text',
			label: __( 'Term ID', 'newspack-rolling-coverage' ),
			enableSorting: true,
			getValue: ( { item } ) => String( item.id ),
		},
		{
			id: 'name',
			type: 'text',
			label: __( 'Name', 'newspack-rolling-coverage' ),
			enableSorting: true,
			enableGlobalSearch: true,
			getValue: ( { item } ) => truncate( item.name, 20 ),
		},
		{
			id: 'count',
			type: 'text',
			label: __( 'Entries', 'newspack-rolling-coverage' ),
			enableSorting: true,
			getValue: ( { item } ) => String( item.count ?? 0 ),
		},
		{
			id: 'status',
			type: 'text',
			label: __( 'Status', 'newspack-rolling-coverage' ),
			getValue: ( { item } ) =>
				String( item.meta?.[ statusKey ] ?? '' ) || 'active',
			elements: [
				{
					value: 'active',
					label: __( 'Active', 'newspack-rolling-coverage' ),
				},
				{
					value: 'paused',
					label: __( 'Paused', 'newspack-rolling-coverage' ),
				},
				{
					value: 'archived',
					label: __( 'Archived', 'newspack-rolling-coverage' ),
				},
			],
			filterBy: {
				operators: [ 'is', 'isNot' ],
			},
		},
		{
			id: 'slack_channel',
			type: 'text',
			label: __( 'Slack', 'newspack-rolling-coverage' ),
			getValue: ( { item } ) => getSlackChannelLabel( item ) || '—',
			render: ( { item } ) => {
				// A liveblog is considered connected when either the channel
				// name or the channel ID meta is present; fall back to the ID
				// when the channel name could not be resolved on connect.
				const label = getSlackChannelLabel( item );
				if ( ! label ) {
					return <span>—</span>;
				}
				return (
					<span className="newspack-rolling-coverage-slack-chip">
						<SlackIcon size={ 14 } />
						<span>{ label }</span>
					</span>
				);
			},
		},
		{
			id: 'created_at',
			type: 'datetime',
			label: __( 'Created', 'newspack-rolling-coverage' ),
			enableSorting: true,
			getValue: ( { item } ) =>
				safeFormatUTCDate( item.meta?.created_at ),
		},
		{
			id: 'modified_at',
			type: 'datetime',
			label: __( 'Modified', 'newspack-rolling-coverage' ),
			enableSorting: true,
			getValue: ( { item } ) =>
				safeFormatUTCDate( item.meta?.modified_at ),
		},
	];
}

/**
 * Default view state for the liveblog list: table layout, sorted by name ascending,
 * showing count, status, created_at, and modified_at columns.
 */
const defaultLiveblogView: ViewState = {
	type: 'table',
	perPage: 20,
	page: 1,
	sort: { field: 'name', direction: 'asc' },
	search: '',
	filters: [],
	fields: [ 'count', 'status', 'slack_channel', 'created_at', 'modified_at' ],
	titleField: 'name',
	layout: {},
};

export { getLiveblogFields, defaultLiveblogView };
