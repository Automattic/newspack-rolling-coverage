import {
	idsNewerThan,
	newIdsInReplace,
} from '../../src/blocks/rolling-coverage/new-entry-ids';

describe( 'newIdsInReplace', () => {
	it( 'returns only the entries above the first one already shown', () => {
		expect(
			newIdsInReplace(
				[ 'n2', 'n1', 'a', 'b', 'old' ],
				new Set( [ 'a', 'b' ] )
			)
		).toEqual( [ 'n2', 'n1' ] );
	} );

	it( 'treats every entry as new when none was shown', () => {
		expect( newIdsInReplace( [ 'n2', 'n1' ], new Set() ) ).toEqual( [
			'n2',
			'n1',
		] );
	} );

	it( 'a returning taken-down entry is not new', () => {
		expect(
			newIdsInReplace(
				[ 'a', 'b', 'c' ],
				new Set( [ 'b', 'c' ] ),
				new Set( [ 'a' ] )
			)
		).toEqual( [] );
	} );

	it( 'leaves out a returning entry above newer ones', () => {
		expect(
			newIdsInReplace(
				[ 'n1', 'a', 'b' ],
				new Set( [ 'b' ] ),
				new Set( [ 'a' ] )
			)
		).toEqual( [ 'n1' ] );
	} );

	it( 'when none of the shown entries is left, treats only the newer ones as new', () => {
		expect(
			newIdsInReplace(
				[ 'n1', 'old1', 'old2' ],
				new Set(),
				new Set(),
				new Set( [ 'n1' ] )
			)
		).toEqual( [ 'n1' ] );
	} );
} );

describe( 'idsNewerThan', () => {
	it( 'returns entries dated after the shared entry', () => {
		expect(
			idsNewerThan(
				[
					{ id: '9', dateGmt: '2026-10-23 10:05:00' },
					{ id: '8', dateGmt: '2026-10-23 10:00:01' },
					{ id: '7', dateGmt: '2026-10-23 10:00:00' },
					{ id: '6', dateGmt: '2026-10-23 09:59:59' },
					{ id: '5', dateGmt: '' },
				],
				'2026-10-23 10:00:00'
			)
		).toEqual( [ '9', '8' ] );
	} );

	it( 'returns none without a date to compare', () => {
		expect(
			idsNewerThan( [ { id: '9', dateGmt: '2026-10-23 10:05:00' } ], '' )
		).toEqual( [] );
	} );
} );
