/**
 * External dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import type { Field, ViewState, Coverage } from '../types';
import { truncate, safeFormatUTCDate } from '../utils/fields';

/**
 * Field definitions for the coverage DataViews table.
 * Configures columns: term ID, name, entry count, status, created date, modified date.
 *
 * @param {string} statusKey Meta key for the coverage status (from AdminConfig).
 * @return {Field< Coverage >[]} Field definitions for the coverage table.
 */
function getCoverageFields( statusKey: string ): Field< Coverage >[] {
	return [
		{
			id: 'term_id',
			type: 'text',
			label: __( 'Term ID', 'newspack-rolling-coverage' ),
			getValue: ( { item } ) => String( item.id ),
		},
		{
			id: 'name',
			type: 'text',
			label: __( 'Name', 'newspack-rolling-coverage' ),
			enableGlobalSearch: true,
			getValue: ( { item } ) => truncate( item.name, 20 ),
		},
		{
			id: 'count',
			type: 'text',
			label: __( 'Entries', 'newspack-rolling-coverage' ),
			getValue: ( { item } ) => String( item.count ?? 0 ),
		},
		{
			id: 'status',
			type: 'text',
			label: __( 'Status', 'newspack-rolling-coverage' ),
			getValue: ( { item } ) =>
				( item.meta?.[ statusKey ] as string ) || 'active',
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
			id: 'created_at',
			type: 'datetime',
			label: __( 'Created', 'newspack-rolling-coverage' ),
			getValue: ( { item } ) =>
				safeFormatUTCDate( item.meta?.created_at ),
		},
		{
			id: 'modified_at',
			type: 'datetime',
			label: __( 'Modified', 'newspack-rolling-coverage' ),
			getValue: ( { item } ) =>
				safeFormatUTCDate( item.meta?.modified_at ),
		},
	];
}

/**
 * Default view state for the coverage list: table layout, unsorted,
 * showing count, status, created_at, and modified_at columns.
 */
const defaultCoverageView: ViewState = {
	type: 'table',
	perPage: 20,
	page: 1,
	search: '',
	filters: [],
	fields: [ 'count', 'status', 'created_at', 'modified_at' ],
	titleField: 'name',
	layout: {},
};

export { getCoverageFields, defaultCoverageView };
