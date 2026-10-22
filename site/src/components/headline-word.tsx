import { useEffect, useState } from 'react';

const WORDS = [ 'story', 'storm', 'trial', 'vote', 'story' ];
const START_MS = 1200;
const STEP_MS = 1200;

/**
 * The changing word in the homepage headline: it runs through a few kinds of story once
 * and settles back on "story". The whole run ends within five seconds of starting, so it
 * needs no pause control, and every word shares one grid cell sized to the widest, so a
 * swap never reflows the headline. The page's HTML and assistive technology only ever
 * carry "story"; the other words exist only while the run plays.
 */
export function HeadlineWord() {
	const [ animated, setAnimated ] = useState( false );
	const [ step, setStep ] = useState( 0 );

	useEffect( () => {
		if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return;
		}
		setAnimated( true );
		const timers = WORDS.slice( 1 ).map( ( _, i ) =>
			setTimeout( () => setStep( i + 1 ), START_MS + i * STEP_MS )
		);
		timers.push( setTimeout( () => setAnimated( false ), START_MS + ( WORDS.length - 2 ) * STEP_MS + 400 ) );
		return () => timers.forEach( clearTimeout );
	}, [] );

	if ( ! animated ) {
		return <span className="text-newspack-600 dark:text-newspack-300">story</span>;
	}

	return (
		<>
			<span className="sr-only">story</span>
			<span aria-hidden="true" className="inline-grid justify-items-start text-newspack-600 dark:text-newspack-300">
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
	);
}
