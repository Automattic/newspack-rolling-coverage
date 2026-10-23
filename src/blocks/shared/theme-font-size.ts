/**
 * A font size preset the theme defines: the preferred slug where the theme
 * has it, else its fallback. The Newspack Theme names its sizes Normal and
 * Huge where block themes have Medium and X-Large.
 *
 * The server makes the same choice for its copies of the built-in layouts
 * (`Layout_Templates::token_value()`), so a change here needs the same change
 * there.
 *
 * @param {string[]} sizes     The theme's font size slugs.
 * @param {string}   preferred The preferred slug.
 * @param {string}   fallback  The slug to use where the theme lacks it.
 * @return {string} The slug.
 */
export function themeFontSize(
	sizes: string[],
	preferred: string,
	fallback: string
): string {
	return ! sizes.includes( preferred ) && sizes.includes( fallback )
		? fallback
		: preferred;
}
