import {
	headingFontFamily,
	scoreboardTemplate,
} from '../../src/blocks/scoreboard/template';

type Item = [ string, Record< string, any >, Item[]? ];

const BLOCK_SIZES = [ 'small', 'medium', 'large', 'x-large', 'xx-large' ];
const CLASSIC_SIZES = [ 'small', 'normal', 'large', 'huge' ];

const parts = ( template: Item[] ) => {
	const [ row, status ] = template;

	return {
		names: [ row[ 2 ]![ 0 ], row[ 2 ]![ 3 ] ],
		scores: [ row[ 2 ]![ 1 ] ],
		status,
		row,
	};
};

describe( 'headingFontFamily', () => {
	it( 'takes the first candidate that names a preset', () => {
		expect(
			headingFontFamily(
				[
					undefined,
					'var(--wp--preset--font-family--missing)',
					'var:preset|font-family|inter',
					'var(--wp--preset--font-family--source-serif-4)',
				],
				[ 'inter', 'source-serif-4' ]
			)
		).toBe( 'inter' );
	} );

	it( 'gives nothing on a theme without font family presets', () => {
		expect(
			headingFontFamily( [ 'Georgia, serif', undefined ], [] )
		).toBeUndefined();
	} );
} );

describe( 'scoreboardTemplate', () => {
	it( 'uses the block theme scale, heading font and muted status there', () => {
		const { names, scores, status, row } = parts(
			scoreboardTemplate( {
				sizes: BLOCK_SIZES,
				colors: [ 'contrast', 'contrast-3' ],
				headingFont: 'inter',
			} ) as Item[]
		);

		expect( names.map( ( name ) => name[ 1 ].fontSize ) ).toEqual( [
			'large',
			'large',
		] );
		expect( names[ 0 ][ 1 ].fontFamily ).toBe( 'inter' );
		expect( scores[ 0 ][ 1 ].fontSize ).toBe( 'xx-large' );
		expect( status[ 1 ].textColor ).toBe( 'contrast-3' );
		expect( row[ 1 ].style.spacing.blockGap ).toBe(
			'var:preset|spacing|40'
		);
	} );

	it( 'falls back to the classic theme scale and colors', () => {
		const { names, scores, status } = parts(
			scoreboardTemplate( {
				sizes: CLASSIC_SIZES,
				colors: [ 'primary', 'medium-gray' ],
			} ) as Item[]
		);

		expect( names[ 0 ][ 1 ].fontSize ).toBe( 'normal' );
		expect( names[ 0 ][ 1 ] ).not.toHaveProperty( 'fontFamily' );
		expect( names[ 0 ][ 1 ].className ).toBe( 'use-header-font' );
		expect( scores[ 0 ][ 1 ].fontSize ).toBe( 'huge' );
		expect( status[ 1 ].fontSize ).toBe( 'small' );
		expect( status[ 1 ].textColor ).toBe( 'medium-gray' );
	} );

	it( 'leaves the status color alone without a muted preset', () => {
		const { status } = parts(
			scoreboardTemplate( { sizes: BLOCK_SIZES, colors: [] } ) as Item[]
		);

		expect( status[ 1 ] ).not.toHaveProperty( 'textColor' );
	} );
} );
