import { DocsLayout } from 'fumadocs-ui/layouts/notebook';
import { DocsPage, type DocsPageProps } from 'fumadocs-ui/layouts/notebook/page';
import { useSidebar } from 'fumadocs-ui/layouts/notebook/slots/sidebar';
import type { Root } from 'fumadocs-core/page-tree';
import type { ComponentProps, CSSProperties, ReactNode } from 'react';
import { navigate } from 'astro:transitions/client';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import { cn } from '@/lib/utils';
import SearchDialog from './search';
import { DrawerA11y } from './drawer-a11y';
import { DrawerSearch } from './drawer-search';
import { DRAWER_SIDEBAR_SLOT, DrawerClose } from './drawer-sidebar';
import { SiteHeader } from './site-header';

export { SITE_TITLE } from '@/lib/site';

export const ROOT_THEME = { enabled: false } as const;

function SidebarBanner( { className, children, ...props }: ComponentProps< 'div' > ) {
	const { mode, open, setOpen } = useSidebar();
	return (
		<div { ...props } className={ cn( 'flex flex-col gap-3 p-4 pb-2 empty:hidden', className ) }>
			{ mode === 'drawer' ? (
				<>
					<DrawerClose />
					<DrawerSearch open={ open } onNavigate={ () => setOpen( false ) } />
				</>
			) : (
				children
			) }
		</div>
	);
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
				slots={ { sidebar: DRAWER_SIDEBAR_SLOT } }
				nav={ { component: <SiteHeader docs pathname={ pathname } />, mode: 'top' } }
				sidebar={ { collapsible: false, 'aria-label': 'Site navigation', banner: SidebarBanner } }
				containerProps={ { style: { '--fd-header-height': 'var(--site-header-height)' } as CSSProperties } }
			>
				<DrawerA11y />
				<DocsPage { ...page }>{ children }</DocsPage>
			</DocsLayout>
		</RootProvider>
	);
}
