import type { ReactNode } from 'react';
import { navigate } from 'astro:transitions/client';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import { DocsSiteHeader } from '@/components/docs/docs-header';
import SearchDialog from '@/components/docs/search-dialog';
import { Footer, Wordmark } from './brand';
import { ROOT_THEME } from './docs';

export type SitePageProps = {
	pathname: string;
	params: AstroProviderProps[ 'params' ];
};

export function SiteLayout( { pathname, params, children }: SitePageProps & { children: ReactNode } ) {
	return (
		<RootProvider
			pathname={ pathname }
			params={ params }
			navigate={ navigate }
			theme={ ROOT_THEME }
			search={ { SearchDialog } }
		>
			<DocsSiteHeader pathname={ pathname } wordmark={ <Wordmark /> } />
			<main id="main" className="flex-1">
				{ children }
			</main>
			<Footer />
		</RootProvider>
	);
}
