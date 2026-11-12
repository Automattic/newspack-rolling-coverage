/**
 * External dependencies
 */
import { useEffect } from '@wordpress/element';
import useUnsavedChangesDialog from 'newspack-components/dist/esm/hooks/use-unsaved-changes-dialog';

/**
 * Warn before leaving a screen with unsaved changes, with Newspack's
 * `useUnsavedChangesDialog` and its dialog. That hook covers links out of the
 * page, reloads and closing the tab, and leaves `#/` links to a react-router
 * v5 `history.block`. This app routes with react-router 7's `HashRouter`,
 * which has no such history, so clicks on the app's own `#/` links (tabs,
 * breadcrumbs) go through the hook's `requestConfirm` here instead. Mount
 * one per screen: the hook allows a single active instance.
 *
 * @param isDirty Whether the screen has unsaved changes.
 * @return The dialog element, to render with the screen.
 */
export function useUnsavedChangesGuard( isDirty: boolean ): JSX.Element {
	const { confirmDialog, requestConfirm } = useUnsavedChangesDialog( {
		when: isDirty,
	} );

	useEffect( () => {
		if ( ! isDirty ) {
			return;
		}

		const handler = ( event: MouseEvent ) => {
			if (
				event.defaultPrevented ||
				event.button !== 0 ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.altKey
			) {
				return;
			}

			const link = ( event.target as Element | null )?.closest(
				'a[href^="#/"]'
			);
			const hash = link?.getAttribute( 'href' );

			if ( ! hash || hash === window.location.hash ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			requestConfirm( () => {
				window.location.hash = hash;
			} );
		};

		document.addEventListener( 'click', handler, true );
		return () => document.removeEventListener( 'click', handler, true );
	}, [ isDirty, requestConfirm ] );

	return confirmDialog;
}
