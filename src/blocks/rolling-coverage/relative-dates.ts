/**
 * Keeps relative entry dates ("5 minutes ago") current. The server writes
 * them when it renders the page, so a cached page, or a tab left open, would
 * otherwise show how old each entry was when the HTML was made.
 */

// Entry dates in the relative format, marked by Rolling_Coverage_Block.
const RELATIVE_DATE_SELECTOR = 'time[data-rc-relative][datetime]';

const REFRESH_INTERVAL_MS = 60000;

// Largest first; WordPress's human_time_diff() uses the same steps.
const UNITS: [ Intl.RelativeTimeFormatUnit, number ][] = [
	[ 'year', 31536000 ],
	[ 'month', 2592000 ],
	[ 'week', 604800 ],
	[ 'day', 86400 ],
	[ 'hour', 3600 ],
	[ 'minute', 60 ],
	[ 'second', 1 ],
];

/**
 * Rewrites the relative dates inside an element from their timestamps.
 *
 * @param {Element} scope The element to update the dates in.
 */
function refreshRelativeDates( scope: Element ): void {
	if ( typeof Intl === 'undefined' || ! Intl.RelativeTimeFormat ) {
		return;
	}

	const formatter = new Intl.RelativeTimeFormat(
		document.documentElement.lang || undefined
	);

	scope
		.querySelectorAll< HTMLTimeElement >( RELATIVE_DATE_SELECTOR )
		.forEach( ( time ) => {
			const timestamp = Date.parse( time.dateTime );

			if ( Number.isNaN( timestamp ) ) {
				return;
			}

			const seconds = ( timestamp - Date.now() ) / 1000;
			const [ unit, size ] = UNITS.find(
				( [ , unitSeconds ] ) => Math.abs( seconds ) >= unitSeconds
			) ?? [ 'second', 1 ];
			const value =
				Math.round( seconds / size ) || ( seconds < 0 ? -1 : 1 );
			const text = formatter.format( value, unit );

			if ( time.textContent !== text ) {
				time.textContent = text;
			}
		} );
}

/**
 * Updates the relative dates in an entries list now, every minute, and in
 * every entry added to it later by polling or loading more.
 *
 * @param {HTMLElement} entriesList The block's entries list.
 */
function keepRelativeDatesCurrent( entriesList: HTMLElement ): void {
	refreshRelativeDates( entriesList );

	window.setInterval(
		() => refreshRelativeDates( entriesList ),
		REFRESH_INTERVAL_MS
	);

	new MutationObserver( ( mutations ) =>
		mutations.forEach( ( mutation ) =>
			mutation.addedNodes.forEach( ( node ) => {
				if ( node instanceof Element ) {
					refreshRelativeDates( node );
				}
			} )
		)
	).observe( entriesList, { childList: true } );
}

export { keepRelativeDatesCurrent };
