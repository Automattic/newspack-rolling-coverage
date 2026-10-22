import { HomeLayout } from 'fumadocs-ui/layouts/home';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import { navigate } from 'astro:transitions/client';
import { GITHUB_URL, NAV_LINKS, NAV_TITLE } from './docs';
import SearchDialog from './search';
import { SiteFooter } from './site-footer';
import { SiteContainer } from './site-container';
import { SiteHeader } from './site-header';

export const NOT_FOUND_DESCRIPTION = 'This page may have moved, or the link may be out of date.';

const focusRing =
	'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-fd-ring';

const buttonBase = `inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-[color,background-color,scale] duration-[160ms] ease-out-strong active:scale-[0.97] motion-reduce:transition-none motion-reduce:active:scale-100 ${ focusRing }`;

export function NotFound( {
	pathname,
	params,
}: {
	pathname: string;
	params: AstroProviderProps[ 'params' ];
} ) {
	return (
		<RootProvider
			pathname={ pathname }
			params={ params }
			navigate={ navigate }
			search={ { SearchDialog } }
		>
			<HomeLayout
				nav={ { title: NAV_TITLE, url: '/' } }
				githubUrl={ GITHUB_URL }
				links={ NAV_LINKS }
				slots={ { header: SiteHeader, container: SiteContainer } }
			>
				<main className="flex-1">
					<div className="mx-auto w-full max-w-6xl px-4 pt-14 pb-24 md:px-6 md:pt-24 md:pb-32">
						<p className="flex flex-wrap items-center gap-3 text-sm font-medium text-fd-muted-foreground">
							<span className="inline-flex items-center gap-2 rounded-full border border-fd-border bg-fd-card px-3 py-1 text-xs font-semibold tracking-wide text-fd-foreground uppercase">
								<span aria-hidden="true" className="size-2 rounded-full bg-fd-muted-foreground" />
								Ended
							</span>
							<span>Page not found</span>
						</p>
						<h1 className="mt-4 max-w-[16ch] text-[2.75rem] leading-[1.05] font-semibold tracking-tight text-balance text-fd-foreground sm:text-6xl">
							This page isn’t live
						</h1>
						<p className="mt-6 max-w-[52ch] text-lg text-pretty text-fd-muted-foreground md:text-xl md:leading-relaxed">
							It may have moved, or the link may be out of date. Browse the docs, or start again from the homepage.
						</p>
						<div className="mt-8 flex flex-col gap-3 sm:flex-row">
							<a
								href="/docs/"
								className={ `${ buttonBase } bg-fd-primary text-fd-primary-foreground hover:bg-fd-primary/85` }
							>
								Browse the docs
							</a>
							<a
								href="/"
								className={ `${ buttonBase } border border-fd-border text-fd-foreground hover:bg-fd-accent hover:text-fd-accent-foreground` }
							>
								Go to the homepage
							</a>
						</div>
					</div>
				</main>
				<SiteFooter />
			</HomeLayout>
		</RootProvider>
	);
}
