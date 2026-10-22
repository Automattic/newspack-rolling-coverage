import type { ComponentProps, ElementType } from 'react';
import { buttonVariants } from 'fumadocs-ui/components/ui/button';

export function Heading( { as, ...props }: ComponentProps< 'h1' > & { as: ElementType } ) {
	const As = as;

	if ( ! props.id ) {
		return <As { ...props } />;
	}

	return (
		<As
			{ ...props }
			className={ `group/heading flex scroll-m-28 flex-row items-center gap-1${ props.className ? ` ${ props.className }` : '' }` }
		>
			<a data-card="" href={ `#${ props.id }` }>
				{ props.children }
			</a>
			<a
				href={ `#${ props.id }` }
				aria-label="Link to this section"
				className={ `${ buttonVariants( { variant: 'ghost', size: 'icon-xs' } ) } not-prose shrink-0 text-fd-muted-foreground opacity-0 transition-opacity focus-visible:opacity-100 group-hover/heading:opacity-100` }
			>
				<svg
					xmlns="http://www.w3.org/2000/svg"
					width="24"
					height="24"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					strokeWidth="2"
					strokeLinecap="round"
					strokeLinejoin="round"
					aria-hidden="true"
				>
					<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
					<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
				</svg>
			</a>
		</As>
	);
}

export const headingComponents = Object.fromEntries(
	[ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ].map( ( tag ) => [
		tag,
		( props: ComponentProps< 'h1' > ) => <Heading as={ tag } { ...props } />,
	] ),
);
