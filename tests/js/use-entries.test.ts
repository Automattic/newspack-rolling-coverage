/**
 * The entries list's count ("Coverage (N)" and "N items") follows the
 * entries the 10-second poll brings in or takes out, without a reload.
 */
import apiFetch from '@wordpress/api-fetch';
import { act, renderHook } from '@testing-library/react';

import { useEntries } from '../../src/admin/hooks/useEntries';
import type {
	EntryPageResponse,
	EntrySyncDelta,
	EntryViewRow,
	UseEntriesOptions,
} from '../../src/admin/types';

jest.mock( '@wordpress/api-fetch' );

const ENTRIES_VIEW =
	'https://example.test/wp-json/rolling-coverage/v1/coverages';
const POLL_MS = 10000;
const FILTER_DEBOUNCE_MS = 500;

/** The list as it opens: newest first, trashed entries left out. */
const VIEW: UseEntriesOptions = {
	coverageId: 7,
	page: 1,
	perPage: 20,
	orderBy: 'date',
	order: 'desc',
	statusExclude: 'trash',
};

function entry(
	id: number,
	status: EntryViewRow[ 'status' ] = 'publish'
): EntryViewRow {
	return {
		id,
		title: `Entry ${ id }`,
		slug: `entry-${ id }`,
		summary: '',
		date: '2026-10-23T10:00:00',
		modified: '2026-10-23T10:00:00',
		status,
		pinned: false,
		archived_at: 0,
		coverage_status: 'active',
		author: { id: 1, name: 'Editor' },
		source: 'wordpress',
		categories: [],
		tags: [],
		breakout_post_id: 0,
		breakout_status: null,
		can_edit: true,
		can_publish: true,
		is_own: true,
	};
}

/**
 * Stands in for the entries-view route. Page mode filters the coverage's
 * entries by status and counts them; sync mode reports every entry changed
 * since the last poll, whatever the list's filters, as the server does.
 * While `holding`, page responses wait until the test releases them.
 */
class EntriesView {
	entries: EntryViewRow[] = [];
	changes: EntrySyncDelta[ 'changed' ] = [];
	pageRequests = 0;
	holding = false;
	held: Array< { url: string; respond: () => void } > = [];
	cursor = 1;

	seed( ...rows: EntryViewRow[] ) {
		this.entries.push( ...rows );
	}

	publish( ...rows: EntryViewRow[] ) {
		this.entries.unshift( ...rows );
		this.changes.push(
			...rows.map( ( row ) => ( {
				...row,
				change_type: 'new' as const,
			} ) )
		);
	}

	/**
	 * Trashes an entry from the list itself, which refetches its page: that
	 * fetch's cursor is past the change, so no poll reports it.
	 */
	trashFromList( id: number ) {
		const index = this.entries.findIndex( ( row ) => row.id === id );
		this.entries[ index ] = { ...this.entries[ index ], status: 'trash' };
	}

	trash( id: number ) {
		const index = this.entries.findIndex( ( row ) => row.id === id );
		this.entries[ index ] = { ...this.entries[ index ], status: 'trash' };
		this.changes.push( {
			...this.entries[ index ],
			change_type: 'update',
		} );
	}

	handle( url: string ): Promise< EntryPageResponse | EntrySyncDelta > {
		const params = new URL( url ).searchParams;

		if ( params.has( 'since' ) ) {
			const changed = this.changes;
			this.changes = [];
			if ( changed.length > 0 ) {
				this.cursor++;
			}
			return Promise.resolve( {
				changed,
				cursor: `${ this.cursor }:2026-10-23 10:00:00`,
				overflow: false,
			} );
		}

		this.pageRequests++;
		const included = params.get( 'status' )?.split( ',' );
		const excluded = params.get( 'status_exclude' )?.split( ',' ) ?? [];
		const matching = this.entries.filter(
			( row ) =>
				( ! included || included.includes( row.status ) ) &&
				! excluded.includes( row.status )
		);
		const page = Number( params.get( 'page' ) );
		const perPage = Number( params.get( 'per_page' ) );
		const response: EntryPageResponse = {
			entries: matching.slice( ( page - 1 ) * perPage, page * perPage ),
			totalItems: matching.length,
			totalPages: Math.max( 1, Math.ceil( matching.length / perPage ) ),
			page,
			cursor: `${ this.cursor }:2026-10-23 10:00:00`,
		};

		if ( ! this.holding ) {
			return Promise.resolve( response );
		}
		return new Promise( ( resolve ) => {
			this.held.push( { url, respond: () => resolve( response ) } );
		} );
	}

	async release( matches: ( url: string ) => boolean ) {
		const released = this.held.filter( ( { url } ) => matches( url ) );
		this.held = this.held.filter( ( { url } ) => ! matches( url ) );
		await act( async () => {
			released.forEach( ( { respond } ) => respond() );
			await jest.advanceTimersByTimeAsync( 0 );
		} );
	}
}

let server: EntriesView;

async function wait( ms: number ) {
	await act( async () => {
		await jest.advanceTimersByTimeAsync( ms );
	} );
}

beforeEach( () => {
	jest.useFakeTimers();
	window.newspackRollingCoverageAdmin = {
		restBaseUrls: { entriesView: ENTRIES_VIEW },
	} as typeof window.newspackRollingCoverageAdmin;
	server = new EntriesView();
	jest.mocked( apiFetch ).mockImplementation( ( { url } ) =>
		server.handle( url as string )
	);
} );

afterEach( () => {
	jest.useRealTimers();
} );

describe( 'useEntries', () => {
	it( 'counts the entries a poll brings in', async () => {
		const { result } = renderHook( () => useEntries( VIEW ) );
		await wait( 0 );
		expect( result.current.totalItems ).toBe( 0 );

		server.publish( entry( 2 ), entry( 1 ) );
		await wait( POLL_MS );

		expect( result.current.rows ).toHaveLength( 2 );
		expect( result.current.totalItems ).toBe( 2 );
		expect( result.current.totalPages ).toBe( 1 );
	} );

	it( 'stops counting an entry a poll reports trashed', async () => {
		server.seed( entry( 3 ), entry( 2 ), entry( 1 ) );
		const { result } = renderHook( () => useEntries( VIEW ) );
		await wait( 0 );
		expect( result.current.totalItems ).toBe( 3 );

		server.trash( 2 );
		await wait( POLL_MS );

		expect( result.current.totalItems ).toBe( 2 );
	} );

	it( 'counts new entries that belong on other pages', async () => {
		server.seed( entry( 3 ), entry( 2 ), entry( 1 ) );
		const { result } = renderHook( () =>
			useEntries( { ...VIEW, page: 2, perPage: 2 } )
		);
		await wait( 0 );
		expect( result.current.totalItems ).toBe( 3 );
		expect( result.current.totalPages ).toBe( 2 );

		server.publish( entry( 5 ), entry( 4 ) );
		await wait( POLL_MS );

		expect( result.current.rows?.map( ( row ) => row.id ) ).toEqual( [
			1,
		] );
		expect( result.current.totalItems ).toBe( 5 );
		expect( result.current.totalPages ).toBe( 3 );
	} );

	it( 'keeps the count of the filters on screen when they change while a poll counts', async () => {
		server.seed( entry( 1, 'draft' ) );
		const { result, rerender } = renderHook(
			( props: UseEntriesOptions ) => useEntries( props ),
			{ initialProps: VIEW }
		);
		await wait( 0 );
		expect( result.current.totalItems ).toBe( 1 );

		// The poll lands, and its count of entries that aren't trashed (2)
		// is still on its way when the list switches to published ones (1).
		server.publish( entry( 2 ) );
		server.holding = true;
		await wait( POLL_MS );
		rerender( { ...VIEW, status: 'publish' } );
		await wait( FILTER_DEBOUNCE_MS );

		await server.release( ( url ) => url.includes( 'status=publish' ) );
		expect( result.current.totalItems ).toBe( 1 );

		await server.release( () => true );
		expect( result.current.totalItems ).toBe( 1 );
	} );

	it( 'keeps the page count of the page size on screen when it changes while a poll counts', async () => {
		server.seed(
			entry( 5 ),
			entry( 4 ),
			entry( 3 ),
			entry( 2 ),
			entry( 1 )
		);
		const { result, rerender } = renderHook(
			( props: UseEntriesOptions ) => useEntries( props ),
			{ initialProps: { ...VIEW, perPage: 2 } }
		);
		await wait( 0 );
		expect( result.current.totalPages ).toBe( 3 );

		// The poll's count of 6 is still on its way when the list switches
		// from 2 to 5 entries a page, which makes 2 pages, not 3.
		server.publish( entry( 6 ) );
		server.holding = true;
		await wait( POLL_MS );
		rerender( { ...VIEW, perPage: 5 } );
		await wait( FILTER_DEBOUNCE_MS );

		await server.release( ( url ) => url.includes( 'per_page=5' ) );
		expect( result.current.totalPages ).toBe( 2 );

		await server.release( () => true );
		expect( result.current.totalPages ).toBe( 2 );
	} );

	it( "keeps a refetch's count when an earlier poll's count lands after it", async () => {
		server.seed( entry( 3 ), entry( 2 ), entry( 1 ) );
		const { result, rerender } = renderHook(
			( props: UseEntriesOptions ) => useEntries( props ),
			{ initialProps: { ...VIEW, refreshKey: 0 } }
		);
		await wait( 0 );

		// The poll's count of 4 is still on its way when the editor trashes
		// an entry from the list, which refetches it (3).
		server.publish( entry( 4 ) );
		server.holding = true;
		await wait( POLL_MS );
		server.trashFromList( 4 );
		rerender( { ...VIEW, refreshKey: 1 } );
		await wait( FILTER_DEBOUNCE_MS );

		await server.release( ( url ) => url.includes( 'per_page=20' ) );
		expect( result.current.totalItems ).toBe( 3 );

		await server.release( () => true );
		expect( result.current.totalItems ).toBe( 3 );
	} );

	it( 'asks for no count when a poll finds no changes', async () => {
		server.seed( entry( 1 ) );
		renderHook( () => useEntries( VIEW ) );
		await wait( 0 );
		expect( server.pageRequests ).toBe( 1 );

		await wait( POLL_MS * 3 );

		expect( server.pageRequests ).toBe( 1 );
	} );
} );
