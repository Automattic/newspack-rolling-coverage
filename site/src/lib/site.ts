export const SITE_TITLE = 'Rolling Coverage';
export const GITHUB_URL = 'https://github.com/Automattic/newspack-rolling-coverage';

/**
 * Header links. In the mobile drawer, `drawer: 'tree'` holds the docs page tree as a folder,
 * and `drawer: false` leaves out a link that is already a section of that tree.
 */
export const NAV_LINKS: { text: string; url: string; external?: boolean; drawer?: 'tree' | false }[] = [
	{ text: 'Home', url: '/' },
	{ text: 'Docs', url: '/docs/', drawer: 'tree' },
	{ text: 'About', url: '/about/' },
	{ text: 'GitHub', url: GITHUB_URL, external: true },
];
