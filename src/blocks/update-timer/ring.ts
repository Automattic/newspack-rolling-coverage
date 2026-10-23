/**
 * Internal dependencies
 */
import { SPIN_MS, prefersReducedMotion } from '../shared/motion';
import type { CheckState } from '../shared/check-event';

export interface RingMotion {
	fill: Animation | null;
	fillKey: string;
	spin: Animation | null;
}

interface RingCheck {
	state: CheckState;
	nextCheckAt: number;
	interval: number;
}

const ARC_SELECTOR = '.newspack-rolling-coverage-update-timer__ring-arc';

/**
 * A timer's ring motion, with nothing running.
 *
 * @return {RingMotion} The motion.
 */
export function idleRing(): RingMotion {
	return { fill: null, fillKey: '', spin: null };
}

/**
 * Stops the countdown fill.
 *
 * @param {RingMotion} motion The ring's motion.
 * @return {void}
 */
function stopFill( motion: RingMotion ): void {
	motion.fill?.cancel();
	motion.fill = null;
	motion.fillKey = '';
}

/**
 * Stops the spin.
 *
 * @param {RingMotion} motion The ring's motion.
 * @return {void}
 */
function stopSpin( motion: RingMotion ): void {
	motion.spin?.cancel();
	motion.spin = null;
}

/**
 * Moves a timer's ring to its feed's check state. While waiting, the arc
 * fills at a constant rate over the wait, starting from the share already
 * gone, so it is full when the check is due. While checking, a quarter arc
 * spins, except for readers who ask for reduced motion, who see the full
 * ring hold still. Idle, as in a background tab or once the coverage stops,
 * runs nothing.
 *
 * @param {SVGSVGElement|null} ring   The ring, if the timer shows one.
 * @param {RingMotion}         motion What runs on it now.
 * @param {RingCheck}          check  The feed's check state.
 * @param {number}             now    The time, in epoch milliseconds.
 * @return {void}
 */
export function syncRing(
	ring: SVGSVGElement | null,
	motion: RingMotion,
	check: RingCheck,
	now: number = Date.now()
): void {
	const arc = ring?.querySelector< SVGElement >( ARC_SELECTOR );

	if ( ! ring || ! arc || typeof arc.animate !== 'function' ) {
		return;
	}

	if ( check.state !== 'checking' ) {
		stopSpin( motion );
	}

	if ( check.state !== 'waiting' ) {
		stopFill( motion );
	}

	if ( check.state === 'checking' ) {
		if ( ! motion.spin && ! prefersReducedMotion() ) {
			motion.spin = ring.animate(
				[
					{ transform: 'rotate(0deg)' },
					{ transform: 'rotate(360deg)' },
				],
				{ duration: SPIN_MS, iterations: Infinity, easing: 'linear' }
			);
		}

		return;
	}

	if ( check.state !== 'waiting' ) {
		return;
	}

	if ( check.interval <= 0 ) {
		stopFill( motion );
		return;
	}

	const key = `${ check.nextCheckAt }:${ check.interval }`;

	if ( motion.fill && motion.fillKey === key ) {
		return;
	}

	stopFill( motion );
	motion.fill = arc.animate(
		[ { strokeDashoffset: 100 }, { strokeDashoffset: 0 } ],
		{ duration: check.interval, easing: 'linear', fill: 'forwards' }
	);
	motion.fill.currentTime = Math.min(
		check.interval,
		Math.max( 0, check.interval - ( check.nextCheckAt - now ) )
	);
	motion.fillKey = key;
}
