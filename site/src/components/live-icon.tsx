import { useEffect, useRef, useState, type ComponentProps } from 'react';

const DOT = 'M12 10A2 2 0 1 1 12 14A2 2 0 1 1 12 10ZM12 11.5A0.5 0.5 0 1 0 12 12.5A0.5 0.5 0 1 0 12 11.5Z';
const INNER_ARCS =
	'M15.536 8.464A5 5 0 0 1 15.536 15.536L14.475 14.475A3.5 3.5 0 0 0 14.475 9.525ZM8.464 15.536A5 5 0 0 1 8.464 8.464L9.525 9.525A3.5 3.5 0 0 0 9.525 14.475Z';
const OUTER_ARCS =
	'M17.657 6.343A8 8 0 0 1 17.657 17.657L16.596 16.596A6.5 6.5 0 0 0 16.596 7.404ZM6.343 17.657A8 8 0 0 1 6.343 6.343L7.404 7.404A6.5 6.5 0 0 0 7.404 16.596Z';

/**
 * The live-streaming icon from newspack-icons, used as the Rolling Coverage mark.
 *
 * With `onAir`, the icon lights from the centre out: once on the first page of a visit
 * (the `rc-intro` class the layout sets, removed once the intro finishes so it can't
 * outrank a hover), and again when the pointer enters the link around it. A replay
 * ignores further hovers until it has finished, so moving in and out quickly doesn't
 * restart it.
 */
export function LiveIcon( {
	onAir = false,
	className = '',
	...props
}: ComponentProps< 'svg' > & { onAir?: boolean } ) {
	const ref = useRef< SVGSVGElement >( null );
	const [ replaying, setReplaying ] = useState( false );

	useEffect( () => {
		const icon = ref.current;
		const link = icon?.closest( 'a' );
		if ( ! onAir || ! icon || ! link ) {
			return;
		}
		if ( document.documentElement.classList.contains( 'rc-intro' ) ) {
			Promise.all( icon.getAnimations( { subtree: true } ).map( ( animation ) => animation.finished ) )
				.then( () => document.documentElement.classList.remove( 'rc-intro' ) )
				.catch( () => {} );
		}
		const canHover = window.matchMedia( '(hover: hover) and (pointer: fine)' );
		const reduce = window.matchMedia( '(prefers-reduced-motion: reduce)' );
		const replay = () => {
			if ( canHover.matches && ! reduce.matches ) {
				setReplaying( true );
			}
		};
		link.addEventListener( 'mouseenter', replay );
		return () => link.removeEventListener( 'mouseenter', replay );
	}, [ onAir ] );

	return (
		<svg
			ref={ ref }
			aria-hidden="true"
			viewBox="0 0 24 24"
			fill="currentColor"
			className={ `${ onAir ? 'rc-live-icon--on-air' : '' } ${ replaying ? 'rc-live-icon--replay' : '' } ${ className }` }
			onAnimationEnd={ ( event ) => {
				if (
					event.animationName === 'rc-on-air-replay' &&
					( event.target as Element ).getAttribute( 'data-part' ) === 'outer'
				) {
					setReplaying( false );
				}
			} }
			{ ...props }
		>
			<path d={ DOT } />
			<path d={ INNER_ARCS } />
			<path data-part="outer" d={ OUTER_ARCS } />
		</svg>
	);
}
