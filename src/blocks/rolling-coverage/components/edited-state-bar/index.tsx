/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import './style.scss';

interface EditedStateOption {
	value: string;
	label: string;
}

interface EditedStateBarProps {
	options: EditedStateOption[];
	value: string;
	onChange: ( value: string ) => void;
	isVisible: boolean;
}

/**
 * A row of mutually-exclusive buttons for switching a block's editor-only
 * preview between states (e.g. default vs. archived).
 *
 * @param {Object}   props           Component props.
 * @param {Object[]} props.options   The available states, as { value, label }.
 * @param {string}   props.value     The currently active state's value.
 * @param {Function} props.onChange  Called with the newly selected value.
 * @param {boolean}  props.isVisible Whether the bar should render at all.
 */
export default function EditedStateBar( {
	options,
	value,
	onChange,
	isVisible,
}: EditedStateBarProps ) {
	if ( ! isVisible ) {
		return null;
	}

	return (
		<div className="newspack-edited-state-bar">
			<span>{ __( 'Edited State', 'newspack-rolling-coverage' ) }</span>
			<div>
				{ options.map( ( option ) => (
					<Button
						key={ option.value }
						data-is-active={ option.value === value }
						onClick={ () => onChange( option.value ) }
					>
						{ option.label }
					</Button>
				) ) }
			</div>
		</div>
	);
}

export type { EditedStateOption };
