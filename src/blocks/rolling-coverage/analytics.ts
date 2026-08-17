/**
 * Internal dependencies
 */
import type { EventName, EventParams } from './types';

/**
 * Analytics event names emitted by the rolling coverage frontend.
 */
export const EVENTS = {
	NEW_ENTRIES_REVEALED: 'coverage_new_entries_revealed',
	POLL_ERROR: 'coverage_poll_error',
} as const;

/**
 * Checks whether a localized analytics config value is enabled.
 *
 * wp_localize_script() casts PHP booleans to strings, so `true` arrives
 * here as '1' (and `false` as '').
 *
 * @param {string | undefined} value Localized config value.
 * @return {boolean} True when the value represents an enabled setting.
 */
function isConfigEnabled( value: string | undefined ): boolean {
	return value === '1';
}

/**
 * Checks whether reader-facing analytics events should be tracked.
 *
 * @return {boolean} True when reader tracking is enabled in localized config.
 */
function canTrackReaderEvents(): boolean {
	return isConfigEnabled(
		window.newspackRollingCoverageFrontend?.readerTrackingEnabled
	);
}

/**
 * Forwards an event to Site Kit's GA4 via gtag().
 *
 * @param {string}      name   Event name.
 * @param {EventParams} params Event parameters.
 * @return {void}
 */
function trackGa4( name: string, params: EventParams ): void {
	if (
		! isConfigEnabled(
			window.newspackRollingCoverageFrontend?.siteKitGa4Enabled
		) ||
		typeof window.gtag !== 'function'
	) {
		return;
	}

	window.gtag( 'event', name, params );
}

/**
 * Tracks a frontend analytics event.
 *
 * @param {EventName}   name   Event name, from EVENTS.
 * @param {EventParams} params Event parameters.
 * @return {void}
 */
export function trackEvent( name: EventName, params: EventParams = {} ): void {
	if ( ! canTrackReaderEvents() ) {
		return;
	}

	// Pushed to dataLayer for any custom/future integrations reading it directly.
	window.dataLayer = window.dataLayer || [];
	window.dataLayer.push( {
		event: name,
		...params,
	} );

	try {
		trackGa4( name, params );
	} catch ( error ) {
		console.error( error ); // eslint-disable-line no-console
	}
}
