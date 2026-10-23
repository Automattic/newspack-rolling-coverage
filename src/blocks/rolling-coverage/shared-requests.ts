/**
 * Entry requests that go out once per URL at a time.
 *
 * Feeds of one coverage on a page often ask the same URL in the same moment,
 * as when feeds with the same config and host post poll the shared poll URL
 * together. The reply depends only on the URL, so they share one request, and
 * each feed reads its own copy of it. The block's DEVELOPMENT.md, under
 * Polling, says which feeds share.
 */

/**
 * Wraps a request so that callers asking a URL while a request for it is in
 * flight share that request. A URL asked again once the reply has arrived
 * gets a request of its own.
 *
 * @param {Function} send Sends the request for a URL.
 * @return {Function} The shared request: a copy of the reply for each caller.
 */
export function shareRequests< T extends { clone: () => T } >(
	send: ( url: string ) => Promise< T >
): ( url: string ) => Promise< T > {
	const inFlight = new Map< string, Promise< T > >();

	return ( url ) => {
		let request = inFlight.get( url );

		if ( ! request ) {
			const forget = () => inFlight.delete( url );

			request = send( url );
			request.then( forget, forget );
			inFlight.set( url, request );
		}

		// Each caller reads its own copy, since a body can be read only once.
		return request.then( ( response ) => response.clone() );
	};
}
