/**
 * External dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { SlackIcon } from '../shared/icons/slack-icon';
import {
	truncate,
	safeFormatUTCDate,
	getSlackChannelLabel,
} from '../utils/fields';
import type { Field, ViewState, Coverage } from '../types';

/**
 * Field definitions for the coverage DataViews table.
 * Configures columns: term ID, name, entry count, status, created date, modified date.
 *
 * @param {string} statusKey       Meta key for the coverage status (from AdminConfig).
 * @param {string} lastModifiedKey Meta key for the coverage's latest entry activity (from AdminConfig).
 * @return {Field< Coverage >[]} Field definitions for the coverage table.
 */
function getCoverageFields(
	statusKey: string,
	lastModifiedKey: string
): Field< Coverage >[] {
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
				{
					value: 'trash',
					label: __( 'Trash', 'newspack-rolling-coverage' ),
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
			id: 'last_modified',
			type: 'datetime',
			label: __( 'Modified', 'newspack-rolling-coverage' ),
			enableSorting: true,
			getValue: ( { item } ) =>
				safeFormatUTCDate(
					item.meta?.[ lastModifiedKey ] as string | undefined
				),
		},
	];
}

/**
 * Default view state for the coverage list: table layout, unsorted,
 * showing count, status, created_at, and last_modified columns.
 */
const defaultCoverageView: ViewState = {
	type: 'table',
	perPage: 20,
	page: 1,
	sort: { field: 'name', direction: 'asc' },
	search: '',
	filters: [],
	fields: [
		'count',
		'status',
		'slack_channel',
		'created_at',
		'last_modified',
	],
	titleField: 'name',
	layout: {},
};

export { getCoverageFields, defaultCoverageView };
