/**
 * Internal dependencies
 */
import { POLL_EVENT, type PollEventDetail } from '../shared/poll-event';
import {
	getFormatter,
	refreshRelativeDates,
	REFRESH_INTERVAL_MS,
} from '../shared/relative-dates';
import { BADGE_CLASSES, badgeStatus } from '../shared/status-badges';
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
	const badge = block.querySelector< HTMLElement >( '.newspack-ui__badge' );
	const label = block.getAttribute( `data-label-${ status }` );

	if ( badge ) {
		badge.classList.remove( ...ALL_MODIFIERS );
		badge.classList.add( ...BADGE_CLASSES[ status ].split( ' ' ) );

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

const blocks = Array.from(
	document.querySelectorAll< HTMLElement >(
		'.wp-block-newspack-rolling-coverage-coverage-status[data-coverage-id]'
	)
);

if ( blocks.length ) {
	const formatter = getFormatter();
	const refresh = () => {
		if ( formatter ) {
			blocks.forEach( ( block ) =>
				refreshRelativeDates( block, formatter )
			);
		}
	};

	refresh();
	window.setInterval( refresh, REFRESH_INTERVAL_MS );

	document.addEventListener( POLL_EVENT, ( event ) => {
		const { detail } = event as CustomEvent< PollEventDetail >;

		blocks
			.filter(
				( block ) =>
					Number( block.dataset.coverageId ) === detail.coverageId
			)
			.forEach( ( block ) => applyPoll( block, detail ) );

		refresh();
	} );
}
