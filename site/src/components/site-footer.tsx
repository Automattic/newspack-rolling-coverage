import { LiveIcon } from './live-icon';

const linkClassName =
	'font-medium text-fd-foreground underline decoration-fd-border underline-offset-4 transition-colors hover:decoration-fd-foreground motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-fd-ring';

/**
 * The credit line shared by the homepage and the About page.
 */
export function SiteFooter() {
	return (
		<footer className="border-t border-fd-border">
			<div className="mx-auto w-full max-w-6xl px-4 py-10 text-sm text-pretty text-fd-muted-foreground md:px-6">
				<a
					href="/"
					className="inline-flex items-center gap-1.5 rounded-sm text-base font-semibold text-fd-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-fd-ring"
				>
					<LiveIcon className="-ms-1 size-7 text-newspack-600 dark:text-newspack-300" />
					Rolling Coverage
				</a>
				<p className="mt-4 max-w-[70ch]">
					Rolling Coverage is built by{ ' ' }
					<a href="https://newspack.com/" className={ linkClassName }>
						Newspack
					</a>{ ' ' }
					as part of{ ' ' }
					<a href="https://emergencymode.news/" className={ linkClassName }>
						Emergency Mode for News
					</a>
					, a program supported by{ ' ' }
					<a href="https://www.pressforward.news/" className={ linkClassName }>
						Press Forward
					</a>
					’s local news infrastructure initiative.
				</p>
			</div>
		</footer>
	);
}
