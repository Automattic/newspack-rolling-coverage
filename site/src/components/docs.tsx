import { DocsLayout } from 'fumadocs-ui/layouts/notebook';
import { DocsPage, type DocsPageProps } from 'fumadocs-ui/layouts/notebook/page';
import type { Root } from 'fumadocs-core/page-tree';
import type { ReactNode } from 'react';
import { navigate } from 'astro:transitions/client';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import { LiveIcon } from './live-icon';
import SearchDialog from './search';

export const SITE_TITLE = 'Rolling Coverage';
export const NAV_TITLE = (
	<>
		<LiveIcon onAir className="-ms-1 -me-1 size-7 text-newspack-600 dark:text-newspack-300" />
		{ SITE_TITLE }
	</>
);
export const GITHUB_URL = 'https://github.com/Automattic/newspack-rolling-coverage';
export const NAV_LINKS = [
	{ text: 'Docs', url: '/docs/' },
	{ text: 'About', url: '/about/' },
];

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
			<DocsLayout
				tree={ tree }
				nav={ { title: NAV_TITLE, url: '/', mode: 'top' } }
				links={ NAV_LINKS }
				githubUrl={ GITHUB_URL }
				sidebar={ { collapsible: false } }
			>
				<DocsPage { ...page }>{ children }</DocsPage>
			</DocsLayout>
		</RootProvider>
	);
}
