/**
 * A filter added from "Add filter" arrives with no value until one is picked.
 * The entries list must leave the rows alone until then, as the server does,
 * instead of matching every row against an empty value and showing none.
 */
import { applyEntryFilters } from '../../src/admin/utils/fields';
import type { Entry } from '../../src/admin/types';

const entries = [
	{ id: 1, title: { raw: 'Polls close' }, status: 'publish' },
	{ id: 2, title: { raw: 'Results in' }, status: 'publish' },
] as unknown as Entry[];

describe( 'applyEntryFilters', () => {
	it.each( [
		[ 'title', 'contains' ],
		[ 'id', 'is' ],
	] )(
		'keeps every row while the %s filter has no value yet',
		( field, operator ) => {
			const filters = [
				{ field, operator, value: undefined as unknown as string },
			];

			expect( applyEntryFilters( entries, filters ) ).toHaveLength( 2 );
		}
	);

	it( 'still narrows the rows once a value is picked', () => {
		const filters = [ { field: 'id', operator: 'is', value: '2' } ];

		expect(
			applyEntryFilters( entries, filters ).map( ( entry ) => entry.id )
		).toEqual( [ 2 ] );
	} );
} );
