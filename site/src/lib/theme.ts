import { useSyncExternalStore } from 'react';

export type Theme = 'light' | 'dark';

export const MOBILE_MENU_QUERY = '(max-width: 767px)';

/**
 * The `dark` class on `html` is the one record of the theme. A stored choice
 * lives under this key only while it differs from the system setting. The
 * inline head script in `layout.astro` reads the same key before paint, after
 * every client-side navigation, and when the system or another tab changes.
 * Below the mobile menu breakpoint it ignores the key and follows the system
 * setting.
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

function subscribeMobileMenu( listener: () => void ) {
	const query = window.matchMedia( MOBILE_MENU_QUERY );
	query.addEventListener( 'change', listener );
	return () => query.removeEventListener( 'change', listener );
}

export function useMobileMenu(): boolean {
	return useSyncExternalStore( subscribeMobileMenu, () => window.matchMedia( MOBILE_MENU_QUERY ).matches, () => false );
}
