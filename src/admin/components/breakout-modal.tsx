/**
 * WordPress dependencies
 */
import { useState, useCallback, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews/wp';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { saveBreakoutSettings } from '../utils/breakout-api';
import { notifySuccess } from '../utils/notices';
import { useAdminContext } from '../hooks/useAdminContext';
import type { BreakoutModalProps, BreakoutFormData } from '../types';

const breakoutFields = [
	{
		id: 'rolling_coverage_breakout_read_more_text',
		type: 'text' as const,
		label: __( 'Read more text', 'newspack-rolling-coverage' ),
		placeholder: __( 'Read more', 'newspack-rolling-coverage' ),
	},
];

const breakoutForm = {
	type: 'regular' as const,
	fields: [ { id: 'rolling_coverage_breakout_read_more_text' } ],
};

/**
 * Modal form for configuring the "read more" link text shown on an entry
 * once its breakout post is published. Only ever opened for entries that
 * already have a breakout post (see entry-actions.ts).
 *
 * @param {BreakoutModalProps} props Component props.
 */
function BreakoutModal( { entry, onClose, onSaved }: BreakoutModalProps ) {
	const { restBaseUrls } = useAdminContext();
	const [ data, setData ] = useState< BreakoutFormData >( {
		rolling_coverage_breakout_read_more_text:
			entry.meta?.rolling_coverage_breakout_read_more_text || '',
	} );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		setData( {
			rolling_coverage_breakout_read_more_text:
				entry.meta?.rolling_coverage_breakout_read_more_text || '',
		} );
		setError( null );
	}, [ entry ] );

	const handleChange = useCallback(
		( edits: Partial< BreakoutFormData > ) => {
			setData( ( prev ) => ( { ...prev, ...edits } ) );
			setError( null );
		},
		[]
	);

	const handleSave = async () => {
		setIsSaving( true );
		setError( null );

		const result = await saveBreakoutSettings(
			restBaseUrls.entries,
			entry.id,
			data.rolling_coverage_breakout_read_more_text
		);

		if ( result.success ) {
			notifySuccess(
				__( 'Breakout setting saved.', 'newspack-rolling-coverage' )
			);
			onSaved();
			onClose();
		} else {
			setError(
				result.error ||
					__(
						'Failed to save breakout setting',
						'newspack-rolling-coverage'
					)
			);
		}

		setIsSaving( false );
	};

	return (
		<>
			<DataForm
				data={ data }
				fields={ breakoutFields }
				form={ breakoutForm }
				onChange={ handleChange }
			/>
			{ error && (
				<div className="newspack-rolling-coverage-error">{ error }</div>
			) }
			<div className="newspack-rolling-coverage-modal-footer">
				<Button
					variant="tertiary"
					onClick={ onClose }
					disabled={ isSaving }
				>
					{ __( 'Cancel', 'newspack-rolling-coverage' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ isSaving }
					disabled={ isSaving }
				>
					{ __( 'Save', 'newspack-rolling-coverage' ) }
				</Button>
			</div>
		</>
	);
}

export { BreakoutModal };
