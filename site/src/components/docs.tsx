import { DocsLayout } from 'fumadocs-ui/layouts/notebook';
import { DocsPage, type DocsPageProps } from 'fumadocs-ui/layouts/notebook/page';
import type { Root } from 'fumadocs-core/page-tree';
import type { ReactNode } from 'react';
import { navigate } from 'astro:transitions/client';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import { LiveIcon } from './live-icon';
import SearchDialog from './search';
import { DrawerA11y } from './drawer-a11y';

export const SITE_TITLE = 'Rolling Coverage';
export const NAV_TITLE = (
	<>
		<LiveIcon onAir className="-ms-1 -me-1 size-7 text-newspack-600 dark:text-newspack-300" />
		<span className="whitespace-nowrap">{ SITE_TITLE }</span>
	</>
);
export const GITHUB_URL = 'https://github.com/Automattic/newspack-rolling-coverage';
export const NAV_LINKS = [
	{ text: 'Docs', url: '/docs/' },
	{ text: 'About', url: '/about/' },
];
export const LAYOUT_PROPS = {
	nav: { title: NAV_TITLE, url: '/', mode: 'top' as const },
	links: NAV_LINKS,
	githubUrl: GITHUB_URL,
	sidebar: { collapsible: false, 'aria-label': 'Site navigation' },
};

export function Docs( {
	tree,
	children,
	pathname,
	params,
	page,
}: {
	tree: Root;
	children: ReactNode;
	pathname: string;
	params: AstroProviderProps[ 'params' ];
	page?: DocsPageProps;
} ) {
	return (
		<RootProvider
			pathname={ pathname }
			params={ params }
			navigate={ navigate }
			search={ { SearchDialog } }
		>
			<DocsLayout tree={ tree } { ...LAYOUT_PROPS }>
				<DrawerA11y />
				<DocsPage { ...page }>{ children }</DocsPage>
			</DocsLayout>
		</RootProvider>
	);
}
