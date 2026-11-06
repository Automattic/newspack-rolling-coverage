/**
 * External dependencies
 */
import { SelectControl } from '@wordpress/components';
import { Select } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import type { PublishMode, PublishModeSelectProps } from '../../types';
import {
	getPublishModeDescription,
	getPublishModeHelp,
	getPublishModeOptions,
} from '../../utils/publish-modes';

/**
 * The Publish Mode control for a Slack channel. In a form it is a dropdown
 * with help text saying what the chosen mode does; in a table row
 * (`isCompact`) it is a low-profile select naming the current mode, whose
 * list describes each mode and ticks the current one.
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

	return (
		<Select.Root
			value={ value }
			items={ options }
			disabled={ disabled }
			onValueChange={ ( next ) => {
				if ( next && next !== value ) {
					onChange( next as PublishMode );
				}
			} }
		>
			<Select.Trigger
				variant="minimal"
				size="compact"
				aria-label={ label }
			/>
			<Select.Popup width="content">
				{ options.map( ( option ) => (
					<Select.Item key={ option.value } value={ option.value }>
						<Select.ItemLabel>{ option.label }</Select.ItemLabel>
						<Select.ItemDescription>
							{ getPublishModeDescription( option.value ) }
						</Select.ItemDescription>
					</Select.Item>
				) ) }
			</Select.Popup>
		</Select.Root>
	);
}

export { PublishModeSelect };
