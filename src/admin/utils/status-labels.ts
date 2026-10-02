/**
 * WordPress dependencies
 */
import { useSyncExternalStore } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { StatusLabels } from '../types';

const listeners = new Set< () => void >();

let labels: StatusLabels = window.newspackRollingCoverageAdmin
	?.statusLabels ?? { active: '', paused: '', archived: '' };

/**
 * The name the admin shows for a coverage status: the site's status
 * indicator label, so the admin and the badge readers see agree.
 *
 * @param {string} status Coverage status.
 * @return {string} The label, or the status itself when it has none.
 */
function getStatusLabel( status: string ): string {
	return labels[ status as keyof StatusLabels ] || status;
}

/**
 * Replaces the labels after the site's settings change, so every view
 * showing a status updates without a reload.
 *
 * @param {StatusLabels} next The site's labels, with the built-in ones filled in.
 */
function setStatusLabels( next: StatusLabels ) {
	labels = next;
	listeners.forEach( ( listener ) => listener() );
}

/**
 * Subscribes to label changes.
 *
 * @param {Function} listener Called when the labels change.
 * @return {Function} Unsubscribes.
 */
function subscribe( listener: () => void ): () => void {
	listeners.add( listener );
	return () => listeners.delete( listener );
}

/**
 * The site's status labels, re-rendering when they change.
 *
 * @return {StatusLabels} The labels.
 */
function useStatusLabels(): StatusLabels {
	return useSyncExternalStore( subscribe, () => labels );
}

export { getStatusLabel, setStatusLabels, useStatusLabels };
