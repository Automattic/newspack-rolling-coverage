import type { ComponentProps } from 'react';
import { usePathname } from 'fumadocs-core/framework';
import { useHomeLayout } from 'fumadocs-ui/layouts/home';
import { buttonVariants } from 'fumadocs-ui/components/ui/button';

const linkClassName =
	'text-sm text-fd-muted-foreground transition-colors hover:text-fd-accent-foreground data-[active=true]:text-fd-primary';

function isCurrent( url: string, pathname: string ) {
	const path = pathname.replace( /\/?$/, '/' );
	return url === '/docs/' ? path.startsWith( '/docs/' ) : path === url;
}

/**
 * The homepage header, laid out like the docs (notebook) header so both pages share one header.
 */
export function SiteHeader( props: ComponentProps< 'header' > ) {
	const { navItems, slots } = useHomeLayout();
	const pathname = usePathname();
	const textLinks = navItems.filter( ( item ) => item.type !== 'icon' && 'url' in item );
	const iconLinks = navItems.filter( ( item ) => item.type === 'icon' );

	const currentProps = ( url: string ) =>
		isCurrent( url, pathname ) ? { 'aria-current': 'page' as const, 'data-active': true } : {};

	return (
		<header
			{ ...props }
			className="sticky top-0 z-40 flex h-14 gap-2 border-b bg-fd-background/80 px-4 backdrop-blur-sm md:px-6"
		>
			<div className="flex flex-1 items-center">
				{ slots.navTitle && <slots.navTitle className="inline-flex items-center gap-2.5 font-semibold" /> }
			</div>
			{ slots.searchTrigger && (
				<slots.searchTrigger.full
					hideIfDisabled
					className="my-auto w-full max-w-sm rounded-xl ps-2.5 max-md:hidden"
				/>
			) }
			<div className="flex flex-1 items-center justify-end md:gap-2">
				<nav aria-label="Main" className="flex items-center gap-6 empty:hidden max-md:hidden">
					{ textLinks.map( ( item ) => (
						<a
							key={ item.url }
							href={ item.url }
							className={ linkClassName }
							{ ...currentProps( item.url ) }
						>
							{ item.text }
						</a>
					) ) }
				</nav>
				{ iconLinks.map( ( item ) => (
					<a
						key={ item.url }
						href={ item.url }
						aria-label={ item.label }
						className={ `${ buttonVariants( { size: 'icon-sm', variant: 'ghost' } ) } text-fd-muted-foreground max-md:hidden` }
					>
						{ item.icon }
					</a>
				) ) }
				<nav aria-label="Main" className="flex items-center gap-1 md:hidden">
					{ slots.searchTrigger && <slots.searchTrigger.sm hideIfDisabled className="p-2" /> }
					{ textLinks.map( ( item ) => (
						<a
							key={ item.url }
							href={ item.url }
							className={ `${ linkClassName } p-2` }
							{ ...currentProps( item.url ) }
						>
							{ item.text }
						</a>
					) ) }
				</nav>
				<div className="flex items-center gap-2 max-md:hidden">
					{ slots.themeSwitch && <slots.themeSwitch /> }
				</div>
			</div>
		</header>
	);
}
