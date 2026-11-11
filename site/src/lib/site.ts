export type NavLink = {
	href: string;
	text: string;
	section?: string;
	drawer?: 'tree' | false;
	external?: boolean;
};

export const SITE_NAME = 'Rolling Coverage';

export const NAV_LINKS: NavLink[] = [
	{ href: '/', text: 'Home', section: '/' },
	{ href: '/docs/', text: 'Docs', section: '/docs/', drawer: 'tree' },
	{ href: '/about/', text: 'About', section: '/about/' },
	// `external: true` would make the docs drawer draw Fumadocs' lucide external-link icon.
	{ href: 'https://github.com/Automattic/newspack-rolling-coverage', text: 'GitHub', external: false },
];

export const FOOTER_LINKS: NavLink[] = [];

/**
 * Below this width the header shows the menu button and the site follows the system color
 * scheme. Keep it equal to Tailwind's `md` breakpoint, which the header's classes use.
 */
export const MOBILE_MENU_QUERY = '(width < 48rem)';

/** The static search index the docs search reads. */
export const SEARCH_INDEX = '/api/search';
