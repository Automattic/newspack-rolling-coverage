export const MOTION_QUERY = '(prefers-reduced-motion: no-preference)';

export const ENTER_MS = 200;
export const OPEN_MS = 250;
export const OPEN_MAX_MS = 450;
export const OPEN_BASE_PX = 240;
export const OPEN_PX_PER_MS = 4;
export const OPEN_FADE_DELAY_MS = 100;
export const CLEAR_MS = 250;
export const COLLAPSE_MS = 200;
export const COLLAPSE_DELAY_MS = 125;
export const EXIT_MS = 120;
export const LEAVE_MS = 150;
export const HOLD_MS = 4000;
export const FADE_CLEANUP_MS = 500;
export const SPIN_MS = 900;

export const EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)';
export const EASE_IN_OUT = 'cubic-bezier(0.77, 0, 0.175, 1)';

/**
 * Whether the reader asked for less movement. Opacity and color fades run
 * either way; drops, scales and smooth scrolling only when this is false.
 *
 * @return {boolean} True when movement should be skipped.
 */
export function prefersReducedMotion(): boolean {
	return ! window.matchMedia( MOTION_QUERY ).matches;
}

/**
 * Whether an element's own animation has yet to end: one running, including
 * one waiting for a frame, as in a background tab, or one paused, as the
 * new-entries scroll holds an arrival. Animations that never end don't
 * count.
 *
 * @param {Element} element The element.
 * @return {boolean} True while such an animation is on the element.
 */
export function isAnimating( element: Element ): boolean {
	if ( typeof element.getAnimations !== 'function' ) {
		return false;
	}

	return element
		.getAnimations()
		.some(
			( animation ) =>
				( animation.playState === 'running' ||
					animation.playState === 'paused' ) &&
				Number.isFinite(
					Number( animation.effect?.getComputedTiming().endTime )
				)
		);
}
