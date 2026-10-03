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
 * @param {string} color Background color.
 * @return {CSSProperties|undefined} Style object, or undefined when unset.
 */
function badgeStyleObject( color?: string ): CSSProperties | undefined {
	const background = normalizeColor( color ?? '' );

	if ( ! background ) {
		return undefined;
	}

	const text = textColor( background );

	return {
		background,
		color: text,
		'--newspack-ui-badge-dot-color': `color-mix(in srgb, ${ text } 60%, ${ background })`,
	} as CSSProperties;
}

export { BADGE_CLASSES, badgeClasses, badgeStatus, badgeStyleObject };
