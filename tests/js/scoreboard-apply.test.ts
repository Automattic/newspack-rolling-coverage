import { applyScore } from '../../src/blocks/scoreboard/apply-score';
import type { Score, ScoreBadge } from '../../src/blocks/shared/score';

const score = (
	first: number,
	second: number,
	status = '',
	badge: ScoreBadge = 'active'
): Score => ( {
	teams: [
		{ name: 'Riverbend FC', score: first },
		{ name: 'Toledo Beacon', score: second },
	],
	status,
	badge,
} );

/**
 * A scoreboard as the server renders it.
 *
 * @param {number} first  First team's score.
 * @param {number} second Second team's score.
 * @param {string} status The status line's markup.
 * @return {HTMLElement} The wrapper.
 */
function scoreboard(
	first: number,
	second: number,
	status = '<p data-rc-score="status" hidden></p>'
): HTMLElement {
	const block = document.createElement( 'div' );

	block.innerHTML = `
		<div class="wp-block-group">
			<p data-rc-score="team1Name">Riverbend FC</p>
			<p data-rc-score="team1Score">${ first }</p>
			<p class="newspack-rolling-coverage-scoreboard__dash" aria-hidden="true">–</p>
			<p data-rc-score="team2Score">${ second }</p>
			<p data-rc-score="team2Name">Toledo Beacon</p>
		</div>
		${ status }
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

	describe( 'with the status line as a badge', () => {
		const LIVE =
			'newspack-ui__badge newspack-ui__badge--success newspack-ui__badge--dot newspack-ui__badge--pulse';

		/**
		 * A scoreboard showing its status line as a badge.
		 *
		 * @param {string} status The status line's markup.
		 * @return {HTMLElement} The wrapper.
		 */
		function badgeScoreboard( status: string ): HTMLElement {
			const block = scoreboard( 2, 1, status );

			block.dataset.statusBadge = 'true';

			return block;
		}

		const badge = ( block: HTMLElement ) =>
			block.querySelector< HTMLElement >(
				'[data-rc-score="status"] > .newspack-ui__badge'
			);

		it( 'swaps the badge’s text and style, without announcing', () => {
			const block = badgeScoreboard(
				`<p data-rc-score="status"><span class="${ LIVE }">Second half</span></p>`
			);

			expect(
				applyScore( block, score( 2, 1, 'Full time', 'archived' ) )
			).toBe( false );
			expect( badge( block )?.className ).toBe(
				'newspack-ui__badge newspack-ui__badge--error'
			);
			expect( badge( block )?.textContent ).toBe( 'Full time' );
			expect( text( block, 'status' ) ).toBe( 'Full time' );
			expect( announced( block ) ).toBe( '' );

			applyScore( block, score( 2, 1, 'Full time', 'paused' ) );

			expect( badge( block )?.className ).toBe(
				'newspack-ui__badge newspack-ui__badge--secondary'
			);
		} );

		it( 'adds the badge to a status line rendered empty', () => {
			const block = badgeScoreboard(
				'<p data-rc-score="status" hidden></p>'
			);

			applyScore( block, score( 2, 1, 'Second half' ) );

			expect( badge( block )?.className ).toBe( LIVE );
			expect( badge( block )?.textContent ).toBe( 'Second half' );
			expect(
				block.querySelector< HTMLElement >( '[data-rc-score="status"]' )
					?.hidden
			).toBe( false );
		} );

		it( 'hides the status line once it is cleared', () => {
			const block = badgeScoreboard(
				`<p data-rc-score="status"><span class="${ LIVE }">Second half</span></p>`
			);
			const status = block.querySelector< HTMLElement >(
				'[data-rc-score="status"]'
			);

			applyScore( block, score( 2, 1 ) );

			expect( status?.hidden ).toBe( true );
			expect( status?.textContent ).toBe( '' );
			expect( badge( block ) ).toBeNull();
		} );

		it( 'keeps the status line as text without the option', () => {
			const block = scoreboard( 2, 1 );

			applyScore( block, score( 2, 1, 'Full time', 'archived' ) );

			expect( badge( block ) ).toBeNull();
			expect( text( block, 'status' ) ).toBe( 'Full time' );
		} );
	} );
} );
