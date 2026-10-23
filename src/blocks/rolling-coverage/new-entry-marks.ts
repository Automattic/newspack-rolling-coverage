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
	COLLAPSE_MS,
	EASE_OUT,
	HOLD_MS,
	prefersReducedMotion,
} from '../shared/motion';

export const NEW_MARKER_ATTR = 'data-rc-new-marker';
export const NEW_STATE_ATTR = 'data-new';

const STATUS_CLASS = 'newspack-rolling-coverage-new-status';

// Browsers can report a fully visible element just under 1 at fractional positions.
const FULLY_IN_VIEW = 0.99;

const ENTRY_THRESHOLDS = Array.from( { length: 21 }, ( _, step ) => step / 20 );

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
 * The entry's marker paragraph, when its template has one.
 *
 * @param {HTMLElement} entry The entry.
 * @return {HTMLElement | null} The marker.
 */
function markerIn( entry: HTMLElement ): HTMLElement | null {
	return entry.querySelector< HTMLElement >( `[${ NEW_MARKER_ATTR }]` );
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

	/**
	 * @param {HTMLElement} root The feed the entries belong to.
	 */
	constructor( root: HTMLElement ) {
		this.doc = root.ownerDocument;

		if ( typeof IntersectionObserver !== 'undefined' ) {
			this.markerObserver = new IntersectionObserver( this.onIntersect, {
				threshold: 1,
			} );
			this.entryObserver = new IntersectionObserver( this.onIntersect, {
				threshold: ENTRY_THRESHOLDS,
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
		const id = entry.dataset.entryId;

		if ( ! id ) {
			return;
		}

		if ( this.marks.has( id ) ) {
			this.adopt( entry );
			return;
		}

		const record: Mark = {
			clock: null,
			entry,
			target: null,
			inView: false,
			clearing: null,
		};
		this.marks.set( id, record );
		this.show( entry );
		this.observe( id, record );
	}

	/**
	 * Carries a mark over to the element that replaced a marked entry. A
	 * running clock keeps running; a clear in progress finishes at once.
	 *
	 * @param {HTMLElement} entry The replacement element.
	 */
	adopt( entry: HTMLElement ): void {
		const id = entry.dataset.entryId;
		const record = id ? this.marks.get( id ) : undefined;

		if ( ! id || ! record ) {
			return;
		}

		this.unobserve( record );
		record.entry = entry;

		if ( record.clearing ) {
			this.settle( record );
			this.marks.delete( id );
			return;
		}

		record.inView = false;
		this.show( entry );
		this.observe( id, record );
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
		this.unobserve( record );
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

		if ( entry.querySelector( `.${ STATUS_CLASS }` ) ) {
			return;
		}

		const status = this.doc.createElement( 'span' );
		status.className = STATUS_CLASS;
		status.textContent = __( 'New', 'newspack-rolling-coverage' );
		entry.appendChild( status );
	}

	private observe( id: string, record: Mark ): void {
		const marker = markerIn( record.entry );
		const observer = marker ? this.markerObserver : this.entryObserver;

		if ( ! observer ) {
			record.inView = true;
			this.startWhenSeen( id, record );
			return;
		}

		record.target = marker ?? record.entry;
		observer.observe( record.target );
	}

	private unobserve( record: Mark ): void {
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
		this.unobserve( record );

		const clearing: Clearing = { timer: null, animation: null };
		record.clearing = clearing;
		record.entry.setAttribute( NEW_STATE_ATTR, 'clearing' );

		const marker = markerIn( record.entry );

		if (
			marker?.parentElement &&
			typeof marker.animate === 'function' &&
			! prefersReducedMotion() &&
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
					easing: EASE_OUT,
					delay: CLEAR_MS,
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

		entry.querySelector( `.${ STATUS_CLASS }` )?.remove();
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
