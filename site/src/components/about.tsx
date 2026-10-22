import type { ReactNode } from 'react';
import { HomeLayout } from 'fumadocs-ui/layouts/home';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import { navigate } from 'astro:transitions/client';
import { GITHUB_URL, NAV_LINKS, NAV_TITLE } from './docs';
import SearchDialog from './search';
import { SiteFooter } from './site-footer';
import { SiteContainer } from './site-container';
import { SiteHeader } from './site-header';

export const ABOUT_DESCRIPTION =
	'Why Newspack built Rolling Coverage, a free, open-source WordPress plugin for live coverage of a developing story.';

const EMERGENCY_MODE_URL = 'https://emergencymode.news/';

const focusRing =
	'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-fd-ring';

const linkClassName = `font-medium text-fd-primary underline decoration-fd-primary/40 underline-offset-4 transition-colors hover:decoration-fd-primary motion-reduce:transition-none ${ focusRing }`;

const buttonBase = `inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-[color,background-color,scale] duration-[160ms] ease-out-strong active:scale-[0.97] motion-reduce:transition-none motion-reduce:active:scale-100 ${ focusRing }`;

function Section( { id, title, children }: { id: string; title: string; children: ReactNode } ) {
	return (
		<section aria-labelledby={ id } className="mt-14 md:mt-16">
			<h2
				id={ id }
				className="text-2xl font-semibold tracking-tight text-balance text-fd-foreground md:text-3xl"
			>
				{ title }
			</h2>
			<div className="mt-4 space-y-4 text-pretty text-fd-muted-foreground md:text-lg md:leading-relaxed">
				{ children }
			</div>
		</section>
	);
}

export function About( {
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
				<main>
					<article className="mx-auto w-full max-w-6xl px-4 pt-14 pb-24 md:px-6 md:pt-24 md:pb-32">
						<div className="max-w-[46rem]">
							<p className="text-sm font-medium text-fd-muted-foreground">About</p>
							<h1 className="mt-4 text-[2.5rem] leading-[1.1] font-semibold tracking-tight text-balance text-fd-foreground sm:text-5xl">
								Why we built Rolling Coverage
							</h1>
							<p className="mt-6 text-lg text-pretty text-fd-muted-foreground md:text-xl md:leading-relaxed">
								Rolling Coverage is a free, open-source WordPress plugin for covering a developing story
								as it happens. Newspack builds it as part of{ ' ' }
								<a href={ EMERGENCY_MODE_URL } className={ linkClassName }>
									Emergency Mode for News
								</a>
								, a program supported by Press Forward’s local news infrastructure initiative.
							</p>

							<Section id="about-stories" title="For any story that unfolds live">
								<p>
									Rolling Coverage works for any story that changes by the minute: a storm, an election
									night, a council meeting, or a big game. Readers follow it on one page, and new entries
									appear as the newsroom publishes them.
								</p>
								<p>
									It started with emergencies. When a storm or a fire hits a community, people look for
									answers right away, and a newsroom that gives them clear, current information earns
									their trust. That moment is hardest for newsrooms that don’t run on a daily news
									cycle. Most publishing tools are built for finished articles, not for a team that
									drops its planned work to follow a story minute by minute.
								</p>
							</Section>

							<Section id="about-wordpress" title="Live coverage, built into WordPress">
								<p>
									Large national newsrooms build their own live coverage tools. Smaller newsrooms often
									choose between paid services that keep the coverage on another company’s platform,
									and manual workarounds that slow it down. Rolling Coverage keeps the coverage on the
									newsroom’s own site, in the WordPress dashboard the team already uses.
								</p>
								<p>
									Each entry works like a post. Editors can save drafts, schedule entries, pin the most
									important one, and use the same blocks as any other post. The feed uses the site’s own
									fonts, colors, and spacing.
								</p>
							</Section>

							<Section id="about-slack" title="Reporters post from Slack">
								<p>
									A reporter at the scene, on a phone with a weak signal, shouldn’t need the full
									WordPress dashboard. They post to a Slack channel the team already uses, and each
									message, with its photos, becomes an entry. A channel can publish entries straight
									away or send them to an editor as drafts.
								</p>
							</Section>

							<Section id="about-editors" title="Editors stay in control">
								<p>
									Editors fix typos and shorthand, pin the update every reader needs, and turn an entry
									that grows into its own story into a full post, while the feed keeps running. When the
									story is over, they end the coverage, and each page can show an ended notice.
								</p>
							</Section>

							<Section id="about-emergency-mode" title="Part of Emergency Mode for News">
								<p>
									Software alone doesn’t carry a newsroom through a crisis. A team also needs to know
									who turns live coverage on, how reporters take turns, and when to pull staff back for
									safety.
								</p>
								<p>
									<a href={ EMERGENCY_MODE_URL } className={ linkClassName }>
										Emergency Mode for News
									</a>{ ' ' }
									is built on three parts: people, plans, and publishing tools.
									It pairs newsroom training, playbooks, and peer support with two open-source tools:
									Rolling Coverage for live coverage, and{ ' ' }
									<a href="/docs/guides/lite-site/" className={ linkClassName }>
										Lite Site
									</a>{ ' ' }
									for a text-only copy of the site that loads on strained networks.
								</p>
							</Section>

							<div className="mt-14 flex flex-col gap-3 sm:flex-row md:mt-16">
								<a
									href="/docs/getting-started/"
									className={ `${ buttonBase } bg-fd-primary text-fd-primary-foreground hover:bg-fd-primary/85` }
								>
									Get started
								</a>
								<a
									href="/docs/"
									className={ `${ buttonBase } border border-fd-border text-fd-foreground hover:bg-fd-accent hover:text-fd-accent-foreground` }
								>
									Browse the docs
								</a>
							</div>
						</div>
					</article>
				</main>
				<SiteFooter />
			</HomeLayout>
		</RootProvider>
	);
}
