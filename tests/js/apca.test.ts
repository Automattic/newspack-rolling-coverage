import { normalizeColor, textColor } from '../../src/blocks/shared/apca';

describe( 'textColor', () => {
	it.each( [
		[ '#000000', '#ffffff' ],
		[ '#ffffff', '#000000' ],
		[ '#f0f0f0', '#000000' ],
		[ '#ffcc00', '#000000' ],
		[ '#ffd700', '#000000' ],
		[ '#178f15', '#000000' ],
		[ '#3366cc', '#ffffff' ],
		[ '#2271b1', '#ffffff' ],
		[ '#dd3333', '#ffffff' ],
		[ '#e65100', '#000000' ],
	] )( 'on %s the text is %s', ( background, expected ) => {
		expect( textColor( background ) ).toBe( expected );
	} );

	it( 'accepts normalized shorthand and alpha forms', () => {
		expect( textColor( normalizeColor( '#FC0' ) ) ).toBe( '#000000' );
		expect( textColor( normalizeColor( '#E6510080' ) ) ).toBe( '#000000' );
	} );
} );
