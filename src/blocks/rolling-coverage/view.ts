/**
 * WordPress dependencies
 */
import { _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.scss';
import type {
	AdSlot,
	PendingEntry,
	PollEntry,
	PollResponse,
	PageResponse,
} from './types';

const BLOCK_SELECTOR = '.wp-block-newspack-rolling-coverage-rolling-coverage';

/**
 * Parses an HTML string into a detached element.
 *
 * @param {string} html HTML markup for a single element.
 * @return {HTMLElement | null} The parsed element, or null if parsing produced none.
 */
function parseElement( html: string ): HTMLElement | null {
	const template = document.createElement( 'template' );
	template.innerHTML = html;
	return template.content.firstElementChild as HTMLElement | null;
}

/**
 * Sets up polling and infinite scroll for a single block instance.
 *
 * @param {HTMLElement} root The block's outer wrapper element.
 * @return {void}
 */
function initBlock( root: HTMLElement ): void {
	const restUrl = root.dataset.restUrl;
	const entriesListEl = root.querySelector< HTMLElement >(
		'.newspack-rolling-coverage-entries'
	);

	if ( ! restUrl || ! entriesListEl ) {
		return;
	}

	const entriesList: HTMLElement = entriesListEl;

	const pollInterval = parseInt( root.dataset.pollInterval || '10', 10 );
	const entriesPerPage = parseInt( root.dataset.entriesPerPage || '20', 10 );
	const templateKey = root.dataset.templateKey || '';
	const sentinel = root.querySelector< HTMLElement >(
		'.newspack-rolling-coverage-sentinel'
	);
	const newEntriesButton = root.querySelector< HTMLButtonElement >(
		'.newspack-rolling-coverage-new-entries'
	);
	const statusEl = root.querySelector< HTMLElement >(
		'.newspack-rolling-coverage-status'
	);

	const status = root.dataset.status || 'active';

	let cursor = root.dataset.cursor || '';
	let before = root.dataset.before || '';
	let hasMore = root.dataset.hasMore === '1';
	let isLoadingMore = false;
	let pollTimeoutId: ReturnType< typeof setTimeout > | null = null;
	let pendingNewEntries: PendingEntry[] = [];
	let polledCount = 0;
	let backlogOffset = entriesPerPage;

	/**
	 * Schedules the next poll.
	 *
	 * @return {void}
	 */
	function schedulePoll(): void {
		pollTimeoutId = setTimeout( poll, pollInterval * 1000 );
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
	 * Inserts entries at the top of the entries list, removing the "no
	 * entries yet" placeholder if it's still present.
	 *
	 * Removes the "no entries yet" placeholder and displays any associated ad
	 * slots.
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
			if ( adEl ) {
				fragment.appendChild( adEl );
				if ( adSlot ) {
					adSlotsToDisplay.push( adSlot );
				}
			}
		} );

		entriesList.insertBefore( fragment, entriesList.firstChild );

		announce(
			sprintf(
				/* translators: %d: number of new coverage entries just added. */
				_n(
					'%d new post added',
					'%d new posts added',
					entries.length,
					'newspack-rolling-coverage'
				),
				entries.length
			)
		);

		if ( adSlotsToDisplay.length > 0 ) {
			displayAdSlots( adSlotsToDisplay );
		}
	}

	/**
	 * Adds entries to the pending queue.
	 *
	 * Updates the "X new posts" button label and visibility.
	 *
	 * @param {PendingEntry[]} newEntries Newly published entries.
	 * @return {void}
	 */
	function queueNewEntries( newEntries: PendingEntry[] ): void {
		pendingNewEntries.unshift( ...newEntries );

		if ( ! newEntriesButton ) {
			return;
		}

		const label = sprintf(
			/* translators: %d: number of new coverage entries waiting to be shown. */
			_n(
				'%d new post',
				'%d new posts',
				pendingNewEntries.length,
				'newspack-rolling-coverage'
			),
			pendingNewEntries.length
		);

		newEntriesButton.textContent = label;
		newEntriesButton.hidden = false;
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

	if ( newEntriesButton ) {
		newEntriesButton.addEventListener( 'click', () => {
			if ( pendingNewEntries.length === 0 ) {
				return;
			}

			const entries = takePendingEntries();
			newEntriesButton.hidden = true;

			const targetY =
				root.getBoundingClientRect().top + window.scrollY - 24;

			insertNewEntries( entries );

			window.scrollTo( {
				top: targetY,
				behavior: 'smooth',
			} );
		} );
	}

	let scrollCheckScheduled = false;

	/**
	 * Reveals pending entries when the reader scrolls back to the top entry.
	 *
	 * @return {void}
	 */
	function checkIfScrolledBackToTop(): void {
		scrollCheckScheduled = false;

		if ( pendingNewEntries.length === 0 || isScrolledPastTop() ) {
			return;
		}

		const entries = takePendingEntries();

		if ( newEntriesButton ) {
			newEntriesButton.hidden = true;
		}

		insertNewEntries( entries );
	}

	window.addEventListener(
		'scroll',
		() => {
			if ( scrollCheckScheduled ) {
				return;
			}

			scrollCheckScheduled = true;
			requestAnimationFrame( checkIfScrolledBackToTop );
		},
		{ passive: true }
	);

	/**
	 * Applies a poll response to the entry list.
	 *
	 * Replaces edited entries immediately, ignoring edits to entries not yet
	 * in view. Inserts or queues newly published entries based on the
	 * reader's scroll position.
	 *
	 * @param {PollEntry[]} entries Entries from the poll response.
	 * @return {void}
	 */
	function applyPollResponse( entries: PollEntry[] ): void {
		const newEntries: PendingEntry[] = [];

		entries.forEach( ( entry ) => {
			const existing = entriesList.querySelector(
				`[data-entry-id="${ entry.id }"]`
			);

			if ( entry.type === 'update' && ! existing ) {
				return;
			}

			const entryEl = parseElement( entry.html );

			if ( ! entryEl ) {
				return;
			}

			if ( existing ) {
				existing.replaceWith( entryEl );
				return;
			}

			const adEl = entry.adHtml ? parseElement( entry.adHtml ) : null;

			newEntries.push( { el: entryEl, adSlot: entry.adSlot, adEl } );
		} );

		if ( newEntries.length === 0 ) {
			return;
		}

		if ( isScrolledPastTop() ) {
			queueNewEntries( newEntries );
		} else {
			insertNewEntries( newEntries );
		}
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
				if ( adSlot.disableInitialLoad ) {
					window.googletag.pubads().refresh( [ slot ] );
				}
			} );
		} );
	}

	/**
	 * Polls for new and edited entries.
	 *
	 * Fetches entries modified at or after the cursor and applies them. Also
	 * passes the running ad counter so the server can continue the interval
	 * across poll batches.
	 *
	 * @return {Promise<void>} Resolves when the poll response has been handled.
	 */
	async function poll(): Promise< void > {
		if ( ! cursor ) {
			return;
		}

		try {
			const url = `${ restUrl }?cursor=${ encodeURIComponent(
				cursor
			) }&template_key=${ encodeURIComponent(
				templateKey
			) }&polled_count=${ polledCount }`;

			const response = await fetch( url );
			if ( response.ok ) {
				const data: PollResponse = await response.json();

				if ( data.overflow ) {
					window.location.reload();
					return;
				}

				if ( data.entries.length > 0 ) {
					applyPollResponse( data.entries );
				}
				cursor = data.cursor || cursor;
				polledCount = data.polledCount ?? polledCount;
			}
		} catch ( error ) {
			// Network hiccups shouldn't break the page; the next poll interval retries.
			console.error( error ); // eslint-disable-line no-console
		}

		schedulePoll();
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
			const url = `${ restUrl }?before=${ encodeURIComponent(
				before
			) }&per_page=${ entriesPerPage }&template_key=${ encodeURIComponent(
				templateKey
			) }&entry_offset=${ backlogOffset }`;
			const response = await fetch( url );
			if ( response.ok ) {
				const data: PageResponse = await response.json();
				if ( data.count > 0 ) {
					entriesList.insertAdjacentHTML( 'beforeend', data.html );
					backlogOffset += data.count;
				}
				if ( data.adSlots && data.adSlots.length > 0 ) {
					displayAdSlots( data.adSlots );
				}
				hasMore = data.hasMore;
				before = data.before || '';
			} else {
				hasMore = false;
			}
		} catch ( error ) {
			// Leave hasMore as-is; retried if the sentinel intersects again.
			console.error( error ); // eslint-disable-line no-console
		} finally {
			isLoadingMore = false;
		}
	}

	if ( cursor && status === 'active' ) {
		schedulePoll();
	}

	document.addEventListener( 'visibilitychange', () => {
		if ( document.hidden ) {
			cancelPoll();
		} else if ( cursor && status === 'active' ) {
			poll();
		}
	} );

	window.addEventListener( 'pagehide', cancelPoll );

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
	}
}

document.querySelectorAll< HTMLElement >( BLOCK_SELECTOR ).forEach( initBlock );
