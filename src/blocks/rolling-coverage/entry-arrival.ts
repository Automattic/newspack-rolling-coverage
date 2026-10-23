/**
 * Opens the space for elements inserted where a feed stacks its entries in a
 * column, so what sits below them glides down instead of jumping, and closes
 * it for entries a capped feed drops to make room.
 */

/**
 * Internal dependencies
 */
import {
	EASE_IN_OUT,
	EASE_OUT,
	ENTER_MS,
	LEAVE_MS,
	OPEN_FADE_DELAY_MS,
	OPEN_MS,
	prefersReducedMotion,
} from '../shared/motion';

type StackStyle = Pick<
	CSSStyleDeclaration,
	'display' | 'flexDirection' | 'rowGap'
>;

type Space = {
	height: number;
	gap: number;
	marginStart: number;
	marginEnd: number;
};

const opens = new WeakMap< Element, Animation >();
const fades = new WeakMap< Element, Animation >();

/**
 * The gap a layout leaves between the children it stacks in a column, or
 * null for one that places them otherwise (a row, a grid), where opening a
 * child's height wouldn't push the ones after it down.
 *
 * @param {StackStyle} style The layout's computed style.
 * @return {number | null} The gap in pixels, or null.
 */
export function stackGap( style: StackStyle ): number | null {
	const display = style.display || '';

	if ( display === 'block' || display === 'flow-root' ) {
		return 0;
	}

	if ( display.endsWith( 'flex' ) && style.flexDirection === 'column' ) {
		return parseFloat( style.rowGap ) || 0;
	}

	return null;
}

/**
 * Keyframes that open an element's space from nothing to its full size. The
 * end margin starts by cancelling the element's whole share of the layout,
 * its gap included, and the clip hides what the elements after it still
 * cover, so the clip's edge meets the next element's top on every frame.
 * The clip reaches a gap past the element's top and bottom, where its New
 * tint can reach, and well past its sides, so nothing it draws there is cut.
 *
 * @param {Space} space The element's size, gap and own margins.
 * @return {Object[]} The keyframes, closed to open.
 */
export function openKeyframes( space: Space ): Keyframe[] {
	const { height, gap, marginStart, marginEnd } = space;

	return [
		{
			marginBlockEnd: `${ -( marginStart + height + gap ) }px`,
			clipPath: `inset(${ -gap }px -100vw ${ marginStart + height }px)`,
		},
		{
			marginBlockEnd: `${ marginEnd }px`,
			clipPath: `inset(${ -gap }px -100vw ${ -( gap + marginEnd ) }px)`,
		},
	];
}

/**
 * The layout that places an element: its parent, or the nearest ancestor
 * that generates a box when the parent is `display: contents`.
 *
 * @param {Element} element The element.
 * @return {Element | null} The layout.
 */
export function layoutOf( element: Element ): Element | null {
	let parent = element.parentElement;

	while (
		parent &&
		window.getComputedStyle( parent ).display === 'contents'
	) {
		parent = parent.parentElement;
	}

	return parent;
}

/**
 * Whether a layout leaves a gap next to an element: only between in-flow
 * children that render.
 *
 * @param {Element} element The element.
 * @return {boolean} True when another in-flow child renders beside it.
 */
function hasInFlowSibling( element: Element ): boolean {
	return Array.from( element.parentElement?.children ?? [] ).some(
		( sibling ) =>
			sibling !== element &&
			sibling.getClientRects().length > 0 &&
			! [ 'absolute', 'fixed' ].includes(
				window.getComputedStyle( sibling ).position
			)
	);
}

/**
 * Measures the space an element takes in a column, or null when its layout
 * doesn't stack it in one.
 *
 * @param {HTMLElement} element The element.
 * @return {Space | null} The space.
 */
function spaceOf( element: HTMLElement ): Space | null {
	const layout = layoutOf( element );
	const gap = layout ? stackGap( window.getComputedStyle( layout ) ) : null;

	if ( gap === null ) {
		return null;
	}

	const style = window.getComputedStyle( element );

	return {
		height: element.getBoundingClientRect().height,
		gap: hasInFlowSibling( element ) ? gap : 0,
		marginStart: parseFloat( style.marginTop ) || 0,
		marginEnd: parseFloat( style.marginBottom ) || 0,
	};
}

/**
 * Whether the page can run the open and close: movement allowed, and
 * scripted animations supported.
 *
 * @param {HTMLElement} element An element to animate.
 * @return {boolean} True when it can.
 */
function canMove( element: HTMLElement ): boolean {
	return typeof element.animate === 'function' && ! prefersReducedMotion();
}

/**
 * Runs the open on an element, from a point in it when it carries on from
 * an element it replaced.
 *
 * @param {HTMLElement} element     The element.
 * @param {Space}       space       Its space.
 * @param {number}      currentTime Where to start, in milliseconds.
 */
function runOpen( element: HTMLElement, space: Space, currentTime = 0 ): void {
	const open = element.animate( openKeyframes( space ), {
		duration: OPEN_MS,
		easing: EASE_IN_OUT,
	} );
	open.currentTime = currentTime;
	opens.set( element, open );
	open.finished.then(
		() => opens.get( element ) === open && opens.delete( element ),
		() => {}
	);
}

/**
 * Runs the fade that goes with an open: it starts once the space is partly
 * open, so the entry comes in as its space opens rather than after.
 *
 * @param {HTMLElement} element     The entry.
 * @param {number}      currentTime Where to start, in milliseconds.
 */
function runFade( element: HTMLElement, currentTime = 0 ): void {
	const fade = element.animate( [ { opacity: 0 }, { opacity: 1 } ], {
		duration: ENTER_MS,
		delay: OPEN_FADE_DELAY_MS,
		easing: EASE_OUT,
		fill: 'backwards',
	} );
	fade.currentTime = currentTime;
	fades.set( element, fade );
	fade.finished.then(
		() => fades.get( element ) === fade && fades.delete( element ),
		() => {}
	);
}

/**
 * Opens the space of elements just inserted in a feed's entry list. An
 * element alone in a group inside the list, as the first entry of an empty
 * Pinned group, opens the group, which appeared with it. The entries given
 * to fade fade in as their space opens.
 *
 * Nothing opens under reduced motion, without scripted animations, or where
 * the layout doesn't stack the elements in a column (a row or a grid).
 *
 * @param {HTMLElement[]} elements Elements just inserted, entries and ads.
 * @param {HTMLElement[]} faded    The entries among them to fade in.
 * @param {HTMLElement}   list     The feed's entry list.
 * @return {HTMLElement[]} The entries left for the caller to fade in.
 */
export function openSpace(
	elements: HTMLElement[],
	faded: HTMLElement[],
	list: HTMLElement
): HTMLElement[] {
	if ( ! elements.length || ! canMove( elements[ 0 ] ) ) {
		return faded;
	}

	const targets = new Map< HTMLElement, HTMLElement >();

	elements.forEach( ( element ) => {
		const parent = element.parentElement;
		const target =
			parent && parent !== list && ! hasInFlowSibling( element )
				? parent
				: element;
		targets.set( element, target );
	} );

	const spaces = new Map< HTMLElement, Space | null >();
	new Set( targets.values() ).forEach( ( target ) =>
		spaces.set( target, spaceOf( target ) )
	);

	spaces.forEach( ( space, target ) => {
		if ( space ) {
			opens.get( target )?.cancel();
			runOpen( target, space );
		}
	} );

	return faded.filter( ( entry ) => {
		const target = targets.get( entry ) ?? entry;

		if ( ! spaces.get( target ) ) {
			return true;
		}

		runFade( entry );
		return false;
	} );
}

/**
 * Carries an element's open and fade over to the element that replaced it,
 * from where they were, so an edit mid-arrival doesn't snap.
 *
 * @param {HTMLElement} from The element that was replaced.
 * @param {HTMLElement} to   Its replacement, already in place.
 * @return {boolean} True when there was an arrival to carry.
 */
export function carryArrival( from: HTMLElement, to: HTMLElement ): boolean {
	const open = opens.get( from );
	const fade = fades.get( from );

	if ( ! open && ! fade ) {
		return false;
	}

	if ( open ) {
		const time = Number( open.currentTime ) || 0;
		const space = spaceOf( to );
		open.cancel();
		opens.delete( from );

		if ( space ) {
			runOpen( to, space, time );
		}
	}

	if ( fade ) {
		const time = Number( fade.currentTime ) || 0;
		fade.cancel();
		fades.delete( from );
		runFade( to, time );
	}

	return true;
}

/**
 * Stops an entry's arrival fade, for an entry that leaves mid-arrival. Its
 * open carries on until the entry goes.
 *
 * @param {HTMLElement} element The entry.
 * @return {number | null} The opacity the fade had reached, or null without one.
 */
export function stopArrivalFade( element: HTMLElement ): number | null {
	const fade = fades.get( element );

	if ( ! fade ) {
		return null;
	}

	const opacity = parseFloat( window.getComputedStyle( element ).opacity );
	fade.cancel();
	fades.delete( element );

	return Number.isNaN( opacity ) ? 1 : opacity;
}

/**
 * Closes an entry's space as it fades out, the way an open runs backwards,
 * then calls back to remove it. Returns false, without calling back, where
 * the space can't close smoothly, so the caller removes the entry at once.
 *
 * @param {HTMLElement} element The entry.
 * @param {Function}    done    Called once the space has closed.
 * @return {boolean} True when the space is closing.
 */
export function closeSpace( element: HTMLElement, done: () => void ): boolean {
	if ( ! canMove( element ) ) {
		return false;
	}

	const space = spaceOf( element );

	if ( ! space ) {
		return false;
	}

	opens.get( element )?.cancel();
	opens.delete( element );
	fades.get( element )?.cancel();
	fades.delete( element );

	const close = element.animate( openKeyframes( space ).reverse(), {
		duration: OPEN_MS,
		easing: EASE_IN_OUT,
		fill: 'forwards',
	} );
	element.animate( [ { opacity: 1 }, { opacity: 0 } ], {
		duration: LEAVE_MS,
		easing: EASE_OUT,
		fill: 'forwards',
	} );
	close.finished.then( done, done );

	return true;
}
