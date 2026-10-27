/**
 * What a Rolling Coverage block's layout attributes point at
 * (`layout-reference.ts`), as the editor reads them.
 */
import {
	layoutReferenceOf,
	slugAttributesFor,
} from '../../src/blocks/rolling-coverage/layout-reference';
import type { BuiltInLayoutSlug } from '../../src/blocks/rolling-coverage/layouts';

const LAYOUT_IDS: Record< BuiltInLayoutSlug, number > = {
	default: 0,
	stream: 0,
	rail: 0,
	clock: 0,
	margin: 0,
	minute: 0,
	byline: 0,
	ticker: 50,
	split: 0,
	wire: 0,
	digest: 0,
	flash: 0,
	alert: 0,
};
const UNRENDERED = { 40: 'ticker', 41: 'flash', 42: 'nope' };

describe( 'layoutReferenceOf', () => {
	it( 'reads a slug as its built-in layout, with the pattern it has now', () => {
		expect(
			layoutReferenceOf( 0, 'ticker', LAYOUT_IDS, UNRENDERED )
		).toEqual( { kind: 'built-in', slug: 'ticker', patternId: 50 } );
		expect(
			layoutReferenceOf( 0, 'flash', LAYOUT_IDS, UNRENDERED )
		).toEqual( { kind: 'built-in', slug: 'flash', patternId: 0 } );
	} );

	it( "reads a built-in layout's pattern ID as that layout", () => {
		expect( layoutReferenceOf( 50, '', LAYOUT_IDS, UNRENDERED ) ).toEqual( {
			kind: 'built-in',
			slug: 'ticker',
			patternId: 50,
		} );
	} );

	it( "reads the ID of a built-in pattern that doesn't render as its layout", () => {
		expect( layoutReferenceOf( 40, '', LAYOUT_IDS, UNRENDERED ) ).toEqual( {
			kind: 'built-in',
			slug: 'ticker',
			patternId: 50,
		} );
		expect( layoutReferenceOf( 41, '', LAYOUT_IDS, UNRENDERED ) ).toEqual( {
			kind: 'built-in',
			slug: 'flash',
			patternId: 0,
		} );
	} );

	it( 'reads any other pattern ID as that pattern', () => {
		expect( layoutReferenceOf( 77, '', LAYOUT_IDS, UNRENDERED ) ).toEqual( {
			kind: 'pattern',
			id: 77,
		} );
		expect( layoutReferenceOf( 42, '', LAYOUT_IDS, UNRENDERED ) ).toEqual( {
			kind: 'pattern',
			id: 42,
		} );
	} );

	it( 'lets the pattern ID decide when both are set', () => {
		expect(
			layoutReferenceOf( 77, 'ticker', LAYOUT_IDS, UNRENDERED )
		).toEqual( { kind: 'pattern', id: 77 } );
	} );

	it( 'reads no reference, or a slug no layout has, as none', () => {
		expect( layoutReferenceOf( 0, '', LAYOUT_IDS, UNRENDERED ) ).toEqual( {
			kind: 'none',
		} );
		expect(
			layoutReferenceOf( 0, 'nope', LAYOUT_IDS, UNRENDERED )
		).toEqual( {
			kind: 'none',
		} );
		expect(
			layoutReferenceOf( 0, 'toString', LAYOUT_IDS, UNRENDERED )
		).toEqual( { kind: 'none' } );
	} );

	it( 'reads an empty list, as PHP sends one', () => {
		expect( layoutReferenceOf( 40, '', LAYOUT_IDS, [] ) ).toEqual( {
			kind: 'pattern',
			id: 40,
		} );
	} );
} );

describe( 'slugAttributesFor', () => {
	const convert = ( layoutId: number, layoutSlug = '' ) =>
		slugAttributesFor(
			layoutReferenceOf( layoutId, layoutSlug, LAYOUT_IDS, UNRENDERED ),
			layoutId
		);

	it( "moves a block on a built-in layout's pattern to the layout's slug", () => {
		expect( convert( 50 ) ).toEqual( {
			layoutId: 0,
			layoutSlug: 'ticker',
		} );
		expect( convert( 40 ) ).toEqual( {
			layoutId: 0,
			layoutSlug: 'ticker',
		} );
		expect( convert( 41 ) ).toEqual( { layoutId: 0, layoutSlug: 'flash' } );
	} );

	it( 'leaves slug blocks, other patterns and blocks without a layout alone', () => {
		expect( convert( 0, 'ticker' ) ).toBeNull();
		expect( convert( 77 ) ).toBeNull();
		expect( convert( 0 ) ).toBeNull();
	} );
} );
