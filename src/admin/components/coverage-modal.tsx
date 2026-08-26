/**
 * External dependencies
 */
import { useState, useCallback, useEffect } from '@wordpress/element';
import { Modal, Button, ExternalLink } from '@wordpress/components';
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
		description: (
			<>
				{ __(
					'Used as the headline in LiveBlogPosting structured data when this coverage is shown on a page or post. Choose a reader-facing title rather than an internal label.',
					'newspack-rolling-coverage'
				) }{ ' ' }
				<ExternalLink href="https://schema.org/LiveBlogPosting">
					{ __( 'Learn more', 'newspack-rolling-coverage' ) }
				</ExternalLink>
			</>
		),
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
		id: 'canonicalUrl',
		type: 'text' as const,
		label: __( 'Canonical URL', 'newspack-rolling-coverage' ),
		placeholder: __(
			'https://example.com/live-coverage',
			'newspack-rolling-coverage'
		),
		description: __(
			'The page readers land on when they open a notification for this coverage.',
			'newspack-rolling-coverage'
		),
	},
];

const coverageForm = {
	type: 'regular' as const,
	fields: [
		{ id: 'name' },
		{ id: 'description' },
		{ id: 'status' },
		{ id: 'canonicalUrl' },
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
		canonicalUrl:
			( coverage?.meta?.[ taxMeta.canonicalUrlKey ] as string ) || '',
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
				canonicalUrl:
					( coverage.meta?.[ taxMeta.canonicalUrlKey ] as string ) ||
					'',
			} );
		} else {
			setData( {
				name: '',
				description: '',
				status: 'active',
				canonicalUrl: '',
			} );
		}
		setError( null );
	}, [ coverage, taxMeta.statusKey, taxMeta.canonicalUrlKey ] );

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
			taxMeta.canonicalUrlKey,
			{
				name: data.name,
				description: data.description,
				status: data.status,
				canonicalUrl: data.canonicalUrl,
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
