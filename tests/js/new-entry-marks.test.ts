import {
	NEW_MARKER_ATTR,
	NEW_STATE_ATTR,
	NewEntryMarks,
} from '../../src/blocks/rolling-coverage/new-entry-marks';
import {
	CLEAR_MS,
	COLLAPSE_MS,
	EASE_OUT,
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
	jest.spyOn( window, 'getComputedStyle' ).mockImplementation(
		() => parentStyle as CSSStyleDeclaration
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

	it( 'clears 4000ms after the marker is fully in view', () => {
		const entry = makeEntry( '1' );
		root.appendChild( entry );
		marks.mark( entry );

		expect( observerOf( markerOf( entry ) )?.options?.threshold ).toBe( 1 );

		fire( markerOf( entry ), { intersectionRatio: 0.5 } );
		jest.advanceTimersByTime( HOLD_MS );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );

		fire( markerOf( entry ), { intersectionRatio: 1 } );
		jest.advanceTimersByTime( HOLD_MS - 1 );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'held' );

		jest.advanceTimersByTime( 1 );
		expect( entry.getAttribute( NEW_STATE_ATTR ) ).toBe( 'clearing' );
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
				easing: EASE_OUT,
				delay: CLEAR_MS,
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
