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

export { BADGE_CLASSES, badgeStatus };
