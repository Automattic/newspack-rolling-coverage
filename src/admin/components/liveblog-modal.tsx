/**
 * WordPress dependencies
 */
import { useState, useCallback, useEffect } from '@wordpress/element';
import { Modal, Button } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews/wp';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { saveLiveblog } from '../utils/liveblog-api';
import type { LiveblogModalProps, LiveblogFormData } from '../types';

const liveblogFields = [
	{
		id: 'name',
		type: 'text' as const,
		label: __( 'Name', 'newspack-rolling-coverage' ),
		placeholder: __( 'Enter liveblog name…', 'newspack-rolling-coverage' ),
		required: true,
	},
	{
		id: 'description',
		type: 'text' as const,
		label: __( 'Description', 'newspack-rolling-coverage' ),
		placeholder: __( 'Optional description…', 'newspack-rolling-coverage' ),
	},
	{
		id: 'status',
		type: 'text' as const,
		label: __( 'Status', 'newspack-rolling-coverage' ),
		elements: [
			{
				value: 'active',
				label: __( 'Active', 'newspack-rolling-coverage' ),
			},
			{
				value: 'paused',
				label: __( 'Paused', 'newspack-rolling-coverage' ),
			},
			{
				value: 'archived',
				label: __( 'Archived', 'newspack-rolling-coverage' ),
			},
		],
	},
];

const liveblogForm = {
	type: 'regular' as const,
	fields: [ { id: 'name' }, { id: 'description' }, { id: 'status' } ],
};

/**
 * Modal form for creating or editing a liveblog term, using DataForm for
 * field rendering. Detects edit vs. create mode based on whether `liveblog`
 * is provided.
 *
 * @param {LiveblogModalProps} props Component props.
 */
function LiveblogModal( { liveblog, onClose, onSaved }: LiveblogModalProps ) {
	const isEditing = liveblog !== null;
	const [ data, setData ] = useState( {
		name: liveblog?.name || '',
		description: liveblog?.description || '',
		status:
			( liveblog?.meta?.rolling_coverage_status as
				| 'active'
				| 'paused'
				| 'archived' ) || 'active',
	} );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const isValid = data.name.trim().length > 0;

	useEffect( () => {
		if ( liveblog ) {
			setData( {
				name: liveblog.name,
				description: liveblog.description,
				status: ( liveblog.meta?.rolling_coverage_status ||
					'active' ) as 'active' | 'paused' | 'archived',
			} );
		} else {
			setData( {
				name: '',
				description: '',
				status: 'active',
			} );
		}
		setError( null );
	}, [ liveblog ] );

	const handleChange = useCallback(
		( edits: Partial< LiveblogFormData > ) => {
			setData( ( prev ) => ( { ...prev, ...edits } ) );
			setError( null );
		},
		[]
	);

	const handleSave = async () => {
		setIsSaving( true );
		setError( null );

		const result = await saveLiveblog(
			{
				name: data.name,
				description: data.description,
				status: data.status,
			},
			isEditing && liveblog ? liveblog.id : undefined
		);

		if ( result.success ) {
			onSaved();
			onClose();
		} else {
			setError(
				result.error ||
					__( 'Failed to save liveblog', 'newspack-rolling-coverage' )
			);
		}

		setIsSaving( false );
	};

	return (
		<Modal
			title={
				isEditing
					? __( 'Edit Liveblog', 'newspack-rolling-coverage' )
					: __( 'New Liveblog', 'newspack-rolling-coverage' )
			}
			onRequestClose={ onClose }
			size="medium"
		>
			<DataForm
				data={ data }
				fields={ liveblogFields }
				form={ liveblogForm }
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
					disabled={ isSaving || ! isValid }
				>
					{ isEditing
						? __( 'Update', 'newspack-rolling-coverage' )
						: __( 'Create', 'newspack-rolling-coverage' ) }
				</Button>
			</div>
		</Modal>
	);
}

export { LiveblogModal };
