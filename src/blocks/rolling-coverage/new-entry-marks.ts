/**
 * Marks entries published while the page is open: a "New" marker that holds
 * until the reader has seen the entry, then clears.
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

export const NEW_MARKER_ATTR = 'data-rc-new-marker';
export const NEW_STATE_ATTR = 'data-new';

const STATUS_CLASS = 'newspack-rolling-coverage-new-status';

// Browsers can report a fully visible element just under 1 at fractional positions.
const FULLY_IN_VIEW = 0.99;

type Clearing = {
	timer: number | null;
	animation: Animation | null;
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

		shown.forEach( ( [ id, record ] ) =>
			this.observe( id, record, this.targetOf( record ) )
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
	}

	private show( entry: HTMLElement ): void {
		entry.setAttribute( NEW_STATE_ATTR, 'held' );

		const marker = markerIn( entry );

		if ( marker ) {
			marker.hidden = false;
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
		entry.removeAttribute( NEW_STATE_ATTR );
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
