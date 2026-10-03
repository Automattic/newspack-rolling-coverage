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
	SelectControl,
	TextControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { humanTimeDiff } from '@wordpress/date';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _x, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { mutedTextColor } from '../shared/muted-color';
import { usePageFeeds } from '../shared/page-feeds';
import {
	badgeClasses,
	badgeStatus,
	badgeStyleObject,
} from '../shared/status-badges';
import type { CoverageStatusAttributes } from './types';

interface CoverageStatusConfig {
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
const NAME_SEPARATOR = '\u0000';
const VIEW_CONTEXT = { context: 'view' };
const SAMPLE_AGE_MS = 2 * 60 * 1000;
const DEFAULT_GAP_SLUG = '30';
const INSERT_SOURCES = [ undefined, 'inserter_menu', 'quick_inserter' ];

const config: CoverageStatusConfig = window.newspackCoverageStatusBlock ?? {
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
 * Editor for the Coverage Status block: the badge of the Rolling Coverage
 * block it follows on this page, or of the one it sits in, or a sample where
 * the page or coverage isn't known.
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
	attributes: CoverageStatusAttributes;
	setAttributes: ( attrs: Partial< CoverageStatusAttributes > ) => void;
	context?: Record< string, unknown >;
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
	const feedCoverageId = context?.[ COVERAGE_ID_CONTEXT ];

	const hasCustomLabels = Object.keys( LABEL_FIELDS ).some(
		( key ) =>
			typeof labels?.[ key ] === 'string' && !! labels[ key ]?.trim()
	);
	const [ customChosen, setCustomChosen ] = useState( false );
	const isCustom = hasCustomLabels || customChosen;

	const { feeds, canChoose } = usePageFeeds( {
		clientId,
		feedCoverageId,
		taxonomySlug: config.taxonomySlug,
		statusMetaKey: config.statusMetaKey,
	} );

	const followed = feeds.includes( coverageId )
		? coverageId
		: ( feeds[ 0 ] ?? 0 );

	const { nameKey, status, newest } = useSelect(
		( select ) => {
			const core = select( coreStore ) as unknown as {
				getEntityRecord: (
					kind: string,
					name: string,
					id: number,
					query: Record< string, string >
				) => unknown;
			};
			const getTerm = ( id: number ) =>
				core.getEntityRecord(
					'taxonomy',
					config.taxonomySlug,
					id,
					VIEW_CONTEXT
				) as
					| {
							name?: string;
							newestEntry?: string | null;
							meta?: Record< string, string >;
					  }
					| undefined;

			const coverage = followed ? getTerm( followed ) : undefined;

			return {
				nameKey: feeds
					.map( ( id ) => getTerm( id )?.name ?? String( id ) )
					.join( NAME_SEPARATOR ),
				status: followed
					? badgeStatus( coverage?.meta?.[ config.statusMetaKey ] )
					: 'active',
				newest: coverage?.newestEntry ?? null,
			};
		},
		[ feeds, followed ]
	);

	const names = useMemo( () => {
		const parts = nameKey ? nameKey.split( NAME_SEPARATOR ) : [];
		return Object.fromEntries(
			feeds.map( ( id, index ) => [
				id,
				decodeEntities( parts[ index ] ?? String( id ) ),
			] )
		) as Record< number, string >;
	}, [ feeds, nameKey ] );

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

	const { justInserted, paletteSlugs, spacingSlugs, blockGapSupport } =
		useSelect(
			( select ) => {
				const blockEditor = select( blockEditorStore ) as unknown as {
					wasBlockJustInserted: (
						id: string,
						source?: string
					) => boolean;
					getSettings: () => {
						colors?: { slug: string }[];
						__experimentalFeatures?: {
							color?: {
								palette?: Record< string, { slug: string }[] >;
							};
							spacing?: {
								blockGap?: boolean;
								spacingSizes?: Record<
									string,
									{ slug: string }[]
								>;
							};
						};
					};
				};
				const settings = blockEditor.getSettings();

				return {
					justInserted: INSERT_SOURCES.some( ( source ) =>
						blockEditor.wasBlockJustInserted( clientId, source )
					),
					paletteSlugs: [
						...Object.values(
							settings.__experimentalFeatures?.color?.palette ??
								{}
						).flat(),
						...( settings.colors ?? [] ),
					]
						.map( ( color ) => color.slug )
						.join( ',' ),
					blockGapSupport:
						settings.__experimentalFeatures?.spacing?.blockGap ??
						false,
					spacingSlugs: Object.values(
						settings.__experimentalFeatures?.spacing
							?.spacingSizes ?? {}
					)
						.flat()
						.map( ( size ) => size.slug )
						.join( ',' ),
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

		if (
			blockGapSupport &&
			spacingSlugs.split( ',' ).includes( DEFAULT_GAP_SLUG ) &&
			! style?.spacing?.blockGap
		) {
			defaults.style = {
				...style,
				spacing: {
					...style?.spacing,
					blockGap: `var:preset|spacing|${ DEFAULT_GAP_SLUG }`,
				},
			};
		}

		if ( Object.keys( defaults ).length ) {
			__unstableMarkNextChangeAsNotPersistent();
			setAttributes( defaults );
		}
	}, [
		justInserted,
		paletteSlugs,
		spacingSlugs,
		blockGapSupport,
		__unstableMarkNextChangeAsNotPersistent,
		textColor,
		style,
		setAttributes,
	] );

	const blockProps = useBlockProps();
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

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Settings', 'newspack-rolling-coverage' ) }
				>
					{ canChoose && feeds.length > 1 && (
						<SelectControl
							__next40pxDefaultSize
							label={ __(
								'Coverage',
								'newspack-rolling-coverage'
							) }
							value={ String(
								feeds.includes( coverageId ) ? coverageId : 0
							) }
							options={ [
								{
									value: '0',
									label: __(
										'First on the page',
										'newspack-rolling-coverage'
									),
								},
								...feeds.map( ( id ) => ( {
									value: String( id ),
									label: names[ id ],
								} ) ),
							] }
							onChange={ ( value: string ) =>
								setAttributes( {
									coverageId: parseInt( value, 10 ) || 0,
								} )
							}
						/>
					) }
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
					{ endedHidden && (
						<Notice
							status="warning"
							isDismissible={ false }
							spokenMessage={ endedNotice }
						>
							{ endedNotice }
						</Notice>
					) }
				</PanelBody>
			</InspectorControls>
			<InspectorControls group="color">
				<ColorGradientSettingsDropdown
					__experimentalIsRenderedInSidebar
					panelId={ clientId }
					settings={ Object.entries( BACKGROUND_FIELDS ).map(
						( [ key, field ] ) => ( {
							label: field,
							colorValue: backgroundColors?.[ key ],
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
