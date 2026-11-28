/**
 * Shows an entry's earlier-day date, such as Clock's date under the time,
 * only while the entry's day isn't the site's current day. The server hides
 * today's when it renders the entry, but a cached page, or a tab left open,
 * can outlive that day.
 */

// Marked by Rolling_Coverage_Block::mark_earlier_day_entry_date() with the entry's day.
const EARLIER_DAY_DATE_SELECTOR =
	'.newspack-rolling-coverage-earlier-day-date[data-rc-day]';

/**
 * The site's timezone, as Settings > General stores it: a named timezone, or
 * an empty name and a UTC offset in hours.
 */
type SiteTimezone = {
	string?: string;
	offset?: number | string;
};

/**
 * The site's day at a moment, `Y-m-d`, worked out in the site's timezone so
 * the reader's own timezone plays no part.
 *
 * @param {number}       now        The moment, in milliseconds since the epoch.
 * @param {SiteTimezone} [timezone] The site's timezone; UTC without one.
 * @return {string} The day.
 */
function siteDay( now: number, timezone?: SiteTimezone ): string {
	if ( timezone?.string && typeof Intl !== 'undefined' ) {
		try {
			const parts = new Intl.DateTimeFormat( 'en-US', {
				timeZone: timezone.string,
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
			} ).formatToParts( now );
			const part = ( type: Intl.DateTimeFormatPartTypes ) =>
				parts.find( ( found ) => found.type === type )?.value;
			const [ year, month, day ] = [
				part( 'year' ),
				part( 'month' ),
				part( 'day' ),
			];

			if ( year && month && day ) {
				return `${ year }-${ month }-${ day }`;
			}
		} catch {
			// A name this browser doesn't know; the offset stands in for it.
		}
	}

	const offsetHours = Number( timezone?.offset ) || 0;

	return new Date( now + offsetHours * 3600000 ).toISOString().slice( 0, 10 );
}

/**
 * Shows or hides the earlier-day dates inside an element for the site's day.
 *
 * @param {Element} scope The element to update the dates in.
 * @param {string}  today The site's current day, `Y-m-d`.
 */
function refreshEarlierDayDates( scope: Element, today: string ): void {
	scope
		.querySelectorAll< HTMLElement >( EARLIER_DAY_DATE_SELECTOR )
		.forEach( ( date ) => {
			const isToday = date.dataset.rcDay === today;

			if ( date.hidden !== isToday ) {
				date.hidden = isToday;
			}
		} );
}

export { refreshEarlierDayDates, siteDay, type SiteTimezone };
