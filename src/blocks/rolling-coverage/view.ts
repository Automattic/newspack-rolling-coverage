/**
 * WordPress dependencies
 */
import { _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.scss';
import type { PollEntry, PollResponse, PageResponse } from './types';

const BLOCK_SELECTOR = '.wp-block-newspack-rolling-coverage-rolling-coverage';

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
 * Sets up polling and infinite scroll for a single block instance.
 *
 * @param {HTMLElement} root The block's outer wrapper element.
 */
function initBlock( root: HTMLElement ): void {
	const restUrl = root.dataset.restUrl;
	const entriesListEl = root.querySelector< HTMLElement >(
		'.newspack-rolling-coverage-entries'
	);

	if ( ! restUrl || ! entriesListEl ) {
		return;
	}

	// Narrowed to a non-nullable binding.
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
	let pendingNewEntries: HTMLElement[] = [];

	/**
	 * Schedules the next poll.
	 */
	function schedulePoll(): void {
		pollTimeoutId = setTimeout( poll, pollInterval * 1000 );
	}

	/**
	 * Cancels any pending poll timeout.
	 */
	function cancelPoll(): void {
		if ( pollTimeoutId !== null ) {
			clearTimeout( pollTimeoutId );
			pollTimeoutId = null;
		}
	}

	/**
	 * Whether the reader has scrolled past the top of the entry list.
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
	 * @param {HTMLElement[]} entries Entries to insert, in poll-response order.
	 */
	function insertNewEntries( entries: HTMLElement[] ): void {
		if ( entries.length === 0 ) {
			return;
		}

		entriesList
			.querySelector( '.newspack-rolling-coverage-entries__empty' )
			?.remove();

		const fragment = document.createDocumentFragment();
		entries.forEach( ( entry ) => fragment.appendChild( entry ) );
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
	}

	/**
	 * Adds entries to the pending queue and updates the "X new posts" button
	 * label and visibility.
	 *
	 * @param {HTMLElement[]} newEntries Newly published entries.
	 */
	function queueNewEntries( newEntries: HTMLElement[] ): void {
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
	 * Returns the pending entries and clears the queue.
	 *
	 * @return {HTMLElement[]} The entries that were pending.
	 */
	function takePendingEntries(): HTMLElement[] {
		const entries = pendingNewEntries;
		pendingNewEntries = [];
		return entries;
	}

	const cleanupFns: Array< () => void > = [];

	/**
	 * Removes all event listeners, observers, and pending timeouts.
	 * Called on pagehide to prevent memory leaks.
	 */
	function cleanup(): void {
		cancelPoll();
		cleanupFns.forEach( ( fn ) => fn() );
		cleanupFns.length = 0;
	}

	if ( newEntriesButton ) {
		const onNewEntriesClick = () => {
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
		};
		newEntriesButton.addEventListener( 'click', onNewEntriesClick );
		cleanupFns.push( () =>
			newEntriesButton!.removeEventListener( 'click', onNewEntriesClick )
		);
	}

	/**
	 * Reveals pending entries the moment the reader scrolls back up to the
	 * top entry on their own.
	 */
	let scrollCheckScheduled = false;

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

	const onScroll = () => {
		if ( scrollCheckScheduled ) {
			return;
		}

		scrollCheckScheduled = true;
		requestAnimationFrame( checkIfScrolledBackToTop );
	};
	window.addEventListener( 'scroll', onScroll, { passive: true } );
	cleanupFns.push( () => window.removeEventListener( 'scroll', onScroll ) );

	/**
	 * Applies a poll response to the entry list.
	 *
	 * Replaces edited entries immediately, ignoring edits to entries not yet
	 * in view. Inserts or queues newly published entries based on the
	 * reader's scroll position.
	 *
	 * @param {PollEntry[]} entries Entries from the poll response.
	 */
	function applyPollResponse( entries: PollEntry[] ): void {
		const newEntries: HTMLElement[] = [];

		entries.forEach( ( entry ) => {
			if ( ! isSafeEntryId( entry.id ) ) {
				return;
			}

			const existing = entriesList.querySelector(
				`[data-entry-id="${ entry.id }"]`
			);

			if ( entry.type === 'update' && ! existing ) {
				return;
			}

			const template = document.createElement( 'template' );
			template.innerHTML = sanitizeHtml( entry.html );
			const entryEl = template.content.firstElementChild as HTMLElement;

			if ( ! entryEl ) {
				return;
			}

			if ( existing ) {
				existing.replaceWith( entryEl );
			} else {
				newEntries.push( entryEl );
			}
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
	 * Polls for entries modified at or after the cursor.
	 *
	 * Applies returned entries or reloads the page when the response overflows
	 * the poll limit.
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
			) }&template_key=${ encodeURIComponent( templateKey ) }`;

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
			}
		} catch ( error ) {
			// Network hiccups shouldn't break the page; the next poll interval retries.
			console.error( error ); // eslint-disable-line no-console
		}

		schedulePoll();
	}

	/**
	 * Loads the next page of older entries and appends them.
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
			) }`;
			const response = await fetch( url );
			if ( response.ok ) {
				const data: PageResponse = await response.json();
				if ( data.count > 0 ) {
					entriesList.insertAdjacentHTML(
						'beforeend',
						sanitizeHtml( data.html )
					);
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

	// Only start polling if the coverage is active at page load.
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

	// Clean up all listeners, observers, and pending timeouts on page unload.
	window.addEventListener( 'pagehide', cleanup, { once: true } );
	cleanupFns.push( () => window.removeEventListener( 'pagehide', cleanup ) );

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
