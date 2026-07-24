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
 * cssEscape polyfill for older browsers.
 */
const cssEscape = ( str: string ): string => {
	if ( typeof CSS !== 'undefined' && CSS.escape ) {
		return CSS.escape( str );
	}
	return str.replace( /([!"#$%&'()*+,./:;<=>?@[\]^`{|}~])/g, '\\$1' );
};

/**
 * Sets up polling and infinite scroll for a single block instance.
 *
 * @param {HTMLElement} root The block's outer wrapper element.
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

	// Narrowed to non-nullable bindings.
	const entriesList: HTMLElement = entriesListEl;
	const restBaseUrl: string = restUrl;

	const pollInterval = parseInt( root.dataset.pollInterval || '10', 10 );
	const entriesPerPage = parseInt( root.dataset.entriesPerPage || '20', 10 );
	const templateKey = root.dataset.templateKey || '';
	const hostPostId = root.dataset.hostPostId || '0';
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
	 */
	function applyPollResponse( entries: PollEntry[] ): void {
		const newEntries: HTMLElement[] = [];

		entries.forEach( ( entry ) => {
			const existing = entriesList.querySelector(
				`[data-entry-id="${ entry.id }"]`
			);

			if ( entry.type === 'update' && ! existing ) {
				return;
			}

			const template = document.createElement( 'template' );
			template.innerHTML = entry.html;
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
			const url = new URL( restBaseUrl );
			url.searchParams.set( 'cursor', cursor );
			url.searchParams.set( 'template_key', templateKey );
			url.searchParams.set( 'host_post_id', hostPostId );

			const response = await fetch( url.toString() );
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
			const url = new URL( restBaseUrl );
			url.searchParams.set( 'before', before );
			url.searchParams.set( 'per_page', String( entriesPerPage ) );
			url.searchParams.set( 'template_key', templateKey );
			url.searchParams.set( 'host_post_id', hostPostId );

			const response = await fetch( url.toString() );
			if ( response.ok ) {
				const data: PageResponse = await response.json();
				if ( data.count > 0 ) {
					entriesList.insertAdjacentHTML( 'beforeend', data.html );
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
	document.addEventListener( 'visibilitychange', () => {
		if ( document.hidden ) {
			cancelPoll();
		} else if ( cursor && status === 'active' ) {
			poll();
		}
	} );

	// Cancel any pending poll on page unload.
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

	// Deep-link detection: if the URL carries the rolling-coverage-entry
	// query var and the entry is not in the initial SSR set, un-hide the
	// deep-link CTA (rendered hidden by the parent block's SSR).
	/**
	 * Detects a deep-link request via the `rolling-coverage-entry` query
	 * var and, if the entry is not in the initial SSR set, un-hides the
	 * deep-link CTA (which was SSR'd with entry data by PHP). The hash
	 * fragment is only for smooth scroll.
	 *
	 * If the entry IS in the DOM, the browser's native #anchor scroll
	 * handles navigation — no CTA needed.
	 */
	function handleDeepLink(): void {
		const params = new URLSearchParams( window.location.search );
		const entrySlug = params.get( 'rolling-coverage-entry' );

		if ( ! entrySlug ) {
			return;
		}

		// If the entry is in ANY block's entries list on the page, the
		// browser scrolls to it via #hash — no CTA needed.
		const allEntryLists = document.querySelectorAll< HTMLElement >(
			BLOCK_SELECTOR + ' .newspack-rolling-coverage-entries'
		);
		for ( const list of allEntryLists ) {
			if ( list.querySelector( `#${ cssEscape( entrySlug ) }` ) ) {
				return;
			}
		}

		// Entry not in DOM — un-hide the CTA (SSR'd with entry data by PHP).
		const cta = root.querySelector< HTMLElement >(
			'.newspack-rolling-coverage-cta'
		);
		if ( cta ) {
			cta.hidden = false;
		}
	}

	handleDeepLink();

	const onHashChange = () => {
		handleDeepLink();
	};
	window.addEventListener( 'hashchange', onHashChange, { once: true } );
}

document.querySelectorAll< HTMLElement >( BLOCK_SELECTOR ).forEach( initBlock );
