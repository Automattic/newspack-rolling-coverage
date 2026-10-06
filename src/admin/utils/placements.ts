/**
 * Internal dependencies
 */
import type { Coverage } from '../types';

/**
 * What a coverage's View Page control does: nothing when no published place
 * shows the coverage, a link when one place with a page of its own does,
 * and a drawer listing every place otherwise.
 */
type PlacementsLink =
	{ kind: 'none' } | { kind: 'link'; url: string } | { kind: 'drawer' };

/**
 * Works out what a coverage's View Page control does.
 *
 * @param {Coverage | null} coverage Coverage, with its placements.
 *
 * @return {PlacementsLink} The control's behavior.
 */
function getPlacementsLink( coverage: Coverage | null ): PlacementsLink {
	const placements = coverage?.placements ?? [];

	if ( ! placements.length ) {
		return { kind: 'none' };
	}

	if ( placements.length === 1 && placements[ 0 ].viewUrl ) {
		return { kind: 'link', url: placements[ 0 ].viewUrl };
	}

	return { kind: 'drawer' };
}

export { getPlacementsLink };
export type { PlacementsLink };
