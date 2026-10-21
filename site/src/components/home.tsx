import { HomeLayout } from 'fumadocs-ui/layouts/home';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import { navigate } from 'astro:transitions/client';
import { GITHUB_URL, NAV_LINKS, SITE_TITLE } from './docs';
import SearchDialog from './search';
import { SiteHeader } from './site-header';

export const SITE_DESCRIPTION =
	'Live updates for developing stories. Post short, timestamped entries from WordPress or Slack, and readers see them as they happen, without reloading the page.';

const sections = [
	{
		title: 'Getting started',
		description: 'Install the plugin and publish your first coverage.',
		href: '/docs/getting-started/',
		primary: true,
	},
	{
		title: 'Guides',
		description: 'Layouts, Slack, pinned entries, and ending a coverage.',
		href: '/docs/guides/',
	},
	{
		title: 'Developers',
		description: 'Hooks and the REST API.',
		href: '/docs/developers/',
	},
];

export function Home( {
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
				nav={ { title: SITE_TITLE, url: '/' } }
				githubUrl={ GITHUB_URL }
				links={ NAV_LINKS }
				slots={ { header: SiteHeader } }
			>
				<div className="w-full px-4 py-16 md:px-6 md:py-24">
					<div className="max-w-3xl">
						<h1 className="text-4xl font-semibold tracking-tight text-fd-foreground md:text-5xl">
							{ SITE_TITLE }
						</h1>
						<p className="mt-4 max-w-[60ch] text-lg text-pretty text-fd-muted-foreground">{ SITE_DESCRIPTION }</p>
					</div>
					<ul className="mt-12 grid max-w-5xl gap-3 sm:grid-cols-3">
						{ sections.map( ( section ) => (
							<li key={ section.href }>
								<a
									href={ section.href }
									className="group flex h-full flex-col gap-1 rounded-xl border border-fd-border p-4 transition-colors hover:bg-fd-accent/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-fd-ring"
								>
									<span
										className={ `flex items-center justify-between gap-2 font-medium ${
											section.primary ? 'text-fd-primary' : 'text-fd-foreground'
										}` }
									>
										{ section.title }
										<svg
											aria-hidden="true"
											viewBox="0 0 16 16"
											fill="none"
											stroke="currentColor"
											strokeWidth="1.5"
											strokeLinecap="round"
											strokeLinejoin="round"
											className="size-4 shrink-0 transition-transform duration-200 ease-out group-hover:translate-x-0.5 motion-reduce:transition-none"
										>
											<path d="M3 8h10M9 4l4 4-4 4" />
										</svg>
									</span>
									<span className="text-sm text-fd-muted-foreground">{ section.description }</span>
								</a>
							</li>
						) ) }
					</ul>
				</div>
			</HomeLayout>
		</RootProvider>
	);
}
