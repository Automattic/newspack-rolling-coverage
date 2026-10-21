import { DocsLayout } from 'fumadocs-ui/layouts/notebook';
import { DocsPage, type DocsPageProps } from 'fumadocs-ui/layouts/notebook/page';
import type { Root } from 'fumadocs-core/page-tree';
import type { ReactNode } from 'react';
import { navigate } from 'astro:transitions/client';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import SearchDialog from './search';

export const SITE_TITLE = 'Rolling Coverage';
export const GITHUB_URL = 'https://github.com/Automattic/newspack-rolling-coverage';
export const NAV_LINKS = [ { text: 'Docs', url: '/docs/' } ];

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
				nav={ { title: SITE_TITLE, url: '/', mode: 'top' } }
				links={ NAV_LINKS }
				githubUrl={ GITHUB_URL }
			>
				<DocsPage { ...page }>{ children }</DocsPage>
			</DocsLayout>
		</RootProvider>
	);
}
