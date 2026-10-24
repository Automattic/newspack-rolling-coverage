import type { CSSProperties, ReactNode } from 'react';
import { usePathname } from 'fumadocs-core/framework';
import type { Root } from 'fumadocs-core/page-tree';
import { DocsLayout, useNotebookLayout } from 'fumadocs-ui/layouts/notebook';
import { DrawerA11y } from './drawer-a11y';
import { LAYOUT_PROPS, NAV_LINKS, SITE_TITLE } from './docs';

const EMPTY_TREE: Root = { name: SITE_TITLE, children: [] };

const linkClassName =
	'-mx-1 rounded-sm px-1 text-sm text-fd-muted-foreground transition-colors hover:text-fd-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fd-ring data-[active=true]:text-fd-primary';

const iconLinkClassName =
	'inline-flex items-center justify-center rounded-md p-1.5 text-fd-muted-foreground transition-colors duration-100 hover:bg-fd-accent hover:text-fd-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fd-ring [&_svg]:size-4.5';

function SiteNav( { className, icons = false }: { className: string; icons?: boolean } ) {
	const { navItems } = useNotebookLayout();
	const pathname = usePathname().replace( /\/?$/, '/' );
	return (
		<nav aria-label="Main" className={ className }>
			{ NAV_LINKS.map( ( { text, url } ) => {
				const current = url === '/docs/' ? pathname.startsWith( url ) : pathname === url;
				return (
					<a
						key={ url }
						href={ url }
						className={ linkClassName }
						{ ...( current
							? {
									'aria-current': 'page' as const,
									'data-active': true,
								}
							: {} ) }
					>
						{ text }
					</a>
				);
			} ) }
			{ icons &&
				navItems
					.filter( ( item ) => item.type === 'icon' )
					.map( ( item ) => (
						<a
							key={ item.url }
							href={ item.url }
							aria-label={ item.label }
							className={ iconLinkClassName }
						>
							{ item.icon }
						</a>
					) ) }
		</nav>
	);
}

const SITE_LINKS = [
	...NAV_LINKS.map( ( link ) => ( { ...link, on: 'menu' as const } ) ),
	{
		type: 'custom' as const,
		on: 'nav' as const,
		children: <SiteNav className="flex items-center gap-6" />,
	},
];

export function SiteLayout( { children }: { children: ReactNode } ) {
	return (
		<DocsLayout
			tree={ EMPTY_TREE }
			{ ...LAYOUT_PROPS }
			links={ SITE_LINKS }
			nav={ {
				...LAYOUT_PROPS.nav,
				children: (
					<SiteNav
						icons
						className="ms-6 flex items-center gap-4 max-md:hidden lg:hidden"
					/>
				),
			} }
			containerProps={ {
				className: '[&>[data-sidebar-placeholder]]:hidden',
				style: { '--fd-sidebar-col': '0px' } as CSSProperties,
			} }
		>
			<DrawerA11y />
			<div className="flex min-w-0 flex-col [grid-area:2/1/4/-1]">{ children }</div>
		</DocsLayout>
	);
}
