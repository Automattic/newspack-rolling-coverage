/**
 * External dependencies
 */
import {
	useState,
	useCallback,
	useEffect,
	useMemo,
} from '@wordpress/element';
import { ExternalLink } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews/wp';
import { __ } from '@wordpress/i18n';
import { Drawer } from 'newspack-components/dist/esm/drawer';

/**
 * Internal dependencies
 */
import { saveCoverage } from '../utils/coverage-api';
import { useAdminContext } from '../hooks/useAdminContext';
import type {
	CoverageDrawerProps,
	Coverage,
	CoverageFormData,
} from '../types';

const coverageFields = [
	{
		id: 'name',
		type: 'text' as const,
		label: __( 'Name', 'newspack-rolling-coverage' ),
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
		description: __(
			'An internal note about this coverage. Readers never see it.',
			'newspack-rolling-coverage'
		),
	},
	{
		id: 'status',
		type: 'text' as const,
		label: __( 'Status', 'newspack-rolling-coverage' ),
		Edit: 'radio' as const,
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
	{
		id: 'adsDisabled',
		type: 'text' as const,
		label: __( 'Ads', 'newspack-rolling-coverage' ),
		description: __(
			'Disable ads for this coverage, useful for emergency or other sensitive news coverage.',
			'newspack-rolling-coverage'
		),
		elements: [
			{
				value: 'enabled',
				label: __( 'Enabled', 'newspack-rolling-coverage' ),
			},
			{
				value: 'disabled',
				label: __( 'Disabled', 'newspack-rolling-coverage' ),
			},
		],
		getValue: ( { item }: { item: CoverageFormData } ) =>
			item.adsDisabled ? 'disabled' : 'enabled',
		setValue: ( { value }: { value: string } ) => ( {
			adsDisabled: value === 'disabled',
		} ),
		Edit: 'toggleGroup' as const,
	},
];

const coverageForm = {
	type: 'regular' as const,
	fields: [
		{ id: 'name' },
		{ id: 'description' },
		{ id: 'status' },
		{ id: 'canonicalUrl' },
		{ id: 'adsDisabled' },
	],
};

/**
 * Form values for a coverage, or the defaults for a new one.
 *
 * @param coverage                Coverage being edited, or null when creating.
 * @param taxMeta                 Term meta keys from the admin config.
 * @param taxMeta.statusKey       Status meta key.
 * @param taxMeta.canonicalUrlKey Canonical URL meta key.
 * @param taxMeta.adsDisabledKey  Ads-disabled meta key.
 */
function getFormData(
	coverage: Coverage | null,
	taxMeta: {
		statusKey: string;
		canonicalUrlKey: string;
		adsDisabledKey: string;
	}
): CoverageFormData {
	return {
		name: coverage?.name || '',
		description: coverage?.description || '',
		status:
			( coverage?.meta?.[
				taxMeta.statusKey
			] as CoverageFormData[ 'status' ] ) || 'active',
		canonicalUrl:
			( coverage?.meta?.[ taxMeta.canonicalUrlKey ] as string ) || '',
		adsDisabled: Boolean( coverage?.meta?.[ taxMeta.adsDisabledKey ] ),
	};
}

/**
 * Drawer form for creating or editing a coverage term, using DataForm for
 * field rendering. Detects edit vs. create mode based on whether `coverage`
 * is provided. Stays mounted so the drawer can play its exit animation.
 *
 * @param {CoverageDrawerProps} props Component props.
 */
function CoverageDrawer( {
	isOpen,
	coverage,
	onClose,
	onSaved,
}: CoverageDrawerProps ) {
	const { restBaseUrls, taxMeta } = useAdminContext();
	const isEditing = coverage !== null;
	const initialData = useMemo(
		() => getFormData( coverage, taxMeta ),
		[ coverage, taxMeta ]
	);
	const [ data, setData ] = useState< CoverageFormData >( initialData );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const isValid = data.name.trim().length > 0;
	const isDirty =
		JSON.stringify( data ) !== JSON.stringify( initialData );

	useEffect( () => {
		if ( isOpen ) {
			setData( initialData );
			setError( null );
		}
	}, [ isOpen, initialData ] );

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
			taxMeta.adsDisabledKey,
			data,
			isEditing && coverage ? coverage.id : undefined
		);

		setIsSaving( false );

		if ( result.success ) {
			onSaved();
			onClose();
		} else {
			setError(
				result.error ||
					__( 'Failed to save coverage', 'newspack-rolling-coverage' )
			);
		}
	};

	const title = isEditing
		? __( 'Edit Coverage', 'newspack-rolling-coverage' )
		: __( 'Add Coverage', 'newspack-rolling-coverage' );

	return (
		<Drawer.Root
			isOpen={ isOpen }
			isDirty={ isDirty && ! isSaving }
			onRequestClose={ onClose }
		>
			<Drawer.Header>
				<Drawer.Title>{ title }</Drawer.Title>
				<Drawer.CloseIcon />
			</Drawer.Header>
			<Drawer.Content>
				<DataForm
					data={ data }
					fields={ coverageFields }
					form={ coverageForm }
					onChange={ handleChange }
				/>
				{ error && (
					<div className="newspack-rolling-coverage-error">
						{ error }
					</div>
				) }
			</Drawer.Content>
			<Drawer.Footer>
				<Drawer.Action
					variant="secondary"
					closes
					disabled={ isSaving }
				>
					{ __( 'Cancel', 'newspack-rolling-coverage' ) }
				</Drawer.Action>
				<Drawer.Action
					variant="primary"
					onClick={ handleSave }
					isBusy={ isSaving }
					disabled={ isSaving || ! isValid }
				>
					{ isEditing
						? __( 'Save', 'newspack-rolling-coverage' )
						: __( 'Add', 'newspack-rolling-coverage' ) }
				</Drawer.Action>
			</Drawer.Footer>
		</Drawer.Root>
	);
}

export { CoverageDrawer };
