/**
 * WordPress dependencies
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { escapeHTML } from '@wordpress/escape-html';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { COVERAGE_ID_CONTEXT } from '../shared/entry-bindings';
import {
	SCORE_KEYS,
	SCORE_META_KEY,
	isScore,
	scorePart,
	type ScoreKey,
	type Score,
} from '../shared/score';
import { badgeClasses, badgeStatus } from '../shared/status-badges';
import { sampleScore } from './sample';
import { SCORE_BINDINGS_SOURCE, STATUS_BADGE_CONTEXT } from './template';

const COVERAGE_TAXONOMY = 'rolling_coverage';
// Edit context needs manage_categories, which Authors and Contributors lack.
const VIEW_CONTEXT = { context: 'view' };

interface BindingsArgs {
	bindings: Record< string, { args?: { key?: string } } >;
	context?: Record< string, unknown >;
	select: ( store: string ) => {
		getEntityRecord: (
			kind: string,
			name: string,
			id: number,
			query: Record< string, string >
		) => { meta?: Record< string, unknown > } | undefined;
	};
}

/**
 * The names the bindings panel lists the score's parts by.
 *
 * @return {Object} Labels by key.
 */
function fieldLabels(): Record< ScoreKey, string > {
	return {
		team1Name: __( 'First team', 'newspack-rolling-coverage' ),
		team1Score: __( 'First team score', 'newspack-rolling-coverage' ),
		team2Score: __( 'Second team score', 'newspack-rolling-coverage' ),
		team2Name: __( 'Second team', 'newspack-rolling-coverage' ),
		status: __( 'Status line', 'newspack-rolling-coverage' ),
	};
}

/**
 * One part of a score as HTML, escaped, mirroring Scoreboard_Block::get_value():
 * in a scoreboard that shows the status line as a badge, the status line is
 * the Coverage Status badge of the score's badge style.
 *
 * @param {Score}   score   The score.
 * @param {string}  key     The part.
 * @param {boolean} asBadge Whether the status line shows as a badge.
 * @return {string} The HTML.
 */
function partHTML( score: Score, key: ScoreKey, asBadge: boolean ): string {
	const text = escapeHTML( scorePart( score, key ) );

	if ( key !== 'status' || ! asBadge || text === '' ) {
		return text;
	}

	return `<span class="newspack-ui__badge ${ badgeClasses(
		badgeStatus( score.badge ),
		true
	) }">${ text }</span>`;
}

/**
 * Mirrors Scoreboard_Block::get_value() in the editor: the parts of the
 * score of the coverage in context, or of a sample score where there is no
 * coverage or it has no score yet, so the scoreboard can still be styled.
 * They are escaped, as on the server, since a paragraph reads its content
 * as HTML. The bindings panel offers the parts wherever a coverage is in
 * context, as inside a scoreboard or a feed.
 */
registerBlockBindingsSource( {
	name: SCORE_BINDINGS_SOURCE,
	usesContext: [ COVERAGE_ID_CONTEXT, STATUS_BADGE_CONTEXT ],
	getValues( { bindings, context, select }: BindingsArgs ) {
		const coverageId = Number( context?.[ COVERAGE_ID_CONTEXT ] ) || 0;
		const stored = coverageId
			? select( 'core' ).getEntityRecord(
					'taxonomy',
					COVERAGE_TAXONOMY,
					coverageId,
					VIEW_CONTEXT
				)?.meta?.[ SCORE_META_KEY ]
			: null;
		const score = isScore( stored ) ? stored : sampleScore();
		const asBadge = context?.[ STATUS_BADGE_CONTEXT ] === true;

		return Object.fromEntries(
			Object.entries( bindings ).map( ( [ attribute, binding ] ) => {
				const key = binding?.args?.key as ScoreKey;

				return [
					attribute,
					SCORE_KEYS.includes( key )
						? partHTML( score, key, asBadge )
						: '',
				];
			} )
		);
	},
	getFieldsList( { context }: { context?: Record< string, unknown > } ) {
		if ( context?.[ COVERAGE_ID_CONTEXT ] === undefined ) {
			return [];
		}

		return SCORE_KEYS.map( ( key ) => ( {
			label: fieldLabels()[ key ],
			type: 'string',
			args: { key },
		} ) );
	},
} );

export { SCORE_BINDINGS_SOURCE };
