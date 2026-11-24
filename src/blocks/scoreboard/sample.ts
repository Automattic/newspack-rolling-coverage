/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Score } from '../shared/score';

/**
 * The score the editor shows where the block has no real one to show.
 *
 * @return {Score} The sample score.
 */
export function sampleScore(): Score {
	return {
		teams: [
			{
				name: __( 'Home team', 'newspack-rolling-coverage' ),
				score: 2,
			},
			{
				name: __( 'Away team', 'newspack-rolling-coverage' ),
				score: 1,
			},
		],
		status: __( 'Second half', 'newspack-rolling-coverage' ),
		badge: 'active',
	};
}
