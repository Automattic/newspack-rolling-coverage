import { useEffect, useState } from 'react';

const WORDS = [ 'story', 'flood', 'trial', 'vote', 'game' ];
const STEP_MS = 2500;

const wordClassName =
	'inline-grid min-w-[2.6em] justify-items-start text-newspack-600 dark:text-newspack-300';

/**
 * The homepage headline, whose last word cycles through a few kinds of story while the
 * homepage is open, and holds while the pointer is over the headline. With reduced motion
 * it stays on "story". The slot is as wide as the widest word from the first paint, and
 * nothing follows it, so a swap never moves or rewraps the headline. The page's HTML and
 * assistive technology only ever carry "story".
 */
export function Headline() {
	const [ motion, setMotion ] = useState( false );
	const [ hovered, setHovered ] = useState( false );
	const [ step, setStep ] = useState( 0 );

	useEffect( () => {
		if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return;
		}
		let cancelled = false;
		document.fonts.ready.then( () => {
			if ( ! cancelled ) {
				setMotion( true );
			}
		} );
		return () => {
			cancelled = true;
		};
	}, [] );

	useEffect( () => {
		if ( ! motion || hovered ) {
			return;
		}
		const timer = setInterval( () => setStep( ( current ) => ( current + 1 ) % WORDS.length ), STEP_MS );
		return () => clearInterval( timer );
	}, [ motion, hovered ] );

	const previous = ( step + WORDS.length - 1 ) % WORDS.length;

	return (
		<span onMouseEnter={ () => setHovered( true ) } onMouseLeave={ () => setHovered( false ) }>
			Cover it live, whatever the{ ' ' }
			{ motion ? (
				<>
					<span className="sr-only">story</span>
					<span aria-hidden="true" className={ wordClassName }>
						{ WORDS.map( ( word, i ) => {
							let state = 'translate-y-[35%] opacity-0';
							if ( i === step ) {
								state = 'translate-y-0 opacity-100';
							} else if ( i === previous ) {
								state = '-translate-y-[35%] opacity-0';
							}
							return (
								<span
									key={ word }
									className={ `col-start-1 row-start-1 transition-[opacity,translate] duration-300 ease-out-strong motion-reduce:transition-none ${ state }` }
								>
									{ word }
								</span>
							);
						} ) }
					</span>
				</>
			) : (
				<span className={ wordClassName }>story</span>
			) }
		</span>
	);
}
