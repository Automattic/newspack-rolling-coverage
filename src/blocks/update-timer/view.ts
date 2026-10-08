/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	CHECK_EVENT,
	readCheck,
	type CheckEventDetail,
	type CheckResult,
} from '../shared/check-event';
import {
	nextCheckLabel,
	newEntriesFoundLabel,
	readEntryName,
} from '../rolling-coverage/entry-name';
import './style.scss';

const TIMER_SELECTOR =
	'.wp-block-newspack-rolling-coverage-update-timer[data-coverage-id]';
const FEED_SELECTOR = '.wp-block-newspack-rolling-coverage-rolling-coverage';
const RESULT_MS = 3000;
const TICK_MS = 250;

interface TimerState {
	feed: HTMLElement | null;
	tickId: ReturnType< typeof setInterval > | null;
	resultText: string;
	resultUntil: number;
}

const states = new WeakMap< HTMLElement, TimerState >();

/**
 * The timers on the page.
 *
 * @return {HTMLElement[]} Their wrappers.
 */
function timers(): HTMLElement[] {
	return Array.from(
		document.querySelectorAll< HTMLElement >( TIMER_SELECTOR )
	);
}

/**
 * A timer's state, created on first use.
 *
 * @param {HTMLElement} timer The timer's wrapper.
 * @return {TimerState} Its state.
 */
function stateOf( timer: HTMLElement ): TimerState {
	let state = states.get( timer );

	if ( ! state ) {
		state = { feed: null, tickId: null, resultText: '', resultUntil: 0 };
		states.set( timer, state );
	}

	return state;
}

/**
 * Picks the feed a timer follows: the one around it when it is for the same
 * coverage, else the one it already follows while that isn't idle and isn't
 * capped, else the first feed for its coverage on the page that isn't idle,
 * preferring an uncapped one as the server's Automatic mode does.
 *
 * @param {HTMLElement} timer The timer's wrapper.
 * @param {TimerState}  state Its state.
 * @return {void}
 */
function chooseFeed( timer: HTMLElement, state: TimerState ): void {
	const own = timer.parentElement?.closest< HTMLElement >( FEED_SELECTOR );

	if ( own && own.dataset.coverageId === timer.dataset.coverageId ) {
		state.feed = own;
		return;
	}

	if (
		state.feed?.isConnected &&
		! state.feed.dataset.latest &&
		readCheck( state.feed ).state !== 'idle'
	) {
		return;
	}

	const candidates = Array.from(
		document.querySelectorAll< HTMLElement >( FEED_SELECTOR )
	).filter(
		( feed ) =>
			feed.dataset.coverageId === timer.dataset.coverageId &&
			readCheck( feed ).state !== 'idle'
	);

	state.feed =
		candidates.find( ( feed ) => ! feed.dataset.latest ) ??
		candidates[ 0 ] ??
		null;
}

/**
 * The text for a check's result, or nothing when the check found nothing
 * new: readers see that in the feed, so the countdown carries on.
 *
 * @param {CheckResult} result What the check found.
 * @param {HTMLElement} feed   The feed that ran it, which carries the entry name.
 * @return {string} The text, or an empty string.
 */
function resultLabel( result: CheckResult, feed: HTMLElement ): string {
	if ( result.outcome === 'failed' ) {
		return __( 'Couldn’t check', 'newspack-rolling-coverage' );
	}

	return result.added > 0
		? newEntriesFoundLabel( result.added, readEntryName( feed ) )
		: '';
}

/**
 * Stops a timer's tick.
 *
 * @param {TimerState} state The timer's state.
 * @return {void}
 */
function stopTick( state: TimerState ): void {
	if ( state.tickId !== null ) {
		clearInterval( state.tickId );
		state.tickId = null;
	}
}

/**
 * Draws a timer from its feed's state: hidden while the feed isn't counting
 * down, "Checking…" while it checks, and otherwise the seconds to the next
 * check, or the last result for a moment. The spinner turns throughout.
 *
 * @param {HTMLElement} timer The timer's wrapper.
 * @return {void}
 */
function render( timer: HTMLElement ): void {
	const state = stateOf( timer );
	const check = state.feed?.isConnected
		? readCheck( state.feed )
		: { state: 'idle' as const, nextCheckAt: 0 };
	const text = timer.querySelector< HTMLElement >(
		'.newspack-rolling-coverage-update-timer__text'
	);

	stopTick( state );
	timer.dataset.checkState = check.state;
	timer.hidden = check.state === 'idle';

	if ( ! text || check.state === 'idle' ) {
		return;
	}

	if ( check.state === 'checking' ) {
		state.resultText = '';
		state.resultUntil = 0;
		text.textContent = __( 'Checking…', 'newspack-rolling-coverage' );
		return;
	}

	const { nextCheckAt } = check;
	const tick = () => {
		if ( ! timer.isConnected ) {
			stopTick( state );
			return;
		}

		const label =
			Date.now() < state.resultUntil
				? state.resultText
				: nextCheckLabel(
						Math.max(
							0,
							Math.ceil( ( nextCheckAt - Date.now() ) / 1000 )
						)
					);

		if ( text.textContent !== label ) {
			text.textContent = label;
		}
	};

	tick();
	state.tickId = setInterval( tick, TICK_MS );
}

timers().forEach( ( timer ) => {
	chooseFeed( timer, stateOf( timer ) );
	render( timer );
} );

document.addEventListener( CHECK_EVENT, ( event ) => {
	const { detail } = event as CustomEvent< CheckEventDetail >;

	timers().forEach( ( timer ) => {
		const state = stateOf( timer );
		const before = state.feed;

		chooseFeed( timer, state );

		if ( state.feed !== detail.feed && state.feed === before ) {
			return;
		}

		if ( state.feed !== before ) {
			state.resultText = '';
			state.resultUntil = 0;
		}

		if ( state.feed === detail.feed && detail.result ) {
			state.resultText = resultLabel( detail.result, detail.feed );
			state.resultUntil = state.resultText ? Date.now() + RESULT_MS : 0;
		}

		render( timer );
	} );
} );
