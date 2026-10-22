import { Fragment, useEffect, useRef, useState, type ReactNode } from 'react';

const BEFORE = [ 'Cover', 'a', 'developing' ];
const AFTER = [ 'as', 'it', 'happens' ];
const WORDS = [ 'story', 'flood', 'trial', 'vote', 'story' ];
const START_MS = 1200;
const STEP_MS = 1200;
const SLOT = BEFORE.length;

const wordClassName = 'text-newspack-600 dark:text-newspack-300';

/**
 * The homepage headline, whose blue word runs once per visit through a few kinds of story
 * and settles back on "story". The whole run ends within five seconds of starting, so it
 * needs no pause control. The word's slot eases to each word's own width, so the rest of
 * the headline follows it instead of leaving a gap. A narrower word would let the following
 * words move up a line, so the run holds the headline's line breaks where they were when it
 * started. The page's HTML and assistive technology only ever carry "story"; the other
 * words exist only while the run plays.
 */
export function Headline() {
	const [ lines, setLines ] = useState< number[][] >();
	const [ step, setStep ] = useState( 0 );
	const [ width, setWidth ] = useState< number >();
	const tokens = useRef< ( HTMLSpanElement | null )[] >( [] );
	const words = useRef< ( HTMLSpanElement | null )[] >( [] );

	useEffect( () => {
		if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return;
		}
		try {
			if ( sessionStorage.getItem( 'rc-headline' ) ) {
				return;
			}
			sessionStorage.setItem( 'rc-headline', '1' );
		} catch {}
		let cancelled = false;
		const timers: ReturnType< typeof setTimeout >[] = [];
		document.fonts.ready.then( () => {
			if ( cancelled ) {
				return;
			}
			const grouped: number[][] = [];
			let top: number | undefined;
			tokens.current.forEach( ( token, i ) => {
				const offset = token?.offsetTop ?? 0;
				if ( top === undefined || offset > top ) {
					grouped.push( [] );
					top = offset;
				}
				grouped[ grouped.length - 1 ].push( i );
			} );
			setLines( grouped );
			WORDS.slice( 1 ).forEach( ( _, i ) =>
				timers.push( setTimeout( () => setStep( i + 1 ), START_MS + i * STEP_MS ) )
			);
			timers.push( setTimeout( () => setLines( undefined ), START_MS + ( WORDS.length - 2 ) * STEP_MS + 400 ) );
		} );
		return () => {
			cancelled = true;
			timers.forEach( clearTimeout );
		};
	}, [] );

	useEffect( () => {
		if ( lines ) {
			setWidth( words.current[ step ]?.offsetWidth );
		}
	}, [ lines, step ] );

	const all = [ ...BEFORE, 'story', ...AFTER ];

	if ( ! lines ) {
		return (
			<>
				{ all.map( ( token, i ) => (
					<Fragment key={ i }>
						{ i > 0 && ' ' }
						<span
							ref={ ( node ) => {
								tokens.current[ i ] = node;
							} }
							className={ i === SLOT ? wordClassName : undefined }
						>
							{ token }
						</span>
					</Fragment>
				) ) }
			</>
		);
	}

	const slot: ReactNode = (
		<>
			<span className="sr-only">story</span>
			<span
				aria-hidden="true"
				className={ `relative inline-block transition-[width] duration-300 ease-in-out-strong motion-reduce:transition-none ${ wordClassName }` }
				style={ { width } }
			>
				<span className="invisible">story</span>
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
							ref={ ( node ) => {
								words.current[ i ] = node;
							} }
							className={ `absolute top-0 left-0 transition-[opacity,translate] duration-300 ease-out-strong motion-reduce:transition-none ${ state }` }
						>
							{ word }
						</span>
					);
				} ) }
			</span>
		</>
	);

	return (
		<>
			{ lines.map( ( line, l ) => (
				<Fragment key={ l }>
					{ l > 0 && <br /> }
					<span className="whitespace-nowrap">
						{ line.map( ( i, n ) => (
							<Fragment key={ i }>
								{ n > 0 && ' ' }
								{ i === SLOT ? slot : all[ i ] }
							</Fragment>
						) ) }
					</span>
				</Fragment>
			) ) }
		</>
	);
}
