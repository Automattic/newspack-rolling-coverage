import type { ComponentProps } from 'react';
import { Icon, check, copy, link } from '@wordpress/icons';
import { CodeBlock, Pre } from 'fumadocs-ui/components/codeblock';
import { buttonVariants } from 'fumadocs-ui/components/ui/button';
import { cn } from '@/lib/utils';

/*
 * The page body is static HTML inside the Docs island, so these buttons are wired by
 * `copy-buttons.ts`. It sets `data-checked` for 1.5s after a copy, which swaps the two icons.
 */

function iconButton( variant?: 'ghost' ) {
	return cn( buttonVariants( { variant, size: 'icon-xs' } ), 'group p-0.5 [&_svg]:size-5 [&_svg]:fill-current' );
}

function CopyIcons( { idle }: { idle: typeof copy } ) {
	return (
		<>
			<Icon icon={ idle } size={ 20 } className="group-data-checked:hidden" />
			<Icon icon={ check } size={ 20 } className="hidden group-data-checked:block" />
		</>
	);
}

type HeadingTag = 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6';

function Heading( { as: As, ...props }: ComponentProps< 'h2' > & { as: HeadingTag } ) {
	if ( ! props.id ) {
		return <As { ...props } />;
	}
	return (
		<As { ...props } className={ cn( 'group/heading flex scroll-m-28 flex-row items-center gap-1', props.className ) }>
			<a data-card="" href={ `#${ props.id }` }>
				{ props.children }
			</a>
			<button
				type="button"
				aria-live="polite"
				data-copy="anchor"
				data-copied-label="Copied Anchor Link"
				className={ cn(
					iconButton( 'ghost' ),
					'not-prose shrink-0 text-fd-muted-foreground opacity-0 transition-opacity group-hover/heading:opacity-100 focus-visible:opacity-100'
				) }
			>
				<CopyIcons idle={ link } />
				<span className="sr-only">Copy Anchor Link</span>
			</button>
		</As>
	);
}

function CodeCopyButton( { className }: { className?: string } ) {
	return (
		<div className={ className }>
			<button
				type="button"
				aria-live="polite"
				data-copy="code"
				data-copied-label="Copied Text"
				className={ cn( iconButton(), 'hover:text-fd-accent-foreground data-checked:text-fd-accent-foreground' ) }
			>
				<CopyIcons idle={ copy } />
				<span className="sr-only">Copy Text</span>
			</button>
		</div>
	);
}

const headings = Object.fromEntries(
	( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ] as const ).map( ( tag ) => [ tag, ( props: ComponentProps< 'h2' > ) => <Heading as={ tag } { ...props } /> ] )
);

/** Overrides for `fumadocs-ui/mdx` that use @wordpress/icons in place of lucide. */
export const docsMdxComponents = {
	...headings,
	pre: ( props: ComponentProps< 'pre' > ) => (
		<CodeBlock { ...props } allowCopy={ false } Actions={ ( { className } ) => <CodeCopyButton className={ className } /> }>
			<Pre>{ props.children }</Pre>
		</CodeBlock>
	),
};
