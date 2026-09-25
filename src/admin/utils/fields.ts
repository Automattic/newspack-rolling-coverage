/**
 * Contains utility functions for formatting field values in the admin interface.
 */

/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Entry, Coverage } from '../types';

/** Machine value for entries sourced from Slack. */
const SOURCE_SLACK = 'slack';
/** Machine value for entries sourced from the WordPress editor. */
const SOURCE_WORDPRESS = 'wordpress';

const POST_STATUS_LABELS: Record< string, string > = {
	publish: __( 'Published', 'newspack-rolling-coverage' ),
	draft: __( 'Draft', 'newspack-rolling-coverage' ),
	pending: __( 'Pending', 'newspack-rolling-coverage' ),
	future: __( 'Scheduled', 'newspack-rolling-coverage' ),
	private: __( 'Private', 'newspack-rolling-coverage' ),
	trash: __( 'Trashed', 'newspack-rolling-coverage' ),
};

/**
 * Returns the display label for a post status.
 *
 * @param {string} status Post status slug.
 * @return {string} Translated label, or the raw status if unrecognised.
 */
function getStatusLabel( status: string ): string {
	return POST_STATUS_LABELS[ status ] || status;
}

/**
 * Filter elements for the status field. Mirrors the endpoint's
 * ALLOWED_STATUSES, including `trash` so trashed entries can be filtered
 * in the main entries view like any other status.
 */
const STATUS_ELEMENTS = Object.entries( POST_STATUS_LABELS ).map(
	( [ value, label ] ) => ( { value, label } )
);

/**
 * Returns the raw title string for an entry (used for filtering).
 *
 * @param {Entry} item Entry object.
 * @return {string} Raw title or empty string.
 */
function getRawTitle( item: Entry ): string {
	return item.title?.rendered || '';
}

/**
 * Returns the raw author name for an entry (used for filtering).
 *
 * @param {Entry} item Entry object.
 * @return {string} Author display name or empty string.
 */
function getRawAuthor( item: Entry ): string {
	return item._embedded?.author?.[ 0 ]?.name || '';
}

/**
 * Returns comma-separated category names for an entry.
 *
 * @param {Entry} item Entry object.
 * @return {string} CSV of category names.
 */
function getCategoryNames( item: Entry ): string {
	return getEmbeddedTerms( item )
		.filter( ( t ) => t.taxonomy === 'category' )
		.map( ( t ) => t.name )
		.join( ', ' );
}

/**
 * Returns comma-separated tag names for an entry.
 *
 * @param {Entry} item Entry object.
 * @return {string} CSV of tag names.
 */
function getTagNames( item: Entry ): string {
	return getEmbeddedTerms( item )
		.filter( ( t ) => t.taxonomy === 'post_tag' )
		.map( ( t ) => t.name )
		.join( ', ' );
}

/**
 * Returns the breakout status slug for an entry (used for filtering).
 *
 * @param {Entry} item Entry object.
 * @return {string} Breakout status slug or 'none'.
 */
function getBreakoutStatus( item: Entry ): string {
	return item.rolling_coverage_breakout_status || 'none';
}

/**
 * Machine values for the archived filter. Archived state is stored in post
 * meta and is orthogonal to `post_status`, so it cannot be expressed through
 * the status filter.
 */
const ARCHIVED_VALUE = 'archived';
const NOT_ARCHIVED_VALUE = 'not-archived';

/**
 * Filter elements for the archived field.
 */
const ARCHIVED_ELEMENTS = [
	{
		value: ARCHIVED_VALUE,
		label: __( 'Archived', 'newspack-rolling-coverage' ),
	},
	{
		value: NOT_ARCHIVED_VALUE,
		label: __( 'Not archived', 'newspack-rolling-coverage' ),
	},
];

/**
 * Returns whether an entry is individually archived, as a filterable value.
 *
 * @param {Entry} item Entry object.
 * @return {string} 'archived' or 'not-archived'.
 */
function getArchivedStatus( item: Entry ): string {
	return item.archivedAt ? ARCHIVED_VALUE : NOT_ARCHIVED_VALUE;
}

/**
 * Formats a Date as a UTC string, returning "—" when the date is null.
 *
 * @param {Date | null} date The date to format, or null.
 * @return {string} UTC date string or "—".
 */
function formatUTC( date: Date | null ): string {
	return date ? date.toUTCString() : '—';
}

/**
 * Normalizes a date string to ISO 8601 UTC for a DataViews `datetime` field.
 *
 * @param {string | null | undefined} dateStr - The raw date string.
 * @return {string} ISO 8601 UTC string, or '' when missing/invalid.
 */
function toISODate( dateStr: string | null | undefined ): string {
	if ( ! dateStr ) {
		return '';
	}

	const normalized = dateStr.includes( 'T' )
		? dateStr
		: dateStr.replace( ' ', 'T' ) + 'Z';

	const date = new Date( normalized );

	return isNaN( date.getTime() ) ? '' : date.toISOString();
}

/**
 * Parses a Slack message timestamp (a "seconds.fraction" string such as
 * "1234567890.012345") into a Date object, returning null for invalid/empty
 * values. Slack timestamps are seconds-since-epoch, not milliseconds.
 *
 * @param {string | null | undefined} ts - The Slack timestamp string.
 * @return {Date | null} The parsed Date, or null if invalid.
 */
function parseSlackTimestamp( ts: string | null | undefined ): Date | null {
	if ( ! ts ) {
		return null;
	}
	const seconds = parseFloat( ts );
	if ( isNaN( seconds ) ) {
		return null;
	}
	const d = new Date( seconds * 1000 );
	return isNaN( d.getTime() ) ? null : d;
}

/**
 * Formats a Slack timestamp as a UTC string (matching the DataViews datetime
 * column style used elsewhere), or "—" if invalid/empty.
 *
 * @param {string | null | undefined} ts - The Slack timestamp string.
 * @return {string} UTC date string or "—".
 */
function safeFormatSlackTimestamp( ts: string | null | undefined ): string {
	return formatUTC( parseSlackTimestamp( ts ) );
}

/**
 * Extracts all taxonomy term objects from the _embedded.wp:term data of an entry.
 *
 * @param {Entry} entry - The entry object with embedded data.
 * @return {Array<{ id: number; name: string; slug: string; taxonomy: string; link: string }>} Flattened array of term objects.
 */
function getEmbeddedTerms( entry: Entry ): Array< {
	id: number;
	name: string;
	slug: string;
	taxonomy: string;
	link: string;
} > {
	return entry._embedded?.[ 'wp:term' ]?.flat() || [];
}

/**
 * Returns the lowercase machine value for an entry's source, defaulting to
 * 'wordpress' when the meta value is absent or not 'slack'. The returned value
 * matches the `elements[]` filter values so DataViews `is`/`isNot` operators
 * (strict equality) match correctly. The human-readable label belongs only in
 * the field's `render` callback.
 *
 * @param {Entry} item The entry whose source meta to read.
 * @return {string} 'slack' or 'wordpress'.
 */
function getEntrySource( item: Entry ): string {
	return String( item.meta?.rolling_coverage_entry_source ?? '' ) ===
		SOURCE_SLACK
		? SOURCE_SLACK
		: SOURCE_WORDPRESS;
}

/**
 * Returns the display label for a coverage term's connected Slack channel, preferring
 * the resolved channel name and falling back to the raw channel ID. Returns an
 * empty string when the coverage term is not connected to a Slack channel.
 *
 * @param {Coverage} item The coverage term whose Slack channel meta to read.
 * @return {string} Channel name, channel ID, or '' if not connected.
 */
function getSlackChannelLabel( item: Coverage ): string {
	const name = String( item.meta?.rolling_coverage_slack_channel_name ?? '' );
	const channelId = String(
		item.meta?.rolling_coverage_slack_channel_id ?? ''
	);
	return name || channelId;
}

/**
 * Returns the active `contains` filter value for a field, or '' when the
 * field has no such filter. Used to keep the matching term visible when a
 * category/tag filter is applied.
 *
 * @param {Array}  filters The DataViews view.filters array.
 * @param {string} fieldId Field id to look up.
 * @return {string} The filter substring, or ''.
 */
function getContainsFilterValue(
	filters: Array< {
		field: string;
		operator: string;
		value: string | string[];
	} >,
	fieldId: string
): string {
	const filter = filters.find(
		( f ) => f.field === fieldId && f.operator === 'contains'
	);

	if ( ! filter || Array.isArray( filter.value ) ) {
		return '';
	}

	return String( filter.value ?? '' );
}

/**
 * Applies all DataViews filters (source, status, date) client-side.
 *
 * Mirrors the built-in operator filter handlers so date, text, and
 * element-based filters all work identically to filterSortAndPaginate.
 *
 * @param {Entry[]} entries The entries to filter.
 * @param {Array}   filters The DataViews view.filters array.
 * @return {Entry[]} The filtered entries.
 */
function applyEntryFilters(
	entries: Entry[],
	filters: Array< {
		field: string;
		operator: string;
		value: string | string[];
	} >
): Entry[] {
	return entries.filter( ( item ) =>
		filters.every( ( filter ) => {
			// Date/Modified are enforced server-side via date_query; skip
			// here so sync deltas that fall outside the window aren't dropped.
			if ( filter.field === 'date' || filter.field === 'modified' ) {
				return true;
			}
			const itemValue = getEntryFieldValue( item, filter.field );
			return matchesOperator( itemValue, filter.operator, filter.value );
		} )
	);
}

/** Reads a field's raw value from an Entry by field id. */
function getEntryFieldValue( item: Entry, fieldId: string ): unknown {
	switch ( fieldId ) {
		case 'source':
			return getEntrySource( item );
		case 'status':
			return item.status;
		case 'date':
			return item.date;
		case 'modified':
			return item.modified;
		case 'title':
			return getRawTitle( item );
		case 'author':
			return getRawAuthor( item );
		case 'id':
			return String( item.id );
		case 'breakout':
			return getBreakoutStatus( item );
		case 'archived':
			return getArchivedStatus( item );
		case 'categories':
			return getCategoryNames( item );
		case 'tags':
			return getTagNames( item );
		default:
			return item.meta?.[ fieldId ] ?? '';
	}
}

/** Evaluates a single filter operator against an item value. */
function matchesOperator(
	itemValue: unknown,
	operator: string,
	filterValue: unknown
): boolean {
	const itemStr = String( itemValue ?? '' );

	// Text/element operators: is, isNot.
	if ( operator === 'is' ) {
		const values = Array.isArray( filterValue )
			? filterValue
			: [ filterValue ];
		return values.includes( itemStr );
	}
	if ( operator === 'isNot' ) {
		const values = Array.isArray( filterValue )
			? filterValue
			: [ filterValue ];
		return ! values.includes( itemStr );
	}
	if ( operator === 'contains' ) {
		return itemStr
			.toLowerCase()
			.includes( String( filterValue ).toLowerCase() );
	}
	if ( operator === 'notContains' ) {
		return ! itemStr
			.toLowerCase()
			.includes( String( filterValue ).toLowerCase() );
	}
	if ( operator === 'startsWith' ) {
		return itemStr
			.toLowerCase()
			.startsWith( String( filterValue ).toLowerCase() );
	}

	// Date operators: compare via Date timestamps.
	const itemDate = new Date( itemStr );
	if ( isNaN( itemDate.getTime() ) ) {
		return true; // Can't compare invalid dates; let the item through.
	}

	const filterDateStr =
		typeof filterValue === 'object' &&
		filterValue !== null &&
		'value' in filterValue
			? getRelativeDate(
					( filterValue as { value: number; unit: string } ).value,
					( filterValue as { value: number; unit: string } ).unit
				)
			: new Date( String( filterValue ) );

	if ( isNaN( filterDateStr.getTime() ) ) {
		return true;
	}

	switch ( operator ) {
		case 'on':
			return itemDate.getTime() === filterDateStr.getTime();
		case 'notOn':
			return itemDate.getTime() !== filterDateStr.getTime();
		case 'before':
			return itemDate < filterDateStr;
		case 'after':
			return itemDate > filterDateStr;
		case 'beforeInc':
			return itemDate <= filterDateStr;
		case 'afterInc':
			return itemDate >= filterDateStr;
		case 'inThePast':
			return itemDate >= filterDateStr && itemDate <= new Date();
		case 'over':
			return itemDate < filterDateStr;
		default:
			return true;
	}
}

/** Computes a relative date in the past (mirrors DataViews' getRelativeDate). */
function getRelativeDate( value: number, unit: string ): Date {
	const now = new Date();
	const ms = {
		days: 86400000,
		weeks: 604800000,
		months: 2592000000,
		years: 31536000000,
	};
	return new Date(
		now.getTime() - ( ms[ unit as keyof typeof ms ] ?? 0 ) * value
	);
}

export {
	toISODate,
	safeFormatSlackTimestamp,
	getEmbeddedTerms,
	getEntrySource,
	getSlackChannelLabel,
	getStatusLabel,
	STATUS_ELEMENTS,
	getRawTitle,
	getRawAuthor,
	getCategoryNames,
	getTagNames,
	getBreakoutStatus,
	getArchivedStatus,
	ARCHIVED_ELEMENTS,
	ARCHIVED_VALUE,
	NOT_ARCHIVED_VALUE,
	SOURCE_SLACK,
	SOURCE_WORDPRESS,
	applyEntryFilters,
	getContainsFilterValue,
};
