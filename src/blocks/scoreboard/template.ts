/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { mutedTextColor } from '../shared/muted-color';
import type { ScoreKey } from '../shared/score';
import type { TemplateItem } from '../rolling-coverage/types';

const SCORE_BINDINGS_SOURCE = 'newspack-rolling-coverage/score';

// Scoreboard_Block::STATUS_BADGE_CONTEXT.
const STATUS_BADGE_CONTEXT = 'newspack-rolling-coverage/statusAsBadge';

// Scoreboard_Block::STATUS_BADGE_DOT_CONTEXT and STATUS_BADGE_COLORS_CONTEXT.
const STATUS_BADGE_DOT_CONTEXT = 'newspack-rolling-coverage/statusBadgeDot';
const STATUS_BADGE_COLORS_CONTEXT =
	'newspack-rolling-coverage/statusBadgeColors';

const DASH_CLASS = 'newspack-rolling-coverage-scoreboard__dash';

/**
 * The theme presets the template picks from.
 */
interface ThemeTokens {
	sizes: string[];
	colors: string[];
	headingFont?: string;
}

/**
 * The slug of the font family preset a global styles value names, as
 * `var(--wp--preset--font-family--inter)` or `var:preset|font-family|inter`.
 *
 * @param {unknown} value The fontFamily value.
 * @return {string|undefined} The slug, or undefined for anything else.
 */
function fontFamilySlug( value: unknown ): string | undefined {
	const match =
		typeof value === 'string'
			? /font-family(?:--|\|)([a-z0-9-]+)/.exec( value )
			: null;

	return match?.[ 1 ];
}

/**
 * The font family preset headings use: the first one the site's styles, then
 * the theme's, give headings, then body text, that the theme or site defines.
 *
 * @param {unknown[]} candidates fontFamily values, in order of precedence.
 * @param {string[]}  families   The font family presets' slugs.
 * @return {string|undefined} The slug, or undefined where none is a preset.
 */
function headingFontFamily(
	candidates: unknown[],
	families: string[]
): string | undefined {
	return candidates
		.map( fontFamilySlug )
		.find( ( slug ) => slug !== undefined && families.includes( slug ) );
}

/**
 * A paragraph bound to one part of the score.
 *
 * @param {string} key        The part.
 * @param {string} name       Its name in List View.
 * @param {Object} attributes More attributes for the paragraph.
 * @return {Array} The template item.
 */
function part(
	key: ScoreKey,
	name: string,
	attributes: Record< string, unknown >
): TemplateItem {
	return [
		'core/paragraph',
		{
			...attributes,
			metadata: {
				name,
				bindings: {
					content: {
						source: SCORE_BINDINGS_SOURCE,
						args: { key },
					},
				},
			},
		},
	];
}

/**
 * The block's inner blocks as inserted: the teams and their scores on one
 * row, a dash between the scores, the names filling the space either side so
 * the scores sit in the middle, and the status line under it.
 *
 * Every choice is a block setting from the theme's presets. Names and scores
 * take the headings' font family and are bold; a block theme scale (Large,
 * XX-Large) gives them its fluid sizes, the classic Newspack Theme's scale
 * Normal and Huge. The dash is a size down from the scores in the text color,
 * and the status line, which is small, takes the muted color the layouts'
 * dates use.
 * Long names wrap within their half of the row.
 *
 * @param {Object}   tokens             The theme's presets.
 * @param {string[]} tokens.sizes       Font size slugs.
 * @param {string[]} tokens.colors      Palette color slugs.
 * @param {string}   tokens.headingFont The headings' font family slug, if a preset.
 * @return {Array} The template.
 */
function scoreboardTemplate( {
	sizes,
	colors,
	headingFont,
}: ThemeTokens ): TemplateItem[] {
	const isBlockScale = sizes.includes( 'xx-large' );
	const font = {
		className: 'use-header-font',
		...( headingFont ? { fontFamily: headingFont } : {} ),
	};
	const name = ( textAlign: string ) => ( {
		...font,
		fontSize: isBlockScale ? 'large' : 'normal',
		style: {
			layout: { selfStretch: 'fill', flexSize: null },
			typography: { fontWeight: '700', lineHeight: '1.2', textAlign },
		},
	} );
	const score = {
		...font,
		fontSize: isBlockScale ? 'xx-large' : 'huge',
		style: { typography: { fontWeight: '700', lineHeight: '1' } },
	};
	const muted = mutedTextColor( colors );
	const mutedColor = muted ? { textColor: muted } : {};
	const dash: TemplateItem = [
		'core/paragraph',
		{
			...font,
			className: `${ font.className } ${ DASH_CLASS }`,
			content: '–',
			fontSize: isBlockScale ? 'x-large' : 'large',
			style: { typography: { lineHeight: '1' } },
			metadata: { name: __( 'Dash', 'newspack-rolling-coverage' ) },
		},
	];

	return [
		[
			'core/group',
			{
				metadata: { name: __( 'Score', 'newspack-rolling-coverage' ) },
				style: { spacing: { blockGap: 'var:preset|spacing|40' } },
				layout: {
					type: 'flex',
					flexWrap: 'nowrap',
					justifyContent: 'center',
					verticalAlignment: 'center',
				},
			},
			[
				part(
					'team1Name',
					__( 'First Team', 'newspack-rolling-coverage' ),
					name( 'right' )
				),
				part(
					'team1Score',
					__( 'First Team Score', 'newspack-rolling-coverage' ),
					score
				),
				dash,
				part(
					'team2Score',
					__( 'Second Team Score', 'newspack-rolling-coverage' ),
					score
				),
				part(
					'team2Name',
					__( 'Second Team', 'newspack-rolling-coverage' ),
					name( 'left' )
				),
			],
		],
		part( 'status', __( 'Status Line', 'newspack-rolling-coverage' ), {
			fontSize: isBlockScale ? 'x-small' : 'small',
			...mutedColor,
			style: { typography: { textAlign: 'center' } },
		} ),
	];
}

export {
	DASH_CLASS,
	SCORE_BINDINGS_SOURCE,
	STATUS_BADGE_COLORS_CONTEXT,
	STATUS_BADGE_CONTEXT,
	STATUS_BADGE_DOT_CONTEXT,
	headingFontFamily,
	scoreboardTemplate,
};
export type { ThemeTokens };
