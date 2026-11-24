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
import { Notice, PanelBody, ToggleControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	BadgeColorSettings,
	BadgeDotControl,
	useBadgePalette,
} from '../shared/badge-settings';
import CoverageChoice from '../shared/coverage-choice';
import { useBlockCoverage } from '../shared/block-coverage';
import { COVERAGE_ID_CONTEXT } from '../shared/entry-bindings';
import { isScore } from '../shared/score';
import {
	STATUS_BADGE_COLORS_CONTEXT,
	STATUS_BADGE_CONTEXT,
	STATUS_BADGE_DOT_CONTEXT,
	headingFontFamily,
	scoreboardTemplate,
} from './template';
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

type GlobalStyles = {
	styles?: {
		typography?: { fontFamily?: string };
		elements?: { heading?: { typography?: { fontFamily?: string } } };
	};
};

const config: ScoreboardConfig = window.newspackScoreboardBlock ?? {
	scoreMetaKey: 'rolling_coverage_score',
	sourceEntryField: 'rolling_coverage_source_entry',
	statusMetaKey: 'rolling_coverage_status',
	taxonomySlug: 'rolling_coverage',
};

/**
 * Editor for the Scoreboard block: the inner blocks showing the score of
 * the liveblog it would show on the site, or a sample, and the Liveblog
 * panel outside a Rolling Coverage block.
 *
 * @param {Object}   props               Block props.
 * @param {string}   props.clientId      Block client ID.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @param {Object}   props.context       Block context.
 */
export default function Edit( {
	clientId,
	attributes,
	setAttributes,
	context,
}: {
	clientId: string;
	attributes: ScoreboardAttributes;
	setAttributes: ( attrs: Partial< ScoreboardAttributes > ) => void;
	context?: Record< string, unknown >;
} ) {
	const { coverageId, showStatusAsBadge, showDot, backgroundColors } =
		attributes;
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

	const { hasScore, tokens } = useSelect(
		( select ) => {
			const core = select( coreStore ) as unknown as {
				getEntityRecord: (
					kind: string,
					name: string,
					id: number,
					query?: Record< string, string >
				) => { meta?: Record< string, unknown > } | undefined;
				getEditedEntityRecord: (
					kind: string,
					name: string,
					id: number | string
				) => GlobalStyles | undefined;
				__experimentalGetCurrentGlobalStylesId: () =>
					number | string | undefined;
				__experimentalGetCurrentThemeBaseGlobalStyles: () =>
					GlobalStyles | undefined;
			};
			const coverage = followed
				? core.getEntityRecord(
						'taxonomy',
						config.taxonomySlug,
						followed,
						VIEW_CONTEXT
					)
				: undefined;
			const settings = (
				select( blockEditorStore ) as unknown as {
					getSettings: () => {
						colors?: { slug: string }[];
						__experimentalFeatures?: {
							color?: {
								palette?: Record< string, { slug: string }[] >;
							};
							typography?: {
								fontSizes?: Record<
									string,
									{ slug: string }[]
								>;
								fontFamilies?: Record<
									string,
									{ slug: string }[]
								>;
							};
						};
					};
				}
			 ).getSettings();
			const features = settings.__experimentalFeatures;
			const slugs = ( origins?: Record< string, { slug: string }[] > ) =>
				Object.values( origins ?? {} )
					.flat()
					.map( ( preset ) => preset.slug );
			const stylesId = core.__experimentalGetCurrentGlobalStylesId();
			const site = stylesId
				? core.getEditedEntityRecord( 'root', 'globalStyles', stylesId )
				: undefined;
			const theme = core.__experimentalGetCurrentThemeBaseGlobalStyles();
			const headingFont = headingFontFamily(
				[
					site?.styles?.elements?.heading?.typography?.fontFamily,
					theme?.styles?.elements?.heading?.typography?.fontFamily,
					site?.styles?.typography?.fontFamily,
					theme?.styles?.typography?.fontFamily,
				],
				slugs( features?.typography?.fontFamilies )
			);

			return {
				hasScore:
					coverage === undefined ||
					isScore( coverage?.meta?.[ config.scoreMetaKey ] ),
				tokens: [
					slugs( features?.typography?.fontSizes ).join( ',' ),
					[
						...slugs( features?.color?.palette ),
						...( settings.colors ?? [] ).map(
							( color ) => color.slug
						),
					].join( ',' ),
					headingFont ?? '',
				].join( ';' ),
			};
		},
		[ followed ]
	);

	const template = useMemo( () => {
		const [ sizes, colors, headingFont ] = tokens.split( ';' );

		return scoreboardTemplate( {
			sizes: sizes.split( ',' ),
			colors: colors.split( ',' ),
			headingFont: headingFont || undefined,
		} );
	}, [ tokens ] );
	const blockProps = useBlockProps();
	const innerBlocksProps = useInnerBlocksProps( blockProps, { template } );
	const { accentSwatch, baseSwatch } = useBadgePalette();
	const blockContext = useMemo(
		() => ( {
			[ COVERAGE_ID_CONTEXT ]: followed,
			[ STATUS_BADGE_CONTEXT ]: showStatusAsBadge,
			[ STATUS_BADGE_DOT_CONTEXT ]: showDot !== false,
			[ STATUS_BADGE_COLORS_CONTEXT ]: backgroundColors ?? {},
		} ),
		[ followed, showStatusAsBadge, showDot, backgroundColors ]
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
				<PanelBody
					title={ __( 'Settings', 'newspack-rolling-coverage' ) }
				>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show status as a badge',
							'newspack-rolling-coverage'
						) }
						help={ __(
							'Pick its style in the Badge menu of the liveblog’s score row, above its entries.',
							'newspack-rolling-coverage'
						) }
						checked={ showStatusAsBadge }
						onChange={ ( value ) =>
							setAttributes( { showStatusAsBadge: value } )
						}
					/>
					{ showStatusAsBadge && (
						<BadgeDotControl
							showDot={ showDot }
							onChange={ ( value ) =>
								setAttributes( { showDot: value } )
							}
						/>
					) }
				</PanelBody>
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
			{ showStatusAsBadge && (
				<BadgeColorSettings
					clientId={ clientId }
					backgroundColors={ backgroundColors }
					onChange={ ( value ) =>
						setAttributes( { backgroundColors: value } )
					}
					accentSwatch={ accentSwatch }
					baseSwatch={ baseSwatch }
				/>
			) }
			<BlockContextProvider value={ blockContext }>
				<div { ...innerBlocksProps } />
			</BlockContextProvider>
		</>
	);
}
