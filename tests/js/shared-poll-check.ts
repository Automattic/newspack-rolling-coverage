/**
 * PHPUnit checks the server's cursor rules against the cases in
 * tests/fixtures/poll-cursor-cases.json (tests/test-shared-poll.php). This
 * script checks the page's copy of those rules, in
 * src/blocks/rolling-coverage/shared-poll.ts, against the same cases, and
 * checks how the page reads a shared reply.
 *
 * CI doesn't run it. Run it by hand from the repository root:
 *
 *     node --experimental-strip-types tests/js/shared-poll-check.ts
 *
 * It needs a Node version with `--experimental-strip-types` and ES module
 * syntax detection, as package.json sets no module type; it has run on
 * Node 22.23.2 and 23.0.0. It prints a line per check and exits non-zero
 * when any check fails.
 */

/* eslint-disable no-console */

import { readFileSync } from 'node:fs';

import {
	classifyChange,
	isSharedReply,
	laterCursor,
	parseCursor,
	readSharedReply,
} from '../../src/blocks/rolling-coverage/shared-poll.ts';
import type {
	ChangeOutcome,
	SharedPollState,
} from '../../src/blocks/rolling-coverage/shared-poll.ts';
import type {
	SharedPollChange,
	SharedPollResponse,
} from '../../src/blocks/rolling-coverage/types.ts';

/**
 * One case from tests/fixtures/poll-cursor-cases.json: a change to entry 7
 * against a page at `cursorModified`, holding entry 7 or not.
 */
interface CursorCase {
	case: string;
	cursorModified: string;
	held: boolean;
	type: SharedPollChange[ 'type' ];
	modified: string;
	published?: string;
	unpublished?: string;
	expected: ChangeOutcome;
}

const cases: CursorCase[] = JSON.parse(
	readFileSync(
		new URL( '../fixtures/poll-cursor-cases.json', import.meta.url ),
		'utf8'
	)
);

let failed = 0;

const check = ( name: string, actual: unknown, expected: unknown ) => {
	const got = JSON.stringify( actual );
	const want = JSON.stringify( expected );

	if ( got === want ) {
		console.log( `ok   ${ name }` );
		return;
	}

	failed++;
	console.log( `FAIL ${ name }: got ${ got }, want ${ want }` );
};

for ( const c of cases ) {
	const position = {
		modified: c.cursorModified,
		ids: c.held ? [ 7 ] : [ 3 ],
		marker: 'm',
	};

	check(
		`case: ${ c.case }`,
		classifyChange( position, {
			id: 7,
			type: c.type,
			modified: c.modified,
			published: c.published,
			unpublished: c.unpublished,
		} ),
		c.expected
	);
}

const SINCE = '2026-01-01 11:00:00';

const reply = (
	fields: Partial< SharedPollResponse >
): SharedPollResponse => ( {
	since: SINCE,
	cursor: '9:2026-01-01 12:05:00@b',
	changes: [],
	overflow: false,
	minPollInterval: 0,
	status: 'active',
	newestEntry: null,
	...fields,
} );

const entry = (
	id: number,
	modified: string,
	published = modified,
	extra: Partial< SharedPollChange > = {}
): SharedPollChange => ( {
	id,
	type: 'entry',
	modified,
	published,
	html: `<article data-entry-id="${ id }" data-arrival=""></article>`,
	...extra,
} );

const at = (
	cursor: string,
	polledCount = 0,
	isCapped = false
): SharedPollState => ( { cursor, polledCount, isCapped } );

// A reply with the page's own marker: nothing to apply. It overflows, so
// only the marker keeps the page from its own cursor request.
check(
	'quiet',
	readSharedReply(
		reply( {
			cursor: '5:2026-01-01 12:00:00@a',
			changes: [ entry( 5, '2026-01-01 12:00:00' ) ],
			overflow: true,
		} ),
		at( '5:2026-01-01 12:00:00@a' )
	)?.entries,
	[]
);

// A new entry and an edit, for a page at the window's start after a quiet
// stretch; the change it holds is skipped.
const applied = readSharedReply(
	reply( {
		changes: [
			entry( 9, '2026-01-01 12:05:00' ),
			entry( 4, '2026-01-01 12:04:00', '2026-01-01 10:00:00' ),
			entry( 5, SINCE ),
		],
	} ),
	at( `5:${ SINCE }@a` )
);
check(
	'apply: types',
	applied?.entries.map( ( e ) => [ e.id, e.type ] ),
	[
		[ 9, 'insert' ],
		[ 4, 'update' ],
	]
);
check( 'apply: cursor', applied?.cursor, '9:2026-01-01 12:05:00@b' );

// What only the page's own cursor request can serve.
check(
	'behind',
	readSharedReply( reply( {} ), at( '5:2026-01-01 10:00:00@a' ) ),
	null
);
check(
	'overflow',
	readSharedReply( reply( { overflow: true } ), at( `5:${ SINCE }@a` ) ),
	null
);
check(
	'capped removal',
	readSharedReply(
		reply( {
			changes: [
				{
					id: 6,
					type: 'remove',
					modified: '2026-01-01 12:05:00',
					unpublished: '2026-01-01 12:05:00',
				},
			],
		} ),
		at( `5:${ SINCE }@a`, 0, true )
	),
	null
);

// The cursor never moves back to an older cached reply's; in the same
// second the ids merge.
check(
	'older reply',
	laterCursor( '9:2026-01-01 12:05:00@b', '5:2026-01-01 12:00:00@a' ),
	'9:2026-01-01 12:05:00@b'
);
check(
	'same second merges ids',
	laterCursor( '3:2026-01-01 12:05:00@a', '9:2026-01-01 12:05:00@b' ),
	'3,9:2026-01-01 12:05:00@b'
);

// Every `adsInterval`th new entry keeps its placement, counting on from the
// page's own count. An edit, entry 4, gets none and doesn't count.
const ad = ( id: number ) => ( {
	adHtml: `<div id="ad-${ id }"></div>`,
	adSlot: null,
} );
const withAds = readSharedReply(
	reply( {
		cursor: '12:2026-01-01 12:09:00@c',
		adsInterval: 2,
		changes: [
			entry( 12, '2026-01-01 12:09:00', '2026-01-01 12:09:00', ad( 12 ) ),
			entry( 11, '2026-01-01 12:08:00', '2026-01-01 12:08:00', ad( 11 ) ),
			entry( 4, '2026-01-01 12:07:30', '2026-01-01 10:00:00', ad( 4 ) ),
			entry( 10, '2026-01-01 12:07:00', '2026-01-01 12:07:00', ad( 10 ) ),
		],
	} ),
	at( `5:${ SINCE }@a`, 1 )
);
check(
	'ads: placed',
	withAds?.entries.map( ( e ) => [ e.id, e.type, e.adHtml ] ),
	[
		[ 12, 'insert', '<div id="ad-12"></div>' ],
		[ 11, 'insert', null ],
		[ 4, 'update', null ],
		[ 10, 'insert', '<div id="ad-10"></div>' ],
	]
);
check( 'ads: count', withAds?.polledCount, 0 );

// An unknown template key comes through, for the page to reload.
check(
	'stale',
	readSharedReply(
		reply( { since: '', cursor: '', staleTemplate: true } ),
		at( `5:${ SINCE }@a` )
	)?.staleTemplate,
	true
);

check( 'parse', parseCursor( '9,3:2026-01-01 12:05:00@x' ), {
	modified: '2026-01-01 12:05:00',
	ids: [ 3, 9 ],
	marker: 'x',
} );

// What the page reads as a shared reply. Anything else, such as the 400 a
// server without shared polling answers the shared URL with, sends the page
// to cursor polling. A stale template's reply has an empty cursor and is
// still one, so the page reloads rather than leaving shared polling.
check( 'shared reply: a reply', isSharedReply( reply( {} ) ), true );
check(
	'shared reply: stale template',
	isSharedReply( reply( { since: '', cursor: '', staleTemplate: true } ) ),
	true
);
(
	[
		[ 'empty object', {} ],
		[ 'null', null ],
		[ 'string', 'recent' ],
		[ 'no changes', { cursor: '' } ],
		[ 'no cursor', { changes: [] } ],
		[ 'changes not a list', { changes: {}, cursor: '' } ],
		[ 'cursor not a string', { changes: [], cursor: null } ],
		[
			'missing-cursor error',
			{
				code: 'rolling_coverage_missing_cursor',
				message: 'Either cursor or before must be provided.',
				data: { status: 400 },
			},
		],
	] as const
 ).forEach( ( [ name, body ] ) =>
	check( `shared reply: ${ name }`, isSharedReply( body ), false )
);

process.exit( failed ? 1 : 0 );
