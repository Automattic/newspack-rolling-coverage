/**
 * External dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import { Button } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import StatusIndicator from 'newspack-components/dist/esm/status-indicator';
import {
	toISODate,
	getSlackChannelLabel,
	COVERAGE_STATUS_INDICATORS,
} from '../utils/fields';
import { formatSiteDateTime } from '../utils/site-date';
import type { View } from '@wordpress/dataviews';
import type { Field, ViewState, Coverage, StatusLabels } from '../types';

/**
 * The coverage statuses as the list shows them, named after the site's
 * status indicator labels.
 *
 * @param {StatusLabels} labels The site's status labels.
 * @return {Object[]} Status filter elements.
 */
function getCoverageStatusElements( labels: StatusLabels ) {
	return [
		{ value: 'active', label: labels.active },
		{ value: 'paused', label: labels.paused },
		{ value: 'archived', label: labels.archived },
		{ value: 'trash', label: __( 'Trash', 'newspack-rolling-coverage' ) },
	];
}

/**
 * Field definitions for the coverage DataViews table.
 * Configures columns: term ID, name, entry count, status, created date, modified date.
 *
 * @param {string}                       statusKey            Meta key for the coverage status (from AdminConfig).
 * @param {string}                       lastModifiedKey      Meta key for the coverage's latest entry activity (from AdminConfig).
 * @param {StatusLabels}                 statusLabels         The site's status labels, which name the statuses.
 * @param {(coverage: Coverage) => void} [onOpenSlackConnect] Opens the Slack connection drawer from the channel name, or a Connect link when unlinked; without it the column is plain text.
 * @return {Field< Coverage >[]} Field definitions for the coverage table.
 */
function getCoverageFields(
	statusKey: string,
	lastModifiedKey: string,
	statusLabels: StatusLabels,
	onOpenSlackConnect?: ( coverage: Coverage ) => void
): Field< Coverage >[] {
	const statusElements = getCoverageStatusElements( statusLabels );

	return [
		{
			id: 'term_id',
			type: 'integer',
			label: __( 'Term ID', 'newspack-rolling-coverage' ),
			enableSorting: true,
			getValue: ( { item } ) => item.id,
		},
		{
			id: 'name',
			type: 'text',
			label: __( 'Name', 'newspack-rolling-coverage' ),
			enableHiding: false,
			enableSorting: true,
			enableGlobalSearch: true,
			getValue: ( { item } ) => decodeEntities( item.name ),
		},
		{
			id: 'count',
			type: 'integer',
			label: __( 'Entries', 'newspack-rolling-coverage' ),
			enableSorting: true,
			getValue: ( { item } ) => item.count ?? 0,
		},
		{
			id: 'entry_count',
			type: 'text',
			label: __( 'Entry count', 'newspack-rolling-coverage' ),
			enableSorting: false,
			enableHiding: false,
			filterBy: false,
			getValue: ( { item } ) => String( item.count ?? 0 ),
			render: ( { item } ) => {
				const count = item.count ?? 0;
				return sprintf(
					/* translators: %d: number of entries in the liveblog. */
					_n(
						'%d entry',
						'%d entries',
						count,
						'newspack-rolling-coverage'
					),
					count
				);
			},
		},
		{
			id: 'status',
			type: 'text',
			label: __( 'Status', 'newspack-rolling-coverage' ),
			getValue: ( { item } ) =>
				String( item.meta?.[ statusKey ] ?? '' ) || 'active',
			elements: statusElements,
			render: ( { item } ) => {
				const status =
					String( item.meta?.[ statusKey ] ?? '' ) || 'active';
				return (
					<StatusIndicator
						status={ COVERAGE_STATUS_INDICATORS[ status ] }
					>
						{ statusElements.find(
							( element ) => element.value === status
						)?.label ?? status }
					</StatusIndicator>
				);
			},
			filterBy: {
				operators: [ 'is', 'isNot' ],
			},
		},
		{
			id: 'slack_channel',
			type: 'text',
			label: __( 'Slack', 'newspack-rolling-coverage' ),
			getValue: ( { item } ) =>
				String(
					item.meta?.rolling_coverage_slack_channel_name ||
						item.meta?.rolling_coverage_slack_channel_id ||
						'—'
				),
			render: ( { item } ) => {
				const label = getSlackChannelLabel( item );
				if ( ! label ) {
					if ( ! onOpenSlackConnect ) {
						return <span>—</span>;
					}
					return (
						<Button
							variant="link"
							className="newspack-rolling-coverage-slack-channel"
							aria-label={ sprintf(
								/* translators: %s: liveblog name. */
								__(
									'Connect %s to a Slack channel',
									'newspack-rolling-coverage'
								),
								decodeEntities( item.name )
							) }
							onClick={ () => onOpenSlackConnect( item ) }
						>
							{ __( 'Connect', 'newspack-rolling-coverage' ) }
						</Button>
					);
				}
				if ( ! onOpenSlackConnect ) {
					return (
						<span className="newspack-rolling-coverage-slack-channel">
							{ label }
						</span>
					);
				}
				return (
					<Button
						variant="link"
						className="newspack-rolling-coverage-slack-channel"
						onClick={ () => onOpenSlackConnect( item ) }
					>
						{ label }
					</Button>
				);
			},
		},
		{
			id: 'created_at',
			type: 'datetime',
			label: __( 'Created', 'newspack-rolling-coverage' ),
			enableSorting: true,
			getValue: ( { item } ) => toISODate( item.meta?.created_at ),
			getValueFormatted: ( { item, field } ) =>
				formatSiteDateTime( field.getValue( { item } ) ),
			render: ( { item, field } ) =>
				field.getValueFormatted( { item, field } ) || '—',
		},
		{
			id: 'last_modified',
			type: 'datetime',
			label: __( 'Modified', 'newspack-rolling-coverage' ),
			enableSorting: true,
			getValue: ( { item } ) =>
				toISODate(
					item.meta?.[ lastModifiedKey ] as string | undefined
				),
			getValueFormatted: ( { item, field } ) =>
				formatSiteDateTime( field.getValue( { item } ) ),
			render: ( { item, field } ) =>
				field.getValueFormatted( { item, field } ) || '—',
		},
	];
}

/**
 * Default view state for the coverage list: a table sorted by name, without
 * trashed coverages, with the entry count under each name and the Entries
 * column hidden.
 */
const defaultCoverageView: ViewState = {
	type: 'table',
	perPage: 20,
	page: 1,
	sort: { field: 'name', direction: 'asc' },
	search: '',
	filters: [ { field: 'status', operator: 'isNot', value: 'trash' } ],
	fields: [ 'status', 'slack_channel', 'created_at', 'last_modified' ],
	titleField: 'name',
	descriptionField: 'entry_count',
	layout: { styles: { count: { align: 'start' } } },
};

/**
 * The view as the list shows it: the entry count sits under each liveblog's
 * name while the Entries column is hidden, and not in both places once it's
 * shown.
 *
 * @param {View} view The stored view.
 * @return {View} The view to render.
 */
function withEntryCount< V extends View >( view: V ): V {
	const hasColumn = ( view.fields ?? [] ).includes( 'count' );
	return {
		...view,
		descriptionField: hasColumn ? undefined : 'entry_count',
	};
}

export { getCoverageFields, defaultCoverageView, withEntryCount };
