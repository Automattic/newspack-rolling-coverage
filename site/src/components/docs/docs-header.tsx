"use client"

import type { ReactNode } from 'react';
import { Icon, search } from '@wordpress/icons';
import { useSearchContext } from 'fumadocs-ui/contexts/search';
import { useSidebar } from 'fumadocs-ui/layouts/notebook/slots/sidebar';
import { Button } from '@/components/ui/button';
import { MenuIcon, SiteHeader } from '@/components/site/site-header';
import { DrawerSearch } from './drawer-search';

export function SearchButton() {
	const { setOpenSearch } = useSearchContext();
	return (
		<Button
			type="button"
			variant="outline"
			size="sm"
			className="px-[calc(var(--newspack-ui-spacer-1)*1.75)] max-md:hidden"
			onClick={ () => setOpenSearch( true ) }
		>
			<Icon icon={ search } />
			Search
		</Button>
	);
}

function DocsMenuButton() {
	const { open, setOpen } = useSidebar();
	return (
		<Button
			type="button"
			variant="ghost"
			size="icon-sm"
			className="md:hidden"
			aria-expanded={ open }
			aria-controls="nd-sidebar-mobile"
			onClick={ () => setOpen( ! open ) }
		>
			<MenuIcon />
		</Button>
	);
}

/**
 * The site header with the docs search: the Search button opens the Fumadocs search dialog,
 * and the mobile Sheet searches inline. With `docs`, the menu button opens the Fumadocs docs
 * drawer instead of the Sheet. Render it inside a Fumadocs `RootProvider`, and with `docs`
 * inside the notebook `DocsLayout`.
 */
export function DocsSiteHeader( { pathname, docs = false, wordmark }: { pathname: string; docs?: boolean; wordmark?: ReactNode } ) {
	return (
		<SiteHeader
			pathname={ pathname }
			wordmark={ wordmark }
			search={ <SearchButton /> }
			drawerSearch={ ( close ) => <DrawerSearch onNavigate={ close } /> }
			menuButton={ docs ? <DocsMenuButton /> : undefined }
			className={ docs ? '[grid-area:1/1/2/-1] [contain:inline-size]' : undefined }
		/>
	);
}
