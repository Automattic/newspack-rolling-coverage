import {
	carryArrival,
	closeSpace,
	openKeyframes,
	openSpace,
	stackGap,
	stopArrivalFade,
} from '../../src/blocks/rolling-coverage/entry-arrival';
import {
	EASE_IN_OUT,
	ENTER_MS,
	OPEN_FADE_DELAY_MS,
	OPEN_MS,
} from '../../src/blocks/shared/motion';

type FakeAnimation = {
	keyframes: Keyframe[];
	options: KeyframeAnimationOptions;
	target: Element;
	currentTime: number;
	cancel: jest.Mock;
	finished: Promise< void >;
};

let reducedMotion = false;
let styles: Map< Element, Partial< CSSStyleDeclaration > >;
let animations: FakeAnimation[];

function makeList( style: Partial< CSSStyleDeclaration > ): HTMLElement {
	const list = document.createElement( 'div' );
	styles.set( list, style );
	document.body.appendChild( list );
	return list;
}

function makeItem( height: number ): HTMLElement {
	const item = document.createElement( 'div' );
	item.getBoundingClientRect = () => ( { height } ) as DOMRect;
	item.getClientRects = () => [ { height } ] as unknown as DOMRectList;
	return item;
}

const column = { display: 'flex', flexDirection: 'column', rowGap: '32px' };

beforeEach( () => {
	reducedMotion = false;
	window.matchMedia = jest.fn( () => ( {
		matches: ! reducedMotion,
	} ) ) as unknown as typeof window.matchMedia;

	styles = new Map();
	jest.spyOn( window, 'getComputedStyle' ).mockImplementation(
		( element ) =>
			( {
				display: 'block',
				position: 'static',
				marginTop: '0px',
				marginBottom: '0px',
				opacity: '0.4',
				...styles.get( element ),
			} ) as CSSStyleDeclaration
	);

	animations = [];
	Element.prototype.animate = jest.fn( function (
		this: Element,
		keyframes: Keyframe[],
		options: KeyframeAnimationOptions
	) {
		const animation: FakeAnimation = {
			keyframes,
			options,
			target: this,
			currentTime: 0,
			cancel: jest.fn(),
			finished: Promise.resolve(),
		};
		animations.push( animation );
		return animation;
	} ) as unknown as Element[ 'animate' ];
} );

afterEach( () => {
	document.body.innerHTML = '';
	jest.restoreAllMocks();
} );

describe( 'stackGap', () => {
	it( 'is the row gap of a column and nothing for a block', () => {
		expect( stackGap( column as CSSStyleDeclaration ) ).toBe( 32 );
		expect( stackGap( { display: 'block' } as CSSStyleDeclaration ) ).toBe(
			0
		);
	} );

	it( 'is null for a row, a grid or contents', () => {
		[
			{ display: 'flex', flexDirection: 'row', rowGap: '16px' },
			{ display: 'grid', rowGap: '0px' },
			{ display: 'contents' },
		].forEach( ( style ) =>
			expect( stackGap( style as CSSStyleDeclaration ) ).toBeNull()
		);
	} );
} );

describe( 'openKeyframes', () => {
	it( 'starts with the whole share cancelled and the clip at the next element', () => {
		expect(
			openKeyframes( {
				height: 200,
				gap: 32,
				marginStart: 0,
				marginEnd: 0,
			} )
		).toEqual( [
			{
				marginBlockEnd: '-232px',
				clipPath: 'inset(-32px -100vw 200px)',
			},
			{ marginBlockEnd: '0px', clipPath: 'inset(-32px -100vw -32px)' },
		] );
	} );
} );

describe( 'openSpace', () => {
	it( 'opens each element in a column and fades its entries in partway', () => {
		const list = makeList( column );
		const below = makeItem( 100 );
		const entry = makeItem( 200 );
		const ad = makeItem( 50 );
		list.append( entry, ad, below );

		expect( openSpace( [ entry, ad ], [ entry ], list ) ).toEqual( [] );

		const [ openEntry, openAd, fade ] = animations;
		expect( openEntry.target ).toBe( entry );
		expect( openEntry.keyframes[ 0 ].marginBlockEnd ).toBe( '-232px' );
		expect( openEntry.options ).toEqual( {
			duration: OPEN_MS,
			easing: EASE_IN_OUT,
		} );
		expect( openAd.target ).toBe( ad );
		expect( openAd.keyframes[ 0 ].marginBlockEnd ).toBe( '-82px' );
		expect( fade.target ).toBe( entry );
		expect( fade.keyframes ).toEqual( [ { opacity: 0 }, { opacity: 1 } ] );
		expect( fade.options ).toEqual(
			expect.objectContaining( {
				duration: ENTER_MS,
				delay: OPEN_FADE_DELAY_MS,
				fill: 'backwards',
			} )
		);
	} );

	it( 'opens the group an entry appeared alone in', () => {
		const list = makeList( column );
		const stack = makeItem( 0 );
		styles.set( stack, column );
		const entry = makeItem( 200 );
		stack.append( entry );
		list.append( stack, makeItem( 100 ) );

		openSpace( [ entry ], [ entry ], list );

		expect( animations[ 0 ].target ).toBe( stack );
		expect( animations[ 1 ].target ).toBe( entry );
	} );

	it( 'leaves the fade to the caller in a row, under reduced motion, or without a gap to share', () => {
		const row = makeList( {
			display: 'flex',
			flexDirection: 'row',
			rowGap: '16px',
		} );
		const entry = makeItem( 200 );
		row.append( entry, makeItem( 200 ) );

		expect( openSpace( [ entry ], [ entry ], row ) ).toEqual( [ entry ] );
		expect( animations ).toHaveLength( 0 );

		reducedMotion = true;
		const list = makeList( column );
		const other = makeItem( 200 );
		list.append( other );

		expect( openSpace( [ other ], [ other ], list ) ).toEqual( [ other ] );
		expect( animations ).toHaveLength( 0 );
	} );

	it( 'counts no gap for an element with nothing beside it', () => {
		const list = makeList( column );
		const entry = makeItem( 200 );
		list.append( entry );

		openSpace( [ entry ], [ entry ], list );

		expect( animations[ 0 ].keyframes[ 0 ].marginBlockEnd ).toBe(
			'-200px'
		);
	} );
} );

describe( 'carryArrival', () => {
	it( 'carries the open and fade over to a replacement from where they were', () => {
		const list = makeList( column );
		const entry = makeItem( 200 );
		list.append( entry, makeItem( 100 ) );
		openSpace( [ entry ], [ entry ], list );
		animations[ 0 ].currentTime = 120;
		animations[ 1 ].currentTime = 120;

		const replacement = makeItem( 240 );
		entry.replaceWith( replacement );

		expect( carryArrival( entry, replacement ) ).toBe( true );
		expect( animations[ 0 ].cancel ).toHaveBeenCalled();
		expect( animations[ 1 ].cancel ).toHaveBeenCalled();
		expect( animations[ 2 ].target ).toBe( replacement );
		expect( animations[ 2 ].keyframes[ 0 ].marginBlockEnd ).toBe(
			'-272px'
		);
		expect( animations[ 2 ].currentTime ).toBe( 120 );
		expect( animations[ 3 ].target ).toBe( replacement );
		expect( animations[ 3 ].currentTime ).toBe( 120 );
	} );

	it( 'does nothing for an element that is not arriving', () => {
		const entry = makeItem( 200 );

		expect( carryArrival( entry, makeItem( 200 ) ) ).toBe( false );
		expect( animations ).toHaveLength( 0 );
	} );
} );

describe( 'stopArrivalFade', () => {
	it( 'stops the fade where it got to and keeps the open', () => {
		const list = makeList( column );
		const entry = makeItem( 200 );
		list.append( entry, makeItem( 100 ) );
		openSpace( [ entry ], [ entry ], list );

		expect( stopArrivalFade( entry ) ).toBe( 0.4 );
		expect( animations[ 1 ].cancel ).toHaveBeenCalled();
		expect( animations[ 0 ].cancel ).not.toHaveBeenCalled();
		expect( stopArrivalFade( entry ) ).toBeNull();
	} );
} );

describe( 'closeSpace', () => {
	it( 'runs the open backwards and calls back once closed', async () => {
		const list = makeList( column );
		const entry = makeItem( 200 );
		list.append( makeItem( 100 ), entry );
		const done = jest.fn();

		expect( closeSpace( entry, done ) ).toBe( true );
		expect( animations[ 0 ].keyframes[ 1 ].marginBlockEnd ).toBe(
			'-232px'
		);
		expect( animations[ 0 ].options.fill ).toBe( 'forwards' );

		await Promise.resolve();
		await Promise.resolve();

		expect( done ).toHaveBeenCalled();
	} );

	it( 'leaves removal to the caller where the space can’t close smoothly', () => {
		reducedMotion = true;
		const list = makeList( column );
		const entry = makeItem( 200 );
		list.append( entry );
		const done = jest.fn();

		expect( closeSpace( entry, done ) ).toBe( false );
		expect( done ).not.toHaveBeenCalled();
	} );
} );
