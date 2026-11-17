import { applyScore } from '../../src/blocks/scoreboard/apply-score';
import type { Score } from '../../src/blocks/shared/score';

const score = ( first: number, second: number, status = '' ): Score => ( {
	teams: [
		{ name: 'Riverbend FC', score: first },
		{ name: 'Toledo Beacon', score: second },
	],
	status,
} );

/**
 * A scoreboard as the server renders it.
 *
 * @param {number} first  First team's score.
 * @param {number} second Second team's score.
 * @return {HTMLElement} The wrapper.
 */
function scoreboard( first: number, second: number ): HTMLElement {
	const block = document.createElement( 'div' );

	block.innerHTML = `
		<div class="wp-block-group">
			<p data-rc-score="team1Name">Riverbend FC</p>
			<p data-rc-score="team1Score">${ first }</p>
			<p class="newspack-rolling-coverage-scoreboard__dash" aria-hidden="true">–</p>
			<p data-rc-score="team2Score">${ second }</p>
			<p data-rc-score="team2Name">Toledo Beacon</p>
		</div>
		<p data-rc-score="status" hidden></p>
		<div data-rc-score-announce aria-live="polite"></div>`;

	return block;
}

const text = ( block: HTMLElement, key: string ) =>
	block.querySelector( `[data-rc-score="${ key }"]` )?.textContent;
const announced = ( block: HTMLElement ) =>
	block.querySelector( '[data-rc-score-announce]' )?.textContent;

describe( 'applyScore', () => {
	it( 'announces a new score and shows it', () => {
		const block = scoreboard( 2, 1 );

		expect( applyScore( block, score( 3, 1 ) ) ).toBe( true );
		expect( text( block, 'team1Score' ) ).toBe( '3' );
		expect( announced( block ) ).toBe( 'Riverbend FC 3, Toledo Beacon 1' );
		expect(
			block.querySelector( '[aria-hidden="true"]' )?.textContent
		).toBe( '–' );
	} );

	it( 'stays quiet when only the status line changes, and shows it', () => {
		const block = scoreboard( 2, 1 );
		const status = block.querySelector< HTMLElement >(
			'[data-rc-score="status"]'
		);

		expect( applyScore( block, score( 2, 1, 'Full time' ) ) ).toBe( false );
		expect( status?.textContent ).toBe( 'Full time' );
		expect( status?.hidden ).toBe( false );
		expect( announced( block ) ).toBe( '' );
	} );

	it( 'compares with the last score it showed, not the rendered one', () => {
		const block = scoreboard( 2, 1 );

		applyScore( block, score( 3, 1 ) );

		expect( applyScore( block, score( 3, 1 ) ) ).toBe( false );
		expect( applyScore( block, score( 3, 2 ) ) ).toBe( true );
		expect( announced( block ) ).toBe( 'Riverbend FC 3, Toledo Beacon 2' );
	} );

	it( 'hides the scoreboard once the score is removed, and shows it again', () => {
		const block = scoreboard( 2, 1 );

		applyScore( block, null );
		expect( block.hidden ).toBe( true );

		applyScore( block, score( 2, 1 ) );
		expect( block.hidden ).toBe( false );
	} );

	it( 'leaves the announcement to another scoreboard when told to', () => {
		const block = scoreboard( 2, 1 );

		expect( applyScore( block, score( 3, 1 ), false ) ).toBe( false );
		expect( text( block, 'team1Score' ) ).toBe( '3' );
		expect( announced( block ) ).toBe( '' );
	} );
} );
