import { useEffect, useState } from 'react';

const WORDS = [ 'story', 'flood', 'trial', 'vote', 'game', 'story' ];
const START_MS = 1200;
const STEP_MS = 1000;

const wordClassName =
	'inline-grid min-w-[2.6em] justify-items-start text-newspack-600 dark:text-newspack-300';

/**
 * The homepage headline, whose last word runs through a few kinds of story each time the
 * homepage opens, then settles back on "story". The whole run ends within five seconds of
 * starting, so it needs no pause control. The word's slot is as wide as the widest word
 * from the first paint, and nothing follows it, so a swap never moves or rewraps the
 * headline. The page's HTML and assistive technology only ever carry "story"; the other
 * words exist only while the run plays.
 */
export function Headline() {
	const [ animated, setAnimated ] = useState( false );
	const [ step, setStep ] = useState( 0 );

	useEffect( () => {
		if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return;
		}
		let cancelled = false;
		const timers: ReturnType< typeof setTimeout >[] = [];
		document.fonts.ready.then( () => {
			if ( cancelled ) {
				return;
			}
			setAnimated( true );
			WORDS.slice( 1 ).forEach( ( _, i ) =>
				timers.push( setTimeout( () => setStep( i + 1 ), START_MS + i * STEP_MS ) )
			);
			timers.push( setTimeout( () => setAnimated( false ), START_MS + ( WORDS.length - 2 ) * STEP_MS + 400 ) );
		} );
		return () => {
			cancelled = true;
			timers.forEach( clearTimeout );
		};
	}, [] );

	return (
		<>
			Cover it live, whatever the{ ' ' }
			{ animated ? (
				<>
					<span className="sr-only">story</span>
					<span aria-hidden="true" className={ wordClassName }>
						{ WORDS.map( ( word, i ) => {
							let state = 'translate-y-[35%] opacity-0';
							if ( i === step ) {
								state = 'translate-y-0 opacity-100';
							} else if ( i < step ) {
								state = '-translate-y-[35%] opacity-0';
							}
							return (
								<span
									key={ i }
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
		</>
	);
}
