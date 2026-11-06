/**
 * External dependencies
 */
import { SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { chevronDown } from '@wordpress/icons';
import { Button, Icon, Menu } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import type { PublishMode, PublishModeSelectProps } from '../../types';
import {
	getPublishModeHelp,
	getPublishModeOptions,
} from '../../utils/publish-modes';

/**
 * The Publish Mode control for a Slack channel. In a form it is a dropdown
 * with help text saying what the chosen mode does; in a table row
 * (`isCompact`) it is a small button naming the current mode, which opens a
 * menu of the modes with the current one ticked. Checkbox items carry the
 * tick; the radio items' marker is a dot.
 *
 * @param {PublishModeSelectProps} props Component props.
 */
function PublishModeSelect( {
	label,
	value,
	onChange,
	disabled = false,
	isCompact = false,
}: PublishModeSelectProps ) {
	const options = getPublishModeOptions();

	if ( ! isCompact ) {
		return (
			<SelectControl
				__next40pxDefaultSize
				label={ label }
				help={ getPublishModeHelp( value ) }
				value={ value }
				options={ options }
				onChange={ ( next ) => onChange( next as PublishMode ) }
				disabled={ disabled }
			/>
		);
	}

	const current =
		options.find( ( option ) => option.value === value )?.label ?? value;

	return (
		<Menu.Root>
			<Menu.Trigger
				disabled={ disabled }
				aria-label={ sprintf(
					/* translators: 1: "Publish mode for #channel", 2: the current publish mode. */
					__( '%1$s: %2$s', 'newspack-rolling-coverage' ),
					label,
					current
				) }
				render={ <Button variant="minimal" size="small" /> }
			>
				{ current }
				<Icon icon={ chevronDown } size={ 16 } />
			</Menu.Trigger>
			<Menu.Popup>
				{ options.map( ( option ) => (
					<Menu.CheckboxItem
						key={ option.value }
						checked={ option.value === value }
						closeOnClick
						onCheckedChange={ () => {
							if ( option.value !== value ) {
								onChange( option.value );
							}
						} }
					>
						<Menu.ItemLabel>{ option.label }</Menu.ItemLabel>
						<Menu.ItemDescription>
							{ getPublishModeHelp( option.value ) }
						</Menu.ItemDescription>
					</Menu.CheckboxItem>
				) ) }
			</Menu.Popup>
		</Menu.Root>
	);
}

export { PublishModeSelect };
