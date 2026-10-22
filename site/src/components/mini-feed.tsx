import { useEffect, useRef, useState } from 'react';

const NEW_ENTRY = 'Power back for 4,000 homes';

const ENTRIES = [
	{ time: '5:27 pm', title: 'Harbor Bridge closed as gusts top 70 mph' },
	{ time: '5:17 pm', title: 'Shelters open at three schools' },
	{ time: '5:08 pm', title: 'Flood warning lifted for Lower Quay' },
];

function Feed( { arrived }: { arrived: boolean } ) {
	return (
		<div className="w-full max-w-[16rem] rounded-xl border border-fd-border bg-fd-card text-sm">
			<div
				className={ `grid transition-[grid-template-rows] duration-400 ease-out-strong motion-reduce:transition-none ${
					arrived ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'
				}` }
			>
				<div className="overflow-hidden">
					<div
						className={ `flex gap-3 rounded-t-xl border-b border-fd-border px-3.5 py-3 transition-[opacity,translate] delay-[120ms] duration-300 ease-out-strong motion-reduce:transition-none ${
							arrived ? 'rc-arrived translate-y-0 opacity-100' : '-translate-y-1 opacity-0'
						}` }
					>
						<span className="flex w-16 shrink-0 items-center gap-1.5 text-xs font-medium whitespace-nowrap text-fd-primary tabular-nums">
							<span aria-hidden="true" className="size-1.5 rounded-full bg-fd-primary" />
							5:36 pm
						</span>
						<span className="font-medium text-fd-foreground">{ NEW_ENTRY }</span>
					</div>
				</div>
			</div>
			{ ENTRIES.map( ( entry, i ) => (
				<div
					key={ entry.time }
					className={ `flex gap-3 px-3.5 py-3 ${ i < ENTRIES.length - 1 ? 'border-b border-fd-border' : '' }` }
				>
					<span className="w-16 shrink-0 text-xs whitespace-nowrap text-fd-muted-foreground tabular-nums">
						{ entry.time }
					</span>
					<span className="text-fd-foreground">{ entry.title }</span>
				</div>
			) ) }
		</div>
	);
}

/**
 * A small feed in which one new entry arrives at the top, once, when it scrolls into view.
 * An invisible copy of the finished feed holds its final height, so the entry's arrival
 * never pushes the rest of the page down.
 */
export function MiniFeed() {
	const ref = useRef< HTMLDivElement >( null );
	const [ arrived, setArrived ] = useState( false );

	useEffect( () => {
		const node = ref.current;
		if ( ! node ) {
			return;
		}
		if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			setArrived( true );
			return;
		}
		let timer: ReturnType< typeof setTimeout >;
		const observer = new IntersectionObserver(
			( [ entry ] ) => {
				if ( entry.isIntersecting ) {
					timer = setTimeout( () => setArrived( true ), 700 );
					observer.disconnect();
				}
			},
			{ threshold: 0.6 }
		);
		observer.observe( node );
		return () => {
			observer.disconnect();
			clearTimeout( timer );
		};
	}, [] );

	return (
		<div
			ref={ ref }
			role="img"
			aria-label={ `An example feed: a new entry, “${ NEW_ENTRY }”, arrives at the top, above three earlier entries.` }
			className="grid w-full"
		>
			<div aria-hidden="true" className="invisible col-start-1 row-start-1">
				<Feed arrived />
			</div>
			<div aria-hidden="true" className="col-start-1 row-start-1 self-start">
				<Feed arrived={ arrived } />
			</div>
		</div>
	);
}
