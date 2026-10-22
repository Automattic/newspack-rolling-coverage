import type { ComponentProps } from 'react';

/**
 * Replaces the home layout's container, which renders a <main> around the header and footer.
 */
export function SiteContainer( { className, ...props }: ComponentProps< 'div' > ) {
	return (
		<div
			id="nd-home-layout"
			{ ...props }
			className={ `flex flex-1 flex-col [--fd-layout-width:1400px]${ className ? ` ${ className }` : '' }` }
		/>
	);
}
