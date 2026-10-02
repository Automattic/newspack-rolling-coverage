/**
 * A preset slug as core writes it in a custom property, mirroring
 * _wp_to_kebab_case(), e.g. "2XLarge" becomes "2-x-large".
 *
 * @param {string} slug Preset slug.
 * @return {string} The kebab-case slug.
 */
function kebabCase( slug: string ): string {
	return slug
		.replace( /([a-z])([A-Z0-9])/g, '$1-$2' )
		.replace( /([0-9])([a-zA-Z])/g, '$1-$2' )
		.replace( /([A-Z])([A-Z][a-z])/g, '$1-$2' )
		.replace( /[\s_]+/g, '-' )
		.toLowerCase();
}

/**
 * A Block spacing setting as CSS, mirroring
 * Rolling_Coverage_Block::spacing_css_value().
 *
 * @param {string|Object} blockGap The Block spacing setting.
 * @return {string|undefined} The space, or undefined when it's unset.
 */
export function blockGapCss(
	blockGap?: string | { top?: string }
): string | undefined {
	const gap = typeof blockGap === 'object' ? blockGap?.top : blockGap;

	if ( ! gap ) {
		return undefined;
	}

	const preset = gap.match( /^var:preset\|spacing\|(.+)$/ );

	return preset
		? `var(--wp--preset--spacing--${ kebabCase( preset[ 1 ] ) })`
		: gap;
}
