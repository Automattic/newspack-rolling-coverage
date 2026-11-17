/**
 * The score row above a liveblog's entries belongs to that liveblog: moving
 * to another liveblog's entries, with a save out or a modal open, never
 * writes the first liveblog's score onto the second.
 */
import apiFetch from '@wordpress/api-fetch';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { createElement, Fragment } from '@wordpress/element';

import { useScorePanel } from '../../src/admin/components/score-panel';
import type { Coverage } from '../../src/admin/types';

jest.mock( '@wordpress/api-fetch' );

const COVERAGES = 'https://example.test/wp-json/wp/v2/rolling-coverage';
const mockFetch = apiFetch as unknown as jest.Mock;

const coverage = ( id: number, first: string, second: string ): Coverage =>
	( {
		id,
		name: `Liveblog ${ id }`,
		meta: {
			rolling_coverage_status: 'active',
			rolling_coverage_score: {
				teams: [
					{ name: first, score: 1 },
					{ name: second, score: 0 },
				],
				status: '',
			},
		},
	} ) as unknown as Coverage;

const A = coverage( 1, 'Riverbend FC', 'Toledo Beacon' );
const B = coverage( 2, 'Kettle Rovers', 'Millrace United' );

/**
 * Renders the header button and the panel for a coverage, as the entries
 * list does.
 *
 * @param {Object}   props            Props.
 * @param {Coverage} props.coverage   The listed coverage.
 * @param {Function} props.setContext The context setter.
 * @return {Element} The button and panel.
 */
function Screen( {
	coverage: listed,
	setContext,
}: {
	coverage: Coverage;
	setContext: jest.Mock;
} ) {
	const { addButton, panel } = useScorePanel( listed, setContext );

	return createElement( Fragment, null, addButton, panel );
}

/**
 * Settles one fetch call by hand.
 *
 * @return {Function} Resolves the request with its own body.
 */
function holdNextFetch(): () => void {
	let release = () => {};

	mockFetch.mockImplementationOnce(
		( { data } ) =>
			new Promise( ( resolve ) => {
				release = () => resolve( { meta: data.meta } );
			} )
	);

	return () => release();
}

const sentTo = () =>
	mockFetch.mock.calls.map( ( [ options ] ) => [
		options.url,
		options.data.meta.rolling_coverage_score?.teams.map(
			( team: { name: string } ) => team.name
		),
	] );

beforeEach( () => {
	mockFetch.mockReset();
	mockFetch.mockImplementation( ( { data } ) =>
		Promise.resolve( { meta: data.meta } )
	);
	window.newspackRollingCoverageAdmin = {
		restBaseUrls: { coverages: COVERAGES },
		capabilities: { canManageTerms: true },
	} as unknown as typeof window.newspackRollingCoverageAdmin;
} );

describe( 'useScorePanel', () => {
	it( 'shows the next liveblog’s score when a save is still out', async () => {
		const setContext = jest.fn();
		const release = holdNextFetch();
		const { rerender } = render(
			createElement( Screen, { coverage: A, setContext } )
		);

		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'Increment' } )[ 0 ]
		);
		rerender( createElement( Screen, { coverage: B, setContext } ) );

		expect(
			screen.getByRole( 'spinbutton', { name: 'Kettle Rovers' } )
		).toHaveValue( 1 );

		await act( async () => release() );

		expect(
			screen.getByRole( 'spinbutton', { name: 'Kettle Rovers' } )
		).toHaveValue( 1 );

		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'Increment' } )[ 0 ]
		);
		await act( async () => {} );

		expect( sentTo() ).toEqual( [
			[ `${ COVERAGES }/1`, [ 'Riverbend FC', 'Toledo Beacon' ] ],
			[ `${ COVERAGES }/2`, [ 'Kettle Rovers', 'Millrace United' ] ],
		] );
	} );

	it( 'closes Edit Teams when the list moves to another liveblog', () => {
		const setContext = jest.fn();
		const { rerender } = render(
			createElement( Screen, { coverage: A, setContext } )
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Score actions' } )
		);
		fireEvent.click(
			screen.getByRole( 'menuitem', { name: 'Edit Teams' } )
		);

		expect( screen.getByLabelText( 'First team' ) ).toHaveValue(
			'Riverbend FC'
		);

		rerender( createElement( Screen, { coverage: B, setContext } ) );

		expect( screen.queryByLabelText( 'First team' ) ).toBeNull();
		expect( mockFetch ).not.toHaveBeenCalled();
	} );

	it( 'keeps Edit Teams open until its own request has saved', async () => {
		const setContext = jest.fn();
		const release = holdNextFetch();
		render( createElement( Screen, { coverage: A, setContext } ) );

		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'Increment' } )[ 0 ]
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Score actions' } )
		);
		fireEvent.click(
			screen.getByRole( 'menuitem', { name: 'Edit Teams' } )
		);
		fireEvent.change( screen.getByLabelText( 'Second team' ), {
			target: { value: 'Toledo Beacon FC' },
		} );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		} );

		expect( screen.getByLabelText( 'Second team' ) ).toBeInTheDocument();

		await act( async () => release() );

		expect( screen.queryByLabelText( 'Second team' ) ).toBeNull();
		expect( sentTo()[ 1 ] ).toEqual( [
			`${ COVERAGES }/1`,
			[ 'Riverbend FC', 'Toledo Beacon FC' ],
		] );
	} );
} );
