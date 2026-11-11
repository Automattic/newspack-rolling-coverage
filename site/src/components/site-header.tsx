import { useEffect, useState } from 'react';
import { Icon, menu, search } from '@wordpress/icons';
import { useSearchContext } from 'fumadocs-ui/contexts/search';
import { useSidebar } from 'fumadocs-ui/layouts/notebook/slots/sidebar';
import { Button } from '@/components/ui/button';
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { CloseIcon } from '@/lib/newspack-ui-icons';
import { useMobileMenu } from '@/lib/theme';
import { cn } from '@/lib/utils';
import { DrawerSearch } from './drawer-search';
import { LiveIcon } from './live-icon';
import { ThemeToggle } from './theme-toggle';

export const SITE_TITLE = 'Rolling Coverage';
export const GITHUB_URL = 'https://github.com/Automattic/newspack-rolling-coverage';
export const NAV_LINKS = [
	{ text: 'Home', url: '/' },
	{ text: 'Docs', url: '/docs/' },
	{ text: 'About', url: '/about/' },
	{ text: 'GitHub', url: GITHUB_URL, external: true },
];

const navLinkClassName =
	'inline-flex min-h-(--newspack-ui-spacer-7) items-center rounded-(--newspack-ui-radius-control) px-(--newspack-ui-spacer-1) text-(length:--newspack-ui-font-size-small) font-(weight:--newspack-ui-font-weight-medium) text-muted-foreground transition-colors duration-(--newspack-ui-duration-color) hover:bg-(--newspack-ui-color-neutral-fill) hover:text-foreground aria-[current=page]:text-foreground aria-[current=page]:underline aria-[current=page]:decoration-(--newspack-ui-color-accent) aria-[current=page]:decoration-2 aria-[current=page]:underline-offset-8';

const MENU_ITEM = 'relative flex flex-row items-center gap-2 rounded-lg p-2 text-start text-fd-muted-foreground wrap-anywhere [&_svg]:size-4 [&_svg]:shrink-0 transition-colors hover:bg-fd-accent/50 hover:text-fd-accent-foreground/80 hover:transition-none data-[active=true]:bg-fd-primary/10 data-[active=true]:text-fd-primary data-[active=true]:hover:transition-colors';
const MENU_CLOSE = 'inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors duration-100 disabled:pointer-events-none disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-fd-ring hover:bg-fd-accent hover:text-fd-accent-foreground p-1.5 [&_svg]:size-4.5 ms-auto text-fd-muted-foreground';

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
					<SheetClose className={ MENU_CLOSE }>
						<CloseIcon />
						<span className="sr-only">Close</span>
					</SheetClose>
					<DrawerSearch onNavigate={ () => setOpen( false ) } />
				</div>
				<nav className="site-menu flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto overscroll-contain p-4" aria-label="Main">
					{ NAV_LINKS.map( ( link ) => {
						const current = isCurrent( link.url, pathname );
						return (
							<a
								key={ link.url }
								href={ link.url }
								rel={ link.external ? 'noopener' : undefined }
								className={ MENU_ITEM }
								data-active={ current }
								aria-current={ current ? 'page' : undefined }
							>
								{ link.text }
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
