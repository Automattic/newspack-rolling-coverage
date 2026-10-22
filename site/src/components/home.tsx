import type { ReactNode } from 'react';
import { HomeLayout } from 'fumadocs-ui/layouts/home';
import { RootProvider } from 'fumadocs-ui/provider/astro';
import type { AstroProviderProps } from 'fumadocs-core/framework/astro';
import { navigate } from 'astro:transitions/client';
import { GITHUB_URL, NAV_LINKS, NAV_TITLE, SITE_TITLE } from './docs';
import { Headline } from './headline';
import { MiniFeed } from './mini-feed';
import { PARTNERS } from './partner-logos';
import SearchDialog from './search';
import { SiteFooter } from './site-footer';
import { SiteContainer } from './site-container';
import { SiteHeader } from './site-header';

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

const focusRing =
	'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-fd-ring';

const linkClassName = `font-medium text-fd-primary underline decoration-fd-primary/40 underline-offset-4 transition-colors hover:decoration-fd-primary motion-reduce:transition-none ${ focusRing }`;

function Label( { children }: { children: ReactNode } ) {
	return (
		<span className="inline-flex items-center rounded-md border border-fd-border bg-fd-card px-2 py-0.5 text-xs font-medium whitespace-nowrap text-fd-card-foreground">
			{ children }
		</span>
	);
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
		body: 'From a full live blog to a strip under the header or a box in the sidebar. Each layout uses the site’s own fonts, colors, and spacing. One coverage can show in several places at once.',
		detail: (
			<p className="text-sm text-fd-muted-foreground">
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
		body: 'When the story is over, end the coverage. Its entries lock, feeds stop checking for updates, and each page can show an ended notice or hide the feed.',
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
		title: 'Create a coverage',
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
		description: 'Install the plugin and publish a first coverage.',
		href: '/docs/getting-started/',
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

const buttonBase = `inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-[color,background-color,scale] duration-[160ms] ease-out-strong active:scale-[0.97] motion-reduce:transition-none motion-reduce:active:scale-100 ${ focusRing }`;

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
				nav={ { title: NAV_TITLE, url: '/' } }
				githubUrl={ GITHUB_URL }
				links={ NAV_LINKS }
				slots={ { header: SiteHeader, container: SiteContainer } }
			>
				<main>
					<div className="mx-auto w-full max-w-6xl px-4 pt-14 pb-24 md:px-6 md:pt-24 md:pb-32">
						<section aria-labelledby="home-title">
							<p className="flex flex-wrap items-center gap-3 text-sm font-medium text-fd-muted-foreground">
								<span className="inline-flex items-center gap-2 rounded-full border border-fd-border bg-fd-card px-3 py-1 text-xs font-semibold tracking-wide text-fd-foreground uppercase">
									<span aria-hidden="true" className="relative flex size-2">
										<span className="absolute inline-flex size-full animate-ping rounded-full [animation-iteration-count:3] bg-fd-error opacity-75 motion-reduce:animate-none" />
										<span className="relative inline-flex size-2 rounded-full bg-fd-error" />
									</span>
									Beta
								</span>
								<span>{ SITE_TITLE } for WordPress</span>
							</p>
							<h1
								id="home-title"
								className="mt-4 max-w-[14ch] text-[2.75rem] leading-[1.05] font-semibold tracking-tight text-balance text-fd-foreground sm:text-6xl lg:text-7xl"
							>
								<Headline />
							</h1>
							<p className="mt-6 max-w-[58ch] text-lg text-pretty text-fd-muted-foreground md:text-xl md:leading-relaxed">
								{ HERO_TEXT }
							</p>
							<div className="mt-8 flex flex-col gap-3 sm:flex-row">
								<a
									href="/docs/getting-started/"
									className={ `${ buttonBase } group bg-fd-primary text-fd-primary-foreground hover:bg-fd-primary/85` }
								>
									Get started
									<Arrow className="transition-transform duration-200 ease-out-strong group-hover:translate-x-0.5 motion-reduce:transition-none" />
								</a>
								<a
									href="/docs/"
									className={ `${ buttonBase } border border-fd-border text-fd-foreground hover:bg-fd-accent hover:text-fd-accent-foreground` }
								>
									Browse the docs
								</a>
							</div>
						</section>

						<section
							aria-labelledby="home-partners"
							className="mt-14 flex flex-col gap-6 border-y border-fd-border py-7 md:mt-20 lg:flex-row lg:items-center lg:justify-between lg:gap-16"
						>
							<div className="max-w-[34ch]">
								<h2 id="home-partners" className="text-sm font-medium text-fd-foreground">
									Part of{ ' ' }
									<a
										href="https://emergencymode.news/"
										className={ `underline decoration-fd-border underline-offset-4 transition-colors hover:decoration-fd-foreground motion-reduce:transition-none ${ focusRing }` }
									>
										Emergency Mode for News
									</a>
								</h2>
								<p className="mt-1 text-sm text-pretty text-fd-muted-foreground">
									A collaboration between OpenNews, Newspack, and NC Local, supported by Press Forward.
								</p>
							</div>
							<ul className="flex flex-wrap items-center gap-x-10 gap-y-5 lg:gap-x-14">
								{ PARTNERS.map( ( partner ) => (
									<li key={ partner.url }>
										<a
											href={ partner.url }
											aria-label={ partner.name }
											className={ `flex rounded-sm text-fd-muted-foreground transition-colors hover:text-fd-foreground motion-reduce:transition-none ${ focusRing }` }
										>
											<svg
												aria-hidden="true"
												viewBox={ partner.viewBox }
												className={ `w-auto ${ partner.className }` }
											>
												<path
													fill="currentColor"
													fillRule={ partner.evenOdd ? 'evenodd' : undefined }
													d={ partner.path }
												/>
											</svg>
										</a>
									</li>
								) ) }
							</ul>
						</section>

						<figure className="mt-10 md:mt-12">
							<img
								src="/images/home/live-feed.webp"
								srcSet="/images/home/live-feed-1672.webp 1512w, /images/home/live-feed.webp 3024w"
								sizes="(min-width: 1152px) 1104px, calc(100vw - 2rem)"
								width={ 3024 }
								height={ 1964 }
								alt="A live coverage of Storm Ingrid on a news site: a pinned entry from the mayor at the top, then timestamped entries with the newest first, including a Full story card, each with its reporter and a Share link."
								loading="lazy"
								decoding="async"
								className="h-auto w-full rounded-xl border border-fd-border"
							/>
							<figcaption className="mt-3 max-w-[70ch] text-sm text-fd-muted-foreground">
								A live coverage on a demo site: the pinned entry first, then the feed in the Clock layout,
								with a full story card.
							</figcaption>
						</figure>

						<section
							aria-labelledby="home-audience"
							className="mt-24 grid gap-10 md:mt-36 lg:grid-cols-[minmax(0,1fr)_16rem] lg:items-center lg:gap-16"
						>
							<div>
								<h2
									id="home-audience"
									className="max-w-[24ch] text-3xl font-semibold tracking-tight text-balance text-fd-foreground md:text-4xl"
								>
									For any story that unfolds live
								</h2>
								<p className="mt-4 max-w-[60ch] text-pretty text-fd-muted-foreground md:text-lg md:leading-relaxed">
									A storm, an election night, a council meeting, or a big game. Rolling Coverage puts a live
									feed on the newsroom’s own WordPress site for any story that changes by the minute, with
									tools the team already knows.
								</p>
								<a href="/about/" className={ `mt-4 inline-flex min-h-6 items-center py-1 text-sm ${ linkClassName }` }>
									Why we built it
								</a>
							</div>
							<MiniFeed />
						</section>

						<section aria-labelledby="home-features" className="mt-24 md:mt-36">
							<h2
								id="home-features"
								className="text-3xl font-semibold tracking-tight text-balance text-fd-foreground md:text-4xl"
							>
								What the newsroom gets
							</h2>
							<p className="mt-4 max-w-[60ch] text-pretty text-fd-muted-foreground">
								Editors stay in control of the story: what goes out, what stays on top, and when it ends.
							</p>
							<ul className="mt-10 divide-y divide-fd-border border-y border-fd-border">
								{ features.map( ( feature ) => (
									<li
										key={ feature.title }
										className="grid gap-3 py-7 md:grid-cols-[minmax(0,16rem)_minmax(0,1fr)] md:gap-x-10 lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)_minmax(0,16rem)]"
									>
										<h3 className="text-lg leading-snug font-semibold text-balance text-fd-foreground">
											<a href={ feature.href } className={ `rounded-sm hover:text-fd-primary ${ focusRing }` }>
												{ feature.title }
											</a>
										</h3>
										<p className="max-w-[62ch] text-pretty text-fd-muted-foreground">{ feature.body }</p>
										<div className="flex flex-wrap content-start gap-2 md:col-start-2 lg:col-start-auto">
											{ feature.detail }
										</div>
									</li>
								) ) }
							</ul>
						</section>

						<section aria-labelledby="home-steps" className="mt-24 md:mt-36">
							<h2
								id="home-steps"
								className="text-3xl font-semibold tracking-tight text-fd-foreground md:text-4xl"
							>
								How it works
							</h2>
							<p className="mt-4 max-w-[60ch] text-pretty text-fd-muted-foreground">
								Three steps from a breaking story to a live feed. On a Newspack site, the support team installs
								the plugin for you.
							</p>
							<ol className="mt-10 grid gap-8 md:grid-cols-3 md:gap-6">
								{ steps.map( ( step, index ) => (
									<li key={ step.href } className="border-t-2 border-fd-foreground pt-5">
										<span className="text-sm font-medium text-fd-muted-foreground tabular-nums">
											<span className="sr-only">Step { index + 1 }, </span>
											{ step.time }
										</span>
										<h3 className="mt-2 text-xl font-semibold tracking-tight text-fd-foreground">
											{ step.title }
										</h3>
										<p className="mt-2 max-w-[38ch] text-pretty text-fd-muted-foreground">{ step.body }</p>
										<a
											href={ step.href }
											className={ `mt-3 inline-flex min-h-6 items-center py-1 text-sm ${ linkClassName }` }
										>
											Read the step<span className="sr-only">: { step.title }</span>
										</a>
									</li>
								) ) }
							</ol>
						</section>

						<section aria-labelledby="home-docs" className="mt-24 md:mt-36">
							<h2 id="home-docs" className="text-2xl font-semibold tracking-tight text-fd-foreground">
								Read the docs
							</h2>
							<ul className="mt-6 grid gap-3 sm:grid-cols-3">
								{ sections.map( ( section ) => (
									<li key={ section.href }>
										<a
											href={ section.href }
											className={ `group flex h-full flex-col gap-1 rounded-xl border border-fd-border p-4 transition-[background-color,scale] duration-[160ms] ease-out-strong hover:bg-fd-accent/40 active:scale-[0.985] motion-reduce:transition-none motion-reduce:active:scale-100 ${ focusRing }` }
										>
											<span
												className="flex items-center justify-between gap-2 font-medium text-fd-foreground"
											>
												{ section.title }
												<Arrow className="transition-transform duration-200 ease-out-strong group-hover:translate-x-0.5 motion-reduce:transition-none" />
											</span>
											<span className="text-sm text-fd-muted-foreground">{ section.description }</span>
										</a>
									</li>
								) ) }
							</ul>
						</section>
					</div>
				</main>
				<SiteFooter />
			</HomeLayout>
		</RootProvider>
	);
}
