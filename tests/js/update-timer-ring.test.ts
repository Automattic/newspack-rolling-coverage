import { readCheck } from '../../src/blocks/shared/check-event';
import { SPIN_MS } from '../../src/blocks/shared/motion';
import {
	idleRing,
	syncRing,
	type RingMotion,
} from '../../src/blocks/update-timer/ring';

const NOW = 1_000_000;

type FakeAnimation = { cancel: jest.Mock; currentTime: number | null };

function fakeAnimation(): FakeAnimation {
	return { cancel: jest.fn(), currentTime: null };
}

function ring() {
	const svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
	const arc = document.createElementNS(
		'http://www.w3.org/2000/svg',
		'circle'
	);

	arc.setAttribute(
		'class',
		'newspack-rolling-coverage-update-timer__ring-arc'
	);
	svg.append( arc );

	const spins: FakeAnimation[] = [];
	const fills: FakeAnimation[] = [];
	const spin = jest.fn( () => {
		spins.push( fakeAnimation() );
		return spins[ spins.length - 1 ];
	} );
	const fill = jest.fn( () => {
		fills.push( fakeAnimation() );
		return fills[ fills.length - 1 ];
	} );

	svg.animate = spin as unknown as typeof svg.animate;
	arc.animate = fill as unknown as typeof arc.animate;

	return { svg, spin, fill, spins, fills };
}

function reduceMotion( reduce: boolean ) {
	window.matchMedia = jest.fn( () => ( {
		matches: ! reduce,
	} ) ) as unknown as typeof window.matchMedia;
}

const waiting = ( nextCheckAt: number, interval = 30000 ) => ( {
	state: 'waiting' as const,
	nextCheckAt,
	interval,
} );
const checking = { state: 'checking' as const, nextCheckAt: 0, interval: 0 };
const idle = { state: 'idle' as const, nextCheckAt: 0, interval: 0 };

describe( 'syncRing', () => {
	let motion: RingMotion;

	beforeEach( () => {
		motion = idleRing();
		reduceMotion( false );
	} );

	it( 'fills over the poll interval, from the share of it already gone', () => {
		const { svg, fill, fills } = ring();

		syncRing( svg, motion, waiting( NOW + 20000 ), NOW );

		expect( fill ).toHaveBeenCalledWith(
			[ { strokeDashoffset: 100 }, { strokeDashoffset: 0 } ],
			{ duration: 30000, easing: 'linear', fill: 'forwards' }
		);
		expect( fills[ 0 ].currentTime ).toBe( 10000 );

		syncRing( svg, idleRing(), waiting( NOW + 45000, 45000 ), NOW );

		expect( fill ).toHaveBeenLastCalledWith(
			expect.anything(),
			expect.objectContaining( { duration: 45000 } )
		);
		expect( fills[ 1 ].currentTime ).toBe( 0 );
	} );

	it( 'keeps the fill running when the same wait is reported again', () => {
		const { svg, fill, fills } = ring();

		syncRing( svg, motion, waiting( NOW + 20000 ), NOW );
		syncRing( svg, motion, waiting( NOW + 20000 ), NOW + 5000 );

		expect( fill ).toHaveBeenCalledTimes( 1 );
		expect( fills[ 0 ].cancel ).not.toHaveBeenCalled();
	} );

	it( 'spins while the check runs, then restarts the countdown', () => {
		const { svg, spin, spins, fill, fills } = ring();

		syncRing( svg, motion, waiting( NOW + 1000 ), NOW );
		syncRing( svg, motion, checking, NOW + 1000 );

		expect( fills[ 0 ].cancel ).toHaveBeenCalled();
		expect( spin ).toHaveBeenCalledWith(
			[ { transform: 'rotate(0deg)' }, { transform: 'rotate(360deg)' } ],
			{ duration: SPIN_MS, iterations: Infinity, easing: 'linear' }
		);

		syncRing( svg, motion, waiting( NOW + 31500 ), NOW + 1500 );

		expect( spins[ 0 ].cancel ).toHaveBeenCalled();
		expect( fill ).toHaveBeenCalledTimes( 2 );
		expect( fills[ 1 ].currentTime ).toBe( 0 );
	} );

	it( 'never spins under reduced motion, and still fills', () => {
		const { svg, spin, fill } = ring();

		reduceMotion( true );
		syncRing( svg, motion, waiting( NOW + 30000 ), NOW );
		syncRing( svg, motion, checking, NOW + 30000 );

		expect( spin ).not.toHaveBeenCalled();
		expect( fill ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'runs nothing while idle, as in a hidden tab or a paused coverage', () => {
		const { svg, spin, spins, fill, fills } = ring();

		syncRing( svg, motion, waiting( NOW + 30000 ), NOW );
		syncRing( svg, motion, idle, NOW + 1000 );

		expect( fills[ 0 ].cancel ).toHaveBeenCalled();

		syncRing( svg, motion, checking, NOW + 2000 );
		syncRing( svg, motion, idle, NOW + 2500 );

		expect( spins[ 0 ].cancel ).toHaveBeenCalled();
		expect( motion ).toEqual( idleRing() );
		expect( fill ).toHaveBeenCalledTimes( 1 );
		expect( spin ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'readCheck', () => {
	it( 'reads the wait the feed reports with its next check', () => {
		const feed = document.createElement( 'div' );

		feed.dataset.checkState = 'waiting';
		feed.dataset.nextCheckAt = String( NOW );
		feed.dataset.checkInterval = '45000';

		expect( readCheck( feed ) ).toEqual( {
			state: 'waiting',
			nextCheckAt: NOW,
			interval: 45000,
		} );
	} );
} );
