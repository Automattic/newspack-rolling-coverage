/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	useBlockProps,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalColorGradientSettingsDropdown as ColorGradientSettingsDropdown,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalUseMultipleOriginColorsAndGradients as useMultipleOriginColorsAndGradients,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	Notice,
	PanelBody,
	TextControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { humanTimeDiff } from '@wordpress/date';
import { __, _x, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import CoverageChoice from '../shared/coverage-choice';
import { mutedTextColor } from '../shared/muted-color';
import { useBlockCoverage } from '../shared/block-coverage';
import {
	badgeClasses,
	badgeStatus,
	badgeStyleObject,
} from '../shared/status-badges';
import type { CoverageStatusAttributes } from './types';

interface CoverageStatusConfig {
	sourceEntryField: string;
	statusLabels: Record< string, string >;
	statusMetaKey: string;
	taxonomySlug: string;
}

declare global {
	interface Window {
		newspackCoverageStatusBlock?: CoverageStatusConfig;
	}
}

const COVERAGE_ID_CONTEXT = 'newspack-rolling-coverage/coverageId';
const VIEW_CONTEXT = { context: 'view' };
const SAMPLE_AGE_MS = 2 * 60 * 1000;
const INSERT_SOURCES = [ undefined, 'inserter_menu', 'quick_inserter' ];

/**
 * The color the picker shows for a badge background: a theme color's palette
 * swatch, or the stored color itself.
 *
 * @param {string} color    Stored background: 'accent', 'base' or a hex color.
 * @param {string} swatches The accent and base swatches, comma-separated.
 * @return {string|undefined} The color to show.
 */
function themeSwatch( color: string | undefined, swatches: string ) {
	const [ accent, base ] = swatches.split( ',' );
	const swatch = { accent, base }[ color ?? '' ];

	return swatch === undefined ? color : swatch || undefined;
}

const config: CoverageStatusConfig = window.newspackCoverageStatusBlock ?? {
	sourceEntryField: 'rolling_coverage_source_entry',
	statusLabels: {
		active: __( 'Live', 'newspack-rolling-coverage' ),
		paused: __( 'Paused', 'newspack-rolling-coverage' ),
		archived: __( 'Ended', 'newspack-rolling-coverage' ),
	},
	statusMetaKey: 'rolling_coverage_status',
	taxonomySlug: 'rolling_coverage',
};

const BACKGROUND_FIELDS: Record< string, string > = {
	active: __( 'Live background', 'newspack-rolling-coverage' ),
	paused: __( 'Paused background', 'newspack-rolling-coverage' ),
	archived: __( 'Ended background', 'newspack-rolling-coverage' ),
};

const LABEL_FIELDS: Record< string, string > = {
	active: __( 'Live label', 'newspack-rolling-coverage' ),
	paused: __( 'Paused label', 'newspack-rolling-coverage' ),
	archived: __( 'Ended label', 'newspack-rolling-coverage' ),
};

/**
 * Editor for the Coverage Status block: the badge of the coverage it shows,
 * decided as on the site, or a sample where that isn't known.
 *
 * @param {Object}          props                            Block props.
 * @param {string}          props.clientId                   Block client ID.
 * @param {Object}          props.attributes                 Block attributes.
 * @param {Function}        props.setAttributes              Attribute setter.
 * @param {Object}          props.context                    Block context.
 * @param {string|string[]} props.__unstableLayoutClassNames Layout classes for the
 *                                                           wrapper, so the editor
 *                                                           shows the gap the site
 *                                                           renders. This block has
 *                                                           no inner blocks to carry
 *                                                           them.
 */
export default function Edit( {
	clientId,
	attributes,
	setAttributes,
	context,
	__unstableLayoutClassNames: layoutClassNames,
}: {
	clientId: string;
	attributes: CoverageStatusAttributes;
	setAttributes: ( attrs: Partial< CoverageStatusAttributes > ) => void;
	context?: Record< string, unknown >;
	__unstableLayoutClassNames?: string | string[];
} ) {
	const {
		coverageId,
		showLastUpdated,
		hideWhenEnded,
		showDot,
		labels,
		backgroundColors,
		textColor,
		style,
	} = attributes;

	const hasCustomLabels = Object.keys( LABEL_FIELDS ).some(
		( key ) =>
			typeof labels?.[ key ] === 'string' && !! labels[ key ]?.trim()
	);
	const [ customChosen, setCustomChosen ] = useState( false );
	const isCustom = hasCustomLabels || customChosen;
	const [ customCoverageChosen, setCustomCoverageChosen ] = useState( false );

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

	const { status, newest } = useSelect(
		( select ) => {
			const core = select( coreStore ) as unknown as {
				getEntityRecord: (
					kind: string,
					name: string,
					id: number,
					query: Record< string, string >
				) => unknown;
			};
			const coverage = followed
				? ( core.getEntityRecord(
						'taxonomy',
						config.taxonomySlug,
						followed,
						VIEW_CONTEXT
					) as
						| {
								newestEntry?: string | null;
								meta?: Record< string, string >;
						  }
						| undefined )
				: undefined;

			return {
				status: followed
					? badgeStatus( coverage?.meta?.[ config.statusMetaKey ] )
					: 'active',
				newest: coverage?.newestEntry ?? null,
			};
		},
		[ followed ]
	);

	const label =
		( typeof labels?.[ status ] === 'string' &&
			labels[ status ]?.trim() ) ||
		config.statusLabels[ status ];

	let updated: string | null = null;

	if ( showLastUpdated && status === 'active' ) {
		if ( ! followed ) {
			updated = humanTimeDiff( new Date( Date.now() - SAMPLE_AGE_MS ) );
		} else if ( newest ) {
			updated = humanTimeDiff( newest );
		}
	}

	const { justInserted, paletteSlugs, themeSwatches } = useSelect(
		( select ) => {
			const blockEditor = select( blockEditorStore ) as unknown as {
				wasBlockJustInserted: (
					id: string,
					source?: string
				) => boolean;
				getSettings: () => {
					colors?: { slug: string; color: string }[];
					__experimentalFeatures?: {
						color?: {
							palette?: Record<
								string,
								{ slug: string; color: string }[]
							>;
						};
					};
				};
			};
			const settings = blockEditor.getSettings();
			const palette = [
				...Object.values(
					settings.__experimentalFeatures?.color?.palette ?? {}
				).flat(),
				...( settings.colors ?? [] ),
			];
			const swatch = ( ...slugs: string[] ) =>
				slugs
					.map(
						( slug ) =>
							palette.find( ( color ) => color.slug === slug )
								?.color
					)
					.find( Boolean ) ?? '';

			return {
				justInserted: INSERT_SOURCES.some( ( source ) =>
					blockEditor.wasBlockJustInserted( clientId, source )
				),
				paletteSlugs: palette
					.map( ( color ) => color.slug )
					.join( ',' ),
				// The sidebar can't resolve the canvas's preset variables, so a theme color shows as its palette swatch.
				themeSwatches: `${ swatch( 'accent', 'primary' ) },${ swatch(
					'base',
					'white'
				) }`,
			};
		},
		[ clientId ]
	);

	const { __unstableMarkNextChangeAsNotPersistent } = useDispatch(
		blockEditorStore.name
	) as unknown as {
		__unstableMarkNextChangeAsNotPersistent: () => void;
	};
	const mutedApplied = useRef( false );

	useEffect( () => {
		if ( mutedApplied.current || ! justInserted ) {
			return;
		}

		mutedApplied.current = true;

		const color = mutedTextColor( paletteSlugs.split( ',' ) );
		const defaults: Partial< CoverageStatusAttributes > = {};

		if ( color && ! textColor && ! style?.color?.text ) {
			defaults.textColor = color;
		}

		if ( Object.keys( defaults ).length ) {
			__unstableMarkNextChangeAsNotPersistent();
			setAttributes( defaults );
		}
	}, [
		justInserted,
		paletteSlugs,
		__unstableMarkNextChangeAsNotPersistent,
		textColor,
		style,
		setAttributes,
	] );

	const blockProps = useBlockProps( { className: layoutClassNames } );
	const colorGradientSettings = useMultipleOriginColorsAndGradients();

	const setBackground = ( key: string, value?: string ) => {
		const next = { ...backgroundColors };

		if ( value ) {
			next[ key ] = value;
		} else {
			delete next[ key ];
		}

		setAttributes( { backgroundColors: next } );
	};
	const endedHidden = !! followed && status === 'archived' && hideWhenEnded;
	const endedNotice = __(
		"This coverage has ended, so the badge won't show on the site.",
		'newspack-rolling-coverage'
	);
	let goneNotice = '';

	if ( isChosenGone ) {
		goneNotice =
			followed || isTemplate
				? __(
						'This coverage no longer exists, so the page’s coverage is used.',
						'newspack-rolling-coverage'
					)
				: __(
						'This coverage no longer exists, so this badge won’t appear on the site.',
						'newspack-rolling-coverage'
					);
	}

	return (
		<>
			<InspectorControls>
				{ endedHidden && (
					<PanelBody>
						<Notice
							status="warning"
							isDismissible={ false }
							spokenMessage={ endedNotice }
						>
							{ endedNotice }
						</Notice>
					</PanelBody>
				) }
				{ ! isInFeed && (
					<PanelBody
						title={ __( 'Coverage', 'newspack-rolling-coverage' ) }
					>
						<CoverageChoice
							value={ coverageId }
							onChange={ ( value ) =>
								setAttributes( { coverageId: value } )
							}
							customChosen={ customCoverageChosen }
							onCustomChosenChange={ setCustomCoverageChosen }
							automaticHelp={ __(
								'Shows the coverage on this page, or a breakout post’s coverage.',
								'newspack-rolling-coverage'
							) }
							customHelp={ __(
								'Always shows this coverage.',
								'newspack-rolling-coverage'
							) }
							taxonomySlug={ config.taxonomySlug }
							statusMetaKey={ config.statusMetaKey }
						/>
						{ goneNotice && (
							<Notice
								status="warning"
								isDismissible={ false }
								spokenMessage={ goneNotice }
							>
								{ goneNotice }
							</Notice>
						) }
					</PanelBody>
				) }
				<PanelBody
					title={ __( 'Settings', 'newspack-rolling-coverage' ) }
				>
					<ToggleGroupControl
						__next40pxDefaultSize
						isBlock
						label={ __( 'Labels', 'newspack-rolling-coverage' ) }
						value={ isCustom ? 'custom' : 'default' }
						onChange={ ( value ) => {
							setCustomChosen( value === 'custom' );

							if (
								value === 'default' &&
								Object.keys( labels ?? {} ).length
							) {
								setAttributes( { labels: {} } );
							}
						} }
					>
						<ToggleGroupControlOption
							value="default"
							label={ _x(
								'Default',
								'status labels',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Default” option. Keep the word used to translate “Default”. */
								__(
									'Default labels',
									'newspack-rolling-coverage'
								)
							}
						/>
						<ToggleGroupControlOption
							value="custom"
							label={ _x(
								'Custom',
								'status labels',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Custom” option. Keep the word used to translate “Custom”. */
								__(
									'Custom labels',
									'newspack-rolling-coverage'
								)
							}
						/>
					</ToggleGroupControl>
					{ isCustom &&
						Object.entries( LABEL_FIELDS ).map(
							( [ key, field ] ) => (
								<TextControl
									key={ key }
									__next40pxDefaultSize
									label={ field }
									placeholder={ config.statusLabels[ key ] }
									value={
										typeof labels?.[ key ] === 'string'
											? labels[ key ]
											: ''
									}
									onChange={ ( value: string ) =>
										setAttributes( {
											labels: {
												...labels,
												[ key ]: value,
											},
										} )
									}
								/>
							)
						) }
					<ToggleGroupControl
						__next40pxDefaultSize
						isBlock
						label={ __(
							'Last updated',
							'newspack-rolling-coverage'
						) }
						value={ showLastUpdated ? 'show' : 'hide' }
						onChange={ ( value ) =>
							setAttributes( {
								showLastUpdated: value === 'show',
							} )
						}
					>
						<ToggleGroupControlOption
							value="show"
							label={ _x(
								'Show',
								'last updated',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Show” option. Keep the word used to translate “Show”. */
								__(
									'Show last updated',
									'newspack-rolling-coverage'
								)
							}
						/>
						<ToggleGroupControlOption
							value="hide"
							label={ _x(
								'Hide',
								'last updated',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Hide” option. Keep the word used to translate “Hide”. */
								__(
									'Hide last updated',
									'newspack-rolling-coverage'
								)
							}
						/>
					</ToggleGroupControl>
					<ToggleGroupControl
						__next40pxDefaultSize
						isBlock
						label={ __(
							'When ended',
							'newspack-rolling-coverage'
						) }
						value={ hideWhenEnded ? 'hide' : 'show' }
						onChange={ ( value ) =>
							setAttributes( {
								hideWhenEnded: value === 'hide',
							} )
						}
					>
						<ToggleGroupControlOption
							value="show"
							label={ _x(
								'Show',
								'when ended',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Show” option. Keep the word used to translate “Show”. */
								__(
									'Show when ended',
									'newspack-rolling-coverage'
								)
							}
						/>
						<ToggleGroupControlOption
							value="hide"
							label={ _x(
								'Hide',
								'when ended',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Hide” option. Keep the word used to translate “Hide”. */
								__(
									'Hide when ended',
									'newspack-rolling-coverage'
								)
							}
						/>
					</ToggleGroupControl>
					<ToggleGroupControl
						__next40pxDefaultSize
						isBlock
						label={ __( 'Dot', 'newspack-rolling-coverage' ) }
						value={ showDot === false ? 'hide' : 'show' }
						onChange={ ( value ) =>
							setAttributes( {
								showDot: value === 'show',
							} )
						}
					>
						<ToggleGroupControlOption
							value="show"
							label={ _x(
								'Show',
								'dot',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Show” option. Keep the word used to translate “Show”. */
								__( 'Show dot', 'newspack-rolling-coverage' )
							}
						/>
						<ToggleGroupControlOption
							value="hide"
							label={ _x(
								'Hide',
								'dot',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Hide” option. Keep the word used to translate “Hide”. */
								__( 'Hide dot', 'newspack-rolling-coverage' )
							}
						/>
					</ToggleGroupControl>
				</PanelBody>
			</InspectorControls>
			<InspectorControls group="color">
				<ColorGradientSettingsDropdown
					__experimentalIsRenderedInSidebar
					panelId={ clientId }
					settings={ Object.entries( BACKGROUND_FIELDS ).map(
						( [ key, field ] ) => ( {
							label: field,
							colorValue: themeSwatch(
								backgroundColors?.[ key ],
								themeSwatches
							),
							onColorChange: ( value?: string ) =>
								setBackground( key, value ),
							resetAllFilter: () => ( {
								backgroundColors: {},
							} ),
							clearable: true,
						} )
					) }
					{ ...colorGradientSettings }
					gradients={ [] }
					disableCustomGradients
				/>
			</InspectorControls>
			<div { ...blockProps }>
				<span
					className={ `newspack-ui__badge ${ badgeClasses(
						status,
						showDot !== false
					) }` }
					style={ badgeStyleObject( backgroundColors?.[ status ] ) }
				>
					{ label }
				</span>
				{ updated && (
					<>
						{ ' ' }
						<span className="newspack-rolling-coverage-updated">
							{ sprintf(
								/* translators: %s: How long ago the newest entry was published, e.g. "2 minutes ago". */
								__( 'Updated %s', 'newspack-rolling-coverage' ),
								updated
							) }
						</span>
					</>
				) }
			</div>
		</>
	);
}
