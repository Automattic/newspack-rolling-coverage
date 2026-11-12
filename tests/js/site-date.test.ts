import { getSettings, setSettings } from '@wordpress/date';

import {
	formatSiteDate,
	formatSiteDateTime,
	formatSiteTime,
	withSeconds,
} from '../../src/admin/utils/site-date';
import { safeFormatSlackTimestamp } from '../../src/admin/utils/fields';
import { formatEventTime } from '../../src/admin/fields/monitor';

const original = getSettings();

function useSite( date: string, time: string, timezone: string ) {
	setSettings( {
		...original,
		formats: { ...original.formats, date, time },
		timezone: {
			offset: 0,
			offsetFormatted: '+00:00',
			string: timezone,
			abbr: '',
		},
	} );
}

afterEach( () => setSettings( original ) );

describe( 'site date helpers', () => {
	it( "join the site date and time formats, not core's datetime default", () => {
		useSite( 'd/m/Y', 'H:i', 'UTC' );
		expect( formatSiteDateTime( '2026-03-04T15:09:00+00:00' ) ).toBe(
			'04/03/2026 15:09'
		);
		expect( formatSiteDate( '2026-03-04T15:09:00+00:00' ) ).toBe(
			'04/03/2026'
		);
		expect( formatSiteTime( '2026-03-04T15:09:00+00:00' ) ).toBe( '15:09' );
	} );

	it( 'keeps AM/PM when the site time format has it', () => {
		useSite( 'F j, Y', 'g:i A', 'UTC' );
		expect( formatSiteDateTime( '2026-03-04T15:09:00Z' ) ).toBe(
			'March 4, 2026 3:09 PM'
		);
	} );

	it( 'formats an instant in the site time zone', () => {
		useSite( 'd/m/Y', 'H:i', 'Pacific/Auckland' );
		expect( formatSiteDateTime( '2026-03-04T15:09:00Z' ) ).toBe(
			'05/03/2026 04:09'
		);
	} );

	it( 'reads a string with no offset as site time', () => {
		useSite( 'd/m/Y', 'H:i', 'Pacific/Auckland' );
		expect( formatSiteDateTime( '2026-03-04T15:09:00' ) ).toBe(
			'04/03/2026 15:09'
		);
	} );

	it( 'returns an empty string for missing or invalid values', () => {
		expect( formatSiteDateTime( '' ) ).toBe( '' );
		expect( formatSiteDateTime( null ) ).toBe( '' );
		expect( formatSiteDateTime( 'not a date' ) ).toBe( '' );
	} );

	it( 'formats a Slack timestamp with the site settings', () => {
		useSite( 'd/m/Y', 'H:i', 'Pacific/Auckland' );
		expect( safeFormatSlackTimestamp( '1772636940.000100' ) ).toBe(
			'05/03/2026 04:09'
		);
		expect( safeFormatSlackTimestamp( '' ) ).toBe( '—' );
	} );

	it( 'adds seconds when asked', () => {
		useSite( 'j F Y', 'g:i a', 'UTC' );
		expect(
			formatSiteTime( '2026-03-04T15:09:42Z', { seconds: true } )
		).toBe( '3:09:42 pm' );
		expect(
			formatSiteDateTime( '2026-03-04T15:09:42Z', { seconds: true } )
		).toBe( '4 March 2026 3:09:42 pm' );
		expect( formatSiteDateTime( '2026-03-04T15:09:42Z' ) ).toBe(
			'4 March 2026 3:09 pm'
		);
	} );

	it( "shows seconds in the Slack monitor's times", () => {
		useSite( 'j F Y', 'H:i', 'Pacific/Auckland' );
		expect(
			formatEventTime( {
				id: 1,
				timestamp: '2026-03-04T15:09:42Z',
				level: 'info',
				message: 'Message received',
				context: {},
			} )
		).toBe( '5 March 2026 04:09:42' );
	} );
} );

describe( 'withSeconds', () => {
	it.each( [
		[ 'g:i a', 'g:i:s a' ],
		[ 'H:i', 'H:i:s' ],
		[ 'H.i', 'H.i.s' ],
		[ 'G\\hi', 'G\\hi:s' ],
		[ 'Hi', 'Hi:s' ],
		[ 'H i', 'H i:s' ],
		[ 'G時i分', 'G時i:s分' ],
		[ 'H\\\\i', 'H\\\\i:s' ],
		[ '\\s H:i', '\\s H:i:s' ],
		[ 'H \\i\\n i', 'H \\i\\n i:s' ],
	] )( 'adds seconds after the minutes in %s', ( pattern, expected ) => {
		expect( withSeconds( pattern ) ).toBe( expected );
	} );

	it.each( [ 'H:i:s', 'g:i:s a', 'g a', 'U', '\\i\\s' ] )(
		'leaves %s as it is',
		( pattern ) => {
			expect( withSeconds( pattern ) ).toBe( pattern );
		}
	);
} );
