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
 * A formatter in the page's language, or the browser's when the page's
 * `lang` isn't a tag Intl accepts.
 *
 * @return {Intl.RelativeTimeFormat | null} The formatter, or null without Intl support.
 */
function getFormatter(): Intl.RelativeTimeFormat | null {
	if ( typeof Intl === 'undefined' || ! Intl.RelativeTimeFormat ) {
		return null;
	}

	try {
		return new Intl.RelativeTimeFormat(
			document.documentElement.lang || undefined
		);
	} catch {
		return new Intl.RelativeTimeFormat();
	}
}

/**
 * Rewrites the relative dates inside an element from their timestamps.
 *
 * @param {Element}                 scope     The element to update the dates in.
 * @param {Intl.RelativeTimeFormat} formatter The formatter to write them with.
 */
function refreshRelativeDates(
	scope: Element,
	formatter: Intl.RelativeTimeFormat
): void {
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
 * Updates the relative dates in a block now and every minute, and in every
 * entry added to its list later by polling or loading more.
 *
 * @param {HTMLElement} root        The block's outer wrapper element.
 * @param {HTMLElement} entriesList The block's entries list.
 * @return {Function} Stops the updates.
 */
function keepRelativeDatesCurrent(
	root: HTMLElement,
	entriesList: HTMLElement
): () => void {
	const formatter = getFormatter();

	if ( ! formatter ) {
		return () => {};
	}

	refreshRelativeDates( root, formatter );

	const intervalId = window.setInterval(
		() => refreshRelativeDates( root, formatter ),
		REFRESH_INTERVAL_MS
	);

	const observer = new MutationObserver( ( mutations ) =>
		mutations.forEach( ( mutation ) =>
			mutation.addedNodes.forEach( ( node ) => {
				if ( node instanceof Element ) {
					refreshRelativeDates( node, formatter );
				}
			} )
		)
	);
	observer.observe( entriesList, { childList: true } );

	return () => {
		window.clearInterval( intervalId );
		observer.disconnect();
	};
}

export {
	keepRelativeDatesCurrent,
	getFormatter,
	refreshRelativeDates,
	REFRESH_INTERVAL_MS,
};
