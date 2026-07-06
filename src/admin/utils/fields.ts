/**
 * Contains utility functions for formatting field values in the admin interface.
 */

/**
 * Internal dependencies
 */
import type { Entry, Coverage } from '../types';

/** Machine value for entries sourced from Slack. */
const SOURCE_SLACK = 'slack';
/** Machine value for entries sourced from the WordPress editor. */
const SOURCE_WORDPRESS = 'wordpress';

/**
 * Truncates a string to `max` characters, appending an ellipsis if shortened.
 *
 * @param {string} text - The string to truncate.
 * @param {number} max  - Maximum character length.
 * @return {string} The truncated string.
 */
function truncate( text: string, max: number ): string {
	return text.length > max ? text.slice( 0, max ) + '…' : text;
}

/**
 * Parses an ISO date string into a Date object, returning null for invalid/empty values.
 *
 * @param {string | null | undefined} dateStr - The date string to parse.
 * @return {Date | null} The parsed Date, or null if invalid.
 */
function parseDate( dateStr: string | null | undefined ): Date | null {
	if ( ! dateStr ) {
		return null;
	}
	const d = new Date( dateStr );
	return isNaN( d.getTime() ) ? null : d;
}

/**
 * Formats a Date as a UTC string, returning "—" when the date is null. Shared
 * by the safe-format helpers to keep the "invalid → em dash" tail in one place.
 *
 * @param {Date | null} date The date to format, or null.
 * @return {string} UTC date string or "—".
 */
function formatUTC( date: Date | null ): string {
	return date ? date.toUTCString() : '—';
}

/**
 * Parses an ISO date string and returns a UTC string, or "—" if invalid.
 *
 * @param {string | null | undefined} dateStr - The date string to parse.
 * @return {string} UTC date string or "—".
 */
function safeFormatUTCDate( dateStr: string | null | undefined ): string {
	return formatUTC( parseDate( dateStr ) );
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
 * Formats a Slack timestamp as a UTC string — the same format used by
 * safeFormatUTCDate for DataViews datetime columns — or "—" if invalid/empty.
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

export {
	truncate,
	safeFormatUTCDate,
	safeFormatSlackTimestamp,
	getEmbeddedTerms,
	getEntrySource,
	getSlackChannelLabel,
	SOURCE_SLACK,
	SOURCE_WORDPRESS,
};
