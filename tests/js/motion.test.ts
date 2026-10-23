import * as fs from 'fs';
import * as path from 'path';

import {
	CLEAR_MS,
	COLLAPSE_DELAY_MS,
	COLLAPSE_MS,
	ENTER_MS,
	EXIT_MS,
	FADE_CLEANUP_MS,
	isAnimating,
	LEAVE_MS,
	MOTION_QUERY,
	OPEN_FADE_DELAY_MS,
	OPEN_MS,
	prefersReducedMotion,
} from '../../src/blocks/shared/motion';

const scss = fs.readFileSync(
	path.resolve( __dirname, '../../src/blocks/shared/_motion.scss' ),
	'utf8'
);

function scssMs( name: string ): number {
	const match = scss.match( new RegExp( `\\$${ name }:\\s*(\\d+)ms` ) );
	return match ? Number( match[ 1 ] ) : NaN;
}

function mockMatchMedia( matches: boolean ) {
	const matchMedia = jest.fn( () => ( { matches } ) );
	window.matchMedia = matchMedia as unknown as typeof window.matchMedia;
	return matchMedia;
}

describe( 'motion values', () => {
	it( 'keeps the fade cleanup past the fades that rely on it', () => {
		expect( FADE_CLEANUP_MS ).toBeGreaterThan(
			Math.max( ENTER_MS, scssMs( 'refresh' ), LEAVE_MS )
		);
	} );

	it( 'fades an arriving entry in while its space opens, not after', () => {
		expect( OPEN_FADE_DELAY_MS ).toBeGreaterThan( 0 );
		expect( OPEN_FADE_DELAY_MS ).toBeLessThan( OPEN_MS );
	} );

	it( 'closes the New label’s line while its fade still runs, within today’s length', () => {
		expect( COLLAPSE_DELAY_MS ).toBeGreaterThan( 0 );
		expect( COLLAPSE_DELAY_MS ).toBeLessThan( CLEAR_MS );
		expect( COLLAPSE_DELAY_MS + COLLAPSE_MS ).toBeLessThanOrEqual( 450 );
	} );

	it( 'declares the same durations in SCSS and TypeScript', () => {
		expect( scssMs( 'enter' ) ).toBe( ENTER_MS );
		expect( scssMs( 'clear' ) ).toBe( CLEAR_MS );
		expect( scssMs( 'exit' ) ).toBe( EXIT_MS );
		expect( scssMs( 'collapse' ) ).toBe( COLLAPSE_MS );
		expect( scssMs( 'leave' ) ).toBe( LEAVE_MS );
	} );
} );

describe( 'prefersReducedMotion', () => {
	it( 'is false when movement is allowed', () => {
		const matchMedia = mockMatchMedia( true );
		expect( prefersReducedMotion() ).toBe( false );
		expect( matchMedia ).toHaveBeenCalledWith( MOTION_QUERY );
	} );

	it( 'is true when movement is not allowed', () => {
		mockMatchMedia( false );
		expect( prefersReducedMotion() ).toBe( true );
	} );
} );

describe( 'isAnimating', () => {
	function withAnimations(
		...animations: [ AnimationPlayState, number ][]
	): Element {
		const element = document.createElement( 'div' );

		element.getAnimations = () =>
			animations.map( ( [ playState, endTime ] ) => ( {
				playState,
				effect: { getComputedTiming: () => ( { endTime } ) },
			} ) ) as unknown as Animation[];

		return element;
	}

	it( 'is true while a fade is held or still to run', () => {
		expect( isAnimating( withAnimations( [ 'paused', 200 ] ) ) ).toBe(
			true
		);
		expect( isAnimating( withAnimations( [ 'running', 200 ] ) ) ).toBe(
			true
		);
	} );

	it( 'is false once the fade is over or cancelled, or for one that never ends', () => {
		expect( isAnimating( withAnimations() ) ).toBe( false );
		expect(
			isAnimating(
				withAnimations(
					[ 'finished', 200 ],
					[ 'idle', 200 ],
					[ 'running', Infinity ]
				)
			)
		).toBe( false );
	} );
} );
