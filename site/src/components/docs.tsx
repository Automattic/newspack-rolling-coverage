import { DocsLayout } from 'fumadocs-ui/layouts/notebook';
import { DocsPage, type DocsPageProps } from 'fumadocs-ui/layouts/notebook/page';
import { useSidebar } from 'fumadocs-ui/layouts/notebook/slots/sidebar';
import type { Root } from 'fumadocs-core/page-tree';
import type { CSSProperties, ReactNode } from 'react';
import { navigate } from 'astro:transitions/client';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import SearchDialog from './search';
import { DrawerA11y } from './drawer-a11y';
import { MenuFooter, NAV_LINKS, SiteHeader } from './site-header';

export { SITE_TITLE } from './site-header';

export const ROOT_THEME = { enabled: false } as const;

function DrawerFooter() {
	const { mode } = useSidebar();
	return mode === 'drawer' ? <MenuFooter className="mx-4 mb-4" /> : null;
}

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
			<DocsLayout
				tree={ tree }
				themeSwitch={ { enabled: false } }
				links={ NAV_LINKS.map( ( link ) => ( { ...link, on: 'menu' as const } ) ) }
				nav={ { component: <SiteHeader docs pathname={ pathname } />, mode: 'top' } }
				sidebar={ { collapsible: false, 'aria-label': 'Site navigation', footer: DrawerFooter } }
				containerProps={ { style: { '--fd-header-height': 'var(--site-header-height)' } as CSSProperties } }
			>
				<DrawerA11y />
				<DocsPage { ...page }>{ children }</DocsPage>
			</DocsLayout>
		</RootProvider>
	);
}
