/**
 * Marks entries published while the page is open: a "New" marker and a tint
 * that hold until the reader has seen the entry, then clear.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	CLEAR_MS,
	COLLAPSE_DELAY_MS,
	COLLAPSE_MS,
	EASE_IN_OUT,
	HOLD_MS,
	prefersReducedMotion,
} from '../shared/motion';
import { layoutOf, stackGap } from './entry-arrival';

export const NEW_MARKER_ATTR = 'data-rc-new-marker';
export const NEW_STATE_ATTR = 'data-new';
export const NEW_LABEL_ATTR = 'data-new-label';

const STATUS_CLASS = 'newspack-rolling-coverage-new-status';

export const TINT_START = '--newspack-rolling-coverage-new-tint-start';
export const TINT_END = '--newspack-rolling-coverage-new-tint-end';

// Browsers can report a fully visible element just under 1 at fractional positions.
const FULLY_IN_VIEW = 0.99;

type Clearing = {
	timer: number | null;
	animation: Animation | null;
};

/**
 * The separator lines at an entry's top and bottom edges, as the width of
 * each line, and whether a card (a box with its own background or border all
 * round) fills the entry.
 */
export type RowEdges = {
	top: number | null;
	bottom: number | null;
	card: boolean;
};

type Mark = {
	clock: number | null;
	entry: HTMLElement;
	target: Element | null;
	inView: boolean;
	clearing: Clearing | null;
};

/**
 * The first of the entry's own elements that match a selector, leaving out
 * those of a feed nested in its content, which uses the same markup.
 *
 * @param {HTMLElement} entry    The entry.
 * @param {string}      selector Selector to match.
 * @return {HTMLElement | null} The element.
 */
function ownIn( entry: HTMLElement, selector: string ): HTMLElement | null {
	return (
		Array.from( entry.querySelectorAll< HTMLElement >( selector ) ).find(
			( element ) => element.closest( '[data-entry-id]' ) === entry
		) ?? null
	);
}

/**
 * The entry's marker paragraph, when its template has one.
 *
 * @param {HTMLElement} entry The entry.
 * @return {HTMLElement | null} The marker.
 */
function markerIn( entry: HTMLElement ): HTMLElement | null {
	return ownIn( entry, `[${ NEW_MARKER_ATTR }]` );
}

/**
 * Whether the marker shows and takes space in the flow. A visually hidden
 * marker (screen-reader text) doesn't, so the reader can't be said to have
 * seen it, and it has no line to close.
 *
 * @param {HTMLElement} marker The marker.
 * @return {boolean} True when the marker is visible.
 */
function isVisibleMarker( marker: HTMLElement ): boolean {
	const { position } = window.getComputedStyle( marker );

	if ( position === 'absolute' || position === 'fixed' ) {
		return false;
	}

	return marker.getBoundingClientRect().height > 1;
}

/**
 * How much of an element is in the viewport counts as seen: half of it, or,
 * for one taller than that, half the viewport's height.
 *
 * @param {DOMRect} rect The element's box.
 * @return {boolean} True when enough of it is in view.
 */
function fillsEnough( rect: DOMRect ): boolean {
	const visible =
		Math.min( rect.bottom, window.innerHeight ) - Math.max( rect.top, 0 );

	return (
		visible > 0 &&
		( visible >= rect.height / 2 || visible >= window.innerHeight / 2 )
	);
}

/**
 * Whether the marker has a line to itself, so clearing it should close that
 * line. It shares one when its parent lays its children out in a row.
 *
 * @param {HTMLElement} marker The marker.
 * @return {boolean} True when the marker is alone on its line.
 */
function isAloneOnLine( marker: HTMLElement ): boolean {
	const parent = marker.parentElement;

	if ( ! parent ) {
		return false;
	}

	const { display, flexDirection } = window.getComputedStyle( parent );

	if ( ! ( display || '' ).includes( 'flex' ) ) {
		return true;
	}

	return ( flexDirection || '' ).startsWith( 'column' );
}

/**
 * What borders an entry's edge on the far side of the gap: a neighbour whose
 * facing edge has a separator line or that is a card, one whose facing edge
 * has neither, or nothing.
 */
export type Facing = 'line' | 'space' | null;

/**
 * Where the tint starts and ends against the entry's own box, as the
 * `inset-block` of its pseudo-element: a negative value reaches past the box.
 * It runs from separator to separator. An edge with its own line stops at
 * it; one facing a neighbour's line or card reaches across the gap to it, so
 * no strip of the gap shows between them; one facing a neighbour without
 * either reaches halfway, so two tinted rows meet in the middle of the gap. An edge with no neighbour (the first or last
 * entry) takes the shape of the other rows: across the gap when the entry
 * has a line on its other edge, halfway otherwise. A card keeps the tint
 * inside its box, and a layout that places entries side by side (a row) has
 * no gaps above and below to reach into.
 *
 * @param {RowEdges}      edges  The entry's own edges.
 * @param {Facing}        before What faces its top edge.
 * @param {Facing}        after  What faces its bottom edge.
 * @param {number | null} gap    The gap between rows, or null for a row layout.
 * @return {number[]} The start and end insets, in pixels.
 */
export function tintInsets(
	edges: RowEdges,
	before: Facing,
	after: Facing,
	gap: number | null
): [ number, number ] {
	if ( gap === null || edges.card ) {
		return [ 0, 0 ];
	}

	const reach = ( facing: Facing, otherLine: number | null ) => {
		if ( facing === 'line' || ( facing === null && otherLine !== null ) ) {
			return -gap;
		}

		return -gap / 2;
	};

	return [
		edges.top ?? reach( before, edges.bottom ),
		edges.bottom ?? reach( after, edges.top ),
	];
}

/**
 * Whether a computed color paints anything.
 *
 * @param {string} color The color.
 * @return {boolean} True when it isn't fully transparent.
 */
function isPainted( color: string ): boolean {
	return (
		!! color &&
		color !== 'transparent' &&
		! /^rgba\(.*,\s*0\)$|\/\s*0\)$/.test( color )
	);
}

/**
 * Finds the separator lines at an entry's top and bottom edges, and whether
 * a card fills it, from the boxes that span its width along those edges: a
 * separator block, or a box with a border on that edge only. The entry's
 * text and a feed nested in it aren't searched.
 *
 * @param {HTMLElement} entry The entry.
 * @return {RowEdges} The edges.
 */
export function rowEdges( entry: HTMLElement ): RowEdges {
	const box = entry.getBoundingClientRect();
	const edges: RowEdges = { top: null, bottom: null, card: false };

	const visit = ( element: Element, depth: number ): void => {
		if (
			depth > 4 ||
			element.matches( '.wp-block-post-content' ) ||
			( element !== entry && element.matches( '[data-entry-id]' ) )
		) {
			return;
		}

		const rect = element.getBoundingClientRect();
		const atTop = Math.abs( rect.top - box.top ) <= 1;
		const atBottom = Math.abs( rect.bottom - box.bottom ) <= 1;

		if ( ( ! atTop && ! atBottom ) || ! rect.height ) {
			return;
		}

		if ( element.matches( 'hr' ) ) {
			if ( atTop ) {
				edges.top ??= rect.height;
			} else {
				edges.bottom ??= rect.height;
			}
			return;
		}

		if ( rect.width < box.width - 1 ) {
			return;
		}

		const style = window.getComputedStyle( element );
		const border = ( side: 'Top' | 'Bottom' | 'Left' | 'Right' ) =>
			style[ `border${ side }Style` ] === 'none'
				? 0
				: parseFloat( style[ `border${ side }Width` ] ) || 0;
		const [ top, bottom, left, right ] = [
			border( 'Top' ),
			border( 'Bottom' ),
			border( 'Left' ),
			border( 'Right' ),
		];

		if (
			atTop &&
			atBottom &&
			( isPainted( style.backgroundColor ) ||
				( top && bottom && left && right ) )
		) {
			edges.card = true;
			return;
		}

		if ( atTop && top && ! bottom ) {
			edges.top ??= top;
		}

		if ( atBottom && bottom && ! top ) {
			edges.bottom ??= bottom;
		}

		Array.from( element.children ).forEach( ( child ) =>
			visit( child, depth + 1 )
		);
	};

	visit( entry, 0 );

	return edges;
}

/**
 * The gap the entry's layout leaves between rows, or null for a layout that
 * places entries side by side.
 *
 * @param {HTMLElement} entry The entry.
 * @return {number | null} The gap in pixels, or null.
 */
function rowGap( entry: HTMLElement ): number | null {
	const layout = layoutOf( entry );

	if ( ! layout ) {
		return null;
	}

	const style = window.getComputedStyle( layout );

	return ( style.display || '' ).endsWith( 'grid' )
		? parseFloat( style.rowGap ) || 0
		: stackGap( style );
}

/**
 * The nearest entry before or after an entry in its list.
 *
 * @param {HTMLElement} entry The entry.
 * @param {boolean}     after Whether to look after it.
 * @return {HTMLElement | null} The neighbour.
 */
function neighbour( entry: HTMLElement, after: boolean ): HTMLElement | null {
	let sibling = after
		? entry.nextElementSibling
		: entry.previousElementSibling;

	while ( sibling && ! sibling.matches( '[data-entry-id]' ) ) {
		sibling = after
			? sibling.nextElementSibling
			: sibling.previousElementSibling;
	}

	return sibling as HTMLElement | null;
}

/**
 * Measures how far an entry's tint reaches (see tintInsets()).
 *
 * @param {HTMLElement} entry The entry.
 * @return {number[]} The start and end insets, in pixels.
 */
function measureTint( entry: HTMLElement ): [ number, number ] {
	const facing = ( other: HTMLElement | null, edge: 'top' | 'bottom' ) => {
		if ( ! other ) {
			return null;
		}

		const edges = rowEdges( other );

		return edges.card || edges[ edge ] !== null ? 'line' : 'space';
	};

	return tintInsets(
		rowEdges( entry ),
		facing( neighbour( entry, false ), 'bottom' ),
		facing( neighbour( entry, true ), 'top' ),
		rowGap( entry )
	);
}

/**
 * Places the tints of several entries: every measurement first, then every
 * inset, so the page lays out once however many there are.
 *
 * @param {Iterable<HTMLElement>} entries The entries.
 */
function placeTints( entries: Iterable< HTMLElement > ): void {
	Array.from(
		entries,
		( entry ) => [ entry, measureTint( entry ) ] as const
	).forEach( ( [ entry, [ start, end ] ] ) => {
		entry.style.setProperty( TINT_START, `${ start }px` );
		entry.style.setProperty( TINT_END, `${ end }px` );
	} );
}

/**
 * Tracks the entries marked as new, keyed by entry ID so a mark and its
 * clock survive the entry's element being replaced.
 */
export class NewEntryMarks {
	private readonly doc: Document;

	private readonly marks = new Map< string, Mark >();

	private readonly markerObserver: IntersectionObserver | null = null;

	private readonly entryObserver: IntersectionObserver | null = null;

	private readonly scrollWatched = new Set< string >();

	private frame: number | null = null;

	private resizeFrame: number | null = null;

	/**
	 * @param {HTMLElement} root The feed the entries belong to.
	 */
	constructor( root: HTMLElement ) {
		this.doc = root.ownerDocument;

		if ( typeof IntersectionObserver !== 'undefined' ) {
			this.markerObserver = new IntersectionObserver( this.onIntersect, {
				threshold: [ FULLY_IN_VIEW, 1 ],
			} );
			this.entryObserver = new IntersectionObserver( this.onIntersect, {
				threshold: [ 0, 0.5 ],
			} );
		}

		this.doc.addEventListener( 'visibilitychange', this.onVisibility );
		window.addEventListener( 'resize', this.onResize );
	}

	/**
	 * Marks an entry as new and starts watching for the reader to see it.
	 *
	 * @param {HTMLElement} entry The new entry.
	 */
	mark( entry: HTMLElement ): void {
		this.markAll( [ entry ] );
	}

	/**
	 * Carries a mark over to the element that replaced a marked entry. A
	 * running clock keeps running; a clear in progress finishes at once.
	 *
	 * @param {HTMLElement} entry The replacement element.
	 */
	adopt( entry: HTMLElement ): void {
		this.markAll( [], [ entry ] );
	}

	/**
	 * Marks several entries as new, and carries the marks of others over to
	 * the elements that replaced them (see adopt()), measuring them all
	 * together so the page lays out once rather than once per entry.
	 *
	 * @param {HTMLElement[]} entries The new entries.
	 * @param {HTMLElement[]} adopted Replacement elements of entries that may be marked.
	 */
	markAll( entries: HTMLElement[], adopted: HTMLElement[] = [] ): void {
		const shown: Array< [ string, Mark ] > = [];
		const take = ( entry: HTMLElement, isNew: boolean ) => {
			const id = entry.dataset.entryId;

			if ( ! id ) {
				return;
			}

			const record = this.marks.get( id );

			if ( ! record ) {
				if ( isNew ) {
					const created: Mark = {
						clock: null,
						entry,
						target: null,
						inView: false,
						clearing: null,
					};
					this.marks.set( id, created );
					shown.push( [ id, created ] );
				}
				return;
			}

			this.unobserve( id, record );
			record.entry = entry;

			if ( record.clearing ) {
				this.settle( record );
				this.marks.delete( id );
				return;
			}

			record.inView = false;
			shown.push( [ id, record ] );
		};

		entries.forEach( ( entry ) => take( entry, true ) );
		adopted.forEach( ( entry ) => take( entry, false ) );

		shown.forEach( ( [ , record ] ) => this.show( record.entry ) );

		// A tint reaches toward the lines of the entries beside it, so labeled entries beside one just shown are placed again.
		const tinted = new Set< HTMLElement >();
		shown.forEach( ( [ , { entry } ] ) => {
			if ( entry.hasAttribute( NEW_LABEL_ATTR ) ) {
				[ entry, neighbour( entry, false ), neighbour( entry, true ) ]
					.filter(
						( other ): other is HTMLElement =>
							!! other?.hasAttribute( NEW_LABEL_ATTR )
					)
					.forEach( ( other ) => tinted.add( other ) );
			}
		} );

		const targets = shown.map(
			( [ id, record ] ) =>
				[ id, record, this.targetOf( record ) ] as const
		);

		placeTints( tinted );
		targets.forEach( ( [ id, record, target ] ) =>
			this.observe( id, record, target )
		);
	}

	/**
	 * Stops tracking an entry, for one that left the page.
	 *
	 * @param {string} entryId The entry ID.
	 */
	forget( entryId: string ): void {
		const record = this.marks.get( entryId );

		if ( ! record ) {
			return;
		}

		this.stopClock( record );
		this.unobserve( entryId, record );
		this.cancelClearing( record );
		this.marks.delete( entryId );
	}

	/**
	 * Whether an entry is marked as new, including while its mark clears.
	 *
	 * @param {string} entryId The entry ID.
	 * @return {boolean} True when the entry is marked.
	 */
	isMarked( entryId: string ): boolean {
		return this.marks.has( entryId );
	}

	/**
	 * Whether an entry is marked and its mark hasn't started to clear.
	 *
	 * @param {string} entryId The entry ID.
	 * @return {boolean} True when the entry's mark is held.
	 */
	isHeld( entryId: string ): boolean {
		const record = this.marks.get( entryId );

		return !! record && ! record.clearing;
	}

	/**
	 * Stops tracking an entry that closes its space as it leaves. A collapse
	 * in progress stops where it got to instead of springing back, so the
	 * entry keeps the height its space closes from.
	 *
	 * @param {string} entryId The entry ID.
	 */
	release( entryId: string ): void {
		const record = this.marks.get( entryId );
		const animation = record?.clearing?.animation;

		if ( record && animation ) {
			try {
				animation.commitStyles();
			} catch {
				this.settle( record );
			}
		}

		this.forget( entryId );
	}

	/**
	 * Stops every clock, observer and animation.
	 */
	destroy(): void {
		Array.from( this.marks.keys() ).forEach( ( id ) => this.forget( id ) );
		this.markerObserver?.disconnect();
		this.entryObserver?.disconnect();
		this.doc.removeEventListener( 'visibilitychange', this.onVisibility );
		window.removeEventListener( 'resize', this.onResize );

		if ( this.resizeFrame !== null ) {
			window.cancelAnimationFrame( this.resizeFrame );
			this.resizeFrame = null;
		}
	}

	private show( entry: HTMLElement ): void {
		entry.setAttribute( NEW_STATE_ATTR, 'held' );

		const marker = markerIn( entry );

		if ( marker ) {
			marker.hidden = false;
			entry.setAttribute( NEW_LABEL_ATTR, '' );
			return;
		}

		if ( ownIn( entry, `.${ STATUS_CLASS }` ) ) {
			return;
		}

		const status = this.doc.createElement( 'span' );
		status.className = STATUS_CLASS;
		/* translators: Label on a coverage entry published while the reader has the page open. */
		status.textContent = __( 'New', 'newspack-rolling-coverage' );
		entry.appendChild( status );
	}

	private targetOf( record: Mark ): Element {
		const marker = markerIn( record.entry );

		return marker && isVisibleMarker( marker ) ? marker : record.entry;
	}

	private observe( id: string, record: Mark, target: Element ): void {
		const observer =
			target === record.entry ? this.entryObserver : this.markerObserver;

		if ( ! observer ) {
			record.inView = true;
			this.startWhenSeen( id, record );
			return;
		}

		record.target = target;
		observer.observe( target );
	}

	private unobserve( id: string, record: Mark ): void {
		this.unwatchScroll( id );

		if ( ! record.target ) {
			return;
		}

		const observer =
			record.target === record.entry
				? this.entryObserver
				: this.markerObserver;
		observer?.unobserve( record.target );
		record.target = null;
	}

	private readonly onIntersect = (
		observed: IntersectionObserverEntry[]
	): void => {
		observed.forEach( ( change ) => {
			this.marks.forEach( ( record, id ) => {
				if ( record.target !== change.target || record.clearing ) {
					return;
				}

				record.inView = this.isSeen( record, change );

				// A ratio threshold can't tell when an entry many viewports tall fills half the viewport.
				if (
					record.target === record.entry &&
					change.isIntersecting &&
					! record.inView
				) {
					this.watchScroll( id );
				} else {
					this.unwatchScroll( id );
				}

				this.startWhenSeen( id, record );
			} );
		} );
	};

	private isSeen( record: Mark, change: IntersectionObserverEntry ): boolean {
		if ( record.target !== record.entry ) {
			return change.intersectionRatio >= FULLY_IN_VIEW;
		}

		return (
			change.intersectionRatio >= 0.5 ||
			change.intersectionRect.height >= window.innerHeight / 2
		);
	}

	private watchScroll( id: string ): void {
		if ( ! this.scrollWatched.size ) {
			window.addEventListener( 'scroll', this.onScroll, {
				passive: true,
			} );
			window.addEventListener( 'resize', this.onScroll );
		}

		this.scrollWatched.add( id );
	}

	private unwatchScroll( id: string ): void {
		if ( ! this.scrollWatched.delete( id ) || this.scrollWatched.size ) {
			return;
		}

		window.removeEventListener( 'scroll', this.onScroll );
		window.removeEventListener( 'resize', this.onScroll );

		if ( this.frame !== null ) {
			window.cancelAnimationFrame( this.frame );
			this.frame = null;
		}
	}

	private readonly onScroll = (): void => {
		if ( this.frame !== null ) {
			return;
		}

		this.frame = window.requestAnimationFrame( () => {
			this.frame = null;
			this.scrollWatched.forEach( ( id ) => {
				const record = this.marks.get( id );

				if (
					record &&
					! fillsEnough( record.entry.getBoundingClientRect() )
				) {
					return;
				}

				this.unwatchScroll( id );

				if ( record ) {
					record.inView = true;
					this.startWhenSeen( id, record );
				}
			} );
		} );
	};

	private readonly onResize = (): void => {
		if ( this.resizeFrame !== null ) {
			return;
		}

		this.resizeFrame = window.requestAnimationFrame( () => {
			this.resizeFrame = null;
			placeTints(
				Array.from(
					this.marks.values(),
					( { entry } ) => entry
				).filter(
					( entry ) =>
						entry.isConnected &&
						entry.hasAttribute( NEW_LABEL_ATTR )
				)
			);
		} );
	};

	private readonly onVisibility = (): void => {
		this.marks.forEach( ( record, id ) => {
			if ( this.doc.visibilityState === 'visible' ) {
				this.startWhenSeen( id, record );
			} else {
				this.stopClock( record );
			}
		} );
	};

	private startWhenSeen( id: string, record: Mark ): void {
		if (
			record.clock === null &&
			! record.clearing &&
			record.inView &&
			this.doc.visibilityState === 'visible'
		) {
			record.clock = window.setTimeout( () => this.clear( id ), HOLD_MS );
		}
	}

	private stopClock( record: Mark ): void {
		if ( record.clock !== null ) {
			window.clearTimeout( record.clock );
			record.clock = null;
		}
	}

	private clear( id: string ): void {
		const record = this.marks.get( id );

		if ( ! record ) {
			return;
		}

		record.clock = null;
		this.unobserve( id, record );

		const clearing: Clearing = { timer: null, animation: null };
		record.clearing = clearing;
		record.entry.setAttribute( NEW_STATE_ATTR, 'clearing' );

		const marker = markerIn( record.entry );

		if (
			marker?.parentElement &&
			typeof marker.animate === 'function' &&
			! prefersReducedMotion() &&
			isVisibleMarker( marker ) &&
			isAloneOnLine( marker )
		) {
			const height = marker.getBoundingClientRect().height;
			const gap =
				parseFloat(
					window.getComputedStyle( marker.parentElement ).rowGap
				) || 0;

			// The negative margin cancels the gap that goes with the marker when it's hidden, so the layout doesn't move again.
			marker.style.boxSizing = 'border-box';
			marker.style.overflow = 'hidden';
			const animation = marker.animate(
				[
					{ height: `${ height }px`, marginBlockEnd: '0px' },
					{ height: '0px', marginBlockEnd: `-${ gap }px` },
				],
				{
					duration: COLLAPSE_MS,
					easing: EASE_IN_OUT,
					delay: COLLAPSE_DELAY_MS,
					fill: 'forwards',
				}
			);
			clearing.animation = animation;
			animation.finished.then(
				() => this.finish( id, clearing ),
				() => {}
			);
			return;
		}

		clearing.timer = window.setTimeout(
			() => this.finish( id, clearing ),
			CLEAR_MS
		);
	}

	private finish( id: string, clearing: Clearing ): void {
		const record = this.marks.get( id );

		if ( ! record || record.clearing !== clearing ) {
			return;
		}

		this.settle( record );
		this.marks.delete( id );
	}

	private settle( record: Mark ): void {
		const { entry } = record;
		const marker = markerIn( entry );

		if ( marker ) {
			marker.hidden = true;
			marker.style.removeProperty( 'box-sizing' );
			marker.style.removeProperty( 'overflow' );

			if ( ! marker.getAttribute( 'style' ) ) {
				marker.removeAttribute( 'style' );
			}
		}

		ownIn( entry, `.${ STATUS_CLASS }` )?.remove();
		entry.style.removeProperty( TINT_START );
		entry.style.removeProperty( TINT_END );

		if ( ! entry.getAttribute( 'style' ) ) {
			entry.removeAttribute( 'style' );
		}

		entry.removeAttribute( NEW_STATE_ATTR );
		entry.removeAttribute( NEW_LABEL_ATTR );
		this.cancelClearing( record );
	}

	private cancelClearing( record: Mark ): void {
		const { clearing } = record;

		if ( ! clearing ) {
			return;
		}

		if ( clearing.timer !== null ) {
			window.clearTimeout( clearing.timer );
		}

		clearing.animation?.cancel();
		record.clearing = null;
	}
}
