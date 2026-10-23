/**
 * When the pattern editor starts an empty layout pattern with a Rolling
 * Coverage block (`layout-pattern-start.tsx`).
 */
import { registerCoreBlocks } from '@wordpress/block-library';
import { createBlock } from '@wordpress/blocks';

import {
	isEmptyPattern,
	nextLayoutStart,
	type LayoutStart,
	type LayoutStartPattern,
} from '../../src/blocks/rolling-coverage/layout-start';

registerCoreBlocks();

const FRESH: LayoutStart = { postId: 0, started: false };

/**
 * Runs the decision over a sequence of editor states, as the editor would.
 *
 * @param {LayoutStartPattern[]} states The pattern's states, in order.
 * @return {boolean[]} Whether each state inserts the block.
 */
function run( states: LayoutStartPattern[] ): boolean[] {
	let last = FRESH;

	return states.map( ( state ) => {
		const { insert, start } = nextLayoutStart( state, last );
		last = start;
		return insert;
	} );
}

describe( 'isEmptyPattern', () => {
	it( 'counts no blocks as empty', () => {
		expect( isEmptyPattern( [] ) ).toBe( true );
	} );

	it( 'counts a lone untouched paragraph as empty', () => {
		expect( isEmptyPattern( [ createBlock( 'core/paragraph' ) ] ) ).toBe(
			true
		);
	} );

	it( 'counts a paragraph with text as content', () => {
		expect(
			isEmptyPattern( [
				createBlock( 'core/paragraph', { content: 'Hello' } ),
			] )
		).toBe( false );
	} );

	it( 'counts any other block as content', () => {
		expect( isEmptyPattern( [ createBlock( 'core/separator' ) ] ) ).toBe(
			false
		);
	} );
} );

describe( 'nextLayoutStart', () => {
	it( 'inserts into an empty layout pattern when it opens', () => {
		expect(
			run( [ { postId: 5, isLayoutPattern: true, isEmpty: true } ] )
		).toEqual( [ true ] );
	} );

	it( 'inserts when an empty pattern joins the layout category', () => {
		expect(
			run( [
				{ postId: 5, isLayoutPattern: false, isEmpty: true },
				{ postId: 5, isLayoutPattern: true, isEmpty: true },
			] )
		).toEqual( [ false, true ] );
	} );

	it( 'leaves a pattern outside the layout category alone', () => {
		expect(
			run( [ { postId: 5, isLayoutPattern: false, isEmpty: true } ] )
		).toEqual( [ false ] );
	} );

	it( 'leaves a layout pattern with content alone, even once emptied', () => {
		expect(
			run( [
				{ postId: 5, isLayoutPattern: true, isEmpty: false },
				{ postId: 5, isLayoutPattern: true, isEmpty: true },
			] )
		).toEqual( [ false, false ] );
	} );

	it( 'inserts once, so a removed block stays removed', () => {
		expect(
			run( [
				{ postId: 5, isLayoutPattern: true, isEmpty: true },
				{ postId: 5, isLayoutPattern: true, isEmpty: false },
				{ postId: 5, isLayoutPattern: true, isEmpty: true },
			] )
		).toEqual( [ true, false, false ] );
	} );

	it( 'starts over when the pattern leaves the category and rejoins it', () => {
		expect(
			run( [
				{ postId: 5, isLayoutPattern: true, isEmpty: true },
				{ postId: 5, isLayoutPattern: true, isEmpty: true },
				{ postId: 5, isLayoutPattern: false, isEmpty: true },
				{ postId: 5, isLayoutPattern: true, isEmpty: true },
			] )
		).toEqual( [ true, false, false, true ] );
	} );

	it( 'starts over for another pattern', () => {
		expect(
			run( [
				{ postId: 5, isLayoutPattern: true, isEmpty: true },
				{ postId: 6, isLayoutPattern: true, isEmpty: true },
			] )
		).toEqual( [ true, true ] );
	} );
} );
