/**
 * External dependencies
 */
import { useEffect } from '@wordpress/element';
import useUnsavedChangesDialog from 'newspack-components/dist/esm/hooks/use-unsaved-changes-dialog';

let popStateGuard: ( ( event: PopStateEvent ) => void ) | null = null;

window.addEventListener( 'popstate', ( event ) => popStateGuard?.( event ) );

/**
 * Warn before leaving a screen with unsaved changes, with Newspack's
 * `useUnsavedChangesDialog` and its dialog. That hook covers links out of the
 * page, reloads and closing the tab, and leaves `#/` routes to a react-router
 * v5 `history.block`. This app routes with react-router 7's `HashRouter`,
 * which has no such history, so moves between the app's own `#/` routes go
 * through the hook's `requestConfirm` here instead:
 *
 * - Clicks on `#/` links (tabs, breadcrumbs) are stopped and asked about.
 * - Back and Forward are caught on `popstate`, by a listener added when this
 *   module loads, so it runs before the router's own: the URL goes back to
 *   the screen's route with `replaceState`, and Discard Changes then puts the
 *   route the user went to in its place.
 *
 * Navigation the user approved is let through on its way to the router, so
 * it's asked about once. Mount one per screen: the hook allows a single
 * active instance.
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

		const screenHash = window.location.hash;
		let approvedHash: string | null = null;

		const goTo = ( hash: string, replace: boolean ) => {
			approvedHash = hash;
			if ( replace ) {
				window.history.replaceState( window.history.state, '', hash );
				window.dispatchEvent(
					new PopStateEvent( 'popstate', {
						state: window.history.state,
					} )
				);
			} else {
				window.location.hash = hash;
			}
		};

		const onClick = ( event: MouseEvent ) => {
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
			requestConfirm( () => goTo( hash, false ) );
		};

		const onPopState = ( event: PopStateEvent ) => {
			const hash = window.location.hash;

			if ( hash === approvedHash ) {
				approvedHash = null;
				return;
			}

			if ( hash === screenHash ) {
				return;
			}

			event.stopImmediatePropagation();
			window.history.replaceState( window.history.state, '', screenHash );
			requestConfirm( () => goTo( hash, true ) );
		};

		document.addEventListener( 'click', onClick, true );
		popStateGuard = onPopState;
		return () => {
			document.removeEventListener( 'click', onClick, true );
			if ( popStateGuard === onPopState ) {
				popStateGuard = null;
			}
		};
	}, [ isDirty, requestConfirm ] );

	return confirmDialog;
}
