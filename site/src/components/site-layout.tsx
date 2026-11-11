import type { ReactNode } from 'react';
import { navigate } from 'astro:transitions/client';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import { ROOT_THEME } from './docs';
import SearchDialog from './search';
import { SiteFooter } from './site-footer';
import { SiteHeader } from './site-header';

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
			<SiteHeader pathname={ pathname } />
			<main id="main" className="flex-1">
				{ children }
			</main>
			<SiteFooter />
		</RootProvider>
	);
}
