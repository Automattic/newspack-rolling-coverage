/**
 * External dependencies.
 */
import { dateI18n, getDate, getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

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
 * in the site's time zone. Core's `formats.datetime` is the locale's default,
 * not the site's setting, so the two site formats are joined here instead.
 *
 * @param {Date|string|null|undefined} value A Date, or a date string. A string
 *                                           with no offset is read as site time.
 * @return {string} The formatted date and time, or '' with no valid value.
 */
function formatSiteDateTime( value: Date | string | null | undefined ): string {
	const date = formatSiteDate( value );
	if ( ! date ) {
		return '';
	}
	return sprintf(
		/* translators: 1: Date in the site's date format, 2: Time in the site's time format. */
		__( '%1$s %2$s', 'newspack-rolling-coverage' ),
		date,
		formatSiteTime( value )
	);
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
