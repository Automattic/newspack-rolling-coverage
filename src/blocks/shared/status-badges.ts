import type { CSSProperties } from 'react';

/**
 * Internal dependencies
 */
import { normalizeColor, textColor } from './apca';

/**
 * The newspack-ui badge modifiers for each coverage status, matching
 * Coverage_Status_Block::BADGE_CLASSES.
 */
const BADGE_CLASSES: Record< string, string > = {
	active: 'newspack-ui__badge--success newspack-ui__badge--dot newspack-ui__badge--pulse',
	paused: 'newspack-ui__badge--secondary',
	archived: 'newspack-ui__badge--error',
};

/**
 * Theme colors a badge background can name instead of a hex color, keyed by
 * the block theme's palette slug, each with the theme's text color and the
 * palette slug of that pair, matching Coverage_Status_Block::THEME_COLORS.
 */
const THEME_COLORS: Record<
	string,
	{ background: string; pair: string; text: string }
> = {
	accent: {
		background:
			'var(--wp--preset--color--accent, var(--newspack-theme-color-primary, #003da5))',
		pair: 'accent-contrast',
		text: 'var(--wp--preset--color--accent-contrast, var(--wp--preset--color--base, var(--newspack-theme-color-against-primary, #fff)))',
	},
	base: {
		background:
			'var(--wp--preset--color--base, var(--newspack-theme-color-bg-body, #fff))',
		pair: 'contrast',
		text: 'var(--wp--preset--color--contrast, var(--newspack-theme-color-text-main, #111))',
	},
};

/**
 * The status a badge shows: a status it doesn't know reads as live.
 *
 * @param {string} status Coverage status.
 * @return {string} 'active', 'paused' or 'archived'.
 */
function badgeStatus( status?: string ): string {
	return status && BADGE_CLASSES[ status ] ? status : 'active';
}

/**
 * The badge classes for a status: the live badge drops its dot and pulse when
 * the dot is hidden.
 *
 * @param {string}  status  Coverage status.
 * @param {boolean} showDot Whether the live badge shows its dot.
 * @return {string} Space-separated modifier classes.
 */
function badgeClasses( status: string, showDot: boolean ): string {
	if ( status === 'active' && ! showDot ) {
		return 'newspack-ui__badge--success';
	}

	return BADGE_CLASSES[ status ];
}

/**
 * The inline badge style for a custom background, matching
 * Coverage_Status_Block::badge_style().
 *
 * @param {string} color   Background color: a THEME_COLORS name or a hex color.
 * @param {Object} presets The palette's current colors by slug: the THEME_COLORS names and their pairs.
 * @return {CSSProperties|undefined} Style object, or undefined when unset.
 */
function badgeStyleObject(
	color?: string,
	presets: Record< string, string > = {}
): CSSProperties | undefined {
	const theme = color ? THEME_COLORS[ color ] : undefined;
	const background = theme?.background ?? normalizeColor( color ?? '' );

	if ( ! background ) {
		return undefined;
	}

	let text = theme?.text;

	if ( theme ) {
		const preset = normalizeColor( presets[ color ?? '' ] ?? '' );

		if ( preset && ! presets[ theme.pair ] ) {
			text = textColor( preset );
		}
	}

	text ??= textColor( background );

	return {
		background,
		color: text,
		'--newspack-ui-badge-dot-color': `color-mix(in srgb, ${ text } 60%, ${ background })`,
	} as CSSProperties;
}

export { BADGE_CLASSES, badgeClasses, badgeStatus, badgeStyleObject };
