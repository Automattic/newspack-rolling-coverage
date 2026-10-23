export const MOTION_QUERY = '(prefers-reduced-motion: no-preference)';

export const ENTER_MS = 200;
export const CLEAR_MS = 250;
export const COLLAPSE_MS = 200;
export const EXIT_MS = 120;
export const LEAVE_MS = 150;
export const HOLD_MS = 4000;
export const FADE_CLEANUP_MS = 500;

export const EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)';

/**
 * Whether the reader asked for less movement. Opacity and color fades run
 * either way; drops, scales and smooth scrolling only when this is false.
 *
 * @return {boolean} True when movement should be skipped.
 */
export function prefersReducedMotion(): boolean {
	return ! window.matchMedia( MOTION_QUERY ).matches;
}
