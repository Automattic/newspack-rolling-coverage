/**
 * The view script's check of the site's current day, which shows an entry's
 * earlier-day date (Clock's date under the time) only when the entry wasn't
 * published today in the site's timezone, whatever the reader's.
 */
import {
	refreshEarlierDayDates,
	siteDay,
} from '../../src/blocks/shared/earlier-day-dates';

const READER_TIMEZONE = process.env.TZ;

afterEach( () => {
	process.env.TZ = READER_TIMEZONE;
} );

describe( 'siteDay', () => {
	it( "reads the day in the site's named timezone, not the reader's", () => {
		const now = Date.parse( '2026-03-04T23:30:00Z' );

		for ( const reader of [ 'Pacific/Kiritimati', 'Pacific/Pago_Pago' ] ) {
			process.env.TZ = reader;

			expect( siteDay( now, { string: 'Europe/London' } ) ).toBe(
				'2026-03-04'
			);
			expect( siteDay( now, { string: 'Asia/Tokyo' } ) ).toBe(
				'2026-03-05'
			);
			expect( siteDay( now, { string: 'America/New_York' } ) ).toBe(
				'2026-03-04'
			);
		}
	} );

	it( "turns over at the site's midnight", () => {
		const timezone = { string: 'America/New_York', offset: -5 };

		expect(
			siteDay( Date.parse( '2026-03-05T04:59:59Z' ), timezone )
		).toBe( '2026-03-04' );
		expect(
			siteDay( Date.parse( '2026-03-05T05:00:00Z' ), timezone )
		).toBe( '2026-03-05' );
	} );

	it( 'follows daylight saving time in a named timezone', () => {
		const timezone = { string: 'America/New_York', offset: -5 };

		// Midnight is 04:00 UTC in summer, so the stale winter offset would be wrong.
		expect(
			siteDay( Date.parse( '2026-07-05T04:30:00Z' ), timezone )
		).toBe( '2026-07-05' );
	} );

	it( 'uses the UTC offset on a site set to one', () => {
		const now = Date.parse( '2026-03-04T18:45:00Z' );

		process.env.TZ = 'America/Los_Angeles';

		expect( siteDay( now, { string: '', offset: 5.5 } ) ).toBe(
			'2026-03-05'
		);
		expect( siteDay( now, { string: '', offset: 5 } ) ).toBe(
			'2026-03-04'
		);
		expect( siteDay( now, { string: '', offset: '-12' } ) ).toBe(
			'2026-03-04'
		);
		expect( siteDay( now, { offset: 0 } ) ).toBe( '2026-03-04' );
	} );

	it( 'falls back to the offset when the named timezone is unknown', () => {
		expect(
			siteDay( Date.parse( '2026-03-04T22:00:00Z' ), {
				string: 'Not/A_Zone',
				offset: 3,
			} )
		).toBe( '2026-03-05' );
	} );

	it( 'reads UTC without a timezone', () => {
		expect( siteDay( Date.parse( '2026-03-04T23:59:00Z' ) ) ).toBe(
			'2026-03-04'
		);
	} );
} );

describe( 'refreshEarlierDayDates', () => {
	/**
	 * A feed with an earlier-day date for each day given.
	 *
	 * @param {string[]} days The entries' days, `Y-m-d`.
	 * @return {HTMLElement} The feed.
	 */
	function feed( days: string[] ): HTMLElement {
		const root = document.createElement( 'div' );

		root.innerHTML = days
			.map(
				( day ) =>
					`<div class="wp-block-post-date newspack-rolling-coverage-earlier-day-date" data-rc-day="${ day }"><time>${ day }</time></div>`
			)
			.join( '' );

		return root;
	}

	const hidden = ( root: HTMLElement ) =>
		Array.from(
			root.querySelectorAll< HTMLElement >( '[data-rc-day]' ),
			( date ) => date.hidden
		);

	it( "hides today's dates and shows earlier days'", () => {
		const root = feed( [ '2026-03-05', '2026-03-04', '2026-02-28' ] );

		refreshEarlierDayDates( root, '2026-03-05' );

		expect( hidden( root ) ).toEqual( [ true, false, false ] );
	} );

	it( 'shows every date once the day turns over', () => {
		const root = feed( [ '2026-03-05', '2026-03-05' ] );
		const timezone = { string: 'Europe/Paris', offset: 1 };

		refreshEarlierDayDates(
			root,
			siteDay( Date.parse( '2026-03-05T22:59:00Z' ), timezone )
		);
		expect( hidden( root ) ).toEqual( [ true, true ] );

		refreshEarlierDayDates(
			root,
			siteDay( Date.parse( '2026-03-05T23:00:00Z' ), timezone )
		);
		expect( hidden( root ) ).toEqual( [ false, false ] );
	} );

	it( 'leaves dates without a day alone', () => {
		const root = document.createElement( 'div' );

		root.innerHTML =
			'<div class="newspack-rolling-coverage-earlier-day-date" hidden></div>';

		refreshEarlierDayDates( root, '2026-03-05' );

		expect( root.querySelector< HTMLElement >( 'div' )?.hidden ).toBe(
			true
		);
	} );
} );
