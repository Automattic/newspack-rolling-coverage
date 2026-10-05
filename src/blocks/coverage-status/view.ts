/**
 * Internal dependencies
 */
import { POLL_EVENT, type PollEventDetail } from '../shared/poll-event';
import {
	getFormatter,
	refreshRelativeDates,
	REFRESH_INTERVAL_MS,
} from '../shared/relative-dates';
import {
	BADGE_CLASSES,
	badgeClasses,
	badgeStatus,
} from '../shared/status-badges';
import './style.scss';

const ALL_MODIFIERS = Object.values( BADGE_CLASSES ).flatMap( ( classes ) =>
	classes.split( ' ' )
);

/**
 * Shows a poll's status and newest entry in a status block.
 *
 * @param {HTMLElement}     block  The block's wrapper.
 * @param {PollEventDetail} detail What the poll reported.
 */
function applyPoll( block: HTMLElement, detail: PollEventDetail ): void {
	const status = badgeStatus( detail.status );

	if (
		status === 'archived' &&
		block.hasAttribute( 'data-hide-when-ended' )
	) {
		block.remove();
		return;
	}

	const badge = block.querySelector< HTMLElement >( '.newspack-ui__badge' );
	const label = block.getAttribute( `data-label-${ status }` );

	if ( badge ) {
		badge.classList.remove( ...ALL_MODIFIERS );
		badge.classList.add(
			...badgeClasses(
				status,
				! block.hasAttribute( 'data-hide-dot' )
			).split( ' ' )
		);

		const style = block.getAttribute( `data-style-${ status }` );

		if ( style ) {
			badge.setAttribute( 'style', style );
		} else {
			badge.removeAttribute( 'style' );
		}

		if ( label !== null && badge.textContent !== label ) {
			badge.textContent = label;
		}
	}

	block.dataset.status = status;

	const updated = block.querySelector< HTMLElement >(
		'.newspack-rolling-coverage-updated'
	);
	const time = updated?.querySelector( 'time' );

	if ( updated && time ) {
		time.dateTime = detail.newestEntry ?? '';

		updated.hidden = status !== 'active' || ! time.dateTime;
	}
}

/**
 * The status blocks in the page. Looked up each time, since blocks in an
 * entry's content also reach the page after load, in entries a poll, load
 * more or the jump to the live feed brings in, and leave it with the entries
 * those replace or drop.
 *
 * @return {HTMLElement[]} The blocks' wrappers.
 */
function statusBlocks(): HTMLElement[] {
	return Array.from(
		document.querySelectorAll< HTMLElement >(
			'.wp-block-newspack-rolling-coverage-coverage-status[data-coverage-id]'
		)
	);
}

const formatter = getFormatter();
const refresh = () => {
	if ( formatter ) {
		statusBlocks().forEach( ( block ) =>
			refreshRelativeDates( block, formatter )
		);
	}
};

refresh();
window.setInterval( refresh, REFRESH_INTERVAL_MS );

document.addEventListener( POLL_EVENT, ( event ) => {
	const { detail } = event as CustomEvent< PollEventDetail >;

	statusBlocks()
		.filter(
			( block ) =>
				Number( block.dataset.coverageId ) === detail.coverageId
		)
		.forEach( ( block ) => applyPoll( block, detail ) );

	refresh();
} );
