import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { SiteLayout, type SitePageProps } from './site-layout';

export const ABOUT_DESCRIPTION =
	'Why Newspack built Rolling Coverage, a free, open-source WordPress plugin for live coverage of a developing story.';

const EMERGENCY_MODE_URL = 'https://emergencymode.news/';

const linkClassName = 'text-link';

function Section( { id, title, children }: { id: string; title: string; children: ReactNode } ) {
	return (
		<section aria-labelledby={ id } className="mt-(--newspack-ui-spacer-9)">
			<h2 id={ id } className="type-section text-foreground">
				{ title }
			</h2>
			<div className="mt-(--newspack-ui-spacer-2) space-y-(--newspack-ui-spacer-2) type-prose text-muted-foreground">
				{ children }
			</div>
		</section>
	);
}

export function About( props: SitePageProps ) {
	return (
		<SiteLayout { ...props }>
			<article className="site-container pt-[calc(var(--section-space)/2)]">
				<div className="max-w-(--newspack-ui-width-content)">
					<p className="type-small font-(weight:--newspack-ui-font-weight-medium) text-muted-foreground">About</p>
					<h1 className="mt-(--newspack-ui-spacer-2) type-headline text-foreground">Why we built Rolling Coverage</h1>
					<p className="mt-(--newspack-ui-spacer-3) type-lead text-muted-foreground">
						Rolling Coverage is a free, open-source WordPress plugin for covering a developing story as it happens.
						Newspack builds it as part of{ ' ' }
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
							story is over, they end the liveblog, and each page can show an ended notice.
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

					<div className="mt-(--newspack-ui-spacer-9) flex flex-col gap-(--newspack-ui-spacer-1) sm:flex-row">
						<Button asChild variant="brand">
							<a href="/docs/getting-started/">Get started</a>
						</Button>
						<Button asChild variant="outline">
							<a href="/docs/">Browse the docs</a>
						</Button>
					</div>
				</div>
			</article>
		</SiteLayout>
	);
}
