/**
 * External dependencies.
 */
import { dateI18n, getDate, getSettings } from '@wordpress/date';

/**
 * Formats a value in the site's date format (Settings > General), in the
 * site's time zone.
 *
 * @param {Date|string|null|undefined} value A Date, or a date string. A string
 *                                           with no offset is read as site time.
 * @return {string} The formatted date, or '' with no valid value.
 */
function formatSiteDate( value: Date | string | null | undefined ): string {
	return format( getSettings().formats.date, value );
}

/**
 * Formats a value in the site's time format (Settings > General), in the
 * site's time zone.
 *
 * @param {Date|string|null|undefined} value A Date, or a date string. A string
 *                                           with no offset is read as site time.
 * @return {string} The formatted time, or '' with no valid value.
 */
function formatSiteTime( value: Date | string | null | undefined ): string {
	return format( getSettings().formats.time, value );
}

/**
 * Formats a value in the site's date and time formats (Settings > General),
 * in the site's time zone. Core's `formats.datetime` is a fixed string, not
 * a site setting, so the two formats are joined here.
 *
 * @param {Date|string|null|undefined} value A Date, or a date string. A string
 *                                           with no offset is read as site time.
 * @return {string} The formatted date and time, or '' with no valid value.
 */
function formatSiteDateTime( value: Date | string | null | undefined ): string {
	const { date, time } = getSettings().formats;
	return format( `${ date } ${ time }`, value );
}

function format(
	pattern: string,
	value: Date | string | null | undefined
): string {
	if ( ! value ) {
		return '';
	}
	const instant = typeof value === 'string' ? getDate( value ) : value;
	return isNaN( instant.getTime() ) ? '' : dateI18n( pattern, instant );
}

export { formatSiteDate, formatSiteTime, formatSiteDateTime };
