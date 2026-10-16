/**
 * External dependencies
 */
import { SelectControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import type { PublishMode, PublishModeSelectProps } from '../../types';
import {
	getPublishModeHelp,
	getPublishModeOptions,
} from '../../utils/publish-modes';

/**
 * The Publish Mode dropdown for a Slack channel, with help text saying
 * what the chosen mode does with the channel's messages.
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
	return (
		<SelectControl
			__next40pxDefaultSize
			label={ label }
			hideLabelFromVision={ isCompact }
			help={ isCompact ? undefined : getPublishModeHelp( value ) }
			value={ value }
			options={ getPublishModeOptions() }
			onChange={ ( next ) => onChange( next as PublishMode ) }
			disabled={ disabled }
		/>
	);
}

export { PublishModeSelect };
