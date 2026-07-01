/**
 * WordPress dependencies
 */
import { _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.scss';
import type { PollResponse, PageResponse } from './types';

const BLOCK_SELECTOR = '.wp-block-newspack-rolling-coverage-rolling-coverage';

/**
 * Parses a poll or pagination response's concatenated entry markup into
 * individual entry elements.
 *
 * @param {string} html Concatenated entry markup.
 * @return {HTMLElement[]} The entries, in the order they appear in `html`.
 */
function parseEntries( html: string ): HTMLElement[] {
	const template = document.createElement( 'template' );
	template.innerHTML = html;
	return Array.from( template.content.children ) as HTMLElement[];
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

	const status = root.dataset.status || 'active';

	let since = root.dataset.since || '';
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
	}

	/**
	 * Adds entries to the pending queue and updates the "X new posts" button
	 * label and visibility.
	 *
	 * @param {HTMLElement[]} newEntries Newly published entries.
	 */
	function queueNewEntries( newEntries: HTMLElement[] ): void {
		pendingNewEntries.push( ...newEntries );

		if ( ! newEntriesButton ) {
			return;
		}

		newEntriesButton.textContent = sprintf(
			/* translators: %d: number of new liveblog entries waiting to be shown. */
			_n(
				'%d new post',
				'%d new posts',
				pendingNewEntries.length,
				'newspack-rolling-coverage'
			),
			pendingNewEntries.length
		);
		newEntriesButton.hidden = false;
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
	 * Applies a poll response to the entry list. Edits to existing entries are
	 * replaced in place immediately. Newly published entries are inserted at
	 * the top if the reader is at the top of the page, or queued behind the
	 * "X new posts" button if they have scrolled down.
	 *
	 * @param {string} html Concatenated entry markup from the poll response.
	 */
	function applyPollResponse( html: string ): void {
		const newEntries: HTMLElement[] = [];

		parseEntries( html ).forEach( ( entry ) => {
			const entryId = entry.dataset.entryId;

			if ( ! entryId ) {
				return;
			}

			const existing = entriesList.querySelector(
				`[data-entry-id="${ entryId }"]`
			);

			if ( existing ) {
				existing.replaceWith( entry );
			} else {
				newEntries.push( entry );
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
	 * Polls for entries modified since `since` — new or edited — and applies
	 * them.
	 */
	async function poll(): Promise< void > {
		if ( ! since ) {
			return;
		}

		try {
			const url = `${ restUrl }?since=${ encodeURIComponent(
				since
			) }&template_key=${ encodeURIComponent( templateKey ) }`;
			const response = await fetch( url );
			if ( response.ok ) {
				const data: PollResponse = await response.json();
				if ( data.count > 0 ) {
					applyPollResponse( data.html );
				}
				since = data.since || since;
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
	if ( since && status === 'active' ) {
		schedulePoll();
	}

	// Resume polling when the user returns to the tab; cancel when they leave.
	document.addEventListener( 'visibilitychange', () => {
		if ( document.hidden ) {
			cancelPoll();
		} else if ( since && status === 'active' ) {
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
}

document.querySelectorAll< HTMLElement >( BLOCK_SELECTOR ).forEach( initBlock );
