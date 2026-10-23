/**
 * A page starts counting polled entries toward their next ad from what it
 * rendered, so ads among arriving entries keep its spacing: one below the
 * unpinned entries above the first ad that follows one of them.
 */
import { startingAdCount } from '../../src/blocks/rolling-coverage/shared-poll';

/**
 * An entries list in the render's order, one letter per child: E an entry,
 * P a pinned entry, A an ad, S the Pinned group holding a pinned entry.
 *
 * @param {string} order The children.
 * @return {HTMLElement} The list.
 */
function list( order: string ): HTMLElement {
	const markup: Record< string, string > = {
		E: '<article data-entry-id="1"></article>',
		P: '<article data-entry-id="2" data-pinned></article>',
		A: '<div class="newspack_global_ad rolling_coverage_entry"></div>',
		S: '<div class="newspack-rolling-coverage-pinned-stack"><article data-entry-id="3" data-pinned></article></div>',
	};
	const el = document.createElement( 'div' );

	el.innerHTML = Array.from( order, ( child ) => markup[ child ] ).join( '' );

	return el;
}

test.each( [
	[ 'a full stretch above the first ad', 'EEEEAEEEEA', 3 ],
	[ 'a pinned entry taking a place in it', 'PEEEAEEEEA', 2 ],
	[
		'an ad among the pinned entries, which new entries go below',
		'PPPPAPEEEA',
		2,
	],
	[ "Split's Pinned group", 'SEEEAEEEEA', 2 ],
	[ 'no ad after the entries', 'EE', 1 ],
	[ 'no entries', '', 0 ],
] )( '%s', ( _case, order, expected ) => {
	expect( startingAdCount( list( order ) ) ).toBe( expected );
} );
