import { useEffect, useState } from 'react';
import { Icon, menu, search } from '@wordpress/icons';
import { useSearchContext } from 'fumadocs-ui/contexts/search';
import { useSidebar } from 'fumadocs-ui/layouts/notebook/slots/sidebar';
import { Button } from '@/components/ui/button';
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { CloseIcon } from '@/lib/newspack-ui-icons';
import { NAV_LINKS, SITE_TITLE } from '@/lib/site';
import { useMobileMenu } from '@/lib/theme';
import { cn } from '@/lib/utils';
import { DRAWER_CLOSE, DRAWER_ITEM, DRAWER_LINKS, FolderChevron } from './drawer-nav';
import { DrawerSearch } from './drawer-search';
import { LiveIcon } from './live-icon';
import { ThemeToggle } from './theme-toggle';

const navLinkClassName =
	'inline-flex min-h-(--newspack-ui-spacer-7) items-center rounded-(--newspack-ui-radius-control) px-(--newspack-ui-spacer-1) text-(length:--newspack-ui-font-size-small) font-(weight:--newspack-ui-font-weight-medium) text-muted-foreground transition-colors duration-(--newspack-ui-duration-color) hover:bg-(--newspack-ui-color-neutral-fill) hover:text-foreground aria-[current=page]:text-foreground aria-[current=page]:underline aria-[current=page]:decoration-(--newspack-ui-color-accent) aria-[current=page]:decoration-2 aria-[current=page]:underline-offset-8';

function isCurrent( url: string, pathname: string ) {
	if ( url.startsWith( 'http' ) ) {
		return false;
	}
	const path = pathname.replace( /\/?$/, '/' );
	return url === '/docs/' ? path.startsWith( url ) : path === url;
}

function NavList( { pathname, className }: { pathname: string; className?: string } ) {
	return (
		<ul className={ cn( 'm-0 flex list-none p-0', className ) }>
			{ NAV_LINKS.map( ( link ) => (
				<li key={ link.url }>
					<a
						href={ link.url }
						rel={ link.external ? 'noopener' : undefined }
						aria-current={ isCurrent( link.url, pathname ) ? 'page' : undefined }
						className={ navLinkClassName }
					>
						{ link.text }
					</a>
				</li>
			) ) }
		</ul>
	);
}

function SearchButton() {
	const { setOpenSearch } = useSearchContext();
	return (
		<Button
			type="button"
			variant="outline"
			size="sm"
			aria-label="Search the docs"
			className="px-[calc(var(--newspack-ui-spacer-1)*1.75)] max-md:hidden"
			onClick={ () => setOpenSearch( true ) }
		>
			<Icon icon={ search } aria-hidden="true" />
			Search
		</Button>
	);
}

function MenuIcon() {
	return (
		<>
			<Icon icon={ menu } aria-hidden="true" />
			<span className="sr-only">Menu</span>
		</>
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

function SiteMenuButton( { pathname }: { pathname: string } ) {
	const [ open, setOpen ] = useState( false );
	const mobile = useMobileMenu();

	useEffect( () => {
		if ( ! mobile ) {
			setOpen( false );
		}
	}, [ mobile ] );

	return (
		<Sheet open={ open } onOpenChange={ setOpen }>
			<SheetTrigger asChild>
				<Button type="button" variant="ghost" size="icon-sm" className="md:hidden">
					<MenuIcon />
				</Button>
			</SheetTrigger>
			<SheetContent
				showCloseButton={ false }
				aria-describedby={ undefined }
				className="gap-0 overflow-hidden text-[0.9375rem]"
				onOpenAutoFocus={ ( event ) => {
					const close = event.currentTarget instanceof HTMLElement ? event.currentTarget.querySelector( 'button' ) : null;
					if ( close ) {
						event.preventDefault();
						close.focus();
					}
				} }
			>
				<SheetTitle className="sr-only">Menu</SheetTitle>
				<SheetDescription className="sr-only">Site navigation</SheetDescription>
				<div className="flex flex-col gap-3 p-4 pb-2">
					<SheetClose className={ DRAWER_CLOSE }>
						<CloseIcon />
						<span className="sr-only">Close</span>
					</SheetClose>
					<DrawerSearch onNavigate={ () => setOpen( false ) } />
				</div>
				<nav className="site-menu flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto overscroll-contain p-4" aria-label="Main">
					{ DRAWER_LINKS.map( ( link ) => {
						const current = link.drawer !== 'tree' && isCurrent( link.url, pathname );
						return (
							<a
								key={ link.url }
								href={ link.url }
								rel={ link.external ? 'noopener' : undefined }
								className={ link.drawer === 'tree' ? `${ DRAWER_ITEM } w-full` : DRAWER_ITEM }
								data-active={ current }
								aria-current={ current ? 'page' : undefined }
							>
								{ link.text }
								{ link.drawer === 'tree' && <FolderChevron open={ false } /> }
							</a>
						);
					} ) }
				</nav>
			</SheetContent>
		</Sheet>
	);
}

export function SiteHeader( { pathname, docs = false }: { pathname: string; docs?: boolean } ) {
	const mobile = useMobileMenu();
	return (
		<header
			className={ cn(
				'sticky top-0 z-40 h-(--site-header-height) bg-(--newspack-ui-color-surface) shadow-[inset_0_-1px_0_var(--newspack-ui-color-border)]',
				docs && '[grid-area:1/1/2/-1] [contain:inline-size]'
			) }
		>
			<div className="site-container flex h-full items-center justify-between gap-(--newspack-ui-spacer-2)">
				<a
					href="/"
					className="inline-flex items-center gap-(--newspack-ui-spacer-half) rounded-(--newspack-ui-radius-control) font-(weight:--newspack-ui-font-weight-strong) tracking-(--newspack-ui-letter-spacing-title-m) whitespace-nowrap text-foreground"
				>
					<LiveIcon onAir className="-ms-1 size-7 text-brand" />
					{ SITE_TITLE }
				</a>
				<div className="flex items-center gap-(--newspack-ui-spacer-1)">
					<nav aria-label="Main" className="me-(--newspack-ui-spacer-1) max-md:hidden">
						<NavList pathname={ pathname } className="gap-(--newspack-ui-spacer-half)" />
					</nav>
					<SearchButton />
					{ ! mobile && <ThemeToggle className="max-md:hidden" /> }
					{ docs ? <DocsMenuButton /> : <SiteMenuButton pathname={ pathname } /> }
				</div>
			</div>
		</header>
	);
}
