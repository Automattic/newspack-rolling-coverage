import { useState } from 'react';
import { Menu, Search } from 'lucide-react';
import { useSearchContext } from 'fumadocs-ui/contexts/search';
import { useSidebar } from 'fumadocs-ui/layouts/notebook/slots/sidebar';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
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

function isCurrent( url: string, pathname: string ) {
	if ( url.startsWith( 'http' ) ) {
		return false;
	}
	const path = pathname.replace( /\/?$/, '/' );
	return url === '/docs/' ? path.startsWith( url ) : path === url;
}

function NavList( { pathname, className, linkClassName }: { pathname: string; className?: string; linkClassName?: string } ) {
	return (
		<ul className={ cn( 'm-0 flex list-none p-0', className ) }>
			{ NAV_LINKS.map( ( link ) => (
				<li key={ link.url }>
					<a
						href={ link.url }
						rel={ link.external ? 'noopener' : undefined }
						aria-current={ isCurrent( link.url, pathname ) ? 'page' : undefined }
						className={ cn( navLinkClassName, linkClassName ) }
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
			className="w-(--newspack-ui-spacer-7) p-0 [&_svg]:size-(--newspack-ui-spacer-3) md:w-auto md:px-[calc(var(--newspack-ui-spacer-1)*1.75)]"
			onClick={ () => setOpenSearch( true ) }
		>
			<Search aria-hidden="true" />
			<span className="max-md:hidden">Search</span>
		</Button>
	);
}

function MenuIcon() {
	return (
		<>
			<Menu aria-hidden="true" />
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
			className="md:hidden [&_svg]:size-(--newspack-ui-spacer-4)"
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
	return (
		<Dialog open={ open } onOpenChange={ setOpen }>
			<DialogTrigger asChild>
				<Button type="button" variant="ghost" size="icon-sm" className="md:hidden [&_svg]:size-(--newspack-ui-spacer-4)">
					<MenuIcon />
				</Button>
			</DialogTrigger>
			<DialogContent aria-describedby={ undefined }>
				<DialogHeader>
					<DialogTitle>{ SITE_TITLE }</DialogTitle>
				</DialogHeader>
				<nav aria-label="Main" className="px-(--newspack-ui-spacer-3) pt-(--newspack-ui-spacer-2)">
					<NavList
						pathname={ pathname }
						className="flex-col"
						linkClassName="flex min-h-(--newspack-ui-spacer-8) text-(length:--newspack-ui-font-size-body)"
					/>
				</nav>
				<MenuFooter className="mx-(--newspack-ui-spacer-5) mt-(--newspack-ui-spacer-3)" />
			</DialogContent>
		</Dialog>
	);
}

export function MenuFooter( { className }: { className?: string } ) {
	return (
		<div className={ cn( 'flex items-center justify-end gap-(--newspack-ui-spacer-2) border-t border-border pt-(--newspack-ui-spacer-3)', className ) }>
			<ThemeToggle />
		</div>
	);
}

export function SiteHeader( { pathname, docs = false }: { pathname: string; docs?: boolean } ) {
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
					<ThemeToggle className="max-md:hidden" />
					{ docs ? <DocsMenuButton /> : <SiteMenuButton pathname={ pathname } /> }
				</div>
			</div>
		</header>
	);
}
