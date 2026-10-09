/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.scss';
import { trackEvent, isConfigEnabled, EVENTS } from './analytics';
import { keepRelativeDatesCurrent } from '../shared/relative-dates';
import { POLL_EVENT } from '../shared/poll-event';
import {
	CHECK_EVENT,
	type CheckEventDetail,
	type CheckResult,
	type CheckState,
} from '../shared/check-event';
import {
	entriesAddedButtonLabel,
	entriesAddedLabel,
	loadingLatestLabel,
	loadMoreFailedLabel,
	newEntriesLabel,
	newerEntriesLabel,
	noNewEntriesLabel,
	readEntryName,
	showingLatestLabel,
} from './entry-name';
import type { PollEventDetail } from '../shared/poll-event';
import type {
	AdSlot,
	PendingEntry,
	PollEntry,
	PollResponse,
	PageResponse,
} from './types';

const BLOCK_SELECTOR = '.wp-block-newspack-rolling-coverage-rolling-coverage';

// How long the Check for Updates button shows the result of a check.
const CHECK_MESSAGE_MS = 3000;

/**
 * How a poll ended: with a reply it applied, a failed request, a reload of
 * the page, or not at all because another poll was running or the page was
 * hidden.
 */
type PollOutcome = 'ok' | 'failed' | 'reloading' | 'skipped';

const STICKY_CARD_SELECTOR =
	'.newspack-rolling-coverage-pinned-card.is-position-sticky';

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

// Each running feed's cleanup(), by its outer wrapper element, so the page can
// stop a feed that leaves it.
const feedStops = new WeakMap< HTMLElement, () => void >();

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
 * The declarations of a block of CSS, as property and value pairs.
 *
 * @param {string} css CSS declarations, such as `border-top-width:3px;`.
 * @return {string[][]} The declarations.
 */
function cssDeclarations( css: string ): string[][] {
	return css
		.split( ';' )
		.map( ( declaration ) => {
			const colon = declaration.indexOf( ':' );

			return colon > 0
				? [
						declaration.slice( 0, colon ).trim(),
						declaration.slice( colon + 1 ).trim(),
					]
				: [];
		} )
		.filter( ( [ property, value ] ) => property && value );
}

// The attributes where a data: URL could open a document: frame sources and
// link or form targets. Other URL attributes, like srcset, only load images.
const URL_ATTRIBUTES = [ 'href', 'src', 'xlink:href', 'action', 'formaction' ];

/**
 * Removes active content from an HTML fragment before it is inserted into the
 * page: scripts, object/embed, base, http-equiv meta and SVG animate and set
 * elements, inline event handlers, an iframe's srcdoc, javascript: and
 * vbscript: URLs in any attribute, and data: URLs in the attributes listed in
 * URL_ATTRIBUTES. Every fragment a feed inserts client-side passes through
 * here — entries and ad markup alike, from a poll, load more or the jump to
 * the live feed. The fetches accept only same-origin replies of the
 * type the feed expects, so this is a second line behind them against markup
 * an account without unfiltered_html could plant. A provider's ad placeholder
 * survives it; the ad's own script loads the creative later.
 *
 * @param {string} html Raw HTML a feed inserts: a REST reply, or the live feed page.
 * @return {string} Sanitized HTML safe for DOM insertion.
 */
function sanitizeHtml( html: string ): string {
	const doc = new DOMParser().parseFromString( html, 'text/html' );

	// Elements that run or embed active content, or act on the page once
	// inserted: base re-points relative links, an http-equiv meta can redirect,
	// and SVG animation can set a link's href to a javascript: URL.
	doc.querySelectorAll(
		'script, object, embed, base, meta[http-equiv], animate, set'
	).forEach( ( el ) => el.remove() );

	doc.querySelectorAll( '*' ).forEach( ( el ) => {
		Array.from( el.attributes ).forEach( ( attr ) => {
			const name = attr.name.toLowerCase();

			// on* event handlers, and an iframe's inline srcdoc document.
			if ( name.startsWith( 'on' ) || name === 'srcdoc' ) {
				el.removeAttribute( attr.name );
				return;
			}

			// The scheme as the browser's URL parser reads it: leading spaces
			// and control characters trimmed, tabs and line breaks dropped.
			const value = attr.value
				.replace( /^[\u0000- ]+/, '' )
				.replace( /[\t\n\r]/g, '' )
				.toLowerCase();

			// javascript: and vbscript: go from any attribute, which also covers
			// a data-* value a script later uses as a link. data: goes only from
			// URL attributes, so an alt text or caption that opens with "Data:"
			// survives.
			if (
				/^(?:javascript|vbscript):/.test( value ) ||
				( value.startsWith( 'data:' ) &&
					URL_ATTRIBUTES.includes( name ) )
			) {
				el.removeAttribute( attr.name );
			}
		} );
	} );

	return doc.body.innerHTML;
}

/**
 * Whether a URL resolves to the page's own origin. A malformed URL is treated
 * as off-origin.
 *
 * @param {string} url URL, absolute or relative to the page.
 * @return {boolean} True when the URL is same-origin with the page.
 */
function isSameOrigin( url: string ): boolean {
	try {
		return (
			new URL( url, window.location.href ).origin ===
			window.location.origin
		);
	} catch {
		return false;
	}
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
 * The elements in a block that match a selector, leaving out those of a feed
 * nested in one of its entries. A nested feed uses the same classes and, for
 * the same coverage, the same entry IDs. It comes before the block's later
 * entries and its sentinel, so a plain query can return its elements instead
 * of the block's own.
 *
 * @param {HTMLElement} block    The block's outer wrapper element.
 * @param {string}      selector Selector to match.
 * @param {HTMLElement} [within] Part of the block to look in; all of it by default.
 * @return {HTMLElement[]} The block's own matching elements, in document order.
 */
function ownElements(
	block: HTMLElement,
	selector: string,
	within: HTMLElement = block
): HTMLElement[] {
	return Array.from(
		within.querySelectorAll< HTMLElement >( selector )
	).filter( ( element ) => element.closest( BLOCK_SELECTOR ) === block );
}

/**
 * The first of a block's own elements that match a selector (see
 * ownElements()).
 *
 * @param {HTMLElement} block    The block's outer wrapper element.
 * @param {string}      selector Selector to match.
 * @param {HTMLElement} [within] Part of the block to look in; all of it by default.
 * @return {HTMLElement | null} The element, or null if the block has none of its own.
 */
function ownElement(
	block: HTMLElement,
	selector: string,
	within: HTMLElement = block
): HTMLElement | null {
	return ownElements( block, selector, within )[ 0 ] ?? null;
}

/**
 * The feeds in a node, the node itself included, in document order.
 *
 * @param {Node} node Node to look in.
 * @return {HTMLElement[]} The feeds' outer wrapper elements.
 */
function feedsIn( node: Node ): HTMLElement[] {
	if ( ! ( node instanceof HTMLElement ) ) {
		return [];
	}

	const feeds = Array.from(
		node.querySelectorAll< HTMLElement >( BLOCK_SELECTOR )
	);

	return node.matches( BLOCK_SELECTOR ) ? [ node, ...feeds ] : feeds;
}

/**
 * Moves focus to an element that takes none of its own, such as an entry,
 * making it focusable until it loses focus.
 *
 * @param {HTMLElement} element   The element to focus.
 * @param {Object}      [options] Focus options.
 * @return {void}
 */
function focusFromScript( element: HTMLElement, options?: FocusOptions ): void {
	if ( ! element.hasAttribute( 'tabindex' ) ) {
		element.setAttribute( 'tabindex', '-1' );
		element.addEventListener(
			'blur',
			() => element.removeAttribute( 'tabindex' ),
			{ once: true }
		);
	}

	element.focus( options );
}

/**
 * How far down the viewport the fixed and sticky elements over its top centre
 * reach, such as the admin bar and a sticky site header, so the floating
 * control and the sticky pinned cards can sit below them. An element taller
 * than half the viewport is an overlay rather than a header, and is passed
 * over.
 *
 * @param {HTMLElement} block The block, whose own floating control and sticky cards are never counted.
 * @return {number} Distance from the top of the viewport, in pixels.
 */
function topBarsBottom( block: HTMLElement ): number {
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
				! block.contains( node ) &&
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
 * The reply is dropped when a redirect carried the request to another origin,
 * so the feed stays on this site as initBlock() required of its URL, and when
 * a successful reply isn't JSON: the entries route always answers in JSON, so
 * a same-origin file such as an upload can't stand in for it.
 *
 * @param {string} url Entries URL.
 * @return {Promise<Response>} The reply, or a failed one when it came from another origin or isn't JSON.
 */
async function fetchEntries( url: string ): Promise< Response > {
	const credentials = entriesCredentials;
	let response = await fetch( url, { credentials } );
	const isRefused = response.status === 401 || response.status === 403;

	if ( credentials === 'omit' && isRefused ) {
		entriesCredentials = 'same-origin';
		response = await fetch( url, { credentials: entriesCredentials } );
	}

	const isJson = ( response.headers.get( 'content-type' ) ?? '' )
		.toLowerCase()
		.includes( 'json' );

	if ( ! isSameOrigin( response.url ) || ( response.ok && ! isJson ) ) {
		return new Response( null, { status: 502 } );
	}

	return response;
}

/**
 * Sets up polling and the loading of older entries for a single block instance.
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
	const entriesListEl = ownElement(
		root,
		'.newspack-rolling-coverage-entries'
	);

	if ( ! restUrl || ! entriesListEl ) {
		return;
	}

	// The feed's REST URL comes from the block's markup, which an author
	// without unfiltered_html can still set. Honour it only when it points at
	// this site, so a planted root can't make the page fetch entries from
	// another origin and insert the reply. A page served from a host other
	// than the REST URL's, such as an alias domain nothing redirects, stays a
	// static first page; the warning says why.
	if ( ! isSameOrigin( restUrl ) ) {
		// eslint-disable-next-line no-console
		console.warn(
			'Rolling Coverage: not starting a feed whose REST URL is on another origin.',
			restUrl
		);
		return;
	}

	const entriesList: HTMLElement = entriesListEl;
	const restBaseUrl: string = restUrl;

	const stopRelativeDates = keepRelativeDatesCurrent( root, entriesList );

	const pollInterval = parseInt( root.dataset.pollInterval || '10', 10 );
	const entryName = readEntryName( root );
	const entriesPerPage = parseInt( root.dataset.entriesPerPage || '20', 10 );
	const templateKey = root.dataset.templateKey || '';
	const hostPostId = root.dataset.hostPostId || '0';
	const sentinel = ownElement( root, '.newspack-rolling-coverage-sentinel' );
	const loadMoreControl = ownElement(
		root,
		'.newspack-rolling-coverage-load-more'
	);
	const loadMoreButton =
		loadMoreControl?.querySelector< HTMLButtonElement >( 'button' ) ?? null;
	const loadMoreLabel = loadMoreButton?.textContent ?? '';
	const newEntriesControl = ownElement(
		root,
		'.newspack-rolling-coverage-new-entries'
	);
	const newEntriesLink =
		newEntriesControl?.querySelector< HTMLElement >( '[data-rc-latest]' ) ??
		null;
	const statusEl = ownElement( root, '.newspack-rolling-coverage-status' );
	const checkControls = ownElements(
		root,
		'.newspack-rolling-coverage-check-updates'
	);
	const checkButtons = checkControls.flatMap(
		( control ) =>
			control.querySelector< HTMLButtonElement >( 'button' ) ?? []
	);
	// Each button's own label, as the layout sets it.
	const checkLabels = new Map(
		checkButtons.map( ( button ) => [ button, button.textContent ?? '' ] )
	);

	const status = root.dataset.status || 'active';
	const isEntryView = root.dataset.view === 'entry';
	// The feed checks for new entries only when the reader asks.
	const checksOnRequest = root.dataset.newEntries === 'button';

	// A Lite Site page renders entries as text, so it asks for them that way.
	const isLite = root.dataset.lite === '1';
	const showsEntries = root.dataset.entries !== 'none';

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

	// The coverage status the last poll reported.
	let polledStatus = status;

	// When the reader last checked for new entries, in milliseconds.
	let lastCheckAt = 0;
	let checkLabelTimeoutId: ReturnType< typeof setTimeout > | null = null;
	let isChecking = false;

	// How many new entries have been added to the page since it loaded.
	let insertedCount = 0;

	// How many new entries polls have brought, shown or waiting to be.
	let arrivedCount = 0;

	// What the last poll found, for the next check report.
	let checkResult: CheckResult | undefined;

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

	// Entries the poll reported taken down. One that comes back shows on
	// reload, not before: polls, load more and a capped feed's whole replies
	// all leave it out, cached load-more replies from before the removal too.
	const removedEntryIds = new Set< string >();

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
			? newerEntriesLabel( newerCount + countedEntryIds.size, entryName )
			: '';
		const text = label || ownLabel;

		// Writing only a change keeps a label holding markup as the server rendered it.
		if ( newEntriesLink.textContent !== text ) {
			newEntriesLink.textContent = text;
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
	 * Tells the page where the feed's check for new entries stands, on its
	 * root and through CHECK_EVENT. A feed that checks only when asked, or
	 * whose coverage isn't live, reports idle: it isn't counting down to
	 * anything a reader can wait for.
	 *
	 * @param {CheckState} state            The state.
	 * @param {Object}     next             When waiting, when the next check is due.
	 * @param {number}     next.nextCheckAt When the next check is due, in epoch milliseconds.
	 * @return {void}
	 */
	function reportCheck(
		state: CheckState,
		next?: { nextCheckAt: number }
	): void {
		const shown =
			isDisposed || checksOnRequest || polledStatus !== 'active'
				? 'idle'
				: state;
		const detail: CheckEventDetail = {
			coverageId: Number( coverageId ),
			feed: root,
			state: shown,
		};

		root.dataset.checkState = shown;

		if ( shown === 'waiting' && next ) {
			root.dataset.nextCheckAt = String( next.nextCheckAt );
			detail.nextCheckAt = next.nextCheckAt;
		} else {
			delete root.dataset.nextCheckAt;
		}

		if ( shown !== 'checking' && checkResult ) {
			detail.result = checkResult;
			checkResult = undefined;
		}

		document.dispatchEvent(
			new CustomEvent< CheckEventDetail >( CHECK_EVENT, { detail } )
		);
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

		if ( checksOnRequest || isPolling || document.hidden ) {
			if ( ! isPolling ) {
				reportCheck( 'idle' );
			}

			return;
		}

		const interval = Math.max( pollInterval, minPollInterval ) * 1000;

		pollTimeoutId = setTimeout( poll, interval );
		reportCheck( 'waiting', {
			nextCheckAt: Date.now() + interval,
		} );
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

		insertedCount += entries.length;

		ownElement(
			root,
			'.newspack-rolling-coverage-entries__empty',
			entriesList
		)?.remove();

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

		announce( entriesAddedLabel( shown, entryName ) );

		if ( adSlotsToDisplay.length > 0 ) {
			displayAdSlots( adSlotsToDisplay );
		}
	}

	/**
	 * Swaps a capped feed's entries for the newest ones a poll sent whole,
	 * leaving out entries taken down, in this reply or earlier, and keeping
	 * the arrival of those it already showed.
	 *
	 * @param {PollEntry[]} entries Removals, then the newest entries, newest first.
	 * @return {void}
	 */
	function replaceEntries( entries: PollEntry[] ): void {
		entries.forEach( ( entry ) => {
			if ( entry.type === 'remove' && isSafeEntryId( entry.id ) ) {
				removedEntryIds.add( String( entry.id ) );
			}
		} );

		const shownEntries = ownElements(
			root,
			':scope > [data-entry-id]',
			entriesList
		);
		const arrivals = new Map(
			shownEntries.map( ( el ) => [
				el.dataset.entryId,
				el.dataset.arrival,
			] )
		);
		const fragment = document.createDocumentFragment();

		const kept = entries
			.filter(
				( entry ) =>
					entry.type !== 'remove' &&
					isSafeEntryId( entry.id ) &&
					! removedEntryIds.has( String( entry.id ) )
			)
			.slice( 0, latestCap || entries.length );
		const firstShown = kept.findIndex( ( entry ) =>
			arrivals.has( String( entry.id ) )
		);

		arrivedCount += firstShown === -1 ? kept.length : firstShown;

		kept.forEach( ( entry ) => {
			const el = parseElement( sanitizeHtml( entry.html ) );

			if ( ! el ) {
				return;
			}

			if ( arrivals.has( String( entry.id ) ) ) {
				el.dataset.arrival = arrivals.get( String( entry.id ) );
			}

			observeEntry( el );
			fragment.appendChild( el );
		} );

		shownEntries.forEach( ( el ) => {
			unobserveEntry( el );
			el.remove();
		} );

		if ( fragment.childElementCount > 0 ) {
			ownElement(
				root,
				'.newspack-rolling-coverage-entries__empty',
				entriesList
			)?.remove();
		}

		entriesList.appendChild( fragment );
		dropLastSeparator();
	}

	/**
	 * Adds entries to the pending queue.
	 *
	 * Updates the "X New Entries" control label and visibility.
	 *
	 * @param {PendingEntry[]} newEntries Newly published entries.
	 * @return {void}
	 */
	function queueNewEntries( newEntries: PendingEntry[] ): void {
		pendingNewEntries.unshift( ...newEntries );

		if ( ! newEntriesControl || ! newEntriesLink ) {
			return;
		}

		const label = newEntriesLabel( pendingNewEntries.length, entryName );

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

	/**
	 * Takes an entry that was taken down off the page and out of the new
	 * entries waiting to be shown, the count of newer entries and the edits
	 * kept for load more.
	 *
	 * @param {string} entryId Entry ID.
	 * @return {void}
	 */
	function removeEntry( entryId: string ): void {
		const existing = ownElement(
			root,
			`[data-entry-id="${ entryId }"]`,
			entriesList
		);

		if ( existing ) {
			unobserveEntry( existing );
			linkedObserver?.unobserve( existing );
			existing.remove();
			dropLastSeparator();
		}

		const waiting = pendingNewEntries.length;

		pendingNewEntries = pendingNewEntries.filter(
			( { el } ) => el.dataset.entryId !== entryId
		);

		if (
			pendingNewEntries.length !== waiting &&
			newEntriesControl &&
			newEntriesLink
		) {
			if ( pendingNewEntries.length > 0 ) {
				newEntriesLink.textContent = newEntriesLabel(
					pendingNewEntries.length,
					entryName
				);
			} else {
				newEntriesControl.hidden = true;
			}
		}

		if ( countedEntryIds.delete( entryId ) ) {
			showNewerCount();
		}

		offPageUpdates.delete( entryId );
		removedEntryIds.add( entryId );
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
		reportCheck( 'idle' );
		stopRelativeDates();
		entrySeenObserver?.disconnect();
		cleanupFns.forEach( ( fn ) => fn() );
		cleanupFns.length = 0;
		feedStops.delete( root );
		delete root.dataset.rcInitialized;
	}

	feedStops.set( root, cleanup );

	/**
	 * Where the page scrolls to show the top of the block.
	 *
	 * @return {number} The vertical scroll position.
	 */
	function blockTopY(): number {
		const bars = topBarsBottom( root );

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
		const linked = ownElement( root, '[data-linked]', entriesList );

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
	 * @param {number} [measuredBars] The bars' bottom, when the caller already measured it this frame.
	 * @return {void}
	 */
	function placeControl( measuredBars?: number ): void {
		if ( ! newEntriesControl || newEntriesControl.hidden ) {
			return;
		}

		const barsBottom = measuredBars ?? topBarsBottom( root );

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
			// Only a page can hold the live feed; a same-origin file such as
			// an upload is left to the link.
			const isHtml = ( response.headers.get( 'content-type' ) ?? '' )
				.toLowerCase()
				.includes( 'text/html' );
			const doc = new DOMParser().parseFromString(
				response.ok && isHtml ? await response.text() : '',
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
			? parseElement( sanitizeHtml( liveControl.outerHTML ) )
			: null;

		cleanup();

		entriesList.replaceChildren(
			parseFragment( sanitizeHtml( liveEntries?.innerHTML ?? '' ) )
		);

		if ( control ) {
			newEntriesControl?.replaceWith( control );
		}

		(
			[
				'cursor',
				'before',
				'hasMore',
				'templateKey',
				'status',
				'entryName',
			] as const
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
		announce( loadingLatestLabel( entryName ) );

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
				focusFromScript( entriesList, { preventScroll: true } );
				announce( showingLatestLabel( entryName ) );
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

			const showsControl =
				newEntriesControl && ! newEntriesControl.hidden;
			const bars =
				showsControl || ownElement( root, STICKY_CARD_SELECTOR )
					? topBarsBottom( root )
					: 0;

			placeControl( bars );
			fitStickyCards( true, bars );
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
	const landingBars = topBarsBottom( root );

	if ( landingBars > 0 ) {
		let id = window.location.hash.slice( 1 );

		try {
			id = decodeURIComponent( id );
		} catch {
			// A malformed escape is matched as written.
		}

		const target = id ? ownElement( root, `#${ cssEscape( id ) }` ) : null;
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
	 * on the page for loadMore(). Drops entries taken down, and leaves one
	 * that comes back for reload. Inserts or queues newly published entries
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

			if ( entry.type === 'remove' ) {
				removeEntry( String( entry.id ) );
				return;
			}

			if ( removedEntryIds.has( String( entry.id ) ) ) {
				return;
			}

			const existing = ownElement(
				root,
				`[data-entry-id="${ entry.id }"]`,
				entriesList
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

			const adEl = entry.adHtml
				? parseElement( sanitizeHtml( entry.adHtml ) )
				: null;

			newEntries.push( { el: entryEl, adSlot: entry.adSlot, adEl } );
		} );

		if ( newEntries.length === 0 ) {
			return;
		}

		arrivedCount += newEntries.length;

		if ( isEntryView ) {
			const countedBefore = countedEntryIds.size;

			newEntries.forEach( ( { el } ) => {
				if ( el.dataset.entryId ) {
					countedEntryIds.add( el.dataset.entryId );
				}
			} );

			if ( canCount && countedEntryIds.size !== countedBefore ) {
				showNewerCount();
				announce( newEntriesLabel( countedEntryIds.size, entryName ) );
			}

			return;
		}

		if ( ! latestCap && ! checksOnRequest && isScrolledPastTop() ) {
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
	 * Decides whether a reply saying the server no longer stores this feed's
	 * template reloads the page now. The fresh render stores the template
	 * again, but a page cache can serve back the copy that sent the old key,
	 * so a repeat reload for the same page and key waits until
	 * OVERFLOW_RELOAD_RETRY_MS has passed. Without session storage there is
	 * no record of a reload, so the page never reloads.
	 *
	 * @return {boolean} True if the page should reload now.
	 */
	function shouldReloadForStaleTemplate(): boolean {
		const storageKey = `newspack-rolling-coverage-stale-template-reload-${ coverageId }-${ templateKey }-${ window.location.pathname }${ window.location.search }`;

		try {
			const lastReload = Number(
				window.sessionStorage.getItem( storageKey )
			);

			if (
				lastReload &&
				Date.now() - lastReload < OVERFLOW_RELOAD_RETRY_MS
			) {
				return false;
			}

			window.sessionStorage.setItem( storageKey, String( Date.now() ) );

			return true;
		} catch {
			return false;
		}
	}

	/**
	 * Polls for new and edited entries.
	 *
	 * Fetches entries modified at or after the cursor and applies them. Also
	 * passes the running ad counter so the server can continue the interval
	 * across poll batches. Takes the place of a poll already scheduled, and
	 * does nothing while another poll is in flight or the page is hidden.
	 *
	 * @return {Promise<PollOutcome>} How the poll ended.
	 */
	async function poll(): Promise< PollOutcome > {
		if ( ! cursor || isPolling || document.hidden ) {
			return 'skipped';
		}

		cancelPoll();
		isPolling = true;
		let outcome: PollOutcome = 'failed';
		const arrivedBefore = arrivedCount;
		let isStopped = false;
		let canReport = showsEntries;

		reportCheck( 'checking' );

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

				// A lite page shows no ads either, so it leaves out the ad
				// count and its readers share one cached reply too.
				if ( ! isLite ) {
					url.searchParams.set(
						'polled_count',
						polledCount.toString()
					);
				}
			}

			if ( isLite ) {
				url.searchParams.set( 'lite', '1' );
			}

			const response = await fetchEntries( url.toString() );
			if ( response.ok ) {
				const data: PollResponse = await response.json();

				// The block was cleaned up meanwhile, so this reply is no longer its own.
				if ( isDisposed ) {
					outcome = 'skipped';
					return outcome;
				}

				minPollInterval = Number( data.minPollInterval ) || 0;
				outcome = 'ok';
				canReport = canReport && ! data.overflow;

				if ( typeof data.status === 'string' ) {
					polledStatus = data.status;
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
					return outcome;
				}

				// The reply carries no entries and keeps the cursor, so a page
				// that has already reloaded for it keeps polling, its checks
				// failing, until the template is stored again.
				if ( data.staleTemplate && showsEntries ) {
					if ( shouldReloadForStaleTemplate() ) {
						canReport = false;
						window.location.reload();
						return 'reloading';
					}

					outcome = 'failed';
				}

				if ( data.overflow && isEntryView ) {
					// A reload lands on the same shared URL, so there is nothing
					// to gain from one, and every later poll overflows from the
					// same cursor: polling ends here, and with it the count.
					canCount = false;
					showNewerCount();
					isStopped = true;
					reportCheck( 'idle' );
					return outcome;
				}

				// A capped feed shares its page with other content, so it never
				// reloads it; its polls send the newest entries instead. A reader
				// who asked for updates gets the reload whatever the guard says.
				if (
					data.overflow &&
					! latestCap &&
					( checksOnRequest || shouldReloadForOverflow() )
				) {
					window.location.reload();
					return 'reloading';
				}

				if ( showsEntries ) {
					if ( data.replace ) {
						replaceEntries( data.entries );
					} else if ( data.entries.length > 0 ) {
						applyPollResponse( data.entries );
					}
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

			if ( isStopped || isDisposed ) {
				checkResult = undefined;
			} else if ( outcome === 'ok' ) {
				checkResult = canReport
					? {
							outcome: 'ok',
							added: arrivedCount - arrivedBefore,
						}
					: undefined;
			} else if ( outcome === 'failed' ) {
				checkResult = { outcome: 'failed' };
			}
		}

		if ( ! isDisposed ) {
			schedulePoll();
		}

		return outcome;
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
	 * Marks the Load More button busy while a page loads, or ready again, and
	 * hides it once no more entries can load.
	 *
	 * @param {boolean} busy Whether a page is loading.
	 * @return {void}
	 */
	function setLoadMoreBusy( busy: boolean ): void {
		if ( ! loadMoreControl || ! loadMoreButton ) {
			return;
		}

		loadMoreControl.hidden = ! hasMore;

		if ( busy ) {
			// A repeat failure only announces again if the region changes.
			announce( '' );
			loadMoreButton.textContent = __(
				'Loading…',
				'newspack-rolling-coverage'
			);
			loadMoreButton.setAttribute( 'aria-busy', 'true' );
			// Unlike disabled, keeps focus on the button, for a retry.
			loadMoreButton.setAttribute( 'aria-disabled', 'true' );
		} else {
			loadMoreButton.textContent = loadMoreLabel;
			loadMoreButton.removeAttribute( 'aria-busy' );
			loadMoreButton.removeAttribute( 'aria-disabled' );
		}
	}

	/**
	 * Tells the reader that pressing Load More failed. A feed that loads on
	 * scroll stays silent.
	 *
	 * @return {void}
	 */
	function announceLoadMoreFailure(): void {
		if ( loadMoreButton ) {
			announce( loadMoreFailedLabel( entryName ) );
		}
	}

	/**
	 * Loads and appends the next page of older entries.
	 *
	 * Sends the backlog position so ad placement stays stable across load-more
	 * pages.
	 *
	 * @return {Promise<HTMLElement | null>} The first entry appended, or null if none was.
	 */
	async function loadMore(): Promise< HTMLElement | null > {
		if ( isLoadingMore || ! hasMore || ! before ) {
			return null;
		}
		isLoadingMore = true;
		setLoadMoreBusy( true );

		let firstAppended: HTMLElement | null = null;
		let isReloading = false;
		const pageBefore = before;

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

			if ( isLite ) {
				url.searchParams.set( 'lite', '1' );
			}

			const response = await fetchEntries( url.toString() );
			if ( response.ok ) {
				const data: PageResponse = await response.json();

				// The block was cleaned up meanwhile, so this reply is no longer its own.
				if ( isDisposed ) {
					return null;
				}

				if ( data.staleTemplate ) {
					if ( shouldReloadForStaleTemplate() ) {
						isReloading = true;
						window.location.reload();
					} else {
						if ( ! loadMoreButton ) {
							hasMore = false;
						}

						announceLoadMoreFailure();
					}

					return null;
				}

				if ( data.count > 0 ) {
					const fragment = parseFragment( sanitizeHtml( data.html ) );

					// Count how many entries were appended so the next page's offset can be correct.
					let appended = 0;

					// Never append an entry that is already in the list, or one taken down since.
					Array.from( fragment.children ).forEach( ( child ) => {
						if (
							! ( child instanceof HTMLElement ) ||
							! child.dataset.entryId
						) {
							return;
						}

						const existing = ownElement(
							root,
							`[data-entry-id="${ cssEscape(
								child.dataset.entryId
							) }"]`,
							entriesList
						);
						if (
							existing ||
							removedEntryIds.has( child.dataset.entryId )
						) {
							child.remove();
							return;
						}

						const entry = applyOffPageUpdate( child );

						observeEntry( entry );
						firstAppended ??= entry;
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
				// The reader can try the button again; the sentinel would
				// ask again each time it comes into view.
				if ( ! loadMoreButton ) {
					hasMore = false;
				}

				trackPollError( 'load_more' );
				announceLoadMoreFailure();
			}
		} catch ( error ) {
			trackPollError( 'load_more' );
			announceLoadMoreFailure();

			// Leave hasMore as-is, so the sentinel or the button can try again.
			console.error( error ); // eslint-disable-line no-console
		} finally {
			if ( ! isReloading ) {
				isLoadingMore = false;

				if ( ! isDisposed ) {
					setLoadMoreBusy( false );
				}
			}
		}

		// A page that adds nothing, its entries all taken down or already
		// shown, leaves the reader with nothing new: the sentinel stays in
		// view, where its observer won't fire again, and a press of the
		// button shows nothing. Load the next one instead.
		if (
			! firstAppended &&
			! isDisposed &&
			hasMore &&
			before !== pageBefore
		) {
			return loadMore();
		}

		return firstAppended;
	}

	if ( loadMoreButton ) {
		const onLoadMoreClick = async () => {
			if ( isLoadingMore ) {
				return;
			}

			const firstAppended = await loadMore();
			const active = loadMoreButton.ownerDocument.activeElement;

			// Leaves focus where the reader moved it while the page loaded.
			if (
				isDisposed ||
				( active &&
					active !== loadMoreButton &&
					active !== document.body )
			) {
				return;
			}

			// Focus would fall to the page with the button hidden.
			const entries = hasMore
				? []
				: ownElements( root, ':scope > [data-entry-id]', entriesList );
			const target = firstAppended ?? entries[ entries.length - 1 ];

			if ( target ) {
				focusFromScript( target );
			}
		};

		loadMoreButton.addEventListener( 'click', onLoadMoreClick );
		cleanupFns.push( () => {
			loadMoreButton.removeEventListener( 'click', onLoadMoreClick );
			setLoadMoreBusy( false );
		} );
		setLoadMoreBusy( false );
	}

	ownElements( root, '[data-entry-id]', entriesList ).forEach( observeEntry );

	/**
	 * Shows a message on the Check for Updates buttons for a few seconds, then
	 * their own labels again.
	 *
	 * @param {string} label The message.
	 * @return {void}
	 */
	function flashCheckLabel( label: string ): void {
		if ( checkLabelTimeoutId !== null ) {
			clearTimeout( checkLabelTimeoutId );
		}

		checkButtons.forEach( ( button ) => {
			button.textContent = label;
		} );
		checkLabelTimeoutId = setTimeout( () => {
			checkLabelTimeoutId = null;
			checkButtons.forEach( ( button ) => {
				button.textContent = checkLabels.get( button ) ?? '';
			} );
		}, CHECK_MESSAGE_MS );
	}

	/**
	 * Marks the Check for Updates buttons busy while a check runs, or ready
	 * again.
	 *
	 * @param {boolean} busy Whether a check is running.
	 * @return {void}
	 */
	function setCheckBusy( busy: boolean ): void {
		isChecking = busy;

		if ( busy ) {
			if ( checkLabelTimeoutId !== null ) {
				clearTimeout( checkLabelTimeoutId );
				checkLabelTimeoutId = null;
			}

			// A repeat result only announces again if the region changes.
			announce( '' );
		}

		checkButtons.forEach( ( button ) => {
			if ( busy ) {
				button.textContent =
					/* translators: Shown on the Check for Updates button while a check runs. */
					__( 'Checking…', 'newspack-rolling-coverage' );
				button.setAttribute( 'aria-busy', 'true' );
				// Unlike disabled, keeps focus on the button.
				button.setAttribute( 'aria-disabled', 'true' );
			} else {
				button.textContent = checkLabels.get( button ) ?? '';
				button.removeAttribute( 'aria-busy' );
				button.removeAttribute( 'aria-disabled' );
			}
		} );
	}

	if (
		checksOnRequest &&
		checkButtons.length > 0 &&
		cursor &&
		status !== 'archived' &&
		! isEntryView
	) {
		const onCheckClick = async () => {
			if ( isChecking ) {
				return;
			}

			setCheckBusy( true );

			// Repeated clicks wait out the site's minimum poll interval.
			const wait = lastCheckAt + minPollInterval * 1000 - Date.now();

			if ( wait > 0 ) {
				await new Promise( ( resolve ) => setTimeout( resolve, wait ) );
			}

			if ( isDisposed ) {
				return;
			}

			// A poll skips a hidden page, so a check waits for the reader to return.
			if ( document.hidden ) {
				await new Promise< void >( ( resolve ) => {
					const onShow = () => {
						if ( ! document.hidden ) {
							document.removeEventListener(
								'visibilitychange',
								onShow
							);
							resolve();
						}
					};

					document.addEventListener( 'visibilitychange', onShow );
				} );
			}

			if ( isDisposed ) {
				return;
			}

			const insertedBefore = insertedCount;
			const outcome = await poll();

			lastCheckAt = Date.now();

			// A reload is on its way, so the buttons stay busy until it lands.
			if ( isDisposed || outcome === 'reloading' ) {
				return;
			}

			setCheckBusy( false );

			if ( outcome === 'failed' ) {
				flashCheckLabel(
					/* translators: Shown briefly on the Check for Updates button when a check fails. */
					__( 'Couldn’t Check', 'newspack-rolling-coverage' )
				);
				announce(
					__(
						'Couldn’t check for updates. Try again.',
						'newspack-rolling-coverage'
					)
				);
				return;
			}

			if ( outcome === 'skipped' ) {
				return;
			}

			// An ended coverage gets no new entries; a paused one may resume.
			if ( polledStatus === 'archived' ) {
				if (
					checkButtons.some(
						( button ) =>
							button.ownerDocument.activeElement === button
					)
				) {
					focusFromScript( entriesList, { preventScroll: true } );
				}

				checkControls.forEach( ( control ) => {
					control.hidden = true;
				} );
				return;
			}

			const added = insertedCount - insertedBefore;

			// New entries land at the top, out of sight of a button further down.
			if ( added > 0 ) {
				flashCheckLabel( entriesAddedButtonLabel( added, entryName ) );
			} else {
				const label = noNewEntriesLabel( entryName );

				flashCheckLabel( label );
				announce( label );
			}
		};

		checkButtons.forEach( ( button ) =>
			button.addEventListener( 'click', onCheckClick )
		);
		cleanupFns.push( () => {
			checkButtons.forEach( ( button ) =>
				button.removeEventListener( 'click', onCheckClick )
			);

			if ( checkLabelTimeoutId !== null ) {
				clearTimeout( checkLabelTimeoutId );
				checkLabelTimeoutId = null;
			}

			setCheckBusy( false );
		} );
		checkControls.forEach( ( control ) => {
			control.hidden = false;
		} );
	}

	if ( cursor && status === 'active' ) {
		schedulePoll();
	}

	// Resume polling when the user returns to the tab; cancel when they leave.
	const onVisibilityChange = () => {
		if ( document.hidden ) {
			cancelPoll();
			reportCheck( 'idle' );
		} else if ( cursor && status === 'active' && ! checksOnRequest ) {
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

	// The top of the bars the sticky cards were last fitted below.
	let stickyCardsBars = -1;

	/**
	 * Sticks each sticky pinned card its own offset below the bars fixed or
	 * stuck at the top of the viewport, such as the admin bar and a sticky
	 * site header, or below the admin bar alone until any are measured. Lets
	 * a card taller than the viewport below that top scroll with the page,
	 * so its end isn't hidden until the feed ends, and makes it sticky again
	 * once it fits.
	 *
	 * @param {boolean} [ifBarsMoved]  Whether to leave the cards as they are while the bars haven't moved.
	 * @param {number}  [measuredBars] The bars' bottom, when the caller already measured it this frame.
	 * @return {void}
	 */
	function fitStickyCards(
		ifBarsMoved = false,
		measuredBars?: number
	): void {
		const cards = ownElements( root, STICKY_CARD_SELECTOR );

		if ( cards.length === 0 ) {
			return;
		}

		const bars = measuredBars ?? topBarsBottom( root );

		if ( ifBarsMoved && bars === stickyCardsBars ) {
			return;
		}

		stickyCardsBars = bars;

		cards.forEach( ( card ) => {
			if ( bars > 0 ) {
				card.style.setProperty(
					'--newspack-rolling-coverage-top-bars',
					`${ bars }px`
				);
			} else {
				card.style.removeProperty(
					'--newspack-rolling-coverage-top-bars'
				);
			}

			const top = parseFloat( window.getComputedStyle( card ).top ) || 0;

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
		ownElements( root, STICKY_CARD_SELECTOR ).forEach( ( card ) =>
			stickyCardObserver?.observe( card )
		);
		fitStickyCards();
	}

	const columnRule = cssDeclarations( root.dataset.columnRule ?? '' );
	const entryRule = cssDeclarations( root.dataset.entryRule ?? '' );

	/**
	 * Moves the pinned card's top border onto the entry heading the entries
	 * beside the card, the first after the card's own entry, when another
	 * entry comes to head them, giving the entry that headed them its own
	 * back. A pinned entry heading them already has the card's look, and is
	 * left as it is (see Rolling_Coverage_Block::column_rules()).
	 *
	 * @return {void}
	 */
	function placeColumnRule(): void {
		if ( columnRule.length === 0 ) {
			return;
		}

		const entryGroupOf = ( entry: Element | null ) =>
			entry?.querySelector< HTMLElement >(
				':scope > .newspack-rolling-coverage-regular-entry'
			) ?? null;
		const ruled = entriesList.querySelector< HTMLElement >(
			':scope > [data-heads-column]'
		);
		let head =
			entriesList.querySelector( ':scope > [data-leads-column]' )
				?.nextElementSibling ?? null;

		while ( head && ! head.matches( '[data-entry-id]' ) ) {
			head = head.nextElementSibling;
		}

		const next =
			head instanceof HTMLElement &&
			! head.hasAttribute( 'data-pinned' ) &&
			entryGroupOf( head )
				? head
				: null;

		if ( next === ruled ) {
			return;
		}

		const swapRule = (
			entry: HTMLElement,
			from: string[][],
			to: string[][]
		) => {
			const group = entryGroupOf( entry );

			from.forEach( ( [ property ] ) =>
				group?.style.removeProperty( property )
			);
			to.forEach( ( [ property, value ] ) =>
				group?.style.setProperty( property, value )
			);
		};

		if ( ruled ) {
			swapRule( ruled, columnRule, entryRule );
			delete ruled.dataset.headsColumn;
		}

		if ( next ) {
			swapRule( next, entryRule, columnRule );
			next.dataset.headsColumn = '';
		}
	}

	// Entries are inserted, replaced and appended in several places, the
	// pinned card among them, so the list itself is watched.
	const entriesListObserver = new MutationObserver( () => {
		watchStickyCards();
		placeColumnRule();
	} );
	entriesListObserver.observe( entriesList, { childList: true } );
	watchStickyCards();
	placeColumnRule();
	on( window, 'resize', () => fitStickyCards() );
	cleanupFns.push( () => {
		entriesListObserver.disconnect();
		stickyCardObserver?.disconnect();
	} );

	// Chrome leaves a link focused from the keyboard partly outside a line that scrolls sideways when some of it already shows. The entry, not the link, is what the line snaps to.
	const revealFocused = ( event: FocusEvent ) => {
		const target = event.target;

		if (
			! ( target instanceof Element ) ||
			target === entriesList ||
			! target.matches( ':focus-visible' ) ||
			getComputedStyle( entriesList ).overflowX === 'visible'
		) {
			return;
		}

		let entry: Element = target;

		while ( entry.parentElement && entry.parentElement !== entriesList ) {
			entry = entry.parentElement;
		}

		entry.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
	};
	entriesList.addEventListener( 'focusin', revealFocused );
	cleanupFns.push( () =>
		entriesList.removeEventListener( 'focusin', revealFocused )
	);

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

// Feeds can sit in an entry's content, so they also reach the page after load,
// in entries a poll, load more or the jump to the live feed brings in, and
// leave it in entries those replace or drop, or with a feed that hides once its
// coverage ends. A feed runs only while it's in the page: one that arrives
// starts, and one that leaves stops. Records arrive once the changes are done,
// so what counts is where each feed ended up: one moved within the page keeps
// running.
new MutationObserver( ( records ) => {
	records.forEach( ( { addedNodes, removedNodes } ) => {
		removedNodes.forEach( ( node ) =>
			feedsIn( node ).forEach( ( feed ) => {
				if ( ! feed.isConnected ) {
					feedStops.get( feed )?.();
				}
			} )
		);
		addedNodes.forEach( ( node ) =>
			feedsIn( node ).forEach( ( feed ) => {
				if ( feed.isConnected ) {
					initBlock( feed );
				}
			} )
		);
	} );
} ).observe( document.body, { childList: true, subtree: true } );
