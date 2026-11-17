/**
 * WordPress dependencies
 */
import {
	BlockContextProvider,
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { Notice, PanelBody } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import CoverageChoice from '../shared/coverage-choice';
import { useBlockCoverage } from '../shared/block-coverage';
import { COVERAGE_ID_CONTEXT } from '../shared/entry-bindings';
import { isScore, type ScoreKey } from '../shared/score';
import { themeFontSize } from '../shared/theme-font-size';
import { SCORE_BINDINGS_SOURCE } from './bindings';
import type { TemplateItem } from '../rolling-coverage/types';
import type { ScoreboardAttributes } from './types';

interface ScoreboardConfig {
	scoreMetaKey: string;
	sourceEntryField: string;
	statusMetaKey: string;
	taxonomySlug: string;
}

declare global {
	interface Window {
		newspackScoreboardBlock?: ScoreboardConfig;
	}
}

const VIEW_CONTEXT = { context: 'view' };

const config: ScoreboardConfig = window.newspackScoreboardBlock ?? {
	scoreMetaKey: 'rolling_coverage_score',
	sourceEntryField: 'rolling_coverage_source_entry',
	statusMetaKey: 'rolling_coverage_status',
	taxonomySlug: 'rolling_coverage',
};

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
 * row, the names filling the space either side so the scores sit in the
 * middle, and the status line under it.
 *
 * @param {string[]} sizes The theme's font size slugs.
 * @return {Array} The template.
 */
function scoreboardTemplate( sizes: string[] ): TemplateItem[] {
	const name = {
		className: 'use-header-font',
		fontSize: themeFontSize( sizes, 'medium', 'normal' ),
		style: {
			layout: { selfStretch: 'fill', flexSize: null },
			typography: { fontWeight: '700' },
		},
	};
	const score = {
		className: 'use-header-font',
		fontSize: themeFontSize( sizes, 'xxx-large', 'huge' ),
		style: { typography: { fontWeight: '700', lineHeight: '1' } },
	};

	return [
		[
			'core/group',
			{
				metadata: { name: __( 'Score', 'newspack-rolling-coverage' ) },
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
					{
						...name,
						style: {
							...name.style,
							typography: {
								...name.style.typography,
								textAlign: 'right',
							},
						},
					}
				),
				part(
					'team1Score',
					__( 'First Team Score', 'newspack-rolling-coverage' ),
					score
				),
				part(
					'team2Score',
					__( 'Second Team Score', 'newspack-rolling-coverage' ),
					score
				),
				part(
					'team2Name',
					__( 'Second Team', 'newspack-rolling-coverage' ),
					name
				),
			],
		],
		part( 'status', __( 'Status Line', 'newspack-rolling-coverage' ), {
			fontSize: themeFontSize( sizes, 'x-small', 'small' ),
			style: { typography: { textAlign: 'center' } },
		} ),
	];
}

/**
 * Editor for the Scoreboard block: the inner blocks showing the score of
 * the liveblog it would show on the site, or a sample, and the Liveblog
 * panel outside a Rolling Coverage block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @param {Object}   props.context       Block context.
 */
export default function Edit( {
	attributes,
	setAttributes,
	context,
}: {
	attributes: ScoreboardAttributes;
	setAttributes: ( attrs: Partial< ScoreboardAttributes > ) => void;
	context?: Record< string, unknown >;
} ) {
	const { coverageId } = attributes;
	const [ customChosen, setCustomChosen ] = useState( false );

	const {
		coverageId: followed,
		isInFeed,
		isTemplate,
		isChosenGone,
	} = useBlockCoverage( {
		feedCoverageId: context?.[ COVERAGE_ID_CONTEXT ],
		postId: context?.postId,
		postType: context?.postType,
		chosenId: coverageId,
		taxonomySlug: config.taxonomySlug,
		statusMetaKey: config.statusMetaKey,
		sourceEntryField: config.sourceEntryField,
	} );

	const { hasScore, sizes } = useSelect(
		( select ) => {
			const coverage = followed
				? (
						select( coreStore ) as unknown as {
							getEntityRecord: (
								kind: string,
								name: string,
								id: number,
								query: Record< string, string >
							) =>
								| { meta?: Record< string, unknown > }
								| undefined;
						}
					 ).getEntityRecord(
						'taxonomy',
						config.taxonomySlug,
						followed,
						VIEW_CONTEXT
					)
				: undefined;
			const settings = (
				select( blockEditorStore ) as unknown as {
					getSettings: () => {
						__experimentalFeatures?: {
							typography?: {
								fontSizes?: { theme?: { slug: string }[] };
							};
						};
					};
				}
			 ).getSettings();

			return {
				hasScore:
					coverage === undefined ||
					isScore( coverage?.meta?.[ config.scoreMetaKey ] ),
				sizes: (
					settings.__experimentalFeatures?.typography?.fontSizes
						?.theme ?? []
				)
					.map( ( size ) => size.slug )
					.join( ',' ),
			};
		},
		[ followed ]
	);

	const template = useMemo(
		() => scoreboardTemplate( sizes.split( ',' ) ),
		[ sizes ]
	);
	const blockProps = useBlockProps();
	const innerBlocksProps = useInnerBlocksProps( blockProps, { template } );
	const blockContext = useMemo(
		() => ( { [ COVERAGE_ID_CONTEXT ]: followed } ),
		[ followed ]
	);

	let notice = '';

	if ( isChosenGone ) {
		notice =
			followed || isTemplate
				? __(
						'This liveblog no longer exists, so the page’s liveblog is used.',
						'newspack-rolling-coverage'
					)
				: __(
						'This liveblog no longer exists, so this scoreboard won’t appear on the site.',
						'newspack-rolling-coverage'
					);
	} else if ( followed && ! hasScore ) {
		notice = __(
			'This liveblog has no score, so the scoreboard won’t appear on the site. Add one at the top of the liveblog’s entries. Until then, a sample score shows here.',
			'newspack-rolling-coverage'
		);
	}

	return (
		<>
			<InspectorControls>
				{ ! isInFeed && (
					<PanelBody
						title={ __( 'Liveblog', 'newspack-rolling-coverage' ) }
					>
						<CoverageChoice
							value={ coverageId }
							onChange={ ( value ) =>
								setAttributes( { coverageId: value } )
							}
							customChosen={ customChosen }
							onCustomChosenChange={ setCustomChosen }
							automaticHelp={ __(
								'Shows the liveblog on this page, or a breakout post’s liveblog.',
								'newspack-rolling-coverage'
							) }
							customHelp={ __(
								'Always shows this liveblog.',
								'newspack-rolling-coverage'
							) }
							taxonomySlug={ config.taxonomySlug }
							statusMetaKey={ config.statusMetaKey }
						/>
					</PanelBody>
				) }
				{ notice && (
					<PanelBody>
						<Notice
							status="warning"
							isDismissible={ false }
							spokenMessage={ notice }
						>
							{ notice }
						</Notice>
					</PanelBody>
				) }
			</InspectorControls>
			<BlockContextProvider value={ blockContext }>
				<div { ...innerBlocksProps } />
			</BlockContextProvider>
		</>
	);
}
