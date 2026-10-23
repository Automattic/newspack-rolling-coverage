import {
	glideScrollTo,
	holdAnimations,
	newEntriesTarget,
} from '../../src/blocks/rolling-coverage/new-entries-scroll';
import { EASE_IN_OUT, OPEN_MS } from '../../src/blocks/shared/motion';

let reducedMotion = false;
let frames: FrameRequestCallback[];
let scrolls: ScrollToOptions[];

function placed( top: number, parent: Element = document.body ): HTMLElement {
	const element = document.createElement( 'div' );
	element.getBoundingClientRect = () => ( { top } ) as DOMRect;
	parent.appendChild( element );
	return element;
}

function runFrame(): void {
	const pending = frames;
	frames = [];
	pending.forEach( ( frame ) => frame( 0 ) );
}

beforeEach( () => {
	document.body.innerHTML = '';
	reducedMotion = false;
	window.matchMedia = jest.fn( () => ( {
		matches: ! reducedMotion,
	} ) ) as unknown as typeof window.matchMedia;

	frames = [];
	window.requestAnimationFrame = ( frame ) => frames.push( frame );

	scrolls = [];
	window.scrollTo = jest.fn( ( options: ScrollToOptions ) => {
		scrolls.push( options );
	} ) as unknown as typeof window.scrollTo;

	Object.defineProperty( window, 'scrollY', {
		value: 3000,
		configurable: true,
	} );
} );

describe( 'newEntriesTarget', () => {
	it( 'lands on the topmost new entry, not the block top, under a short pinned entry', () => {
		const block = placed( -2000 );
		// A pinned entry 200px tall sits between the block's top and the new entries.
		const newest = placed( -1740, block );
		const older = placed( -1400, block );

		expect( newEntriesTarget( block, [ older, newest ], [], 96 ) ).toEqual(
			{ top: 3000 - 1740 - 96, entry: newest }
		);
	} );

	it( 'lands on the topmost new entry under a long pinned entry', () => {
		const block = placed( -2000 );
		const newest = placed( -200, block );

		expect( newEntriesTarget( block, [ newest ], [], 24 ) ).toEqual( {
			top: 3000 - 200 - 24,
			entry: newest,
		} );
	} );

	it( 'skips entries in a sticky card and goes to the block top without others', () => {
		const block = placed( -2000 );
		const card = placed( 0, block );
		const pinned = placed( 40, card );

		expect( newEntriesTarget( block, [ pinned ], [ card ], 96 ) ).toEqual( {
			top: 3000 - 2000 - 96,
			entry: null,
		} );

		const unpinned = placed( -1500, block );

		expect(
			newEntriesTarget( block, [ pinned, unpinned ], [ card ], 96 )
		).toEqual( { top: 3000 - 1500 - 96, entry: unpinned } );
	} );
} );

describe( 'glideScrollTo', () => {
	let timings: KeyframeEffectOptions[];
	let progress: number;
	let playState: AnimationPlayState;

	beforeEach( () => {
		timings = [];
		progress = 0;
		playState = 'running';

		window.KeyframeEffect = jest.fn(
			( _target, _keyframes, options: KeyframeEffectOptions ) => {
				timings.push( options );
				return { getComputedTiming: () => ( { progress } ) };
			}
		) as unknown as typeof KeyframeEffect;
		window.Animation = jest.fn( ( effect ) => ( {
			effect,
			play: jest.fn(),
			cancel: jest.fn(),
			get playState() {
				return playState;
			},
		} ) ) as unknown as typeof Animation;
	} );

	it( 'moves like an entry opening, then calls back once there', () => {
		const done = jest.fn();

		glideScrollTo( 1000, done );

		expect( timings ).toEqual( [
			expect.objectContaining( {
				duration: OPEN_MS,
				easing: EASE_IN_OUT,
			} ),
		] );

		progress = 0.5;
		runFrame();
		expect( scrolls.pop() ).toEqual( {
			top: 2000,
			behavior: 'instant',
		} );
		expect( done ).not.toHaveBeenCalled();

		progress = 1;
		playState = 'finished';
		runFrame();
		expect( scrolls.pop() ).toEqual( {
			top: 1000,
			behavior: 'instant',
		} );
		expect( done ).toHaveBeenCalledTimes( 1 );
		expect( frames ).toHaveLength( 0 );
	} );

	it( 'stops where it is when the reader scrolls, and calls back', () => {
		const done = jest.fn();

		glideScrollTo( 1000, done );
		window.dispatchEvent( new Event( 'wheel' ) );

		expect( done ).toHaveBeenCalledTimes( 1 );
		runFrame();
		expect( scrolls ).toHaveLength( 0 );
	} );

	it( 'jumps at once under reduced motion', () => {
		const done = jest.fn();
		reducedMotion = true;

		glideScrollTo( 1000, done );

		expect( scrolls ).toEqual( [ { top: 1000, behavior: 'instant' } ] );
		expect( done ).toHaveBeenCalledTimes( 1 );
		expect( timings ).toHaveLength( 0 );
	} );
} );

describe( 'holdAnimations', () => {
	function fakeAnimation( target: Element ) {
		const animation = {
			effect: { target },
			playState: 'running' as AnimationPlayState,
			pause: jest.fn( () => {
				animation.playState = 'paused';
			} ),
			play: jest.fn( () => {
				animation.playState = 'running';
			} ),
		};

		return animation;
	}

	it( 'holds only the animations the insert starts in the block, until released', () => {
		const block = placed( 0 );
		const outside = placed( 0 );
		const before = fakeAnimation( block );
		const arrival = fakeAnimation( placed( 0, block ) );
		const elsewhere = fakeAnimation( outside );
		let animations: unknown[] = [ before ];

		document.getAnimations = () => animations as Animation[];

		const release = holdAnimations( block, () => {
			animations = [ before, arrival, elsewhere ];
		} );

		expect( arrival.pause ).toHaveBeenCalled();
		expect( before.pause ).not.toHaveBeenCalled();
		expect( elsewhere.pause ).not.toHaveBeenCalled();
		expect( arrival.play ).not.toHaveBeenCalled();

		release();

		expect( arrival.play ).toHaveBeenCalledTimes( 1 );
	} );
} );
