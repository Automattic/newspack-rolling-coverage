/**
 * Feeds of the same coverage and layout on one page ask the same entries
 * URLs at the same moment, so they share one request, each reading its own
 * copy of the reply.
 */
import { shareRequests } from '../../src/blocks/rolling-coverage/shared-requests';

/**
 * A reply that records which copy each caller got.
 */
class Reply {
	readonly url: string;
	readonly copy: number;

	constructor( url: string, copy = 0 ) {
		this.url = url;
		this.copy = copy;
	}

	clone(): Reply {
		return new Reply( this.url, this.copy + 1 );
	}
}

/**
 * A request sender whose replies arrive when the test says so.
 *
 * @return {Object} The sender, the URLs it was asked, and a way to answer or fail them.
 */
function sender() {
	const sent: string[] = [];
	const pending: Array< {
		resolve: ( reply: Reply ) => void;
		reject: ( error: Error ) => void;
		url: string;
	} > = [];

	return {
		sent,
		send: ( url: string ) => {
			sent.push( url );

			return new Promise< Reply >( ( resolve, reject ) =>
				pending.push( { resolve, reject, url } )
			);
		},
		answer: () => {
			const request = pending.shift();
			request?.resolve( new Reply( request.url ) );
		},
		fail: () => pending.shift()?.reject( new Error( 'offline' ) ),
	};
}

test( 'callers asking a URL in flight share its request', async () => {
	const { sent, send, answer } = sender();
	const fetchEntries = shareRequests( send );

	const first = fetchEntries( '/entries?recent=1' );
	const second = fetchEntries( '/entries?recent=1' );
	expect( sent ).toEqual( [ '/entries?recent=1' ] );
	answer();

	const [ a, b ] = await Promise.all( [ first, second ] );

	expect( a.url ).toBe( '/entries?recent=1' );
	expect( b.url ).toBe( '/entries?recent=1' );
	// Each caller reads a copy, never the reply itself or another's copy.
	expect( a.copy ).toBe( 1 );
	expect( b.copy ).toBe( 1 );
	expect( a ).not.toBe( b );
} );

test( 'callers asking different URLs send their own requests', async () => {
	const { sent, send, answer } = sender();
	const fetchEntries = shareRequests( send );

	const first = fetchEntries( '/entries?recent=1&template_key=a' );
	const second = fetchEntries( '/entries?recent=1&template_key=b' );
	answer();
	answer();

	await Promise.all( [ first, second ] );

	expect( sent ).toEqual( [
		'/entries?recent=1&template_key=a',
		'/entries?recent=1&template_key=b',
	] );
} );

test( 'a URL asked again after its reply arrived sends a new request', async () => {
	const { sent, send, answer } = sender();
	const fetchEntries = shareRequests( send );

	const first = fetchEntries( '/entries?recent=1' );
	answer();
	await first;

	const second = fetchEntries( '/entries?recent=1' );
	answer();
	await second;

	expect( sent ).toEqual( [ '/entries?recent=1', '/entries?recent=1' ] );
} );

test( 'a failed request fails every caller, and the next ask sends again', async () => {
	const { sent, send, answer, fail } = sender();
	const fetchEntries = shareRequests( send );

	const first = fetchEntries( '/entries?recent=1' );
	const second = fetchEntries( '/entries?recent=1' );
	expect( sent ).toHaveLength( 1 );
	fail();

	await expect( first ).rejects.toThrow( 'offline' );
	await expect( second ).rejects.toThrow( 'offline' );

	const third = fetchEntries( '/entries?recent=1' );
	answer();

	await expect( third ).resolves.toBeInstanceOf( Reply );
	expect( sent ).toHaveLength( 2 );
} );
