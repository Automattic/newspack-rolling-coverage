import { useSyncExternalStore } from 'react';

export type Theme = 'light' | 'dark';

/**
 * The `dark` class on `html` is the one record of the theme. A stored choice
 * lives under this key only while it differs from the system setting. The
 * inline head script in `layout.astro` reads the same key before paint, after
 * every client-side navigation, and when the system or another tab changes.
 */
export const THEME_KEY = 'theme';

function currentTheme(): Theme {
	return document.documentElement.classList.contains( 'dark' ) ? 'dark' : 'light';
}

function systemTheme(): Theme {
	return window.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light';
}

export function applyTheme( next: Theme ) {
	const root = document.documentElement;
	root.classList.toggle( 'dark', next === 'dark' );
	root.style.colorScheme = next;
	try {
		if ( next === systemTheme() ) {
			localStorage.removeItem( THEME_KEY );
		} else {
			localStorage.setItem( THEME_KEY, next );
		}
	} catch {}
}

function subscribe( listener: () => void ) {
	const observer = new MutationObserver( listener );
	observer.observe( document.documentElement, { attributes: true, attributeFilter: [ 'class' ] } );
	return () => observer.disconnect();
}

export function useTheme(): Theme {
	return useSyncExternalStore( subscribe, currentTheme, () => 'light' );
}
