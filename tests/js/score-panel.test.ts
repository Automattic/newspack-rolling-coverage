/**
 * The score row above a liveblog's entries belongs to that liveblog: moving
 * to another liveblog's entries, with a save out or a modal open, never
 * writes the first liveblog's score onto the second.
 */
import apiFetch from '@wordpress/api-fetch';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { DropdownMenu } from '@wordpress/components';
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
 * Renders the header menu's Add Score item and the panel for a coverage, as
 * the entries list does.
 *
 * @param {Object}   props            Props.
 * @param {Coverage} props.coverage   The listed coverage.
 * @param {Function} props.setContext The context setter.
 * @return {Element} The menu and panel.
 */
function Screen( {
	coverage: listed,
	setContext,
}: {
	coverage: Coverage;
	setContext: jest.Mock;
} ) {
	const { addControl, panel } = useScorePanel( listed, setContext );

	return createElement(
		Fragment,
		null,
		addControl &&
			createElement( DropdownMenu, {
				label: 'More actions',
				controls: [ addControl ],
			} ),
		panel
	);
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
	it( 'offers Add Score in the header menu only while there is no score', () => {
		const setContext = jest.fn();
		const unscored = {
			...A,
			meta: { rolling_coverage_status: 'active' },
		} as unknown as Coverage;
		const { rerender } = render(
			createElement( Screen, { coverage: unscored, setContext } )
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'More actions' } )
		);
		fireEvent.click(
			screen.getByRole( 'menuitem', { name: 'Add Score' } )
		);

		expect( screen.getByLabelText( 'First team' ) ).toHaveValue( '' );

		rerender( createElement( Screen, { coverage: A, setContext } ) );

		expect(
			screen.queryByRole( 'button', { name: 'More actions' } )
		).toBeNull();
	} );

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

	it( 'keeps the status being typed when a step saves', async () => {
		const setContext = jest.fn( ( update ) => update );
		const { rerender } = render(
			createElement( Screen, { coverage: A, setContext } )
		);

		fireEvent.change( screen.getByLabelText( 'Status' ), {
			target: { value: 'Second ha' },
		} );
		await act( async () => {
			fireEvent.click(
				screen.getAllByRole( 'button', { name: 'Increment' } )[ 0 ]
			);
		} );

		const saved = coverage( 1, 'Riverbend FC', 'Toledo Beacon' );
		(
			saved.meta.rolling_coverage_score as { teams: { score: number }[] }
		 ).teams[ 0 ].score = 2;
		rerender( createElement( Screen, { coverage: saved, setContext } ) );

		expect( screen.getByLabelText( 'Status' ) ).toHaveValue( 'Second ha' );
	} );

	it( 'saves a typed score once, when the field is left', async () => {
		const setContext = jest.fn();
		render( createElement( Screen, { coverage: A, setContext } ) );
		const field = screen.getByRole( 'spinbutton', {
			name: 'Riverbend FC',
		} );

		fireEvent.change( field, { target: { value: '1' } } );
		fireEvent.change( field, { target: { value: '12' } } );

		expect( mockFetch ).not.toHaveBeenCalled();

		await act( async () => {
			fireEvent.blur( field );
		} );

		expect(
			mockFetch.mock.calls.map(
				( [ options ] ) =>
					options.data.meta.rolling_coverage_score.teams[ 0 ].score
			)
		).toEqual( [ 12 ] );
	} );

	it( 'leaves the score alone when the field is cleared', async () => {
		const setContext = jest.fn();
		render( createElement( Screen, { coverage: A, setContext } ) );
		const field = screen.getByRole( 'spinbutton', {
			name: 'Riverbend FC',
		} );

		fireEvent.change( field, { target: { value: '' } } );
		await act( async () => {
			fireEvent.blur( field );
		} );

		expect( mockFetch ).not.toHaveBeenCalled();
		expect( field ).toHaveValue( 1 );
	} );
} );
