/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, TextControl } from '@wordpress/components';
import { Stack, Text } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';
import Modal from 'newspack-components/dist/esm/modal';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { useConfirmDialog } from './confirm-dialog';
import {
	fetchStatusLabels,
	saveStatusLabels,
} from '../utils/status-labels-api';
import { notifySuccess } from '../utils/notices';
import type { StatusLabels } from '../types';

const EMPTY_LABELS: StatusLabels = { active: '', paused: '', archived: '' };

/**
 * Site-wide settings for Rolling Coverage: the status indicator's default
 * labels, used by every block that doesn't set its own.
 *
 * @param {Object}   props         Component props.
 * @param {Function} props.onClose Closes the modal.
 */
function SettingsModal( { onClose }: { onClose: () => void } ) {
	const config = useAdminContext();
	const { requestConfirm, dialog: confirmDialog } = useConfirmDialog();
	const [ labels, setLabels ] = useState< StatusLabels >( EMPTY_LABELS );
	const [ savedLabels, setSavedLabels ] =
		useState< StatusLabels >( EMPTY_LABELS );
	const [ isLoaded, setIsLoaded ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		let isCurrent = true;

		fetchStatusLabels( config.restBaseUrls.statusLabels ).then(
			( result ) => {
				if ( ! isCurrent ) {
					return;
				}

				if ( result.success && result.data ) {
					setLabels( result.data );
					setSavedLabels( result.data );
					setIsLoaded( true );
				} else {
					setError(
						result.error ??
							__(
								'The settings couldn’t be loaded.',
								'newspack-rolling-coverage'
							)
					);
				}
			}
		);

		return () => {
			isCurrent = false;
		};
	}, [ config.restBaseUrls.statusLabels ] );

	const isDirty = (
		Object.keys( labels ) as Array< keyof StatusLabels >
	 ).some( ( key ) => labels[ key ] !== savedLabels[ key ] );

	const handleClose = useCallback( () => {
		if ( isSaving ) {
			return;
		}

		if ( ! isDirty ) {
			onClose();
			return;
		}

		requestConfirm( {
			title: __( 'Discard changes?', 'newspack-rolling-coverage' ),
			description: __(
				'The changes to these settings haven’t been saved.',
				'newspack-rolling-coverage'
			),
			confirmLabel: __( 'Discard', 'newspack-rolling-coverage' ),
			onConfirm: async () => onClose(),
		} );
	}, [ isDirty, isSaving, onClose, requestConfirm ] );

	const handleSave = async () => {
		setIsSaving( true );
		setError( null );

		const result = await saveStatusLabels(
			config.restBaseUrls.statusLabels,
			labels
		);

		setIsSaving( false );

		if ( result.success && result.data ) {
			setSavedLabels( result.data );
			notifySuccess( __( 'Saved.', 'newspack-rolling-coverage' ) );
			onClose();
		} else {
			setError(
				result.error ??
					__(
						'The settings couldn’t be saved.',
						'newspack-rolling-coverage'
					)
			);
		}
	};

	const fields: Array< { key: keyof StatusLabels; label: string } > = [
		{
			key: 'active',
			label: __( 'Live label', 'newspack-rolling-coverage' ),
		},
		{
			key: 'paused',
			label: __( 'Paused label', 'newspack-rolling-coverage' ),
		},
		{
			key: 'archived',
			label: __( 'Ended label', 'newspack-rolling-coverage' ),
		},
	];

	return (
		<>
			{ confirmDialog }
			<Modal
				size="medium"
				title={ __( 'Settings', 'newspack-rolling-coverage' ) }
				onRequestClose={ handleClose }
			>
				<Stack direction="column" gap="xl">
					<Text render={ <p /> }>
						{ __(
							'Set the text the status indicator shows for each coverage status. A block can still set its own.',
							'newspack-rolling-coverage'
						) }
					</Text>
					{ error && (
						<Notice
							status="error"
							isDismissible={ false }
							politeness={ isLoaded ? 'assertive' : 'polite' }
						>
							{ error }
						</Notice>
					) }
					{ fields.map( ( { key, label } ) => (
						<TextControl
							key={ key }
							__next40pxDefaultSize
							label={ label }
							placeholder={ config.statusLabelDefaults[ key ] }
							maxLength={ config.statusLabelMaxLength }
							value={ labels[ key ] }
							disabled={ ! isLoaded || isSaving }
							onChange={ ( value: string ) =>
								setLabels( ( prev ) => ( {
									...prev,
									[ key ]: value,
								} ) )
							}
						/>
					) ) }
					<Stack direction="row" gap="sm" justify="flex-end">
						<Button
							variant="tertiary"
							onClick={ handleClose }
							disabled={ isSaving }
						>
							{ __( 'Cancel', 'newspack-rolling-coverage' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ handleSave }
							isBusy={ isSaving }
							disabled={ ! isLoaded || isSaving || ! isDirty }
							accessibleWhenDisabled
						>
							{ __( 'Save', 'newspack-rolling-coverage' ) }
						</Button>
					</Stack>
				</Stack>
			</Modal>
		</>
	);
}

export default SettingsModal;
