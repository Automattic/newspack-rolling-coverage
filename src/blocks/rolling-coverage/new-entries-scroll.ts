/**
 * Takes the reader to the entries the new-entries control just brought in:
 * the page glides to the topmost of them, and their arrival plays once it
 * gets there.
 */

/**
 * Internal dependencies
 */
import { EASE_IN_OUT, OPEN_MS, prefersReducedMotion } from '../shared/motion';

const STOP_EVENTS = [ 'wheel', 'touchstart', 'pointerdown', 'keydown' ];

/**
 * Where the page scrolls to show entries the reader just added: the topmost
 * of them, `offset` pixels below the viewport's top, so it lands clear of
 * the bars over it, whatever sits above it in the feed.
 *
 * An entry in a sticky card doesn't count: while the card is stuck, the
 * entry reads where the card is held rather than where it sits. With no
 * entry that counts, the page goes to the block's top.
 *
 * @param {HTMLElement}   block  The block.
 * @param {HTMLElement[]} added  The added entries.
 * @param {HTMLElement[]} cards  The block's sticky cards.
 * @param {number}        offset Space to leave above the entry, in pixels.
 * @return {{top: number, entry: HTMLElement|null}} The scroll position, and the entry it lands on, or null for the block's top.
 */
export function newEntriesTarget(
	block: HTMLElement,
	added: HTMLElement[],
	cards: HTMLElement[],
	offset: number
): { top: number; entry: HTMLElement | null } {
	const pageTop = ( element: HTMLElement ) =>
		element.getBoundingClientRect().top + window.scrollY - offset;
	let target: { top: number; entry: HTMLElement | null } = {
		top: Infinity,
		entry: null,
	};

	added.forEach( ( entry ) => {
		if (
			! entry.isConnected ||
			cards.some( ( card ) => card.contains( entry ) )
		) {
			return;
		}

		const top = pageTop( entry );

		if ( top < target.top ) {
			target = { top, entry };
		}
	} );

	return target.entry ? target : { top: pageTop( block ), entry: null };
}

/**
 * Pauses the animations `insert` starts inside `scope`, such as the
 * entries' arrival, and returns a function that plays them. A paused
 * animation holds its first frame, so an entry whose space opens stays
 * closed and transparent until then.
 *
 * @param {Element}  scope  Where to look for the animations.
 * @param {Function} insert Inserts the elements.
 * @return {Function} Plays the held animations.
 */
export function holdAnimations(
	scope: Element,
	insert: () => void
): () => void {
	if ( typeof document.getAnimations !== 'function' ) {
		insert();
		return () => {};
	}

	const running = new Set( document.getAnimations() );

	insert();

	const held = document.getAnimations().filter( ( animation ) => {
		const target = ( animation.effect as KeyframeEffect | null )?.target;

		return (
			! running.has( animation ) && !! target && scope.contains( target )
		);
	} );

	held.forEach( ( animation ) => animation.pause() );

	return () =>
		held.forEach(
			( animation ) =>
				animation.playState === 'paused' && animation.play()
		);
}

/**
 * Scrolls the window to a position, moving like an entry's space opening
 * (`OPEN_MS` on `EASE_IN_OUT`), then calls back. Under reduced motion, or
 * where the browser can't time it, it jumps there at once. A reader who
 * scrolls, touches, clicks or presses a key on the way stops it where it
 * is, which also calls back.
 *
 * @param {number}   top  The scroll position.
 * @param {Function} done Called once the scroll ends or stops.
 * @return {Function} Stops the scroll.
 */
export function glideScrollTo( top: number, done: () => void ): () => void {
	const from = window.scrollY;
	let stopped = false;
	let timing: Animation | null = null;

	const stop = () => {
		if ( stopped ) {
			return;
		}

		stopped = true;
		STOP_EVENTS.forEach( ( type ) =>
			window.removeEventListener( type, stop )
		);
		timing?.cancel();
		done();
	};

	if (
		prefersReducedMotion() ||
		typeof Animation !== 'function' ||
		typeof KeyframeEffect !== 'function' ||
		Math.abs( top - from ) < 1
	) {
		window.scrollTo( { top, behavior: 'instant' } );
		stop();
		return stop;
	}

	// A blank effect gives the token curve's progress without a hand-rolled
	// copy of it.
	const curve = new Animation(
		new KeyframeEffect( null, null, {
			duration: OPEN_MS,
			easing: EASE_IN_OUT,
			fill: 'forwards',
		} )
	);
	timing = curve;

	const step = () => {
		if ( stopped ) {
			return;
		}

		const progress = curve.effect?.getComputedTiming().progress ?? 0;

		window.scrollTo( {
			top: from + ( top - from ) * progress,
			behavior: 'instant',
		} );

		if ( curve.playState === 'finished' ) {
			stop();
		} else {
			window.requestAnimationFrame( step );
		}
	};

	STOP_EVENTS.forEach( ( type ) =>
		window.addEventListener( type, stop, { passive: true } )
	);
	curve.play();
	window.requestAnimationFrame( step );

	return stop;
}
