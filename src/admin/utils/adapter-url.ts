/** Helpers for syncing the active chat-source adapter slug to the `?adapter=` URL query parameter. */

/**
 * Reads the active chat-source adapter slug from the `?adapter=` URL query
 * parameter, or null when window/search is unavailable.
 *
 * @return {string | null} The adapter slug from the URL, or null.
 */
function getAdapterFromUrl(): string | null {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const params = new URLSearchParams( window.location.search );
	return params.get( 'adapter' );
}

/**
 * Writes the active adapter slug into the `?adapter=` URL query parameter
 * without triggering a navigation.
 *
 * @param {string} slug Active adapter slug to write into the URL.
 */
function setAdapterInUrl( slug: string ): void {
	if ( typeof window === 'undefined' ) {
		return;
	}
	const url = new URL( window.location.href );
	url.searchParams.set( 'adapter', slug );
	window.history.replaceState( {}, '', url.toString() );
}

export { getAdapterFromUrl, setAdapterInUrl };
