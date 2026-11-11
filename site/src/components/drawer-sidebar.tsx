import { useMemo, type ReactNode } from 'react';
import Link from 'fumadocs-core/link';
import { usePathname } from 'fumadocs-core/framework';
import type * as PageTree from 'fumadocs-core/page-tree';
import { TreeContextProvider, useTreeContext, useTreePath } from 'fumadocs-ui/contexts/tree';
import { CollapsibleTrigger } from 'fumadocs-ui/components/ui/collapsible';
import {
	SidebarFolder,
	SidebarFolderContent,
	SidebarSeparator,
	useFolder,
	useFolderDepth,
} from 'fumadocs-ui/components/sidebar/base';
import {
	Sidebar,
	SidebarCollapseTrigger,
	SidebarProvider,
	SidebarTrigger,
	useSidebar,
	type SidebarProps,
} from 'fumadocs-ui/layouts/notebook/slots/sidebar';
import { cn } from '@/lib/utils';
import { CloseIcon } from '@/lib/newspack-ui-icons';
import {
	DRAWER_CLOSE,
	DRAWER_ITEM,
	DRAWER_ITEM_BASE,
	DRAWER_ITEM_HIGHLIGHT,
	DRAWER_ITEM_HOVER,
	FolderChevron,
	drawerOffset,
	drawerTree,
} from './drawer-nav';

function normalize( url: string ) {
	return url.length > 1 && url.endsWith( '/' ) ? url.slice( 0, -1 ) : url;
}

function DrawerSeparator( { item }: { item: PageTree.Separator } ) {
	const depth = useFolderDepth();
	return (
		<SidebarSeparator
			className="inline-flex items-center gap-2 mb-1.5 px-2 mt-6 first:mt-0 empty:mb-0 [&_svg]:size-4 [&_svg]:shrink-0"
			style={ drawerOffset( depth ) }
		>
			{ item.icon }
			{ item.name }
		</SidebarSeparator>
	);
}

function DrawerFolderHead( { item }: { item: PageTree.Folder } ) {
	const { open, setOpen, depth, collapsible } = useFolder()!;
	const pathname = usePathname();
	const style = drawerOffset( depth - 1 );

	if ( item.index ) {
		const active = normalize( item.index.url ) === normalize( pathname );
		return (
			<Link
				href={ item.index.url }
				external={ item.index.external }
				data-active={ active }
				className={ cn( DRAWER_ITEM, depth > 1 && DRAWER_ITEM_HIGHLIGHT, 'w-full' ) }
				style={ style }
				onClick={ ( event ) => {
					if ( ! collapsible ) {
						return;
					}
					if ( event.target instanceof Element && event.target.matches( '[data-icon], [data-icon] *' ) ) {
						setOpen( ! open );
						event.preventDefault();
					} else {
						setOpen( active ? ! open : true );
					}
				} }
			>
				{ item.icon }
				{ item.name }
				{ collapsible && <FolderChevron open={ open } /> }
			</Link>
		);
	}

	if ( ! collapsible ) {
		return (
			<div className={ cn( DRAWER_ITEM_BASE, 'w-full' ) } style={ style }>
				{ item.icon }
				{ item.name }
			</div>
		);
	}

	return (
		<CollapsibleTrigger className={ cn( DRAWER_ITEM_BASE, DRAWER_ITEM_HOVER, 'w-full' ) } style={ style }>
			{ item.icon }
			{ item.name }
			<FolderChevron open={ open } />
		</CollapsibleTrigger>
	);
}

function DrawerFolderBody( { children }: { children: ReactNode } ) {
	const depth = useFolderDepth();
	return (
		<SidebarFolderContent
			className={ cn(
				'relative',
				depth === 1 && "before:content-[''] before:absolute before:w-px before:inset-y-1 before:bg-fd-border before:inset-s-2.5"
			) }
		>
			<div className="flex flex-col gap-0.5 pt-0.5">{ children }</div>
		</SidebarFolderContent>
	);
}

function DrawerFolder( { item, children }: { item: PageTree.Folder; children: ReactNode } ) {
	const path = useTreePath();
	return (
		<SidebarFolder collapsible={ item.collapsible } active={ path.includes( item ) } defaultOpen={ item.defaultOpen }>
			<DrawerFolderHead item={ item } />
			<DrawerFolderBody>{ children }</DrawerFolderBody>
		</SidebarFolder>
	);
}

const DRAWER_COMPONENTS = { Folder: DrawerFolder, Separator: DrawerSeparator };

/**
 * The notebook sidebar. Both modes render folders with the WordPress chevron in place of
 * Fumadocs' lucide one. In drawer mode it renders the site links with the docs tree nested
 * under Docs, from `drawerTree()`.
 */
function DocsSidebar( props: SidebarProps ) {
	const { mode } = useSidebar();
	const { full } = useTreeContext();
	const tree = useMemo( () => drawerTree( full ), [ full ] );

	if ( mode !== 'drawer' ) {
		return <Sidebar { ...props } components={ DRAWER_COMPONENTS } />;
	}
	return (
		<TreeContextProvider tree={ tree }>
			<Sidebar { ...props } components={ DRAWER_COMPONENTS } />
		</TreeContextProvider>
	);
}

/** Replaces Fumadocs' lucide close icon with the newspack-ui one, keeping its trigger and label. */
export function DrawerClose() {
	return (
		<SidebarTrigger className={ DRAWER_CLOSE }>
			<CloseIcon />
		</SidebarTrigger>
	);
}

export const DRAWER_SIDEBAR_SLOT = {
	provider: SidebarProvider,
	root: DocsSidebar,
	trigger: SidebarTrigger,
	collapseTrigger: SidebarCollapseTrigger,
	useSidebar,
};
