import type { ReactNode } from 'react';
import { navigate } from 'astro:transitions/client';
import type { Root } from 'fumadocs-core/page-tree';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import type { DocsPageProps } from 'fumadocs-ui/layouts/notebook/page';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import { DocsShell } from '@/components/docs/docs-shell';
import SearchDialog from '@/components/docs/search-dialog';
import { Wordmark } from './brand';

export const ROOT_THEME = { enabled: false } as const;

/**
 * The docs page island. It renders the kit's `DocsShell` in a provider that passes Astro's
 * `navigate`, so search results open through the client router like every other link.
 */
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
			theme={ ROOT_THEME }
			search={ { SearchDialog } }
		>
			<DocsShell tree={ tree } pathname={ pathname } page={ page } wordmark={ <Wordmark /> }>
				{ children }
			</DocsShell>
		</RootProvider>
	);
}
