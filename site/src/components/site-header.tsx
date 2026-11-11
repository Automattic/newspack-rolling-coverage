import { useState, type ComponentProps } from 'react';
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
	{ text: 'Docs', url: '/docs/' },
	{ text: 'About', url: '/about/' },
];

const navLinkClassName =
	'inline-flex min-h-(--newspack-ui-spacer-7) items-center rounded-(--newspack-ui-radius-control) px-(--newspack-ui-spacer-1) text-(length:--newspack-ui-font-size-small) font-(weight:--newspack-ui-font-weight-medium) text-muted-foreground transition-colors duration-(--newspack-ui-duration-color) hover:bg-(--newspack-ui-color-neutral-fill) hover:text-foreground aria-[current=page]:text-foreground aria-[current=page]:underline aria-[current=page]:decoration-(--newspack-ui-color-accent) aria-[current=page]:decoration-2 aria-[current=page]:underline-offset-8';

function isCurrent( url: string, pathname: string ) {
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

export function GitHubIcon( props: ComponentProps< 'svg' > ) {
	return (
		<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" { ...props }>
			<path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
		</svg>
	);
}

export function GitHubLink( { className }: { className?: string } ) {
	return (
		<Button asChild variant="ghost" size="icon-sm" className={ cn( '[&_svg]:size-(--newspack-ui-spacer-3)', className ) }>
			<a href={ GITHUB_URL } aria-label="GitHub">
				<GitHubIcon />
			</a>
		</Button>
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
		<div className={ cn( 'flex items-center justify-between gap-(--newspack-ui-spacer-2) border-t border-border pt-(--newspack-ui-spacer-3)', className ) }>
			<GitHubLink className="-ms-(--newspack-ui-spacer-1)" />
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
					<GitHubLink className="max-md:hidden" />
					<ThemeToggle className="max-md:hidden" />
					{ docs ? <DocsMenuButton /> : <SiteMenuButton pathname={ pathname } /> }
				</div>
			</div>
		</header>
	);
}
