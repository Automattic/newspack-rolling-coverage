import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Headline } from './headline';
import { MiniFeed } from './mini-feed';
import { PARTNERS } from './partner-logos';
import { SITE_NAME } from '@/lib/site';
import { SiteLayout, type SitePageProps } from './site-layout';

const HERO_TEXT =
	'Post short, timestamped entries from WordPress or Slack. Readers see each one appear on the page as it is published, without reloading.';

export const SITE_DESCRIPTION = `Live updates for developing stories. ${ HERO_TEXT }`;

const LAYOUTS = [
	'Bulletin',
	'Stream',
	'Rail',
	'Clock',
	'Margin',
	'Minute',
	'Byline',
	'Ticker',
	'Split',
	'Wire',
	'Digest',
	'Flash',
	'Alert',
];

function Label( { children }: { children: ReactNode } ) {
	return <Badge variant="secondary">{ children }</Badge>;
}

function Arrow( { className = '' }: { className?: string } ) {
	return (
		<svg
			aria-hidden="true"
			viewBox="0 0 16 16"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
			className={ `size-4 shrink-0 ${ className }` }
		>
			<path d="M3 8h10M9 4l4 4-4 4" />
		</svg>
	);
}

const features: { title: string; href: string; body: string; detail: ReactNode }[] = [
	{
		title: 'Updates without reloading',
		href: '/docs/getting-started/put-the-feed-on-a-page/',
		body: 'New entries appear at the top of the feed while readers are on the page. Someone who has scrolled down gets a button instead, so the page doesn’t jump while they read.',
		detail: <Label>3 new entries</Label>,
	},
	{
		title: 'Post from WordPress or Slack',
		href: '/docs/guides/slack/',
		body: 'Write entries in the dashboard with the same blocks as a post: text, images, video, embeds. Or connect a Slack channel, and messages posted there become entries.',
		detail: (
			<>
				<Label>Add Entry</Label>
				<Label>Slack Connection</Label>
			</>
		),
	},
	{
		title: '13 layouts, in the site’s own theme',
		href: '/docs/guides/layouts/',
		body: 'From a full liveblog to a strip under the header or a box in the sidebar. Each layout uses the site’s own fonts, colors, and spacing. One liveblog can show in several places at once.',
		detail: (
			<p className="type-small text-muted-foreground">
				<span className="sr-only">The layouts: </span>
				{ LAYOUTS.join( ', ' ) }
			</p>
		),
	},
	{
		title: 'Pinned entries and full stories',
		href: '/docs/guides/pinned-entries/',
		body: 'Pin the update every reader needs, such as an evacuation order, above newer ones. When an entry grows into a story of its own, turn it into a post, and the feed links readers to it.',
		detail: (
			<>
				<Label>Pin</Label>
				<Label>Create Breakout Post</Label>
			</>
		),
	},
	{
		title: 'A clear end',
		href: '/docs/guides/end-a-coverage/',
		body: 'When the story is over, end the liveblog. Its entries lock, feeds stop checking for updates, and each page can show an ended notice or hide the feed.',
		detail: (
			<>
				<Label>Live</Label>
				<Label>Paused</Label>
				<Label>Ended</Label>
			</>
		),
	},
	{
		title: 'Key takeaways and ads, if you want them',
		href: '/docs/guides/ai-key-takeaways/',
		body: 'With the WordPress AI plugin, generate a short summary of the latest entries for an editor to check and use. With Newspack Ads, show an ad every few entries, or keep a sensitive story ad-free.',
		detail: (
			<>
				<Label>Generate Key Takeaways</Label>
				<Label>Ads interval</Label>
			</>
		),
	},
];

const steps = [
	{
		time: '9:02 am',
		title: 'Create a liveblog',
		href: '/docs/getting-started/create-your-first-coverage/',
		body: 'Give the story a name, such as “Storm Ingrid: live updates”. Leave its status on Live.',
	},
	{
		time: '9:04 am',
		title: 'Add entries',
		href: '/docs/getting-started/add-entries/',
		body: 'Post short updates as the story moves, from the dashboard or a connected Slack channel.',
	},
	{
		time: '9:05 am',
		title: 'Put the feed on a page',
		href: '/docs/getting-started/put-the-feed-on-a-page/',
		body: 'Add the Rolling Coverage block, pick a layout, and publish. Readers see new entries as they arrive.',
	},
];

const sections = [
	{
		title: 'Getting started',
		description: 'Install the plugin and publish a first liveblog.',
		href: '/docs/getting-started/',
	},
	{
		title: 'Guides',
		description: 'Layouts, Slack, pinned entries, and ending a liveblog.',
		href: '/docs/guides/',
	},
	{
		title: 'Developers',
		description: 'Hooks and the REST API.',
		href: '/docs/developers/',
	},
];

export function Home( props: SitePageProps ) {
	return (
		<SiteLayout { ...props }>
			<section aria-labelledby="home-title" className="site-container pt-[calc(var(--section-space)/2)]">
				<p className="flex flex-wrap items-center gap-(--newspack-ui-spacer-1) type-small font-(weight:--newspack-ui-font-weight-medium) text-muted-foreground">
					<Badge variant="outline" className="gap-(--newspack-ui-spacer-half) uppercase">
						<span aria-hidden="true" className="relative flex size-1.5">
							<span className="absolute inline-flex size-full animate-ping rounded-full bg-(--newspack-ui-color-error) opacity-75 motion-reduce:animate-none" />
							<span className="relative inline-flex size-1.5 rounded-full bg-(--newspack-ui-color-error)" />
						</span>
						Beta
					</Badge>
					<span>{ SITE_NAME } for WordPress</span>
				</p>
				<h1 id="home-title" className="mt-(--newspack-ui-spacer-3) max-w-[9.25em] type-display text-foreground">
					<Headline />
				</h1>
				<p className="mt-(--newspack-ui-spacer-3) max-w-[58ch] type-lead text-muted-foreground">{ HERO_TEXT }</p>
				<div className="mt-(--newspack-ui-spacer-5) flex flex-col gap-(--newspack-ui-spacer-1) sm:flex-row">
					<Button asChild variant="brand" className="group">
						<a href="/docs/getting-started/">
							Get started
							<Arrow className="transition-transform duration-200 ease-out-strong group-hover:translate-x-0.5 motion-reduce:transition-none" />
						</a>
					</Button>
					<Button asChild variant="outline">
						<a href="/docs/">Browse the docs</a>
					</Button>
				</div>
			</section>

			<section aria-labelledby="home-partners" className="site-container mt-(--newspack-ui-spacer-9)">
				<div className="flex flex-col gap-(--newspack-ui-spacer-5) border-y border-(--newspack-ui-color-border) py-(--newspack-ui-spacer-5) lg:flex-row lg:items-center lg:justify-between lg:gap-(--newspack-ui-spacer-11)">
					<div className="max-w-[34ch]">
						<h2 id="home-partners" className="type-small font-(weight:--newspack-ui-font-weight-medium) text-foreground">
							Part of{ ' ' }
							<a href="https://emergencymode.news/" className="text-link">
								Emergency Mode for News
							</a>
						</h2>
						<p className="mt-(--newspack-ui-spacer-half) type-small text-pretty text-muted-foreground">
							A collaboration between OpenNews, Newspack, and NC Local, supported by Press Forward.
						</p>
					</div>
					<ul className="flex flex-wrap items-center gap-x-(--newspack-ui-spacer-8) gap-y-(--newspack-ui-spacer-4) lg:gap-x-(--newspack-ui-spacer-11)">
						{ PARTNERS.map( ( partner ) => (
							<li key={ partner.url }>
								<a
									href={ partner.url }
									aria-label={ partner.name }
									className="flex text-muted-foreground transition-colors duration-(--newspack-ui-duration-color) hover:text-foreground"
								>
									<svg aria-hidden="true" viewBox={ partner.viewBox } className={ `w-auto ${ partner.className }` }>
										<path fill="currentColor" fillRule={ partner.evenOdd ? 'evenodd' : undefined } d={ partner.path } />
									</svg>
								</a>
							</li>
						) ) }
					</ul>
				</div>
			</section>

			<figure className="site-container mt-(--newspack-ui-spacer-8)">
				<img
					src="/images/home/live-feed.webp"
					srcSet="/images/home/live-feed-1672.webp 1512w, /images/home/live-feed.webp 3024w"
					sizes="(min-width: 1328px) 1264px, (min-width: 782px) calc(100vw - 4rem), calc(100vw - 2rem)"
					width={ 3024 }
					height={ 1964 }
					alt="A liveblog of Storm Ingrid on a news site: a pinned entry from the mayor at the top, then timestamped entries with the newest first, including a Full story card, each with its reporter and a Share link."
					loading="lazy"
					decoding="async"
					className="h-auto w-full rounded-(--newspack-ui-radius-surface) border border-border"
				/>
				<figcaption className="mt-(--newspack-ui-spacer-2) max-w-[70ch] type-small text-muted-foreground">
					A liveblog on a demo site: the pinned entry first, then the feed in the Clock layout, with a full story card.
				</figcaption>
			</figure>

			<section
				aria-labelledby="home-audience"
				className="site-container section-space mt-[calc(var(--section-space)/2)] grid gap-(--newspack-ui-spacer-8) lg:grid-cols-[minmax(0,1fr)_16rem] lg:items-center lg:gap-(--newspack-ui-spacer-11)"
			>
				<div>
					<h2 id="home-audience" className="max-w-[15.85em] type-section text-foreground">
						For any story that unfolds live
					</h2>
					<p className="mt-(--newspack-ui-spacer-2) max-w-[60ch] type-lead text-muted-foreground">
						A storm, an election night, a council meeting, or a big game. Rolling Coverage puts a live feed on the
						newsroom’s own WordPress site for any story that changes by the minute, with tools the team already knows.
					</p>
					<a href="/about/" className="mt-(--newspack-ui-spacer-2) inline-flex min-h-6 items-center py-1 type-small text-link">
						Why we built it
					</a>
				</div>
				<MiniFeed />
			</section>

			<section aria-labelledby="home-features" className="site-container section-space">
				<h2 id="home-features" className="type-section text-foreground">
					What the newsroom gets
				</h2>
				<p className="mt-(--newspack-ui-spacer-2) max-w-[60ch] type-lead text-muted-foreground">
					Editors stay in control of the story: what goes out, what stays on top, and when it ends.
				</p>
				<ul className="mt-(--newspack-ui-spacer-6) divide-y divide-border">
					{ features.map( ( feature ) => (
						<li
							key={ feature.title }
							className="grid gap-(--newspack-ui-spacer-2) py-(--newspack-ui-spacer-5) first:pt-0 last:pb-0 md:grid-cols-[minmax(0,16rem)_minmax(0,1fr)] md:gap-x-(--newspack-ui-spacer-8) lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)_minmax(0,16rem)]"
						>
							<h3 className="type-title text-foreground">
								<a href={ feature.href } className="rounded-(--newspack-ui-border-radius-xs) underline-offset-3 hover:underline">
									{ feature.title }
								</a>
							</h3>
							<p className="max-w-[62ch] text-pretty text-muted-foreground">{ feature.body }</p>
							<div className="flex flex-wrap content-start gap-(--newspack-ui-spacer-1) md:col-start-2 lg:col-start-auto">
								{ feature.detail }
							</div>
						</li>
					) ) }
				</ul>
			</section>

			<section aria-labelledby="home-steps" className="site-container section-space">
				<h2 id="home-steps" className="type-section text-foreground">
					How it works
				</h2>
				<p className="mt-(--newspack-ui-spacer-2) max-w-[60ch] type-lead text-muted-foreground">
					Three steps from a breaking story to a live feed. On a Newspack site, the support team installs the plugin for you.
				</p>
				<ol className="mt-(--newspack-ui-spacer-6) grid gap-(--newspack-ui-spacer-6) md:grid-cols-3 md:gap-(--newspack-ui-spacer-5)">
					{ steps.map( ( step, index ) => (
						<li key={ step.href } className="border-t-2 border-foreground pt-(--newspack-ui-spacer-3)">
							<span className="type-small font-(weight:--newspack-ui-font-weight-medium) text-muted-foreground tabular-nums">
								<span className="sr-only">Step { index + 1 }, </span>
								{ step.time }
							</span>
							<h3 className="mt-(--newspack-ui-spacer-1) type-title text-foreground">{ step.title }</h3>
							<p className="mt-(--newspack-ui-spacer-1) max-w-[38ch] text-pretty text-muted-foreground">{ step.body }</p>
							<a href={ step.href } className="mt-(--newspack-ui-spacer-2) inline-flex min-h-6 items-center py-1 type-small text-link">
								Read the step<span className="sr-only">: { step.title }</span>
							</a>
						</li>
					) ) }
				</ol>
			</section>

			<section aria-labelledby="home-docs" className="site-container section-space">
				<h2 id="home-docs" className="type-section text-foreground">
					Read the docs
				</h2>
				<ul className="mt-(--newspack-ui-spacer-6) grid gap-(--newspack-ui-spacer-2) sm:grid-cols-3">
					{ sections.map( ( section ) => (
						<li key={ section.href }>
							<a href={ section.href } className="group block h-full rounded-(--newspack-ui-radius-surface)">
								<Card className="h-full transition-colors duration-(--newspack-ui-duration-color) group-hover:border-(color:--newspack-ui-color-border-hover)">
									<CardHeader>
										<CardTitle className="flex items-center justify-between gap-(--newspack-ui-spacer-1)">
											{ section.title }
											<Arrow className="transition-transform duration-200 ease-out-strong group-hover:translate-x-0.5 motion-reduce:transition-none" />
										</CardTitle>
										<CardDescription>{ section.description }</CardDescription>
									</CardHeader>
								</Card>
							</a>
						</li>
					) ) }
				</ul>
			</section>
		</SiteLayout>
	);
}
