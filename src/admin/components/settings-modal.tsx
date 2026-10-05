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
import { fetchLatestLabel, saveLatestLabel } from '../utils/latest-label-api';
import { notifySuccess } from '../utils/notices';
import { setStatusLabels } from '../utils/status-labels';
import type { StatusLabels } from '../types';

const EMPTY_LABELS: StatusLabels = { active: '', paused: '', archived: '' };

/**
 * Site-wide settings for Rolling Coverage: the Coverage Status block's default
 * labels, used by every block that doesn't set its own, and the text of the
 * "Jump to Latest" button every feed shows.
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
	const [ latestLabel, setLatestLabel ] = useState( '' );
	const [ savedLatestLabel, setSavedLatestLabel ] = useState( '' );
	const [ isLoaded, setIsLoaded ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		let isCurrent = true;

		Promise.all( [
			fetchStatusLabels( config.restBaseUrls.statusLabels ),
			fetchLatestLabel( config.restBaseUrls.latestLabel ),
		] ).then( ( [ labelsResult, latestResult ] ) => {
			if ( ! isCurrent ) {
				return;
			}

			if ( labelsResult.data && latestResult.data ) {
				setLabels( labelsResult.data );
				setSavedLabels( labelsResult.data );
				setLatestLabel( latestResult.data.label );
				setSavedLatestLabel( latestResult.data.label );
				setIsLoaded( true );
			} else {
				setError(
					labelsResult.error ??
						latestResult.error ??
						__(
							'The settings couldn’t be loaded.',
							'newspack-rolling-coverage'
						)
				);
			}
		} );

		return () => {
			isCurrent = false;
		};
	}, [ config.restBaseUrls.statusLabels, config.restBaseUrls.latestLabel ] );

	const areLabelsDirty = (
		Object.keys( labels ) as Array< keyof StatusLabels >
	 ).some( ( key ) => labels[ key ] !== savedLabels[ key ] );
	const isLatestLabelDirty = latestLabel !== savedLatestLabel;
	const isDirty = areLabelsDirty || isLatestLabelDirty;

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

		const [ labelsResult, latestResult ] = await Promise.all( [
			areLabelsDirty
				? saveStatusLabels( config.restBaseUrls.statusLabels, labels )
				: null,
			isLatestLabelDirty
				? saveLatestLabel( config.restBaseUrls.latestLabel, {
						label: latestLabel,
					} )
				: null,
		] );

		setIsSaving( false );

		if ( labelsResult?.data ) {
			const saved = labelsResult.data;

			setLabels( saved );
			setSavedLabels( saved );
			setStatusLabels( {
				active: saved.active || config.statusLabelDefaults.active,
				paused: saved.paused || config.statusLabelDefaults.paused,
				archived: saved.archived || config.statusLabelDefaults.archived,
			} );
		}

		if ( latestResult?.data ) {
			setLatestLabel( latestResult.data.label );
			setSavedLatestLabel( latestResult.data.label );
		}

		const failed = [ labelsResult, latestResult ].find(
			( result ) => result && ! result.data
		);

		if ( failed ) {
			setError(
				failed.error ??
					__(
						'The settings couldn’t be saved.',
						'newspack-rolling-coverage'
					)
			);
			return;
		}

		notifySuccess( __( 'Saved.', 'newspack-rolling-coverage' ) );
		onClose();
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
				<Stack direction="column" gap="2xl">
					{ error && (
						<Notice
							status="error"
							isDismissible={ false }
							politeness={ isLoaded ? 'assertive' : 'polite' }
						>
							{ error }
						</Notice>
					) }
					<Stack direction="column" gap="xl">
						<Stack direction="column" gap="sm">
							{ /* eslint-disable-next-line jsx-a11y/heading-has-content -- content is supplied via the Text children through @wordpress/ui's render prop. */ }
							<Text variant="heading-md" render={ <h2 /> }>
								{ __(
									'Coverage Status',
									'newspack-rolling-coverage'
								) }
							</Text>
							<Text render={ <p /> }>
								{ __(
									'Set the text the status indicator shows for each coverage status. A block can still set its own.',
									'newspack-rolling-coverage'
								) }
							</Text>
						</Stack>
						{ fields.map( ( { key, label } ) => (
							<TextControl
								key={ key }
								__next40pxDefaultSize
								label={ label }
								placeholder={
									config.statusLabelDefaults[ key ]
								}
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
					</Stack>
					<Stack direction="column" gap="xl">
						<Stack direction="column" gap="sm">
							{ /* eslint-disable-next-line jsx-a11y/heading-has-content -- content is supplied via the Text children through @wordpress/ui's render prop. */ }
							<Text variant="heading-md" render={ <h2 /> }>
								{ __(
									'Jump to Latest',
									'newspack-rolling-coverage'
								) }
							</Text>
							<Text render={ <p /> }>
								{ __(
									'Set the text of the button that takes readers back to the live feed. When it can, the button counts the new posts instead.',
									'newspack-rolling-coverage'
								) }
							</Text>
						</Stack>
						<TextControl
							__next40pxDefaultSize
							label={ __(
								'Button label',
								'newspack-rolling-coverage'
							) }
							placeholder={ config.latestLabelDefault }
							maxLength={ config.latestLabelMaxLength }
							value={ latestLabel }
							disabled={ ! isLoaded || isSaving }
							onChange={ setLatestLabel }
						/>
					</Stack>
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
