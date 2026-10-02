export const MUTED_TEXT_COLORS = [ 'contrast-3', 'medium-gray' ];

/**
 * The muted text color the theme defines, matching the muted color of the
 * Rolling Coverage dates: the block theme's Contrast 3, else the classic
 * theme's Medium Gray.
 *
 * @param {string[]} slugs The palette's color slugs.
 * @return {string|undefined} The color slug, or undefined without a match.
 */
export function mutedTextColor( slugs: string[] ): string | undefined {
	return MUTED_TEXT_COLORS.find( ( candidate ) =>
		slugs.includes( candidate )
	);
}
