/**
 * After failed polls, a page waits longer before it polls again, so a site
 * that can't answer isn't asked at the full pace by every open page.
 */
import {
	BACKOFF_CAP_MS,
	pollWait,
} from '../../src/blocks/rolling-coverage/poll-backoff';

const INTERVAL = 30 * 1000;

test( 'a page whose polls get replies waits its interval', () => {
	expect( pollWait( INTERVAL, 0, 1 ) ).toBe( INTERVAL );
	expect( pollWait( INTERVAL, 0, 0.5 ) ).toBe( INTERVAL );
} );

test( 'each failure in a row doubles the wait', () => {
	expect( pollWait( INTERVAL, 1, 1 ) ).toBe( 60 * 1000 );
	expect( pollWait( INTERVAL, 2, 1 ) ).toBe( 120 * 1000 );
	expect( pollWait( INTERVAL, 3, 1 ) ).toBe( 240 * 1000 );
} );

test( 'the wait stops growing at the cap', () => {
	expect( BACKOFF_CAP_MS ).toBe( 5 * 60 * 1000 );
	expect( pollWait( INTERVAL, 4, 1 ) ).toBe( BACKOFF_CAP_MS );
	expect( pollWait( INTERVAL, 10, 1 ) ).toBe( BACKOFF_CAP_MS );
} );

test( 'a long outage keeps the wait at the cap', () => {
	expect( pollWait( INTERVAL, 5000, 1 ) ).toBe( BACKOFF_CAP_MS );
	expect( pollWait( 0, 5000, 1 ) ).toBe( 0 );
} );

test( 'an interval longer than the cap is the most a page waits', () => {
	const interval = 10 * 60 * 1000;

	expect( pollWait( interval, 1, 1 ) ).toBe( interval );
	expect( pollWait( interval, 3, 0.5 ) ).toBe( interval );
} );

test( 'the spread shortens the wait, never below the interval', () => {
	expect( pollWait( INTERVAL, 1, 0.75 ) ).toBe( 45 * 1000 );
	expect( pollWait( INTERVAL, 3, 0.5 ) ).toBe( 120 * 1000 );
	expect( pollWait( INTERVAL, 1, 0.5 ) ).toBe( INTERVAL );
	expect( pollWait( INTERVAL, 10, 0.5 ) ).toBe( BACKOFF_CAP_MS / 2 );
} );
