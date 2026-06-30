/**
 * Internal dependencies
 */
import './style.scss';
import type { PollResponse, PageResponse } from './types';

const BLOCK_SELECTOR = '.wp-block-newspack-rolling-coverage-rolling-coverage';

/**
 * Sets up polling and infinite scroll for a single block instance.
 *
 * @param {HTMLElement} root The block's outer wrapper element.
 */
function initBlock( root: HTMLElement ): void {
	const restUrl = root.dataset.restUrl;
	const entriesList = root.querySelector< HTMLElement >(
		'.newspack-rolling-coverage-entries'
	);

	if ( ! restUrl || ! entriesList ) {
		return;
	}

	const pollInterval = parseInt( root.dataset.pollInterval || '10', 10 );
	const entriesPerPage = parseInt( root.dataset.entriesPerPage || '20', 10 );
	const sourcePostId = root.dataset.sourcePostId || '0';
	const instanceId = root.dataset.instanceId || '';
	const sentinel = root.querySelector< HTMLElement >(
		'.newspack-rolling-coverage-sentinel'
	);

	let since = root.dataset.since || '';
	let before = root.dataset.before || '';
	let hasMore = before !== '';
	let isLoadingMore = false;

	/**
	 * Polls for entries newer than `since` and prepends them.
	 */
	async function poll(): Promise< void > {
		if ( ! since ) {
			return;
		}

		try {
			const url = `${ restUrl }?since=${ encodeURIComponent(
				since
			) }&source_post_id=${ sourcePostId }&instance_id=${ encodeURIComponent(
				instanceId
			) }`;
			const response = await fetch( url );
			if ( response.ok ) {
				const data: PollResponse = await response.json();
				if ( data.count > 0 && entriesList ) {
					entriesList.insertAdjacentHTML( 'afterbegin', data.html );
				}
				since = data.since || since;
			}
		} catch ( error ) {
			// Network hiccups shouldn't break the page; the next poll interval retries.
			console.error( error ); // eslint-disable-line no-console
		}

		setTimeout( poll, pollInterval * 1000 );
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
			) }&per_page=${ entriesPerPage }&source_post_id=${ sourcePostId }&instance_id=${ encodeURIComponent(
				instanceId
			) }`;
			const response = await fetch( url );
			if ( response.ok ) {
				const data: PageResponse = await response.json();
				if ( data.count > 0 && entriesList ) {
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

	if ( since ) {
		setTimeout( poll, pollInterval * 1000 );
	}

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
