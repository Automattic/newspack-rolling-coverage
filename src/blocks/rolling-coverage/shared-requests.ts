/**
 * Entry requests that go out once per URL at a time.
 *
 * Feeds of the same coverage and layout on one page ask the same URLs: they
 * start polling together when the page loads and again when its tab shows, so
 * their polls go out in the same moment. The reply depends only on the URL,
 * so they share one request, and each feed reads its own copy of the reply.
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
