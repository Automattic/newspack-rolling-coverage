/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.scss';
import { trackEvent, isConfigEnabled, EVENTS } from './analytics';
import { keepRelativeDatesCurrent } from '../shared/relative-dates';
import { POLL_EVENT } from '../shared/poll-event';
import type { PollEventDetail } from '../shared/poll-event';
import type {
	AdSlot,
	PendingEntry,
	PollEntry,
	PollResponse,
	PageResponse,
} from './types';

const BLOCK_SELECTOR = '.wp-block-newspack-rolling-coverage-rolling-coverage';

// How long an overflow holds back another reload into the same cursor. The
// reload can land on a page cache copy from before the burst, which overflows
// again on its next poll; without the wait the reader would reload on every
// poll until that copy expires.
const OVERFLOW_RELOAD_RETRY_MS = 60 * 1000;

// How long the jump to the live feed waits for the page before it navigates instead.
const JUMP_TIMEOUT_MS = 8000;

// How long the jump waits for stylesheets the live feed needs before it shows
// the feed without them.
const STYLES_TIMEOUT_MS = 3000;

// Space left above the block when the page scrolls to it, matching the
// control's gap in the stylesheet.
const EDGE_GAP = 24;

// Space between the bars at the top of the viewport and what sits below them.
const BAR_GAP = 16;

// How long the linked entry's outline stays once the reader can see it.
const LINKED_OUTLINE_MS = 4000;

// The tallest the floating control is expected to be, so an entry scrolled
// into view lands clear of it.
const CONTROL_HEIGHT = 56;

// Entries are the same for every reader, so requests for them go out without
// credentials and readers share one cached reply. A login or cart cookie makes
// the page cache and the CDN skip that copy, and every poll from that reader
// would run PHP. Cookies would not change the reply: with no nonce, core
// clears the cookie user before the route runs.
//
// Users who can edit posts send credentials anyway, so their requests skip
// both caches. A load-more reply is otherwise cached for minutes, and a reload
// would bring back an older entry as it was before they changed, trashed or
// unpublished it.
//
// A site gated in front of the REST API refuses a request without credentials,
// so fetchEntries() moves the page to the browser's default on a refusal.
let entriesCredentials: RequestCredentials = isConfigEnabled(
	window.newspackRollingCoverageFrontend?.canEditPosts
)
	? 'same-origin'
	: 'omit';

/**
 * cssEscape polyfill for older browsers.
 */
const cssEscape = ( str: string ): string => {
	if ( typeof CSS !== 'undefined' && CSS.escape ) {
		return CSS.escape( str );
	}
	return str.replace( /([!"#$%&'()*+,./:;<=>?@[\]^`{|}~])/g, '\\$1' );
};

/**
 * Strips <script> tags and on* event handler attributes from an HTML
 * string as a defense-in-depth measure against XSS. The HTML is
 * already sanitized server-side by WordPress's block rendering pipeline
 * (including KSES), but this prevents execution if a compromised or
 * unfiltered-html account injected inline scripts.
 *
 * @param {string} html Raw HTML from the REST API.
 * @return {string} Sanitized HTML safe for DOM insertion.
 */
function sanitizeHtml( html: string ): string {
	const doc = new DOMParser().parseFromString( html, 'text/html' );

	// Remove all <script> elements.
	doc.querySelectorAll( 'script' ).forEach( ( el ) => el.remove() );

	// Remove all on* event handler attributes.
	doc.querySelectorAll( '*' ).forEach( ( el ) => {
		Array.from( el.attributes ).forEach( ( attr ) => {
			if ( attr.name.startsWith( 'on' ) ) {
				el.removeAttribute( attr.name );
			}
		} );
	} );

	return doc.body.innerHTML;
}

/**
 * Validates that a value is a finite positive integer, safe for use
 * in CSS selectors and DOM operations.
 *
 * @param {unknown} value Value to validate.
 * @return {boolean} True if the value is a safe positive integer.
 */
function isSafeEntryId( value: unknown ): boolean {
	return (
		typeof value === 'number' &&
		Number.isFinite( value ) &&
		value > 0 &&
		Number.isInteger( value )
	);
}

/**
 * Parses an HTML string into a detached document fragment.
 *
 * @param {string} html HTML markup, possibly containing multiple elements.
 * @return {DocumentFragment} The parsed fragment.
 */
function parseFragment( html: string ): DocumentFragment {
	const template = document.createElement( 'template' );
	template.innerHTML = html;
	return template.content;
}

/**
 * Parses an HTML string into a detached element.
 *
 * @param {string} html HTML markup for a single element.
 * @return {HTMLElement | null} The parsed element, or null if parsing produced none.
 */
function parseElement( html: string ): HTMLElement | null {
	return parseFragment( html ).firstElementChild as HTMLElement | null;
}

/**
 * The label of the control on a feed opened at a shared entry: the number of
 * newer entries, exact up to ten and from there the round number it has
 * passed, e.g. "10+ Newer Posts" for 11 to 50. Mirrors
 * Rolling_Coverage_Block::newer_posts_label().
 *
 * @param {number} count How many entries are newer.
 * @return {string} The label, or an empty string when there are none.
 */
function newerPostsLabel( count: number ): string {
	if ( count < 1 ) {
		return '';
	}

	if ( count <= 10 ) {
		return sprintf(
			/* translators: %d: number of coverage entries newer than the one shown, from 1 to 10. */
			_n(
				'%d Newer Post',
				'%d Newer Posts',
				count,
				'newspack-rolling-coverage'
			),
			count
		);
	}

	let floor = 10;

	if ( count > 100 ) {
		floor = 100;
	} else if ( count > 50 ) {
		floor = 50;
	}

	return sprintf(
		/* translators: %d: a round number the count of newer coverage entries has passed: 10, 50 or 100. */
		_n(
			'%d+ Newer Post',
			'%d+ Newer Posts',
			floor,
			'newspack-rolling-coverage'
		),
		floor
	);
}

/**
 * How far down the viewport the fixed and sticky elements over its top centre
 * reach, such as the admin bar and a sticky site header, so the floating
 * control can sit below them. An element taller than half the viewport is an
 * overlay rather than a header, and is passed over.
 *
 * @param {HTMLElement|null} control The floating control, which is never counted.
 * @return {number} Distance from the top of the viewport, in pixels.
 */
function topBarsBottom( control: HTMLElement | null ): number {
	const x = window.innerWidth / 2;
	const checked = new Set< Element >();
	let bottom = 0;

	// Bars can stack, like a sticky header held below the admin bar.
	for ( let i = 0; i < 4; i++ ) {
		const y = bottom + 1;
		let next = bottom;

		document.elementsFromPoint( x, y ).forEach( ( element ) => {
			let below: Element | null = null;

			for (
				let node: Element | null = element;
				node &&
				node !== document.body &&
				! control?.contains( node ) &&
				! checked.has( node );
				node = node.parentElement
			) {
				checked.add( node );

				const { position } = window.getComputedStyle( node );

				if ( position !== 'fixed' && position !== 'sticky' ) {
					below = node;
					continue;
				}

				// A full-screen layer can hold a bar, like a prompt pinned to
				// the top without an overlay; the box inside it is the bar.
				const bar =
					node.getBoundingClientRect().height < window.innerHeight / 2
						? node
						: below;
				const rect = bar?.getBoundingClientRect();

				if (
					rect &&
					bar?.checkVisibility?.( {
						opacityProperty: true,
						visibilityProperty: true,
					} ) !== false &&
					rect.top <= y &&
					rect.bottom > next &&
					rect.height < window.innerHeight / 2
				) {
					next = rect.bottom;
				}

				break;
			}
		} );

		if ( next <= bottom ) {
			break;
		}

		bottom = next;
	}

	return bottom;
}

/**
 * Requests entries, with credentials only on a site that requires them.
 *
 * HTTP authentication, a firewall challenge or a login requirement in front
 * of the REST API answers 401 or 403 to a request that carries none. That
 * request is repeated with the browser's default, which every later request
 * on the page keeps, so entries keep loading there at the cost of the shared
 * reply.
 *
 * @param {string} url Entries URL.
 * @return {Promise<Response>} The reply, from the repeated request if the first was refused.
 */
async function fetchEntries( url: string ): Promise< Response > {
	const credentials = entriesCredentials;
	const response = await fetch( url, { credentials } );
	const isRefused = response.status === 401 || response.status === 403;

	if ( credentials !== 'omit' || ! isRefused ) {
		return response;
	}

	entriesCredentials = 'same-origin';

	return fetch( url, { credentials: entriesCredentials } );
}

/**
 * Sets up polling and infinite scroll for a single block instance.
 *
 * @param {HTMLElement} root The block's outer wrapper element.
 * @return {void}
 */
function initBlock( root: HTMLElement ): void {
	if ( root.dataset.rcInitialized === '1' ) {
		return;
	}
	root.dataset.rcInitialized = '1';

	const restUrl = root.dataset.restUrl;
	const entriesListEl = root.querySelector< HTMLElement >(
		'.newspack-rolling-coverage-entries'
	);

	if ( ! restUrl || ! entriesListEl ) {
		return;
	}

	const entriesList: HTMLElement = entriesListEl;
	const restBaseUrl: string = restUrl;

	const stopRelativeDates = keepRelativeDatesCurrent( root, entriesList );

	const pollInterval = parseInt( root.dataset.pollInterval || '10', 10 );
	const entriesPerPage = parseInt( root.dataset.entriesPerPage || '20', 10 );
	const templateKey = root.dataset.templateKey || '';
	const hostPostId = root.dataset.hostPostId || '0';
	const sentinel = root.querySelector< HTMLElement >(
		'.newspack-rolling-coverage-sentinel'
	);
	const newEntriesControl = root.querySelector< HTMLElement >(
		'.newspack-rolling-coverage-new-entries'
	);
	const newEntriesLink =
		newEntriesControl?.querySelector< HTMLElement >( '[data-rc-latest]' ) ??
		null;
	const statusEl = root.querySelector< HTMLElement >(
		'.newspack-rolling-coverage-status'
	);

	const status = root.dataset.status || 'active';
	const isEntryView = root.dataset.view === 'entry';

	const coverageId = root.dataset.coverageId || '0';

	let cursor = root.dataset.cursor || '';
	let before = root.dataset.before || '';
	let hasMore = root.dataset.hasMore === '1';
	const latestCap = parseInt( root.dataset.latest || '0', 10 ) || 0;
	let isLoadingMore = false;
	let isJumping = false;
	let linkedObserver: IntersectionObserver | null = null;
	let isDisposed = false;
	let pollTimeoutId: ReturnType< typeof setTimeout > | null = null;
	let pendingNewEntries: PendingEntry[] = [];
	let polledCount = 0;

	// Whether a poll request is in flight. At most one poll is in flight or
	// scheduled at a time, so tab switches and back/forward navigation can't
	// start a second chain of polls.
	let isPolling = false;

	// The site's minimum poll interval, in seconds; 0 when it sets none. Each
	// poll brings the current value, so an open page follows it both ways.
	let minPollInterval =
		parseInt( root.dataset.minPollInterval || '0', 10 ) || 0;
	let backlogOffset = entriesList.querySelectorAll(
		':scope > [data-entry-id]'
	).length;

	// Tracks forward-poll health so a sustained outage reports one error per
	// episode (healthy->failing transition) instead of one per failed interval.
	let isForwardPollHealthy = true;

	// Edits the poll delivered for entries not yet on the page, latest HTML by
	// entry ID. A cached load-more reply can predate them while the cursor has
	// already moved past them, so loadMore() applies them as the entries arrive.
	const offPageUpdates = new Map< string, string >();

	const countedEntryIds = new Set< string >();

	// Entries newer than the shared entry when the server rendered the page.
	const newerCount =
		parseInt( newEntriesControl?.dataset.newerCount || '0', 10 ) || 0;

	// The control's own text: the server keeps it on the link when it writes
	// the count in its place.
	const ownLabel =
		newEntriesLink?.dataset.label ?? newEntriesLink?.textContent ?? '';

	// Whether the poll can still tell how many entries are newer.
	let canCount = true;

	/**
	 * Shows on the control how many entries are newer than the shared entry:
	 * those the page was rendered with plus those the poll has counted since.
	 * With none, or once the poll can no longer count, the control shows its
	 * own text.
	 *
	 * @return {void}
	 */
	function showNewerCount(): void {
		if ( ! newEntriesLink ) {
			return;
		}

		const label = canCount
			? newerPostsLabel( newerCount + countedEntryIds.size )
			: '';

		if ( label || ! canCount ) {
			newEntriesLink.textContent = label || ownLabel;
		}
	}

	if ( isEntryView ) {
		showNewerCount();
	}

	// Entry IDs already reported as seen. Guards against re-firing
	// coverage_entry_seen when a polled edit replaces an already-seen entry's element.
	const seenEntryIds = new Set< string >();

	// Observes entry elements for viewport visibility, reporting each as seen
	// the moment any part of it enters the viewport.
	const entrySeenObserver: IntersectionObserver | null =
		typeof IntersectionObserver === 'undefined'
			? null
			: new IntersectionObserver( ( observerEntries ) => {
					observerEntries.forEach( ( observerEntry ) => {
						if ( ! observerEntry.isIntersecting ) {
							return;
						}

						const target = observerEntry.target as HTMLElement;
						unobserveEntry( target );

						const entryId = target.dataset.entryId;

						if ( ! entryId || seenEntryIds.has( entryId ) ) {
							return;
						}

						seenEntryIds.add( entryId );

						trackEvent( EVENTS.ENTRY_SEEN, {
							coverage_id: coverageId,
							entry_id: entryId,
							arrival: target.dataset.arrival || 'initial',
						} );
					} );
				} );

	/**
	 * Starts observing an entry element for viewport visibility, unless it's
	 * already been reported as seen.
	 *
	 * @param {HTMLElement} el Entry element, carrying data-entry-id and data-arrival.
	 * @return {void}
	 */
	function observeEntry( el: HTMLElement ): void {
		const entryId = el.dataset.entryId;

		if ( ! entrySeenObserver || ! entryId || seenEntryIds.has( entryId ) ) {
			return;
		}

		entrySeenObserver.observe( el );
	}

	/**
	 * Stops observing an entry element for viewport visibility, e.g. before
	 * it's replaced by a polled edit.
	 *
	 * @param {HTMLElement} el Entry element to stop observing.
	 * @return {void}
	 */
	function unobserveEntry( el: HTMLElement ): void {
		entrySeenObserver?.unobserve( el );
	}

	/**
	 * Schedules the next poll, at the block's interval or the site's minimum,
	 * whichever is longer, in place of any poll already scheduled. Schedules
	 * none while a poll is in flight, as that poll schedules the next, or
	 * while the page is hidden, as showing it polls at once.
	 *
	 * @return {void}
	 */
	function schedulePoll(): void {
		cancelPoll();

		if ( isPolling || document.hidden ) {
			return;
		}

		pollTimeoutId = setTimeout(
			poll,
			Math.max( pollInterval, minPollInterval ) * 1000
		);
	}

	/**
	 * Tracks a failed entry request.
	 *
	 * @param {'poll' | 'load_more'} errorType Which request failed.
	 * @return {void}
	 */
	function trackPollError( errorType: 'poll' | 'load_more' ): void {
		trackEvent( EVENTS.POLL_ERROR, {
			coverage_id: coverageId,
			error_type: errorType,
		} );
	}

	/**
	 * Cancels any pending poll timeout.
	 *
	 * @return {void}
	 */
	function cancelPoll(): void {
		if ( pollTimeoutId !== null ) {
			clearTimeout( pollTimeoutId );
			pollTimeoutId = null;
		}
	}

	/**
	 * Checks whether the reader has scrolled past the top of the entry list.
	 *
	 * @return {boolean} True if the reader has scrolled past the first entry.
	 */
	function isScrolledPastTop(): boolean {
		const firstEntry = entriesList.firstElementChild;
		return !! firstEntry && firstEntry.getBoundingClientRect().bottom < 0;
	}

	/**
	 * Announces a message to screen readers via the status live region.
	 *
	 * @param {string} message Message to announce.
	 */
	function announce( message: string ): void {
		if ( statusEl ) {
			statusEl.textContent = message;
		}
	}

	/**
	 * The first unpinned entry in the list, where pinned entries end.
	 *
	 * @param {HTMLElement} [except] Entry to leave out.
	 * @return {HTMLElement|null} The entry, or null if every entry is pinned.
	 */
	function firstUnpinnedEntry( except?: HTMLElement ): HTMLElement | null {
		return (
			Array.from(
				entriesList.querySelectorAll< HTMLElement >(
					':scope > [data-entry-id]:not([data-pinned])'
				)
			).find( ( entry ) => entry !== except ) ?? null
		);
	}

	/**
	 * Removes the oldest entries beyond the cap of a capped feed, and stops
	 * watching them for being seen.
	 *
	 * @return {void}
	 */
	function trimToLatestCap(): void {
		if ( ! latestCap ) {
			return;
		}

		const entries = entriesList.querySelectorAll< HTMLElement >(
			':scope > [data-entry-id]'
		);

		for ( let i = entries.length - 1; i >= latestCap; i-- ) {
			unobserveEntry( entries[ i ] );
			entries[ i ].remove();
		}
	}

	/**
	 * Inserts entries above the newest unpinned entry, below any pinned
	 * entries, removing the "no entries yet" placeholder if it's still
	 * present.
	 *
	 * Removes the "no entries yet" placeholder, starts observing each entry
	 * for coverage_entry_seen, and displays any associated ad slots.
	 *
	 * @param {PendingEntry[]} entries Entries to insert, newest first.
	 * @return {void}
	 */
	function insertNewEntries( entries: PendingEntry[] ): void {
		if ( entries.length === 0 ) {
			return;
		}

		entriesList
			.querySelector( '.newspack-rolling-coverage-entries__empty' )
			?.remove();

		const fragment = document.createDocumentFragment();
		const adSlotsToDisplay: AdSlot[] = [];

		entries.forEach( ( { el, adSlot, adEl } ) => {
			fragment.appendChild( el );
			observeEntry( el );
			if ( adEl ) {
				fragment.appendChild( adEl );
				if ( adSlot ) {
					adSlotsToDisplay.push( adSlot );
				}
			}
		} );

		entriesList.insertBefore( fragment, firstUnpinnedEntry() );
		trimToLatestCap();
		dropLastSeparator();

		const shown = Math.min( entries.length, latestCap || entries.length );

		announce(
			sprintf(
				/* translators: %d: number of new coverage entries just added. */
				_n(
					'%d new post added',
					'%d new posts added',
					shown,
					'newspack-rolling-coverage'
				),
				shown
			)
		);

		if ( adSlotsToDisplay.length > 0 ) {
			displayAdSlots( adSlotsToDisplay );
		}
	}

	/**
	 * Label for the control that tells the reader new entries are waiting.
	 *
	 * @param {number} count How many new entries are waiting.
	 * @return {string} The label.
	 */
	function newEntriesLabel( count: number ): string {
		return sprintf(
			/* translators: %d: number of new coverage entries waiting to be shown. */
			_n(
				'%d New Post',
				'%d New Posts',
				count,
				'newspack-rolling-coverage'
			),
			count
		);
	}

	/**
	 * Adds entries to the pending queue.
	 *
	 * Updates the "X New Posts" control label and visibility.
	 *
	 * @param {PendingEntry[]} newEntries Newly published entries.
	 * @return {void}
	 */
	function queueNewEntries( newEntries: PendingEntry[] ): void {
		pendingNewEntries.unshift( ...newEntries );

		if ( ! newEntriesControl || ! newEntriesLink ) {
			return;
		}

		const label = newEntriesLabel( pendingNewEntries.length );

		newEntriesLink.textContent = label;
		newEntriesControl.hidden = false;
		placeControl();
		announce( label );
	}

	/**
	 * Gets the pending entries and clears the queue.
	 *
	 * @return {PendingEntry[]} The entries that were pending.
	 */
	function takePendingEntries(): PendingEntry[] {
		const entries = pendingNewEntries;
		pendingNewEntries = [];
		return entries;
	}

	const cleanupFns: Array< () => void > = [];

	// Registers a listener and queues its removal for cleanup.
	function on< K extends keyof WindowEventMap >(
		target: Window,
		type: K,
		handler: ( event: WindowEventMap[ K ] ) => void,
		options?: AddEventListenerOptions
	): void {
		target.addEventListener( type, handler, options );
		cleanupFns.push( () => target.removeEventListener( type, handler ) );
	}

	// Removes the listeners, observers and timers this run set up, and lets the
	// block be initialized again. Requests still in flight are not aborted;
	// their replies are dropped.
	function cleanup(): void {
		isDisposed = true;
		cancelPoll();
		stopRelativeDates();
		entrySeenObserver?.disconnect();
		cleanupFns.forEach( ( fn ) => fn() );
		cleanupFns.length = 0;
		delete root.dataset.rcInitialized;
	}

	/**
	 * Where the page scrolls to show the top of the block.
	 *
	 * @return {number} The vertical scroll position.
	 */
	function blockTopY(): number {
		const bars = topBarsBottom( newEntriesControl );

		return (
			root.getBoundingClientRect().top +
			window.scrollY -
			( bars > 0 ? bars + BAR_GAP : EDGE_GAP )
		);
	}

	/**
	 * Fades the linked entry's outline a few seconds after the reader first
	 * sees it. The wait starts only while the page is visible, so a link
	 * opened in a background tab still shows it.
	 *
	 * @return {void}
	 */
	function fadeLinkedOutline(): void {
		const linked = entriesList.querySelector( '[data-linked]' );

		if (
			! linked ||
			root.dataset.linkedFaded !== undefined ||
			typeof IntersectionObserver === 'undefined'
		) {
			return;
		}

		let isInView = false;
		let fadeTimeoutId: ReturnType< typeof setTimeout > | null = null;

		const startWhenSeen = () => {
			if (
				fadeTimeoutId === null &&
				isInView &&
				document.visibilityState === 'visible'
			) {
				fadeTimeoutId = setTimeout( stop, LINKED_OUTLINE_MS );
			}
		};

		const observer = new IntersectionObserver(
			( entries ) => {
				isInView = entries.some( ( entry ) => entry.isIntersecting );
				startWhenSeen();
			},
			{ threshold: 0 }
		);
		linkedObserver = observer;

		function stop(): void {
			root.dataset.linkedFaded = '';
			observer.disconnect();
			linkedObserver = null;
			document.removeEventListener( 'visibilitychange', startWhenSeen );
		}

		observer.observe( linked );
		document.addEventListener( 'visibilitychange', startWhenSeen );

		cleanupFns.push( () => {
			observer.disconnect();
			document.removeEventListener( 'visibilitychange', startWhenSeen );

			if ( fadeTimeoutId !== null ) {
				clearTimeout( fadeTimeoutId );
			}
		} );
	}

	/**
	 * Keeps the floating control below the bars at the top of the viewport.
	 * Without any, the stylesheet's position applies.
	 *
	 * @return {void}
	 */
	function placeControl(): void {
		if ( ! newEntriesControl || newEntriesControl.hidden ) {
			return;
		}

		const barsBottom = topBarsBottom( newEntriesControl );

		if ( barsBottom > 0 ) {
			newEntriesControl.style.setProperty(
				'--newspack-rolling-coverage-control-top',
				`${ barsBottom + BAR_GAP }px`
			);
		} else {
			newEntriesControl.style.removeProperty(
				'--newspack-rolling-coverage-control-top'
			);
		}
	}

	/**
	 * Finds this block in a fetched copy of the page: the block of the same
	 * coverage, at the same position among that coverage's blocks.
	 *
	 * @param {Document} doc The fetched page.
	 * @return {HTMLElement | null} The block, or null if the page doesn't hold it.
	 */
	function findBlockIn( doc: Document ): HTMLElement | null {
		const selector = `${ BLOCK_SELECTOR }[data-coverage-id="${ cssEscape(
			coverageId
		) }"]`;
		const position = Array.from(
			document.querySelectorAll< HTMLElement >( selector )
		).indexOf( root );

		return (
			doc.querySelectorAll< HTMLElement >( selector )[ position ] ?? null
		);
	}

	/**
	 * This page's stylesheet for a fetched page's, by id. Core serves a
	 * block's stylesheet inline (`…-inline-css`) or linked (`…-css`) depending
	 * on the page, so either form counts as the other.
	 *
	 * @param {string} id The fetched stylesheet's id.
	 * @return {HTMLElement | null} This page's element, or null if it has neither form.
	 */
	function ownStyleFor( id: string ): HTMLElement | null {
		const twinId = id.endsWith( '-inline-css' )
			? id.replace( /-inline-css$/, '-css' )
			: id.replace( /-css$/, '-inline-css' );

		return (
			document.getElementById( id ) ?? document.getElementById( twinId )
		);
	}

	/**
	 * The rules a fetched inline style holds and this page's copy of it
	 * doesn't. Throws where constructable stylesheets aren't supported.
	 *
	 * @param {HTMLStyleElement} style The fetched style.
	 * @param {HTMLStyleElement} own   This page's style of the same id.
	 * @return {string[]} The missing rules, as CSS text.
	 */
	function missingRules(
		style: HTMLStyleElement,
		own: HTMLStyleElement
	): string[] {
		if ( style.textContent === own.textContent ) {
			return [];
		}

		const rulesOf = ( css: string ) => {
			const sheet = new CSSStyleSheet();
			sheet.replaceSync( css );
			return Array.from( sheet.cssRules, ( rule ) => rule.cssText );
		};
		const ownRules = new Set( rulesOf( own.textContent ?? '' ) );

		return rulesOf( style.textContent ?? '' ).filter(
			( rule ) => ! ownRules.has( rule )
		);
	}

	/**
	 * Adds to this page the styles a fetched page has and this one doesn't,
	 * so blocks taken from it show styled here: core loads a block's styles
	 * only on pages that render the block. A stylesheet this page lacks goes
	 * where the fetched page has it among the stylesheets both pages share,
	 * as a block's own styles must come before the theme's for the theme's
	 * to apply. An inline style both pages have under one id, such as the
	 * theme's global styles or the block supports styles, holds only the
	 * rules of the blocks its page renders, so the rules this page's copy
	 * lacks are added in one style element after it. Waits for linked
	 * stylesheets to load, up to STYLES_TIMEOUT_MS or until the jump is
	 * aborted. Throws, so the jump navigates instead, when a missing
	 * stylesheet is deferred, disabled or guarded by a nonce, as a copy of
	 * it would not apply.
	 *
	 * @param {Document}    doc    The fetched page.
	 * @param {AbortSignal} signal Aborts the jump.
	 * @return {Promise<void>} Resolves when the styles are in place or the wait is over.
	 */
	async function adoptLiveStyles(
		doc: Document,
		signal: AbortSignal
	): Promise< void > {
		const styles = Array.from(
			doc.querySelectorAll< HTMLElement >(
				'link[rel="stylesheet"][id], style[id]'
			)
		).filter( ( style ) => ! style.closest( 'noscript' ) );
		const owns = styles.map( ( style ) => ownStyleFor( style.id ) );
		// Where each fetched stylesheet sits on this page, to place the
		// missing ones by. The block's entries are about to be replaced, so
		// nothing inside the block is placed by.
		const placed = owns.map( ( own ) =>
			own && ! root.contains( own ) ? own : null
		);
		const extraRules = new Map< HTMLStyleElement, string[] >();

		// Everything that can make the jump give up comes before any change.
		styles.forEach( ( style, index ) => {
			const own = owns[ index ];

			if ( ! own ) {
				if (
					[ 'onload', 'disabled', 'nonce' ].some( ( name ) =>
						style.hasAttribute( name )
					)
				) {
					throw new Error( 'The stylesheet cannot be copied.' );
				}

				return;
			}

			if (
				own instanceof HTMLStyleElement &&
				style instanceof HTMLStyleElement
			) {
				const rules = missingRules( style, own );

				if ( rules.length > 0 && style.hasAttribute( 'nonce' ) ) {
					throw new Error( 'The style rules cannot be copied.' );
				}

				if ( rules.length > 0 ) {
					extraRules.set( own, rules );
				}
			}
		} );

		extraRules.forEach( ( rules, own ) => {
			const extra = document.createElement( 'style' );
			const media = own.getAttribute( 'media' );

			extra.dataset.rcLiveFor = own.id;
			extra.textContent = rules.join( '\n' );

			if ( media ) {
				extra.setAttribute( 'media', media );
			}

			// One per style, however many times the jump runs on this page.
			const previous = Array.from(
				document.querySelectorAll< HTMLElement >(
					'style[data-rc-live-for]'
				)
			).find( ( element ) => element.dataset.rcLiveFor === own.id );

			if ( previous ) {
				previous.replaceWith( extra );
			} else {
				own.after( extra );
			}
		} );

		const loads: Promise< void >[] = [];

		styles.forEach( ( style, index ) => {
			if ( owns[ index ] ) {
				return;
			}

			// A fresh element, so nothing but the stylesheet itself comes along.
			const copy = document.createElement(
				style instanceof HTMLLinkElement ? 'link' : 'style'
			);
			const media = style.getAttribute( 'media' );

			copy.id = style.id;

			if ( media ) {
				copy.setAttribute( 'media', media );
			}

			if ( copy instanceof HTMLLinkElement ) {
				copy.rel = 'stylesheet';
				copy.href = style.getAttribute( 'href' ) ?? '';
				loads.push(
					new Promise( ( resolve ) => {
						copy.addEventListener( 'load', () => resolve() );
						copy.addEventListener( 'error', () => resolve() );
					} )
				);
			} else {
				copy.textContent = style.textContent;
			}

			// Before the next stylesheet both pages share, or else after the
			// nearest earlier one, counting those just placed.
			const next = placed.slice( index + 1 ).find( Boolean );
			const earlier = placed.slice( 0, index ).reverse().find( Boolean );

			if ( next ) {
				next.before( copy );
			} else if ( earlier ) {
				earlier.after( copy );
			} else {
				document.head.append( copy );
			}

			placed[ index ] = copy;
		} );

		if ( loads.length === 0 ) {
			return;
		}

		let timeoutId: ReturnType< typeof setTimeout > | undefined;

		await Promise.race( [
			Promise.all( loads ),
			new Promise< void >( ( resolve ) => {
				timeoutId = setTimeout( resolve, STYLES_TIMEOUT_MS );
				signal.addEventListener( 'abort', () => resolve(), {
					once: true,
				} );
			} ),
		] );

		clearTimeout( timeoutId );
	}

	/**
	 * Whether a fetched block can replace the shared view in place. It can't
	 * when it is itself a shared view, when the coverage's status has changed
	 * since this page rendered, as the Follow button and archived notice
	 * depend on it, when it holds ads, which need the page's own ad setup to
	 * run, or when its entries hold scripts or interactive blocks, which would
	 * never start.
	 *
	 * @param {HTMLElement | null} live The fetched block.
	 * @return {boolean} True if the block can be shown in place.
	 */
	function canShowInPlace( live: HTMLElement | null ): live is HTMLElement {
		const liveEntries = live?.querySelector(
			'.newspack-rolling-coverage-entries'
		);

		return (
			!! live &&
			!! liveEntries &&
			live.dataset.view !== 'entry' &&
			live.dataset.status === root.dataset.status &&
			! live.querySelector( '.newspack_global_ad' ) &&
			! liveEntries.querySelector( 'script, [data-wp-interactive]' ) &&
			!! live.querySelector(
				'.newspack-rolling-coverage-new-entries [data-rc-latest]'
			)
		);
	}

	/**
	 * Fetches the live feed's page and returns this block from it, when the
	 * block can replace the shared view in place.
	 *
	 * @param {string} url The live feed's URL.
	 * @return {Promise<Object | null>} The live block and the URL it came from, or null to navigate instead.
	 */
	async function fetchLiveBlock(
		url: string
	): Promise< { live: HTMLElement; url: string } | null > {
		const controller = new AbortController();
		const timeoutId = setTimeout(
			() => controller.abort(),
			JUMP_TIMEOUT_MS
		);
		let fetched: { live: HTMLElement; url: string } | null = null;

		try {
			const response = await fetch( url, { signal: controller.signal } );
			const doc = new DOMParser().parseFromString(
				response.ok ? await response.text() : '',
				'text/html'
			);
			const live = findBlockIn( doc );
			const finalUrl = new URL( response.url || url, url );

			// A page the request was redirected to on another site is left to
			// the link.
			if (
				finalUrl.origin === window.location.origin &&
				canShowInPlace( live )
			) {
				await adoptLiveStyles( doc, controller.signal );

				if ( ! controller.signal.aborted ) {
					fetched = {
						live,
						url: response.redirected ? finalUrl.toString() : url,
					};
				}
			}
		} catch {
			fetched = null;
		}

		clearTimeout( timeoutId );

		return fetched;
	}

	/**
	 * Turns the shared view into the live feed: takes over the live block's
	 * entries, cursors, status and control, then starts the block again on
	 * the same element, as a freshly loaded normal view.
	 *
	 * @param {HTMLElement} live The live block, from the fetched page.
	 * @param {string}      url  The live feed's URL.
	 * @return {void}
	 */
	function showLiveBlock( live: HTMLElement, url: string ): void {
		const liveEntries = live.querySelector< HTMLElement >(
			'.newspack-rolling-coverage-entries'
		);
		const liveControl = live.querySelector< HTMLElement >(
			'.newspack-rolling-coverage-new-entries'
		);
		const control = liveControl
			? parseElement( liveControl.outerHTML )
			: null;

		cleanup();

		entriesList.replaceChildren(
			parseFragment( liveEntries?.innerHTML ?? '' )
		);

		if ( control ) {
			newEntriesControl?.replaceWith( control );
		}

		(
			[ 'cursor', 'before', 'hasMore', 'templateKey', 'status' ] as const
		 ).forEach( ( key ) => {
			root.dataset[ key ] = live.dataset[ key ] ?? '';
		} );
		delete root.dataset.view;

		window.history.replaceState( window.history.state, '', url );

		initBlock( root );
	}

	/**
	 * Marks the control busy while the jump to the live feed runs, or ready
	 * again.
	 *
	 * @param {boolean} jumping Whether the jump is running.
	 * @return {void}
	 */
	function setJumping( jumping: boolean ): void {
		isJumping = jumping;

		if ( jumping ) {
			newEntriesControl?.setAttribute( 'aria-busy', 'true' );
		} else {
			newEntriesControl?.removeAttribute( 'aria-busy' );
		}
	}

	/**
	 * Replaces the shared view with the live feed without leaving the page,
	 * landing at the top of the block. Navigates to the live feed instead when
	 * it can't be shown in place.
	 *
	 * @param {string} url The live feed's URL.
	 * @return {Promise<void>} Resolves when the live feed shows or the page navigates.
	 */
	async function jumpToLatest( url: string ): Promise< void > {
		setJumping( true );
		announce(
			__( 'Loading the latest posts…', 'newspack-rolling-coverage' )
		);

		const fetched = await fetchLiveBlock( url );

		if ( isDisposed ) {
			return;
		}

		// A page restored by Back must find the control ready again.
		const navigate = () => {
			setJumping( false );
			window.location.assign( url );
		};

		if ( ! fetched ) {
			navigate();
			return;
		}

		const reducesMotion = window.matchMedia(
			'(prefers-reduced-motion: reduce)'
		).matches;
		// Once cleanup() has run, a failure would leave a block that does
		// nothing, so the live feed's own page takes over.
		const show = ( behavior: ScrollBehavior ) => {
			try {
				showLiveBlock( fetched.live, fetched.url );
				window.scrollTo( { top: blockTopY(), behavior } );
				entriesList.setAttribute( 'tabindex', '-1' );
				entriesList.addEventListener(
					'blur',
					() => entriesList.removeAttribute( 'tabindex' ),
					{ once: true }
				);
				entriesList.focus( { preventScroll: true } );
				announce(
					__(
						'Showing the latest posts.',
						'newspack-rolling-coverage'
					)
				);
			} catch {
				navigate();
			}
		};

		if (
			typeof document.startViewTransition === 'function' &&
			! reducesMotion
		) {
			document.startViewTransition( () => show( 'instant' ) );
			return;
		}

		show( reducesMotion ? 'instant' : 'smooth' );
	}

	if ( newEntriesControl && newEntriesLink ) {
		const onNewEntriesClick = ( event: MouseEvent ) => {
			// Any other click keeps the link's own behavior, e.g. a new tab.
			if (
				event.button !== 0 ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.altKey
			) {
				return;
			}

			if ( isEntryView ) {
				const liveUrl = newEntriesControl.dataset.liveUrl;
				const url = liveUrl
					? new URL( liveUrl, window.location.href )
					: null;

				// Ads need the live feed's own page, so the link navigates.
				if (
					! url ||
					url.origin !== window.location.origin ||
					root.dataset.ads === '1'
				) {
					return;
				}

				event.preventDefault();

				if ( ! isJumping ) {
					jumpToLatest( url.toString() );
				}

				return;
			}

			event.preventDefault();

			if ( pendingNewEntries.length === 0 ) {
				return;
			}

			const entries = takePendingEntries();
			newEntriesControl.hidden = true;

			const targetY = blockTopY();

			insertNewEntries( entries );

			window.scrollTo( {
				top: targetY,
				behavior: 'smooth',
			} );
		};
		newEntriesLink.addEventListener( 'click', onNewEntriesClick );
		cleanupFns.push( () =>
			newEntriesLink.removeEventListener( 'click', onNewEntriesClick )
		);
	}

	let scrollCheckScheduled = false;

	/**
	 * Reveals pending entries when the reader scrolls back to the top entry.
	 *
	 * @return {void}
	 */
	function checkIfScrolledBackToTop(): void {
		scrollCheckScheduled = false;

		if (
			isDisposed ||
			pendingNewEntries.length === 0 ||
			isScrolledPastTop()
		) {
			return;
		}

		const entries = takePendingEntries();

		if ( newEntriesControl ) {
			newEntriesControl.hidden = true;
		}

		insertNewEntries( entries );
	}

	const onScroll = () => {
		if ( scrollCheckScheduled ) {
			return;
		}

		scrollCheckScheduled = true;
		requestAnimationFrame( () => {
			checkIfScrolledBackToTop();
			placeControl();
		} );
	};
	window.addEventListener( 'scroll', onScroll, { passive: true } );
	cleanupFns.push( () => window.removeEventListener( 'scroll', onScroll ) );
	on( window, 'resize', onScroll );

	placeControl();

	// The browser lands on the hash target before the bars can be measured,
	// and a theme's offset for its sticky header leaves no room for the
	// control, so the margin is set on the target itself. Only a target still
	// where that landing put it is moved, never a page the reader has scrolled.
	const landingBars = topBarsBottom( newEntriesControl );

	if ( landingBars > 0 ) {
		let id = window.location.hash.slice( 1 );

		try {
			id = decodeURIComponent( id );
		} catch {
			// A malformed escape is matched as written.
		}

		const target = id
			? root.querySelector( `#${ cssEscape( id ) }` )
			: null;
		// Core pads the root's scroll area by the admin bar's height.
		const scrollPadding =
			parseFloat(
				window.getComputedStyle( document.documentElement )
					.scrollPaddingTop
			) || 0;
		const isAtLanding =
			target instanceof HTMLElement &&
			Math.abs(
				target.getBoundingClientRect().top -
					scrollPadding -
					parseFloat(
						window.getComputedStyle( target ).scrollMarginTop
					)
			) < 2;

		root.style.setProperty(
			'--newspack-rolling-coverage-scroll-offset',
			`${ landingBars - scrollPadding + BAR_GAP + CONTROL_HEIGHT }px`
		);

		if ( target instanceof HTMLElement ) {
			target.style.scrollMarginTop =
				target.dataset.linked === undefined
					? 'var(--newspack-rolling-coverage-scroll-offset)'
					: 'var(--newspack-rolling-coverage-linked-margin)';

			if ( isAtLanding ) {
				target.scrollIntoView( { behavior: 'instant' } );
			}
		}
	}

	fadeLinkedOutline();

	/**
	 * Applies a poll response to the entry list.
	 *
	 * Replaces edited entries immediately, and keeps edits to entries not yet
	 * on the page for loadMore(). Inserts or queues newly published entries
	 * based on the reader's scroll position. When the feed opens at a shared
	 * entry, new entries are added to the control's count instead of inserted.
	 * A capped feed inserts new entries at once, whatever the scroll position,
	 * and ignores edits to entries it doesn't show.
	 *
	 * @param {PollEntry[]} entries Entries from the poll response.
	 * @return {void}
	 */
	function applyPollResponse( entries: PollEntry[] ): void {
		const newEntries: PendingEntry[] = [];

		entries.forEach( ( entry ) => {
			if ( ! isSafeEntryId( entry.id ) ) {
				return;
			}

			const existing = entriesList.querySelector< HTMLElement >(
				`[data-entry-id="${ entry.id }"]`
			);

			const template = document.createElement( 'template' );
			template.innerHTML = sanitizeHtml( entry.html );
			const entryEl = template.content.firstElementChild as HTMLElement;

			if ( entry.type === 'update' && ! existing ) {
				if ( latestCap ) {
					return;
				}

				offPageUpdates.set( String( entry.id ), entry.html );
				return;
			}

			if ( ! entryEl ) {
				return;
			}

			if ( existing ) {
				// Preserve the entry's original arrival across the replace;
				// observeEntry() is a no-op if it was already reported as seen.
				unobserveEntry( existing );
				entryEl.dataset.arrival = existing.dataset.arrival;

				// The poll can't know which entry the page's link names.
				if ( existing.dataset.linked !== undefined ) {
					entryEl.dataset.linked = '';
					linkedObserver?.unobserve( existing );
					linkedObserver?.observe( entryEl );
				}

				existing.replaceWith( entryEl );

				if (
					existing.hasAttribute( 'data-pinned' ) !==
					entryEl.hasAttribute( 'data-pinned' )
				) {
					entriesList.insertBefore(
						entryEl,
						firstUnpinnedEntry( entryEl )
					);
				}

				observeEntry( entryEl );
				dropLastSeparator();

				return;
			}

			const adEl = entry.adHtml ? parseElement( entry.adHtml ) : null;

			newEntries.push( { el: entryEl, adSlot: entry.adSlot, adEl } );
		} );

		if ( newEntries.length === 0 ) {
			return;
		}

		if ( isEntryView ) {
			const countedBefore = countedEntryIds.size;

			newEntries.forEach( ( { el } ) => {
				if ( el.dataset.entryId ) {
					countedEntryIds.add( el.dataset.entryId );
				}
			} );

			if ( canCount && countedEntryIds.size !== countedBefore ) {
				showNewerCount();
				announce( newEntriesLabel( countedEntryIds.size ) );
			}

			return;
		}

		if ( ! latestCap && isScrolledPastTop() ) {
			queueNewEntries( newEntries );
		} else {
			insertNewEntries( newEntries );
		}
	}

	/**
	 * Drops the closing separator, after the entry group or at its end, and a
	 * closing pinned card's space below it, from the last entry once no more
	 * entries can load, as the server renders it (see
	 * Rolling_Coverage_Block::shape_entry_template()). Entries re-rendered by
	 * the poll, and a final page that comes back empty, don't know they're
	 * last.
	 */
	function dropLastSeparator(): void {
		if ( hasMore ) {
			return;
		}

		const entries = entriesList.querySelectorAll< HTMLElement >(
			':scope > [data-entry-id]'
		);

		const last = entries[ entries.length - 1 ];

		last?.querySelector(
			[
				':scope > .wp-block-separator:last-child',
				':scope > .newspack-rolling-coverage-regular-entry:last-child > .wp-block-separator:last-child',
				':scope > .newspack-rolling-coverage-regular-entry:last-child > .wp-block-group__inner-container > .wp-block-separator:last-child',
			].join( ', ' )
		)?.remove();
		last?.querySelector< HTMLElement >(
			':scope > .newspack-rolling-coverage-pinned-card:last-child'
		)?.style.removeProperty( 'margin-bottom' );
	}

	/**
	 * Checks whether an element is in or past the viewport.
	 *
	 * An element is treated as in or past the viewport when it is not further
	 * down or right of the visible area.
	 *
	 * @param {HTMLElement} element Element to check.
	 * @return {boolean} True if the element is in or past the viewport.
	 */
	function isInOrPastViewport( element: HTMLElement ): boolean {
		const bounding = element.getBoundingClientRect();
		return (
			bounding.right <=
				( window.innerWidth || document.documentElement.clientWidth ) &&
			bounding.bottom <=
				( window.innerHeight || document.documentElement.clientHeight )
		);
	}

	/**
	 * Finds the width of the nearest bounds container.
	 *
	 * Looks for an ancestor matching one of the given bounds selectors so a
	 * slot's available width can be measured against its real container instead
	 * of the full viewport.
	 *
	 * @param {HTMLElement} container       The ad slot's container element.
	 * @param {string[]}    boundsSelectors Selectors to search for a bounds container.
	 * @return {number} The bounds container's offset width, or 0 if none matched.
	 */
	function findBoundsWidth(
		container: HTMLElement,
		boundsSelectors: string[]
	): number {
		for ( const selector of boundsSelectors ) {
			const candidates =
				document.querySelectorAll< HTMLElement >( selector );
			for ( const candidate of candidates ) {
				if ( candidate.contains( container ) ) {
					return candidate.offsetWidth;
				}
			}
		}
		return 0;
	}

	/**
	 * Measures an ad slot against its rendered container.
	 *
	 * Filters the size map to widths that fit and reserves height when fixed
	 * height ads are enabled.
	 *
	 * @param {AdSlot}      adSlot    Ad slot definition.
	 * @param {HTMLElement} container The slot's container element, already in the DOM.
	 * @return {Record<string, number[][]>} The size map filtered to widths that fit.
	 */
	function measureAdSlot(
		adSlot: AdSlot,
		container: HTMLElement
	): Record< string, number[][] > {
		const boundsWidth = findBoundsWidth(
			container,
			adSlot.boundsSelectors
		);
		const sizeMap = { ...adSlot.sizeMap };

		const containerWidth = container.parentElement?.offsetWidth ?? 0;
		const availableWidth = boundsWidth
			? Math.max( boundsWidth, containerWidth ) + adSlot.boundsBleed
			: window.innerWidth;

		if ( boundsWidth > 0 ) {
			Object.keys( sizeMap ).forEach( ( viewportWidth ) => {
				if ( parseInt( viewportWidth, 10 ) > availableWidth ) {
					delete sizeMap[ viewportWidth ];
				}
			} );
		}

		if (
			adSlot.fixedHeight.active &&
			container.parentElement &&
			isInOrPastViewport( container )
		) {
			let height = 0;
			Object.keys( sizeMap ).forEach( ( viewportWidth ) => {
				if ( parseInt( viewportWidth, 10 ) < availableWidth ) {
					sizeMap[ viewportWidth ].forEach( ( size ) => {
						height = Math.max( height, size[ 1 ] );
					} );
				}
			} );

			let prop: 'height' | 'minHeight' = 'height';
			if (
				adSlot.fixedHeight.useMaxHeight &&
				adSlot.fixedHeight.maxHeight < height
			) {
				height = adSlot.fixedHeight.maxHeight;
				prop = 'minHeight';
			}

			container.parentElement.style[ prop ] = `${ height }px`;
		}

		return sizeMap;
	}

	/**
	 * Defines and displays GPT ad slots.
	 *
	 * The container divs must already exist in the DOM before this is called.
	 *
	 * @param {AdSlot[]} adSlots Ad slot definitions to display.
	 * @return {void}
	 */
	function displayAdSlots( adSlots: AdSlot[] ): void {
		if ( ! window.googletag || adSlots.length === 0 ) {
			return;
		}

		adSlots.forEach( ( adSlot ) => {
			const container = document.getElementById( adSlot.containerId );
			const sizeMap = container
				? measureAdSlot( adSlot, container )
				: adSlot.sizeMap;

			window.googletag.cmd.push( function () {
				const baseSizes = adSlot.fluid ? [ 'fluid' ] : [];
				const sizes = [ ...adSlot.sizes, ...baseSizes ];

				const slot = window.googletag
					.defineSlot( adSlot.path, sizes, adSlot.containerId )
					?.addService( window.googletag.pubads() );

				if ( ! slot ) {
					return;
				}

				Object.keys( adSlot.targeting ).forEach( ( key ) => {
					slot.setTargeting( key, adSlot.targeting[ key ] );
				} );

				const mapping = window.googletag.sizeMapping();

				Object.keys( sizeMap ).forEach( ( viewportWidth ) => {
					mapping.addSize(
						[ parseInt( viewportWidth, 10 ), 0 ],
						[ ...baseSizes, ...sizeMap[ viewportWidth ] ]
					);
				} );
				mapping.addSize( [ 0, 0 ], baseSizes );
				slot.defineSizeMapping( mapping.build() );

				window.googletag.display( adSlot.containerId );

				// Refresh the slot explicitly when initial load is disabled.
				if (
					window.googletag.getConfig( 'disableInitialLoad' )
						.disableInitialLoad
				) {
					window.googletag.pubads().refresh( [ slot ] );
				}
			} );
		} );
	}

	/**
	 * Decides whether an overflow reloads the page now. A reload that lands on
	 * a page cache copy from before the burst starts from the same cursor and
	 * overflows again, so a repeat reload for that cursor waits until
	 * OVERFLOW_RELOAD_RETRY_MS has passed. Polling carries on meanwhile.
	 *
	 * @return {boolean} True if the page should reload now.
	 */
	function shouldReloadForOverflow(): boolean {
		const storageKey = `newspack-rolling-coverage-overflow-reload-${ coverageId }`;

		try {
			const lastReload = JSON.parse(
				window.sessionStorage.getItem( storageKey ) || 'null'
			);

			if (
				lastReload?.cursor === cursor &&
				Date.now() - lastReload.time < OVERFLOW_RELOAD_RETRY_MS
			) {
				return false;
			}

			window.sessionStorage.setItem(
				storageKey,
				JSON.stringify( { cursor, time: Date.now() } )
			);
		} catch {
			// Without session storage there's no record of the last reload, so
			// reload as before.
		}

		return true;
	}

	/**
	 * Polls for new and edited entries.
	 *
	 * Fetches entries modified at or after the cursor and applies them. Also
	 * passes the running ad counter so the server can continue the interval
	 * across poll batches. Takes the place of a poll already scheduled, and
	 * does nothing while another poll is in flight or the page is hidden.
	 *
	 * @return {Promise<void>} Resolves when the poll response has been handled.
	 */
	async function poll(): Promise< void > {
		if ( ! cursor || isPolling || document.hidden ) {
			return;
		}

		cancelPoll();
		isPolling = true;

		try {
			const url = new URL( restBaseUrl );
			url.searchParams.set( 'cursor', cursor );
			url.searchParams.set( 'template_key', templateKey );

			// A capped feed shows no Share or ads, so it leaves out the page
			// and ad count; every page holding it then shares one cached reply.
			if ( latestCap ) {
				url.searchParams.set( 'latest', String( latestCap ) );
			} else {
				url.searchParams.set( 'host_post_id', hostPostId );
				url.searchParams.set( 'polled_count', polledCount.toString() );
			}

			const response = await fetchEntries( url.toString() );
			if ( response.ok ) {
				const data: PollResponse = await response.json();

				// The block was cleaned up meanwhile, so this reply is no longer its own.
				if ( isDisposed ) {
					return;
				}

				minPollInterval = Number( data.minPollInterval ) || 0;

				if ( typeof data.status === 'string' ) {
					document.dispatchEvent(
						new CustomEvent< PollEventDetail >( POLL_EVENT, {
							detail: {
								coverageId: Number( coverageId ),
								status: data.status,
								newestEntry: data.newestEntry ?? null,
							},
						} )
					);
				}

				// Pages rendered before the coverage ended, open or cached, close
				// up the way a fresh render does.
				if (
					data.status === 'archived' &&
					root.dataset.hideWhenEnded === 'true'
				) {
					cleanup();
					root.remove();
					return;
				}

				if ( data.overflow && isEntryView ) {
					// A reload lands on the same shared URL, so there is nothing
					// to gain from one, and every later poll overflows from the
					// same cursor: polling ends here, and with it the count.
					canCount = false;
					showNewerCount();
					return;
				}

				// A capped feed shares its page with other content, so it never
				// reloads it; its polls send the newest entries instead.
				if (
					data.overflow &&
					! latestCap &&
					shouldReloadForOverflow()
				) {
					window.location.reload();
					return;
				}

				if ( data.entries.length > 0 ) {
					applyPollResponse( data.entries );
				}
				cursor = data.cursor || cursor;
				polledCount = data.polledCount ?? polledCount;
				isForwardPollHealthy = true;
			} else if ( isForwardPollHealthy ) {
				trackPollError( 'poll' );
				isForwardPollHealthy = false;
			}
		} catch ( error ) {
			if ( isForwardPollHealthy ) {
				trackPollError( 'poll' );
				isForwardPollHealthy = false;
			}

			// Network hiccups shouldn't break the page; the next poll interval retries.
			console.error( error ); // eslint-disable-line no-console
		} finally {
			isPolling = false;
		}

		if ( ! isDisposed ) {
			schedulePoll();
		}
	}

	/**
	 * Swaps an entry from a load-more reply for the edit the poll delivered
	 * while it was off the page, if there is one, keeping the entry's arrival.
	 *
	 * @param {HTMLElement} el Entry element from the load-more reply.
	 * @return {HTMLElement} The element that now stands in the reply.
	 */
	function applyOffPageUpdate( el: HTMLElement ): HTMLElement {
		const entryId = el.dataset.entryId;
		const html = entryId ? offPageUpdates.get( entryId ) : undefined;

		if ( ! entryId || html === undefined ) {
			return el;
		}

		offPageUpdates.delete( entryId );

		const updatedEl = parseElement( sanitizeHtml( html ) );

		if ( ! updatedEl ) {
			return el;
		}

		updatedEl.dataset.arrival = el.dataset.arrival;
		el.replaceWith( updatedEl );

		return updatedEl;
	}

	/**
	 * Loads and appends the next page of older entries.
	 *
	 * Sends the backlog position so ad placement stays stable across load-more
	 * pages.
	 *
	 * @return {Promise<void>} Resolves when the next page has been handled.
	 */
	async function loadMore(): Promise< void > {
		if ( isLoadingMore || ! hasMore || ! before ) {
			return;
		}
		isLoadingMore = true;

		try {
			const url = new URL( restBaseUrl );
			url.searchParams.set( 'before', before );
			url.searchParams.set( 'per_page', String( entriesPerPage ) );
			url.searchParams.set( 'template_key', templateKey );
			url.searchParams.set( 'entry_offset', backlogOffset.toString() );

			if ( latestCap ) {
				url.searchParams.set( 'latest', String( latestCap ) );
			} else {
				url.searchParams.set( 'host_post_id', hostPostId );
			}

			if ( isEntryView ) {
				url.searchParams.set( 'skip_pinned', '1' );
			}

			const response = await fetchEntries( url.toString() );
			if ( response.ok ) {
				const data: PageResponse = await response.json();

				// The block was cleaned up meanwhile, so this reply is no longer its own.
				if ( isDisposed ) {
					return;
				}

				if ( data.count > 0 ) {
					const fragment = parseFragment( data.html );

					// Count how many entries were appended so the next page's offset can be correct.
					let appended = 0;

					// Defensive: never append an entry that is already in the list.
					Array.from( fragment.children ).forEach( ( child ) => {
						if (
							! ( child instanceof HTMLElement ) ||
							! child.dataset.entryId
						) {
							return;
						}

						const existing = entriesList.querySelector(
							`[data-entry-id="${ cssEscape(
								child.dataset.entryId
							) }"]`
						);
						if ( existing ) {
							child.remove();
							return;
						}

						observeEntry( applyOffPageUpdate( child ) );
						appended++;
					} );

					entriesList.appendChild( fragment );
					backlogOffset += appended;
				}
				if ( data.adSlots && data.adSlots.length > 0 ) {
					displayAdSlots( data.adSlots );
				}
				hasMore = data.hasMore;
				before = data.before || '';
				dropLastSeparator();
			} else {
				hasMore = false;

				trackPollError( 'load_more' );
			}
		} catch ( error ) {
			trackPollError( 'load_more' );

			// Leave hasMore as-is; retried if the sentinel intersects again.
			console.error( error ); // eslint-disable-line no-console
		} finally {
			isLoadingMore = false;
		}
	}

	entriesList
		.querySelectorAll< HTMLElement >( '[data-entry-id]' )
		.forEach( observeEntry );

	if ( cursor && status === 'active' ) {
		schedulePoll();
	}

	// Resume polling when the user returns to the tab; cancel when they leave.
	const onVisibilityChange = () => {
		if ( document.hidden ) {
			cancelPoll();
		} else if ( cursor && status === 'active' ) {
			poll();
		}
	};
	document.addEventListener( 'visibilitychange', onVisibilityChange );
	cleanupFns.push( () =>
		document.removeEventListener( 'visibilitychange', onVisibilityChange )
	);

	// Clean up on unload, but not when the page enters the back/forward cache.
	on(
		window,
		'pagehide',
		( event ) => {
			if ( ! event.persisted ) {
				cleanup();
			}
		},
		{ once: true }
	);

	// Resume polling after a BFCache restore, with the control ready again if
	// the page was left during a jump.
	on( window, 'pageshow', ( event ) => {
		if ( ! event.persisted ) {
			return;
		}

		setJumping( false );

		if ( cursor && status === 'active' ) {
			schedulePoll();
		}
	} );

	/**
	 * Sticks each sticky pinned card below the bars fixed or stuck at the top
	 * of the viewport, such as the admin bar and a sticky site header, or at
	 * the stylesheet's top when there are none. Lets a card taller than the
	 * viewport below that top scroll with the page, so its end isn't hidden
	 * until the feed ends, and makes it sticky again once it fits.
	 *
	 * @return {void}
	 */
	function fitStickyCards(): void {
		root.querySelectorAll< HTMLElement >(
			'.newspack-rolling-coverage-pinned-card.is-position-sticky'
		).forEach( ( card ) => {
			const bars = topBarsBottom( card );

			if ( bars > 0 ) {
				card.style.top = `${ bars }px`;
			} else {
				card.style.removeProperty( 'top' );
			}

			const top =
				bars || parseFloat( window.getComputedStyle( card ).top ) || 0;

			if (
				card.getBoundingClientRect().height >
				window.innerHeight - top
			) {
				card.style.position = 'static';
			} else {
				card.style.removeProperty( 'position' );
			}
		} );
	}

	const stickyCardObserver =
		typeof ResizeObserver === 'undefined'
			? null
			: new ResizeObserver( ( entries ) => {
					entries.forEach( ( { target } ) => {
						if ( ! target.isConnected ) {
							stickyCardObserver?.unobserve( target );
						}
					} );
					fitStickyCards();
				} );

	/**
	 * Watches each sticky pinned card in the block for changes in its size,
	 * and fits them all.
	 *
	 * @return {void}
	 */
	function watchStickyCards(): void {
		root.querySelectorAll< HTMLElement >(
			'.newspack-rolling-coverage-pinned-card.is-position-sticky'
		).forEach( ( card ) => stickyCardObserver?.observe( card ) );
		fitStickyCards();
	}

	// Entries are inserted, replaced and appended in several places, the
	// pinned card among them, so the list itself is watched.
	const entriesListObserver = new MutationObserver( watchStickyCards );
	entriesListObserver.observe( entriesList, { childList: true } );
	watchStickyCards();
	on( window, 'resize', fitStickyCards );
	cleanupFns.push( () => {
		entriesListObserver.disconnect();
		stickyCardObserver?.disconnect();
	} );

	if ( sentinel && hasMore ) {
		const observer = new IntersectionObserver( ( entries ) => {
			entries.forEach( ( entry ) => {
				if ( entry.isIntersecting ) {
					loadMore().then( () => {
						if ( ! hasMore ) {
							observer.disconnect();
						}
					} );
				}
			} );
		} );
		observer.observe( sentinel );
		cleanupFns.push( () => observer.disconnect() );
	}
}

document.querySelectorAll< HTMLElement >( BLOCK_SELECTOR ).forEach( initBlock );
