import * as fs from 'fs';
import * as path from 'path';

import {
	CLEAR_MS,
	COLLAPSE_MS,
	ENTER_MS,
	EXIT_MS,
	FADE_CLEANUP_MS,
	HOLD_MS,
	LEAVE_MS,
	MOTION_QUERY,
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
	it( 'holds the linked outline for four seconds', () => {
		expect( HOLD_MS ).toBe( 4000 );
	} );

	it( 'keeps the fade cleanup past the longest sequence', () => {
		expect( FADE_CLEANUP_MS ).toBeGreaterThan( CLEAR_MS + COLLAPSE_MS );
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
