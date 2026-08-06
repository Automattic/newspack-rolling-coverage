/**
 * External dependencies
 */
import { useState, useCallback, useEffect } from '@wordpress/element';
import { Modal, Button } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews/wp';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { saveCoverage } from '../utils/coverage-api';
import { useAdminContext } from '../hooks/useAdminContext';
import type { CoverageModalProps, Coverage, CoverageFormData } from '../types';

const coverageFields = [
	{
		id: 'name',
		type: 'text' as const,
		label: __( 'Name', 'newspack-rolling-coverage' ),
		placeholder: __( 'Enter coverage name…', 'newspack-rolling-coverage' ),
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
	{
		id: 'adsDisabled',
		type: 'boolean' as const,
		label: __( 'Disable ads', 'newspack-rolling-coverage' ),
		description: __(
			'Disable ads for this coverage, useful for emergency or other sensitive news coverage.',
			'newspack-rolling-coverage'
		),
		Edit: 'toggle' as const,
	},
];

const coverageForm = {
	type: 'regular' as const,
	fields: [
		{ id: 'name' },
		{ id: 'description' },
		{ id: 'status' },
		{ id: 'adsDisabled' },
	],
};

/**
 * Modal form for creating or editing a coverage term, using DataForm for
 * field rendering. Detects edit vs. create mode based on whether `coverage`
 * is provided.
 *
 * @param {CoverageModalProps} props Component props.
 */
function CoverageModal( { coverage, onClose, onSaved }: CoverageModalProps ) {
	const { restBaseUrls, taxMeta } = useAdminContext();
	const isEditing = coverage !== null;
	const [ data, setData ] = useState( {
		name: coverage?.name || '',
		description: coverage?.description || '',
		status:
			( coverage?.meta?.[
				taxMeta.statusKey
			] as Coverage[ 'meta' ][ 'rolling_coverage_status' ] ) || 'active',
		adsDisabled: Boolean( coverage?.meta?.[ taxMeta.adsDisabledKey ] ),
	} );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const isValid = data.name.trim().length > 0;

	useEffect( () => {
		if ( coverage ) {
			setData( {
				name: coverage.name,
				description: coverage.description,
				status:
					( coverage.meta?.[
						taxMeta.statusKey
					] as Coverage[ 'meta' ][ 'rolling_coverage_status' ] ) ||
					'active',
				adsDisabled: Boolean(
					coverage.meta?.[ taxMeta.adsDisabledKey ]
				),
			} );
		} else {
			setData( {
				name: '',
				description: '',
				status: 'active',
				adsDisabled: false,
			} );
		}
		setError( null );
	}, [ coverage, taxMeta.statusKey, taxMeta.adsDisabledKey ] );

	const handleChange = useCallback(
		( edits: Partial< CoverageFormData > ) => {
			setData( ( prev ) => ( { ...prev, ...edits } ) );
			setError( null );
		},
		[]
	);

	const handleSave = async () => {
		setIsSaving( true );
		setError( null );

		const result = await saveCoverage(
			restBaseUrls.coverages,
			taxMeta.statusKey,
			taxMeta.adsDisabledKey,
			{
				name: data.name,
				description: data.description,
				status: data.status,
				adsDisabled: data.adsDisabled,
			},
			isEditing && coverage ? coverage.id : undefined
		);

		if ( result.success ) {
			onSaved();
			onClose();
		} else {
			setError(
				result.error ||
					__( 'Failed to save coverage', 'newspack-rolling-coverage' )
			);
		}

		setIsSaving( false );
	};

	return (
		<Modal
			title={
				isEditing
					? __( 'Edit Coverage', 'newspack-rolling-coverage' )
					: __( 'New Coverage', 'newspack-rolling-coverage' )
			}
			onRequestClose={ onClose }
			size="medium"
		>
			<DataForm
				data={ data }
				fields={ coverageFields }
				form={ coverageForm }
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

export { CoverageModal };
