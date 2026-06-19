/**
 * Contains utility functions for formatting field values in the admin interface.
 */

import type { Entry } from '../types';

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
 * Parses an ISO date string and returns an ISO string, or "—" if invalid.
 *
 * @param {string | null | undefined} dateStr - The date string to parse.
 * @return {string} ISO date string or "—".
 */
function safeParseDate( dateStr: string | null | undefined ): string {
	return parseDate( dateStr )?.toISOString() ?? '—';
}

/**
 * Parses an ISO date string and returns a UTC string, or "—" if invalid.
 *
 * @param {string | null | undefined} dateStr - The date string to parse.
 * @return {string} UTC date string or "—".
 */
function safeFormatUTCDate( dateStr: string | null | undefined ): string {
	return parseDate( dateStr )?.toUTCString() ?? '—';
}

/**
 * Extracts all taxonomy term objects from the _embedded.wp:term data of an entry.
 *
 * @param {Entry} entry - The entry object with embedded data.
 * @return {Array<{ id: number; name: string; slug: string; taxonomy: string; link: string }>} Flattened array of term objects.
 */
function getEmbeddedTerms( entry: Entry ) {
	return entry._embedded?.[ 'wp:term' ]?.flat() || [];
}

export { truncate, safeParseDate, safeFormatUTCDate, getEmbeddedTerms };
