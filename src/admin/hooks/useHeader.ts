/**
 * External dependencies
 */
import { useOutletContext } from 'react-router';
import { useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { ContextExports, HeaderState } from '../types';

/**
 * Fill the page header's actions, breadcrumb count and tabs while the
 * calling view is mounted.
 *
 * Element values must be memoized: a new element on every render would set
 * layout state on every render and loop.
 *
 * @param header                  Header slots for the current view.
 * @param header.actions          Header actions.
 * @param header.count            Item count shown on the current crumb.
 * @param header.tabbedNavigation A `TabbedNavigation` element.
 * @param header.isEmpty          Whether the view shows its empty state.
 */
export function useHeader( {
	actions,
	count,
	tabbedNavigation,
	isEmpty,
}: HeaderState ): void {
	const [ , , , setHeader ] = useOutletContext< ContextExports >();

	useEffect( () => {
		setHeader( { actions, count, tabbedNavigation, isEmpty } );
	}, [ actions, count, tabbedNavigation, isEmpty, setHeader ] );

	useEffect( () => () => setHeader( {} ), [ setHeader ] );
}
