import { useEffect, useRef, useState } from 'react';
import { Icon, pause, play, rotateLeft } from '@wordpress/icons';
import { Button } from '@/components/ui/button';

const DESCRIPTION =
	'A liveblog of a river flood on a news site, below the site’s masthead: a pinned entry from the county’s emergency management director at the top, then timestamped entries along a rail, including a Full story card with a photo. A new entry, “Tetanus shots at the fairgrounds shelter”, arrives below the pinned one with a New label, which fades after a few seconds.';

type Status = 'idle' | 'playing' | 'paused' | 'ended';

const LABELS = { idle: 'Play video', playing: 'Pause video', paused: 'Play video', ended: 'Replay video' };
const ICONS = { idle: play, playing: pause, paused: play, ended: rotateLeft };

export function HeroVideo() {
	const ref = useRef< HTMLVideoElement >( null );
	const timer = useRef< ReturnType< typeof setTimeout > >( undefined );
	const touched = useRef( false );
	const [ status, setStatus ] = useState< Status >( 'idle' );

	useEffect( () => {
		const node = ref.current;
		if ( ! node || window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return;
		}
		const observer = new IntersectionObserver(
			( [ entry ] ) => {
				// A window shorter than the video can never show 90% of it, so a view that fills most of the window counts too.
				const inView =
					entry.intersectionRatio >= 0.9 || entry.intersectionRect.height >= window.innerHeight * 0.9;
				if ( inView ) {
					observer.disconnect();
					timer.current = setTimeout( () => {
						if ( ! touched.current ) {
							node.play().catch( () => {} );
						}
					}, 400 );
				}
			},
			{ threshold: Array.from( { length: 21 }, ( _, index ) => index / 20 ) }
		);
		observer.observe( node );
		return () => {
			observer.disconnect();
			clearTimeout( timer.current );
		};
	}, [] );

	const toggle = () => {
		const node = ref.current;
		if ( ! node ) {
			return;
		}
		touched.current = true;
		clearTimeout( timer.current );
		if ( status === 'playing' ) {
			node.pause();
			return;
		}
		if ( status === 'ended' ) {
			node.currentTime = 0;
		}
		node.play().catch( () => {} );
	};

	return (
		<div className="relative">
			<video
				ref={ ref }
				muted
				playsInline
				preload="metadata"
				poster="/images/home/live-feed.webp"
				width={ 3024 }
				height={ 1964 }
				aria-label={ DESCRIPTION }
				className="h-auto w-full rounded-(--newspack-ui-radius-surface) border border-border"
				onPlay={ () => setStatus( 'playing' ) }
				onPause={ () => setStatus( ref.current?.ended ? 'ended' : 'paused' ) }
				onEnded={ () => setStatus( 'ended' ) }
			>
				<source media="(max-width: 781px)" src="/images/home/live-feed-1512.mp4" type="video/mp4" />
				<source src="/images/home/live-feed.mp4" type="video/mp4" />
			</video>
			<Button
				type="button"
				size="icon"
				variant="default"
				aria-label={ LABELS[ status ] }
				onClick={ toggle }
				className="[--btn-bg:var(--newspack-ui-color-overlay-80)] [--btn-bg-hover:var(--newspack-ui-color-overlay-90)] [--btn-bg-pressed:var(--newspack-ui-color-overlay-100)] dark:[--btn-ink:var(--newspack-ui-color-neutral-100)] dark:[--btn-focus:var(--newspack-ui-color-neutral-100)] dark:hover:text-(color:--newspack-ui-color-neutral-100) absolute right-(--newspack-ui-spacer-3) bottom-(--newspack-ui-spacer-3) rounded-full"
			>
				<Icon icon={ ICONS[ status ] } size={ 20 } />
			</Button>
		</div>
	);
}
