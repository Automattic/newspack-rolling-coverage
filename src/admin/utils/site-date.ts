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

type TimeOptions = {
	seconds?: boolean;
};

/**
 * Adds seconds to a PHP time format, right after its minutes (`i`): `g:i a`
 * becomes `g:i:s a` and `H:i` becomes `H:i:s`. A format with no minutes, or
 * one that already shows seconds, is returned as it is. Backslash-escaped
 * characters are literal text, so they are skipped.
 *
 * @param {string} pattern A PHP date format.
 * @return {string} The format with seconds.
 */
function withSeconds( pattern: string ): string {
	let minutes = -1;
	for ( let i = 0; i < pattern.length; i++ ) {
		const char = pattern[ i ];
		if ( char === '\\' ) {
			i++;
		} else if ( char === 's' ) {
			return pattern;
		} else if ( char === 'i' && minutes === -1 ) {
			minutes = i;
		}
	}
	if ( minutes === -1 ) {
		return pattern;
	}
	return (
		pattern.slice( 0, minutes + 1 ) + ':s' + pattern.slice( minutes + 1 )
	);
}

/**
 * Formats a value in the site's time format (Settings > General), in the
 * site's time zone.
 *
 * @param {Date|string|null|undefined} value           A Date, or a date string. A string
 *                                                     with no offset is read as site time.
 * @param {Object}                     options         Options.
 * @param {boolean}                    options.seconds Whether to add seconds to the format.
 * @return {string} The formatted time, or '' with no valid value.
 */
function formatSiteTime(
	value: Date | string | null | undefined,
	{ seconds = false }: TimeOptions = {}
): string {
	const pattern = getSettings().formats.time;
	return format( seconds ? withSeconds( pattern ) : pattern, value );
}

/**
 * Formats a value in the site's date and time formats (Settings > General),
 * in the site's time zone. Core's `formats.datetime` is the locale's default,
 * not the site's setting, so the two site formats are joined here instead.
 *
 * @param {Date|string|null|undefined} value           A Date, or a date string. A string
 *                                                     with no offset is read as site time.
 * @param {Object}                     options         Options.
 * @param {boolean}                    options.seconds Whether to add seconds to the time.
 * @return {string} The formatted date and time, or '' with no valid value.
 */
function formatSiteDateTime(
	value: Date | string | null | undefined,
	options: TimeOptions = {}
): string {
	const date = formatSiteDate( value );
	if ( ! date ) {
		return '';
	}
	return sprintf(
		/* translators: 1: Date in the site's date format, 2: Time in the site's time format. */
		__( '%1$s %2$s', 'newspack-rolling-coverage' ),
		date,
		formatSiteTime( value, options )
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

export { formatSiteDate, formatSiteTime, formatSiteDateTime, withSeconds };
