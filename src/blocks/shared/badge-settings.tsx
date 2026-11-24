/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalColorGradientSettingsDropdown as ColorGradientSettingsDropdown,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalUseMultipleOriginColorsAndGradients as useMultipleOriginColorsAndGradients,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __, _x } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { badgePresets } from './status-badges';

type PaletteColor = { slug: string; color: string };

type BadgeSettingsStore = {
	getSettings: () => {
		colors?: PaletteColor[];
		__experimentalFeatures?: {
			color?: { palette?: Record< string, PaletteColor[] > };
		};
	};
};

const BACKGROUND_FIELDS: Record< string, string > = {
	active: __( 'Live background', 'newspack-rolling-coverage' ),
	paused: __( 'Paused background', 'newspack-rolling-coverage' ),
	archived: __( 'Ended background', 'newspack-rolling-coverage' ),
};

/**
 * The color the picker shows for a badge background: a theme color's palette
 * swatch, else the plain color its variables fall back to, or the stored
 * color itself.
 *
 * @param {string} color        Stored background: 'accent', 'base' or a hex color.
 * @param {string} accentSwatch The palette's accent (or primary) color.
 * @param {string} baseSwatch   The palette's base (or white) color.
 * @return {string|undefined} The color to show.
 */
function themeSwatch(
	color: string | undefined,
	accentSwatch: string,
	baseSwatch: string
) {
	if ( color === 'accent' ) {
		return accentSwatch || '#003da5';
	}

	if ( color === 'base' ) {
		return baseSwatch || '#ffffff';
	}

	return color;
}

/**
 * The palette colors a badge preview and its color pickers need: the
 * swatches the pickers show for the theme colors, and the presets
 * badgeStyleObject() reads.
 *
 * @return {Object} `accentSwatch`, `baseSwatch` and `presets`.
 */
function useBadgePalette(): {
	accentSwatch: string;
	baseSwatch: string;
	presets: Record< string, string >;
} {
	const { accentSwatch, baseSwatch, presetKey } = useSelect( ( select ) => {
		const settings = (
			select( blockEditorStore ) as unknown as BadgeSettingsStore
		 ).getSettings();
		const origins = settings.__experimentalFeatures?.color?.palette ?? {};
		const palette = [
			...Object.values( origins ).flat(),
			...( settings.colors ?? [] ),
		];
		const swatch = ( ...slugs: string[] ) =>
			slugs
				.map(
					( slug ) =>
						palette.find( ( color ) => color.slug === slug )?.color
				)
				.find( Boolean ) ?? '';

		return {
			accentSwatch: swatch( 'accent', 'primary' ),
			baseSwatch: swatch( 'base', 'white' ),
			presetKey: JSON.stringify( badgePresets( origins ) ),
		};
	}, [] );

	return {
		accentSwatch,
		baseSwatch,
		presets: JSON.parse( presetKey ) as Record< string, string >,
	};
}

/**
 * The Dot setting of a status badge: show or hide its dot, and with it the
 * live badge's pulse.
 *
 * @param {Object}   props          Props.
 * @param {boolean}  props.showDot  Whether the badge shows its dot.
 * @param {Function} props.onChange Called with the new value.
 */
function BadgeDotControl( {
	showDot,
	onChange,
}: {
	showDot?: boolean;
	onChange: ( showDot: boolean ) => void;
} ) {
	return (
		<ToggleGroupControl
			__next40pxDefaultSize
			isBlock
			label={ __( 'Dot', 'newspack-rolling-coverage' ) }
			value={ showDot === false ? 'hide' : 'show' }
			onChange={ ( value ) => onChange( value === 'show' ) }
		>
			<ToggleGroupControlOption
				value="show"
				label={ _x( 'Show', 'dot', 'newspack-rolling-coverage' ) }
				aria-label={
					/* translators: Screen reader name for the “Show” option. Keep the word used to translate “Show”. */
					__( 'Show dot', 'newspack-rolling-coverage' )
				}
			/>
			<ToggleGroupControlOption
				value="hide"
				label={ _x( 'Hide', 'dot', 'newspack-rolling-coverage' ) }
				aria-label={
					/* translators: Screen reader name for the “Hide” option. Keep the word used to translate “Hide”. */
					__( 'Hide dot', 'newspack-rolling-coverage' )
				}
			/>
		</ToggleGroupControl>
	);
}

/**
 * A background color for each status badge, in the block's Color panel.
 *
 * @param {Object}   props                  Props.
 * @param {string}   props.clientId         Block client ID.
 * @param {Object}   props.backgroundColors Backgrounds by status.
 * @param {Function} props.onChange         Called with the new backgrounds.
 * @param {string}   props.accentSwatch     The palette's accent swatch, from useBadgePalette().
 * @param {string}   props.baseSwatch       The palette's base swatch, from useBadgePalette().
 */
function BadgeColorSettings( {
	clientId,
	backgroundColors,
	onChange,
	accentSwatch,
	baseSwatch,
}: {
	clientId: string;
	backgroundColors?: Partial< Record< string, string > >;
	onChange: ( backgroundColors: Partial< Record< string, string > > ) => void;
	accentSwatch: string;
	baseSwatch: string;
} ) {
	const colorGradientSettings = useMultipleOriginColorsAndGradients();

	const setBackground = ( key: string, value?: string ) => {
		const next = { ...backgroundColors };

		if ( value ) {
			next[ key ] = value;
		} else {
			delete next[ key ];
		}

		onChange( next );
	};

	return (
		<InspectorControls group="color">
			<ColorGradientSettingsDropdown
				__experimentalIsRenderedInSidebar
				panelId={ clientId }
				settings={ Object.entries( BACKGROUND_FIELDS ).map(
					( [ key, field ] ) => ( {
						label: field,
						colorValue: themeSwatch(
							backgroundColors?.[ key ],
							accentSwatch,
							baseSwatch
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
	);
}

export { BadgeColorSettings, BadgeDotControl, useBadgePalette };
