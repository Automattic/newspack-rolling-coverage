/**
 * Column widths are worked out on every render and never stored, but a list's
 * own column settings, such as an alignment, must survive the round trip.
 */
import type { View } from '@wordpress/dataviews';
import {
	evenColumns,
	withoutColumnWidths,
} from '../../src/admin/utils/even-columns';

const view = {
	type: 'table',
	fields: [ 'level', 'channel', 'time' ],
	titleField: 'message',
	layout: { styles: { time: { align: 'start' } } },
} as unknown as View;

describe( 'evenColumns', () => {
	it( 'keeps a column alignment through the round trip and stores no width', () => {
		const stored = withoutColumnWidths( evenColumns( view ) ) as {
			layout: { styles: Record< string, Record< string, unknown > > };
		};

		expect( stored.layout.styles ).toEqual( {
			time: { align: 'start' },
		} );
	} );

	it( 'sizes a fitted column to its content and shares the rest', () => {
		const styles = (
			evenColumns( view, [ 'time' ] ) as unknown as {
				layout: { styles: Record< string, Record< string, unknown > > };
			}
		 ).layout.styles;

		expect( styles.time ).toEqual( { align: 'start', width: '1%' } );
		expect( styles.level.width ).toBe( '35%' );
		expect( styles.channel.width ).toBe( '35%' );
	} );
} );
