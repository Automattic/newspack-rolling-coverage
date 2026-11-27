/**
 * A poll replaces the summary card only when the server's markers say the
 * Summary changed, whatever the browser did to the card's text.
 */
import { applySummaryCard } from '../../src/blocks/rolling-coverage/summary-card';

const SUMMARY = {
	id: 7,
	modified: '2026-11-17 09:27:20',
	updated: 'Updated 9:27 am',
	html: '<div class="newspack-rolling-coverage-summary-card" data-rc-summary="7" data-modified-gmt="2026-11-17 09:27:20" data-rc-updated="Updated 9:27 am"><h3>What we know</h3></div>',
};

function parse( html: string ): HTMLElement | null {
	const template = document.createElement( 'template' );
	template.innerHTML = html;

	return template.content.firstElementChild as HTMLElement | null;
}

function shownCard(): HTMLElement {
	document.body.innerHTML = SUMMARY.html;

	return document.body.firstElementChild as HTMLElement;
}

test( 'a card whose text the browser changed stays while its markers match', () => {
	const card = shownCard();
	card.querySelector( 'h3' )!.textContent = 'Ce que nous savons';

	expect( applySummaryCard( card, SUMMARY, parse ) ).toBe( false );
	expect( document.body.firstElementChild ).toBe( card );
} );

test( 'a card is replaced when its Updated line now reads differently', () => {
	const card = shownCard();

	expect(
		applySummaryCard(
			card,
			{ ...SUMMARY, updated: 'Updated November 17, 2026, 9:27 am' },
			parse
		)
	).toBe( true );
	expect( document.body.firstElementChild ).not.toBe( card );
} );

test( 'a card is replaced when the Summary was edited', () => {
	const card = shownCard();

	expect(
		applySummaryCard(
			card,
			{ ...SUMMARY, modified: '2026-11-17 10:00:00' },
			parse
		)
	).toBe( true );
} );

test( 'a Summary taken down leaves the hidden placeholder', () => {
	shownCard();

	applySummaryCard(
		document.body.firstElementChild as HTMLElement,
		null,
		parse
	);

	const placeholder = document.body.firstElementChild as HTMLElement;
	expect( placeholder.getAttribute( 'data-rc-summary' ) ).toBe( '0' );
	expect( placeholder.hidden ).toBe( true );
} );

test( 'a card a poll brings says how long ago as it shows', () => {
	document.documentElement.lang = 'en';
	const card = shownCard();
	const twoHoursAgo = new Date( Date.now() - 2 * 3600000 ).toISOString();

	applySummaryCard(
		card,
		{
			...SUMMARY,
			modified: '2026-11-17 10:00:00',
			html: `<div class="newspack-rolling-coverage-summary-card" data-rc-summary="7" data-modified-gmt="2026-11-17 10:00:00" data-rc-updated="Updated 10:00 am"><p>Updated <time datetime="${ twoHoursAgo }" data-rc-relative>1 min ago</time></p></div>`,
		},
		parse
	);

	expect( document.body.querySelector( 'time' )!.textContent ).toBe(
		'2 hours ago'
	);
} );
