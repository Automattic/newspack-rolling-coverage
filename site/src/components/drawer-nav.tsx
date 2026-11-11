import type { CSSProperties } from 'react';
import { chevronDown, Icon } from '@wordpress/icons';
import type * as PageTree from 'fumadocs-core/page-tree';
import { NAV_LINKS } from '@/lib/site';
import { cn } from '@/lib/utils';

/**
 * Class strings copied from Fumadocs' notebook sidebar (`itemVariants`, the drawer close button),
 * so the homepage Sheet and the parts of the docs sidebar we render ourselves match its items.
 */
export const DRAWER_ITEM_BASE = 'relative flex flex-row items-center gap-2 rounded-lg p-2 text-start text-fd-muted-foreground wrap-anywhere [&_svg]:size-4 [&_svg]:shrink-0';
export const DRAWER_ITEM_HOVER = 'transition-colors hover:bg-fd-accent/50 hover:text-fd-accent-foreground/80 hover:transition-none';
export const DRAWER_ITEM = `${ DRAWER_ITEM_BASE } ${ DRAWER_ITEM_HOVER } data-[active=true]:bg-fd-primary/10 data-[active=true]:text-fd-primary data-[active=true]:hover:transition-colors`;
export const DRAWER_ITEM_HIGHLIGHT = "data-[active=true]:before:content-[''] data-[active=true]:before:bg-fd-primary data-[active=true]:before:absolute data-[active=true]:before:w-px data-[active=true]:before:inset-y-2.5 data-[active=true]:before:inset-s-2.5";
export const DRAWER_CLOSE = 'inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors duration-100 disabled:pointer-events-none disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fd-ring hover:bg-fd-accent hover:text-fd-accent-foreground p-1.5 [&_svg]:size-4.5 ms-auto text-fd-muted-foreground';

/** Fumadocs' inline-start offset for an item at a folder depth. */
export function drawerOffset( depth: number ): CSSProperties {
	return { paddingInlineStart: `calc(${ 2 + 3 * depth } * var(--spacing))` };
}

export function FolderChevron( { open }: { open: boolean } ) {
	return (
		<Icon
			icon={ chevronDown }
			size={ 20 }
			data-icon=""
			aria-hidden="true"
			style={ { width: 20, height: 20 } }
			className={ cn( 'ms-auto fill-current transition-transform', ! open && '-rotate-90 rtl:rotate-90' ) }
		/>
	);
}

/** The site links shown in the drawer. Links marked `drawer: false` are sections of the docs tree. */
export const DRAWER_LINKS = NAV_LINKS.filter( ( link ) => link.drawer !== false );

/**
 * The page tree the docs drawer renders: the site links at the top level, with the docs tree
 * nested as an open folder under the link marked `drawer: 'tree'`.
 */
export function drawerTree( root: PageTree.Root ): PageTree.Root {
	return {
		...root,
		$id: `drawer:${ root.$id ?? 'root' }`,
		fallback: undefined,
		children: DRAWER_LINKS.map( ( link ): PageTree.Node =>
			link.drawer === 'tree'
				? {
						type: 'folder',
						$id: `drawer:${ link.url }`,
						name: link.text,
						defaultOpen: true,
						index: { type: 'page', $id: `drawer:${ link.url }:index`, name: link.text, url: link.url },
						children: root.children,
				  }
				: {
						type: 'page',
						$id: `drawer:${ link.url }`,
						name: link.text,
						url: link.url,
						external: false,
				  }
		),
	};
}
