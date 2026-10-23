import {
	NEW_LABEL_ATTR,
	NEW_MARKER_ATTR,
	NEW_STATE_ATTR,
	NewEntryMarks,
	TINT_END,
	TINT_START,
	rowEdges,
	tintInsets,
} from '../../src/blocks/rolling-coverage/new-entry-marks';
import {
	CLEAR_MS,
	COLLAPSE_DELAY_MS,
	COLLAPSE_MS,
	EASE_IN_OUT,
	HOLD_MS,
} from '../../src/blocks/shared/motion';

type Fired = {
	intersectionRatio: number;
	intersectionRect?: { height: number };
};

class MockIntersectionObserver {
	static instances: MockIntersectionObserver[] = [];

	callback: IntersectionObserverCallback;
	options: IntersectionObserverInit | undefined;
	targets = new Set< Element >();

	constructor(
		callback: IntersectionObserverCallback,
		options?: IntersectionObserverInit
	) {
		this.callback = callback;
		this.options = options;
		MockIntersectionObserver.instances.push( this );
	}

	observe( target: Element ) {
		this.targets.add( target );
	}

	unobserve( target: Element ) {
		this.targets.delete( target );
	}

	disconnect() {
		this.targets.clear();
	}

	takeRecords() {
		return [];
	}
}

function observerOf( target: Element ): MockIntersectionObserver | undefined {
	return MockIntersectionObserver.instances.find( ( instance ) =>
		instance.targets.has( target )
	);
}

function fire(
	target: Element,
	{ intersectionRatio, intersectionRect }: Fired
) {
	const observer = observerOf( target );

	if ( ! observer ) {
		throw new Error( 'The target is not observed.' );
	}

	observer.callback(
		[
			{
				target,
				intersectionRatio,
				isIntersecting: intersectionRatio > 0,
				intersectionRect: intersectionRect ?? { height: 0 },
			} as unknown as IntersectionObserverEntry,
		],
		observer as unknown as IntersectionObserver
	);
}

let visibility: DocumentVisibilityState = 'visible';

function setVisibility( state: DocumentVisibilityState ) {
	visibility = state;
	document.dispatchEvent( new Event( 'visibilitychange' ) );
}

let reducedMotion = false;
let parentStyle: Partial< CSSStyleDeclaration > = { display: 'block' };
let markerStyle: Partial< CSSStyleDeclaration > = { position: 'static' };
let animate: jest.Mock;

async function flush() {
	await Promise.resolve();
	await Promise.resolve();
}

function makeEntry( id: string, withMarker = true ): HTMLElement {
	const entry = document.createElement( 'div' );
	entry.dataset.entryId = id;

	const top = document.createElement( 'div' );
	top.className = 'top';

	if ( withMarker ) {
		const marker = document.createElement( 'p' );
		marker.setAttribute( NEW_MARKER_ATTR, '' );
		marker.hidden = true;
		marker.textContent = 'New';
		marker.getBoundingClientRect = () => ( { height: 18 } ) as DOMRect;
		top.appendChild( marker );
	}

	const time = document.createElement( 'p' );
	time.textContent = '10:00';
	top.appendChild( time );
	entry.appendChild( top );

	return entry;
}

function markerOf( entry: HTMLElement ): HTMLElement {
	return entry.querySelector( `[${ NEW_MARKER_ATTR }]` ) as HTMLElement;
}

let root: HTMLElement;
let marks: NewEntryMarks;

beforeEach( () => {
	jest.useFakeTimers();
	MockIntersectionObserver.instances = [];
	window.IntersectionObserver =
		MockIntersectionObserver as unknown as typeof IntersectionObserver;

	visibility = 'visible';
	Object.defineProperty( document, 'visibilityState', {
		configurable: true,
		get: () => visibility,
	} );

	reducedMotion = false;
	window.matchMedia = jest.fn( () => ( {
		matches: ! reducedMotion,
	} ) ) as unknown as typeof window.matchMedia;

	parentStyle = { display: 'block' };
	markerStyle = { position: 'static' };
	jest.spyOn( window, 'getComputedStyle' ).mockImplementation(
		( element ) =>
			( element.hasAttribute( NEW_MARKER_ATTR )
				? markerStyle
				: parentStyle ) as CSSStyleDeclaration
	);

	animate = jest.fn( () => ( {
		finished: Promise.resolve(),
		cancel: jest.fn(),
	} ) );
	Element.prototype.animate = animate as unknown as Element[ 'animate' ];

	Object.defineProperty( window, 'innerHeight', {
		configurable: true,
		value: 800,
	} );

	root = document.createElement( 'div' );
	document.body.appendChild( root );
	marks = new NewEntryMarks( root );
} );

afterEach( () => {
	marks.destroy();
	root.remove();
	jest.restoreAllMocks();
	jest.useRealTimers();
} );

describe( 'NewEntryMarks', () => {
	it( 'marks an entry and reveals its marker', () => {
		const entry = makeEntry( '1' );
		root.appendChild( entry );

		marks.mark( entry );

		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );
		expect( markerOf( entry ).hidden ).toBe( false );
		expect( marks.isMarked( '1' ) ).toBe( true );
		expect(
			entry.querySelector( '.newspack-rolling-coverage-new-status' )
		).toBeNull();
	} );

	it( 'adds a screen-reader "New" when the template has no marker', () => {
		const entry = makeEntry( '1', false );
		root.appendChild( entry );

		marks.mark( entry );

		const status = entry.querySelector(
			'.newspack-rolling-coverage-new-status'
		);
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );
		expect( status?.tagName ).toBe( 'SPAN' );
		expect( status?.textContent ).toBe( 'New' );
	} );

	it( "ignores the marker of a feed nested in the entry's content", () => {
		const entry = makeEntry( '1', false );
		const nested = makeEntry( '2' );
		entry.appendChild( nested );
		root.appendChild( entry );

		marks.mark( entry );

		expect( markerOf( nested ).hidden ).toBe( true );
		expect(
			entry.querySelector(
				':scope > .newspack-rolling-coverage-new-status'
			)
		).not.toBeNull();
	} );

	it( 'clears 4000ms after the marker is fully in view', () => {
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );

		expect( observerOf( markerOf( entry ) )?.options?.threshold ).toEqual(
			expect.arrayContaining( [ 1 ] )
		);

		fire( markerOf( entry ), { intersectionRatio: 0.5 } );
		jest.advanceTimersByTime( HOLD_MS );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );

		fire( markerOf( entry ), { intersectionRatio: 1 } );
		jest.advanceTimersByTime( HOLD_MS - 1 );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );

		jest.advanceTimersByTime( 1 );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );
		expect( marks.isMarked( '1' ) ).toBe( true );
		expect( marks.isHeld( '1' ) ).toBe( false );
	} );

	it( 'does not start the clock while the tab is hidden', () => {
		visibility = 'hidden';
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );

		fire( markerOf( entry ), { intersectionRatio: 1 } );
		jest.advanceTimersByTime( HOLD_MS * 2 );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );

		setVisibility( 'visible' );
		jest.advanceTimersByTime( HOLD_MS );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );
	} );

	it( 'hiding the tab cancels the clock and seeing it again restarts from zero', () => {
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );

		fire( markerOf( entry ), { intersectionRatio: 1 } );
		jest.advanceTimersByTime( HOLD_MS - 1000 );

		setVisibility( 'hidden' );
		jest.advanceTimersByTime( HOLD_MS * 2 );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );

		fire( markerOf( entry ), { intersectionRatio: 0 } );
		setVisibility( 'visible' );
		jest.advanceTimersByTime( HOLD_MS * 2 );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );

		fire( markerOf( entry ), { intersectionRatio: 1 } );
		jest.advanceTimersByTime( HOLD_MS - 1 );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );

		jest.advanceTimersByTime( 1 );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );
	} );

	it( 'scrolling away does not cancel a running clock', () => {
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );

		fire( markerOf( entry ), { intersectionRatio: 1 } );
		jest.advanceTimersByTime( 1000 );
		fire( markerOf( entry ), { intersectionRatio: 0 } );
		jest.advanceTimersByTime( HOLD_MS - 1000 );

		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );
	} );

	it( 'an entry with no marker counts as seen at half the entry or half the viewport', () => {
		const short = makeEntry( 'short', false );
		const tall = makeEntry( 'tall', false );
		root.append( short, tall );
		marks.mark( short );
		marks.mark( tall );

		fire( short, { intersectionRatio: 0.49 } );
		fire( tall, {
			intersectionRatio: 0.2,
			intersectionRect: { height: 399 },
		} );
		jest.advanceTimersByTime( HOLD_MS );
		expect( short.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );
		expect( tall.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );

		fire( short, { intersectionRatio: 0.5 } );
		fire( tall, {
			intersectionRatio: 0.2,
			intersectionRect: { height: 400 },
		} );
		jest.advanceTimersByTime( HOLD_MS );
		expect( short.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );
		expect( tall.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );
	} );

	it( 'a marker that settles just under fully in view starts the clock', () => {
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );

		const thresholds = [
			observerOf( markerOf( entry ) )?.options?.threshold,
		].flat() as number[];
		expect(
			thresholds.some(
				( threshold ) => threshold <= 0.995 && threshold > 0.5
			)
		).toBe( true );

		fire( markerOf( entry ), { intersectionRatio: 0.995 } );
		jest.advanceTimersByTime( HOLD_MS );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );
	} );

	it( 'a visually hidden marker is seen by the half-entry rule and never collapses', async () => {
		markerStyle = { position: 'absolute' };
		parentStyle = {
			display: 'flex',
			flexDirection: 'column',
			rowGap: '8px',
		};
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );
		const marker = markerOf( entry );

		expect( observerOf( marker ) ).toBeUndefined();
		expect( observerOf( entry ) ).toBeDefined();

		fire( entry, { intersectionRatio: 0.1 } );
		jest.advanceTimersByTime( HOLD_MS );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );

		fire( entry, { intersectionRatio: 0.5 } );
		jest.advanceTimersByTime( HOLD_MS );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );

		jest.advanceTimersByTime( CLEAR_MS );
		await flush();
		expect( animate ).not.toHaveBeenCalled();
		expect( marker.hidden ).toBe( true );
		expect( entry.hasAttribute( NEW_STATE_ATTR ) ).toBe( false );
	} );

	it( 'a marker with no height is seen by the half-entry rule', () => {
		const entry = makeEntry( '1' );
		markerOf( entry ).getBoundingClientRect = () =>
			( { height: 1 } ) as DOMRect;
		root.appendChild( entry );
		marks.mark( entry );

		expect( observerOf( markerOf( entry ) ) ).toBeUndefined();
		expect( observerOf( entry ) ).toBeDefined();
	} );

	it( 'an entry many viewports tall counts as seen once it fills half the viewport', () => {
		const entry = makeEntry( '1', false );
		let rect = { top: 700, bottom: 700 + 800 * 40, height: 800 * 40 };
		entry.getBoundingClientRect = () => rect as DOMRect;
		root.appendChild( entry );
		marks.mark( entry );

		fire( entry, {
			intersectionRatio: 0.003,
			intersectionRect: { height: 100 },
		} );
		window.dispatchEvent( new Event( 'scroll' ) );
		jest.advanceTimersByTime( HOLD_MS * 2 );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );

		rect = { top: 400, bottom: 400 + 800 * 40, height: 800 * 40 };
		window.dispatchEvent( new Event( 'scroll' ) );
		jest.advanceTimersByTime( 100 );
		jest.advanceTimersByTime( HOLD_MS );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );
	} );

	it( 'a forgotten tall entry stops listening for scrolls', () => {
		const entry = makeEntry( '1', false );
		const removeListener = jest.spyOn( window, 'removeEventListener' );
		root.appendChild( entry );
		marks.mark( entry );

		fire( entry, {
			intersectionRatio: 0.003,
			intersectionRect: { height: 100 },
		} );
		marks.forget( '1' );

		expect( removeListener ).toHaveBeenCalledWith(
			'scroll',
			expect.any( Function )
		);
	} );

	it( 'adopt keeps the mark and the running clock on a replacement element', () => {
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );

		fire( markerOf( entry ), { intersectionRatio: 1 } );
		jest.advanceTimersByTime( 3000 );

		const replacement = makeEntry( '1' );
		entry.replaceWith( replacement );
		marks.adopt( replacement );

		expect( replacement.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );
		expect( markerOf( replacement ).hidden ).toBe( false );
		expect( observerOf( markerOf( entry ) ) ).toBeUndefined();
		expect( observerOf( markerOf( replacement ) ) ).toBeDefined();

		jest.advanceTimersByTime( HOLD_MS - 3000 );
		expect( replacement.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );
	} );

	it( 'adopt finishes a clear at once on a replacement element', async () => {
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );

		fire( markerOf( entry ), { intersectionRatio: 1 } );
		animate.mockImplementation( () => ( {
			finished: new Promise( () => {} ),
			cancel: jest.fn(),
		} ) );
		jest.advanceTimersByTime( HOLD_MS );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );

		const replacement = makeEntry( '1' );
		entry.replaceWith( replacement );
		marks.adopt( replacement );
		await flush();

		expect( replacement.hasAttribute( NEW_STATE_ATTR ) ).toBe( false );
		expect( markerOf( replacement ).hidden ).toBe( true );
		expect( marks.isMarked( '1' ) ).toBe( false );
	} );

	it( 'forget cancels the clock', () => {
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );

		fire( markerOf( entry ), { intersectionRatio: 1 } );
		marks.forget( '1' );
		jest.advanceTimersByTime( HOLD_MS * 2 );

		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );
		expect( marks.isMarked( '1' ) ).toBe( false );
		expect( observerOf( markerOf( entry ) ) ).toBeUndefined();
	} );

	it( 'collapses a marker alone on its line and ends hidden with no inline styles', async () => {
		parentStyle = {
			display: 'flex',
			flexDirection: 'column',
			rowGap: '8px',
		};
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );
		const marker = markerOf( entry );

		fire( marker, { intersectionRatio: 1 } );
		jest.advanceTimersByTime( HOLD_MS );

		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );
		expect( animate ).toHaveBeenCalledTimes( 1 );
		expect( animate.mock.instances[ 0 ] ).toBe( marker );
		expect( animate ).toHaveBeenCalledWith(
			[
				{ height: '18px', marginBlockEnd: '0px' },
				{ height: '0px', marginBlockEnd: '-8px' },
			],
			expect.objectContaining( {
				duration: COLLAPSE_MS,
				easing: EASE_IN_OUT,
				delay: COLLAPSE_DELAY_MS,
			} )
		);

		await flush();

		expect( marker.hidden ).toBe( true );
		expect( marker.getAttribute( 'style' ) ).toBeNull();
		expect( entry.hasAttribute( NEW_STATE_ATTR ) ).toBe( false );
		expect( marks.isMarked( '1' ) ).toBe( false );
	} );

	it( 'fades but does not collapse a marker that shares its line', async () => {
		parentStyle = {
			display: 'flex',
			flexDirection: 'row',
			rowGap: '8px',
		};
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );
		const marker = markerOf( entry );

		fire( marker, { intersectionRatio: 1 } );
		jest.advanceTimersByTime( HOLD_MS );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );

		jest.advanceTimersByTime( CLEAR_MS - 1 );
		await flush();
		expect( marker.hidden ).toBe( false );

		jest.advanceTimersByTime( 1 );
		await flush();
		expect( animate ).not.toHaveBeenCalled();
		expect( marker.hidden ).toBe( true );
		expect( entry.hasAttribute( NEW_STATE_ATTR ) ).toBe( false );
		expect( marks.isMarked( '1' ) ).toBe( false );
	} );

	it( 'under reduced motion the collapse is instant and the marker still clears', async () => {
		reducedMotion = true;
		parentStyle = {
			display: 'flex',
			flexDirection: 'column',
			rowGap: '8px',
		};
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );
		const marker = markerOf( entry );

		fire( marker, { intersectionRatio: 1 } );
		jest.advanceTimersByTime( HOLD_MS );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );

		jest.advanceTimersByTime( CLEAR_MS );
		await flush();

		expect( animate ).not.toHaveBeenCalled();
		expect( marker.hidden ).toBe( true );
		expect( marker.getAttribute( 'style' ) ).toBeNull();
		expect( entry.hasAttribute( NEW_STATE_ATTR ) ).toBe( false );
	} );

	it( 'removes the screen-reader "New" once cleared', async () => {
		const entry = makeEntry( '1', false );
		root.appendChild( entry );
		marks.mark( entry );

		fire( entry, { intersectionRatio: 1 } );
		jest.advanceTimersByTime( HOLD_MS + CLEAR_MS );
		await flush();

		expect(
			entry.querySelector( '.newspack-rolling-coverage-new-status' )
		).toBeNull();
		expect( entry.hasAttribute( NEW_STATE_ATTR ) ).toBe( false );
	} );
} );

describe( 'tintInsets', () => {
	const noLines = { top: null, bottom: null, card: false };

	it( 'runs from its own top line across the gap to the line below (Clock)', () => {
		expect(
			tintInsets( { ...noLines, top: 1 }, 'space', 'solid', 32 )
		).toEqual( [ 1, -32 ] );
	} );

	it( 'reaches up across the gap to the line above and stops at its own (Bulletin)', () => {
		expect(
			tintInsets( { ...noLines, bottom: 1 }, 'solid', 'space', 32 )
		).toEqual( [ -32, 1 ] );
	} );

	it( 'reaches halfway toward a neighbor without a line', () => {
		expect(
			tintInsets( { ...noLines, bottom: 1 }, 'space', null, 32 )
		).toEqual( [ -16, 1 ] );
		expect( tintInsets( noLines, 'space', 'space', 32 ) ).toEqual( [
			-16, -16,
		] );
	} );

	it( 'gives the first and last entries the shape of the other rows', () => {
		expect(
			tintInsets( { ...noLines, top: 1 }, 'solid', null, 16 )
		).toEqual( [ 1, -16 ] );
		expect(
			tintInsets( { ...noLines, bottom: 1 }, null, 'space', 32 )
		).toEqual( [ -32, 1 ] );
		expect( tintInsets( noLines, null, null, 24 ) ).toEqual( [ -12, -12 ] );
	} );

	it( 'stays inside a card and in a row layout', () => {
		expect(
			tintInsets( { ...noLines, card: true }, 'solid', 'solid', 32 )
		).toEqual( [ 0, 0 ] );
		expect(
			tintInsets( { ...noLines, top: 1 }, 'solid', 'solid', null )
		).toEqual( [ 0, 0 ] );
	} );
} );

describe( 'rowEdges', () => {
	function box(
		element: Element,
		top: number,
		height: number,
		width = 600
	): void {
		element.getBoundingClientRect = () =>
			( {
				top,
				bottom: top + height,
				height,
				width,
				left: 0,
				right: width,
			} ) as DOMRect;
	}

	let styles: Map< Element, Partial< CSSStyleDeclaration > >;

	beforeEach( () => {
		styles = new Map();
		jest.mocked( window.getComputedStyle ).mockImplementation(
			( element ) =>
				( {
					borderTopStyle: 'none',
					borderBottomStyle: 'none',
					borderLeftStyle: 'none',
					borderRightStyle: 'none',
					backgroundColor: 'rgba(0, 0, 0, 0)',
					...styles.get( element ),
				} ) as CSSStyleDeclaration
		);
	} );

	function entryWith( ...children: HTMLElement[] ): HTMLElement {
		const entry = document.createElement( 'article' );
		entry.dataset.entryId = '1';
		entry.append( ...children );
		box( entry, 100, 200 );
		return entry;
	}

	it( 'finds a top border on a box that spans the entry', () => {
		const columns = document.createElement( 'div' );
		box( columns, 100, 200 );
		styles.set( columns, {
			borderTopStyle: 'solid',
			borderTopWidth: '1px',
		} );

		expect( rowEdges( entryWith( columns ) ) ).toEqual( {
			top: 1,
			bottom: null,
			card: false,
		} );
	} );

	it( 'finds a closing separator block', () => {
		const text = document.createElement( 'div' );
		box( text, 100, 160 );
		const separator = document.createElement( 'hr' );
		box( separator, 299, 1, 100 );

		expect( rowEdges( entryWith( text, separator ) ) ).toEqual( {
			top: null,
			bottom: 1,
			card: false,
		} );
	} );

	it( 'takes a box with a background as a card', () => {
		const card = document.createElement( 'div' );
		box( card, 100, 200 );
		styles.set( card, { backgroundColor: 'rgb(240, 240, 240)' } );

		expect( rowEdges( entryWith( card ) ).card ).toBe( true );
	} );

	it( 'takes a box with a border all around as a card', () => {
		const card = document.createElement( 'div' );
		box( card, 100, 200 );
		styles.set( card, {
			borderTopStyle: 'solid',
			borderTopWidth: '1px',
			borderBottomStyle: 'solid',
			borderBottomWidth: '1px',
			borderLeftStyle: 'solid',
			borderLeftWidth: '1px',
			borderRightStyle: 'solid',
			borderRightWidth: '1px',
		} );

		expect( rowEdges( entryWith( card ) ) ).toEqual( {
			top: null,
			bottom: null,
			card: true,
		} );
	} );

	it( 'takes a box with a background image as a card', () => {
		const card = document.createElement( 'div' );
		box( card, 100, 200 );
		styles.set( card, {
			backgroundImage: 'linear-gradient(red, blue)',
		} );

		expect( rowEdges( entryWith( card ) ).card ).toBe( true );
	} );

	it( 'does not take a thin painted bar as a card', () => {
		const bar = document.createElement( 'div' );
		box( bar, 100, 200, 4 );
		styles.set( bar, { backgroundColor: 'rgb(0, 61, 165)' } );

		expect( rowEdges( entryWith( bar ) ).card ).toBe( false );
	} );

	it( 'finds a card six levels down', () => {
		const levels = Array.from( { length: 6 }, () =>
			document.createElement( 'div' )
		);
		levels.forEach( ( level, i ) => {
			box( level, 100, 200, 600 - i * 20 );
			levels[ i - 1 ]?.append( level );
		} );
		styles.set( levels[ 5 ], {
			backgroundColor: 'rgb(240, 240, 240)',
		} );

		expect( rowEdges( entryWith( levels[ 0 ] ) ).card ).toBe( true );
	} );

	it( 'ignores a separator inside a column', () => {
		const columns = document.createElement( 'div' );
		box( columns, 100, 200 );
		const column = document.createElement( 'div' );
		box( column, 100, 200, 180 );
		const rule = document.createElement( 'hr' );
		box( rule, 299, 1, 180 );
		column.append( rule );
		columns.append( column );

		expect( rowEdges( entryWith( columns ) ).bottom ).toBeNull();
	} );

	it( "takes a card in one of the entry's columns", () => {
		const columns = document.createElement( 'div' );
		box( columns, 100, 200 );
		const time = document.createElement( 'div' );
		box( time, 100, 40, 180 );
		const card = document.createElement( 'div' );
		box( card, 100, 200, 420 );
		styles.set( card, { backgroundColor: 'rgb(240, 240, 240)' } );
		columns.append( time, card );

		expect( rowEdges( entryWith( columns ) ).card ).toBe( true );
	} );

	it( 'takes any painted background as a card', () => {
		const group = document.createElement( 'div' );
		box( group, 100, 200 );
		styles.set( group, { backgroundColor: 'rgb(10, 20, 0)' } );

		expect( rowEdges( entryWith( group ) ).card ).toBe( true );
	} );

	it( 'ignores lines in the text and boxes narrower than the entry', () => {
		const content = document.createElement( 'div' );
		content.className = 'wp-block-post-content';
		box( content, 100, 200 );
		const rule = document.createElement( 'hr' );
		box( rule, 299, 1 );
		content.append( rule );
		const column = document.createElement( 'div' );
		box( column, 100, 200, 200 );
		styles.set( column, {
			borderTopStyle: 'solid',
			borderTopWidth: '1px',
		} );

		expect( rowEdges( entryWith( content, column ) ) ).toEqual( {
			top: null,
			bottom: null,
			card: false,
		} );
	} );
} );

describe( 'the tint', () => {
	beforeEach( () => {
		parentStyle = {
			display: 'flex',
			flexDirection: 'column',
			rowGap: '32px',
		};
	} );

	function paintCard( card: HTMLElement ): void {
		const computed = jest.mocked( window.getComputedStyle );
		const style = computed.getMockImplementation() as (
			element: Element
		) => CSSStyleDeclaration;
		computed.mockImplementation( ( element ) =>
			element === card
				? ( {
						backgroundColor: 'rgb(240, 240, 240)',
					} as CSSStyleDeclaration )
				: style( element )
		);
	}

	it( 'reaches across the gap while marked and leaves no inline style once cleared', async () => {
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );

		expect( entry.style.getPropertyValue( TINT_START ) ).toBe( '-16px' );
		expect( entry.style.getPropertyValue( TINT_END ) ).toBe( '-16px' );

		fire( markerOf( entry ), { intersectionRatio: 1 } );
		jest.advanceTimersByTime( HOLD_MS + CLEAR_MS );
		await flush();

		expect( entry.hasAttribute( 'style' ) ).toBe( false );
	} );

	it( 'reaches across the whole gap to a neighboring card', () => {
		const pinned = makeEntry( '1', false );
		pinned.getBoundingClientRect = () =>
			( {
				top: 0,
				bottom: 120,
				height: 120,
				width: 600,
				left: 0,
				right: 600,
			} ) as DOMRect;
		paintCard( pinned );
		const entry = makeEntry( '2' );
		root.append( pinned, entry );

		marks.mark( entry );

		expect( entry.style.getPropertyValue( TINT_START ) ).toBe( '-32px' );
		expect( entry.style.getPropertyValue( TINT_END ) ).toBe( '-16px' );
	} );

	it( "reaches across the whole gap to a neighbor's closing line", () => {
		const above = makeEntry( '1', false );
		above.getBoundingClientRect = () =>
			( { top: 0, bottom: 120, height: 120, width: 600 } ) as DOMRect;
		const line = document.createElement( 'hr' );
		line.getBoundingClientRect = () =>
			( { top: 119, bottom: 120, height: 1, width: 600 } ) as DOMRect;
		above.append( line );
		const entry = makeEntry( '2' );
		root.append( above, entry );

		marks.mark( entry );

		expect( entry.style.getPropertyValue( TINT_START ) ).toBe( '-32px' );
	} );

	it( 'reaches halfway when something other than an entry sits between', () => {
		const pinned = makeEntry( '1', false );
		pinned.getBoundingClientRect = () =>
			( { top: 0, bottom: 120, height: 120, width: 600 } ) as DOMRect;
		paintCard( pinned );
		const ad = document.createElement( 'div' );
		ad.getClientRects = () => [ {} ] as unknown as DOMRectList;
		const entry = makeEntry( '2' );
		root.append( pinned, ad, entry );

		marks.mark( entry );

		expect( entry.style.getPropertyValue( TINT_START ) ).toBe( '-16px' );
	} );

	it( 'reaches halfway toward an ad after the last entry', () => {
		const entry = makeEntry( '1' );
		entry.getBoundingClientRect = () =>
			( { top: 0, bottom: 120, height: 120, width: 600 } ) as DOMRect;
		const line = document.createElement( 'hr' );
		line.getBoundingClientRect = () =>
			( { top: 0, bottom: 1, height: 1, width: 600 } ) as DOMRect;
		entry.prepend( line );
		const ad = document.createElement( 'div' );
		ad.getClientRects = () => [ {} ] as unknown as DOMRectList;
		root.append( entry, ad );

		marks.mark( entry );

		expect( entry.style.getPropertyValue( TINT_END ) ).toBe( '-16px' );
	} );

	it( 'places the tint again once a frame after a card is put beside it', async () => {
		const entry = makeEntry( '1' );
		root.append( entry );
		marks.mark( entry );

		expect( entry.style.getPropertyValue( TINT_START ) ).toBe( '-16px' );

		const pinned = makeEntry( '2', false );
		pinned.getBoundingClientRect = () =>
			( { top: 0, bottom: 120, height: 120, width: 600 } ) as DOMRect;
		paintCard( pinned );
		entry.before( pinned );
		await flush();
		jest.advanceTimersByTime( 20 );

		expect( entry.style.getPropertyValue( TINT_START ) ).toBe( '-32px' );
	} );
} );

describe( 'marking in batches', () => {
	beforeEach( () => {
		parentStyle = {
			display: 'flex',
			flexDirection: 'column',
			rowGap: '32px',
		};
	} );

	it( 'reveals every marker before measuring any', () => {
		const entries = [
			makeEntry( '1' ),
			makeEntry( '2' ),
			makeEntry( '3' ),
		];
		root.append( ...entries );
		const revealedAtRead: boolean[] = [];
		entries.forEach( ( entry ) => {
			markerOf( entry ).getBoundingClientRect = () => {
				revealedAtRead.push(
					entries.every( ( other ) => ! markerOf( other ).hidden )
				);
				return { height: 18 } as DOMRect;
			};
		} );

		marks.markAll( entries );

		expect( revealedAtRead.length ).toBeGreaterThan( 0 );
		expect( revealedAtRead.every( Boolean ) ).toBe( true );
		entries.forEach( ( entry ) =>
			expect( observerOf( markerOf( entry ) ) ).toBeDefined()
		);
	} );

	it( 'carries the marks of replacements it is given and marks no new ones from them', () => {
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );
		const replacement = makeEntry( '1' );
		const unmarked = makeEntry( '2' );
		entry.replaceWith( replacement );
		root.appendChild( unmarked );

		marks.markAll( [], [ replacement, unmarked ] );

		expect( markerOf( replacement ).hidden ).toBe( false );
		expect( replacement.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );
		expect( marks.isMarked( '2' ) ).toBe( false );
		expect( markerOf( unmarked ).hidden ).toBe( true );
	} );

	it( 'flags only an entry whose own marker shows, until the mark settles', async () => {
		const entry = makeEntry( '1' );
		const nesting = makeEntry( '2', false );
		nesting.appendChild( makeEntry( '3' ) );
		root.append( entry, nesting );

		marks.markAll( [ entry, nesting ] );

		expect( entry.hasAttribute( NEW_LABEL_ATTR ) ).toBe( true );
		expect( nesting.hasAttribute( NEW_LABEL_ATTR ) ).toBe( false );

		fire( markerOf( entry ), { intersectionRatio: 1 } );
		jest.advanceTimersByTime( HOLD_MS );
		await flush();

		expect( entry.hasAttribute( NEW_LABEL_ATTR ) ).toBe( false );
	} );

	it( 'places the tint again on a labeled neighbor of an entry marked later', () => {
		const older = makeEntry( '1' );
		root.appendChild( older );
		marks.mark( older );
		older.style.removeProperty( TINT_START );
		const newer = makeEntry( '2' );
		root.prepend( newer );

		marks.mark( newer );

		expect( older.style.getPropertyValue( TINT_START ) ).toBe( '-16px' );
	} );

	it( 'places the tints again once a frame after the window resizes', () => {
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );
		entry.style.removeProperty( TINT_START );

		window.dispatchEvent( new Event( 'resize' ) );
		window.dispatchEvent( new Event( 'resize' ) );

		expect( entry.style.getPropertyValue( TINT_START ) ).toBe( '' );

		jest.advanceTimersByTime( 20 );

		expect( entry.style.getPropertyValue( TINT_START ) ).toBe( '-16px' );
	} );

	it( 'release holds a collapse where it got to, where forget lets it spring back', () => {
		const commitStyles = jest.fn();
		const cancel = jest.fn();
		animate.mockImplementation( () => ( {
			finished: new Promise( () => {} ),
			cancel,
			commitStyles,
		} ) );
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );
		fire( markerOf( entry ), { intersectionRatio: 1 } );
		jest.advanceTimersByTime( HOLD_MS );

		marks.release( '1' );

		expect( commitStyles ).toHaveBeenCalledTimes( 1 );
		expect( commitStyles.mock.invocationCallOrder[ 0 ] ).toBeLessThan(
			cancel.mock.invocationCallOrder[ 0 ]
		);
		expect( marks.isMarked( '1' ) ).toBe( false );
	} );
} );
