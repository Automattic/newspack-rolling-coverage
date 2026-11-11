import { LiveIcon } from './live-icon';

/**
 * The credit line shared by the homepage and the About page.
 */
export function SiteFooter() {
	return (
		<footer className="pt-[calc(var(--section-space)/2)] pb-(--newspack-ui-spacer-8) type-small text-pretty text-muted-foreground">
			<div className="site-container">
				<a
					href="/"
					className="inline-flex items-center gap-(--newspack-ui-spacer-half) rounded-(--newspack-ui-radius-control) text-(length:--newspack-ui-font-size-body) font-(weight:--newspack-ui-font-weight-strong) text-foreground"
				>
					<LiveIcon className="-ms-1 size-7 text-brand" />
					Rolling Coverage
				</a>
				<p className="mt-(--newspack-ui-spacer-2) max-w-[70ch]">
					Rolling Coverage is built by{ ' ' }
					<a href="https://newspack.com/" className="text-link">
						Newspack
					</a>{ ' ' }
					as part of{ ' ' }
					<a href="https://emergencymode.news/" className="text-link">
						Emergency Mode for News
					</a>
					, a program supported by{ ' ' }
					<a href="https://www.pressforward.news/" className="text-link">
						Press Forward
					</a>
					’s local news infrastructure initiative.
				</p>
			</div>
		</footer>
	);
}
