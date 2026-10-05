/**
 * WordPress dependencies
 */
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { __, _x } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import CoveragePicker from './coverage-picker';

interface CoverageChoiceProps {
	value: number;
	onChange: ( coverageId: number ) => void;
	customChosen: boolean;
	onCustomChosenChange: ( customChosen: boolean ) => void;
	automaticHelp: string;
	customHelp: string;
	taxonomySlug: string;
	statusMetaKey: string;
}

/**
 * The Coverage setting of the blocks that show or follow a coverage:
 * Automatic (0), or Custom with a picker for any coverage. Custom stays
 * selected before a coverage is picked through `customChosen`, which the
 * block keeps so it survives the inspector closing.
 *
 * @param {Object}   props                      Props.
 * @param {number}   props.value                The chosen coverage ID, or 0 for Automatic.
 * @param {Function} props.onChange             Called with the new coverage ID.
 * @param {boolean}  props.customChosen         Whether Custom was picked without a coverage yet.
 * @param {Function} props.onCustomChosenChange Called when Custom is picked or left.
 * @param {string}   props.automaticHelp        Help shown under Automatic.
 * @param {string}   props.customHelp           Help shown under Custom.
 * @param {string}   props.taxonomySlug         The coverage taxonomy.
 * @param {string}   props.statusMetaKey        The coverage status meta key.
 */
export default function CoverageChoice( {
	value,
	onChange,
	customChosen,
	onCustomChosenChange,
	automaticHelp,
	customHelp,
	taxonomySlug,
	statusMetaKey,
}: CoverageChoiceProps ) {
	const isCustom = value > 0 || customChosen;

	return (
		<>
			<ToggleGroupControl
				__next40pxDefaultSize
				isBlock
				label={ __( 'Coverage', 'newspack-rolling-coverage' ) }
				hideLabelFromVision
				help={ isCustom ? customHelp : automaticHelp }
				value={ isCustom ? 'custom' : 'automatic' }
				onChange={ ( next ) => {
					onCustomChosenChange( next === 'custom' );

					if ( next !== 'custom' && value ) {
						onChange( 0 );
					}
				} }
			>
				<ToggleGroupControlOption
					value="automatic"
					label={ _x(
						'Automatic',
						'coverage choice',
						'newspack-rolling-coverage'
					) }
					aria-label={
						/* translators: Screen reader name for the “Automatic” option. Keep the word used to translate “Automatic”. */
						__( 'Automatic coverage', 'newspack-rolling-coverage' )
					}
				/>
				<ToggleGroupControlOption
					value="custom"
					label={ _x(
						'Custom',
						'coverage choice',
						'newspack-rolling-coverage'
					) }
					aria-label={
						/* translators: Screen reader name for the “Custom” option. Keep the word used to translate “Custom”. */
						__( 'Custom coverage', 'newspack-rolling-coverage' )
					}
				/>
			</ToggleGroupControl>
			{ isCustom && (
				<CoveragePicker
					value={ value }
					onChange={ ( next ) => {
						if ( ! next ) {
							onCustomChosenChange( true );
						}

						onChange( next );
					} }
					taxonomySlug={ taxonomySlug }
					statusMetaKey={ statusMetaKey }
				/>
			) }
		</>
	);
}
