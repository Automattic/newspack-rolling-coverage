/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { useViewportMatch } from '@wordpress/compose';
import { Button, Notice, TextControl } from '@wordpress/components';
import { Stack, Tabs, Text } from '@wordpress/ui';
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
import {
	fetchLabelSetting,
	saveLabelSetting,
} from '../utils/label-setting-api';
import { fetchEntryName, saveEntryName } from '../utils/entry-name-api';
import { notifySuccess } from '../utils/notices';
import { setStatusLabels } from '../utils/status-labels';
import type { ApiResult, EntryName, StatusLabels } from '../types';

const EMPTY_LABELS: StatusLabels = { active: '', paused: '', archived: '' };
const EMPTY_NAME: EntryName = { singular: '', plural: '' };

type SettingsTab = 'labels' | 'status';

/**
 * Site-wide settings for Rolling Coverage: the Coverage Status block's default
 * labels, used by every block that doesn't set its own, the text of the
 * "Jump to Latest" button every feed shows, the label over a broken-out
 * entry's full story, and what readers see entries called. It opens once
 * the settings have loaded, so its fields never fill in after it shows.
 *
 * @param {Object}   props         Component props.
 * @param {Function} props.onClose Closes the modal.
 * @param {Function} props.onReady Called once the modal shows, loaded or failed.
 */
function SettingsModal( {
	onClose,
	onReady,
}: {
	onClose: () => void;
	onReady?: () => void;
} ) {
	const config = useAdminContext();
	const { requestConfirm, dialog: confirmDialog } = useConfirmDialog();
	const [ labels, setLabels ] = useState< StatusLabels >( EMPTY_LABELS );
	const [ savedLabels, setSavedLabels ] =
		useState< StatusLabels >( EMPTY_LABELS );
	const [ latestLabel, setLatestLabel ] = useState( '' );
	const [ savedLatestLabel, setSavedLatestLabel ] = useState( '' );
	const [ breakoutLabel, setBreakoutLabel ] = useState( '' );
	const [ savedBreakoutLabel, setSavedBreakoutLabel ] = useState( '' );
	const [ entryName, setEntryName ] = useState< EntryName >( EMPTY_NAME );
	const [ savedEntryName, setSavedEntryName ] =
		useState< EntryName >( EMPTY_NAME );
	const [ tab, setTab ] = useState< SettingsTab >( 'labels' );
	const [ isLoaded, setIsLoaded ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ errorAttempt, setErrorAttempt ] = useState( 0 );
	const isWide = useViewportMatch( 'medium' );

	useEffect( () => {
		let isCurrent = true;

		Promise.all( [
			fetchStatusLabels( config.restBaseUrls.statusLabels ),
			fetchLabelSetting( config.restBaseUrls.latestLabel ),
			fetchLabelSetting( config.restBaseUrls.breakoutLabel ),
			fetchEntryName( config.restBaseUrls.entryName ),
		] ).then(
			( [ labelsResult, latestResult, breakoutResult, nameResult ] ) => {
				if ( ! isCurrent ) {
					return;
				}

				if (
					labelsResult.data &&
					latestResult.data &&
					breakoutResult.data &&
					nameResult.data
				) {
					setLabels( labelsResult.data );
					setSavedLabels( labelsResult.data );
					setLatestLabel( latestResult.data.label );
					setSavedLatestLabel( latestResult.data.label );
					setBreakoutLabel( breakoutResult.data.label );
					setSavedBreakoutLabel( breakoutResult.data.label );
					setEntryName( nameResult.data );
					setSavedEntryName( nameResult.data );
					setIsLoaded( true );
				} else {
					setError(
						labelsResult.error ??
							latestResult.error ??
							breakoutResult.error ??
							nameResult.error ??
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
	}, [
		config.restBaseUrls.statusLabels,
		config.restBaseUrls.latestLabel,
		config.restBaseUrls.breakoutLabel,
		config.restBaseUrls.entryName,
	] );

	const areLabelsDirty = (
		Object.keys( labels ) as Array< keyof StatusLabels >
	 ).some( ( key ) => labels[ key ] !== savedLabels[ key ] );
	const isLatestLabelDirty = latestLabel !== savedLatestLabel;
	const isBreakoutLabelDirty = breakoutLabel !== savedBreakoutLabel;
	const isEntryNameDirty =
		entryName.singular !== savedEntryName.singular ||
		entryName.plural !== savedEntryName.plural;
	const isDirty =
		areLabelsDirty ||
		isLatestLabelDirty ||
		isBreakoutLabelDirty ||
		isEntryNameDirty;

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
		if (
			isEntryNameDirty &&
			! entryName.singular.trim() !== ! entryName.plural.trim()
		) {
			setTab( 'labels' );
			setErrorAttempt( ( attempt ) => attempt + 1 );
			setError(
				__(
					'Set both the singular and the plural, or leave both empty.',
					'newspack-rolling-coverage'
				)
			);
			return;
		}

		setIsSaving( true );
		setError( null );

		const [ labelsResult, latestResult, breakoutResult, nameResult ] =
			await Promise.all( [
				areLabelsDirty
					? saveStatusLabels(
							config.restBaseUrls.statusLabels,
							labels
						)
					: null,
				isLatestLabelDirty
					? saveLabelSetting( config.restBaseUrls.latestLabel, {
							label: latestLabel,
						} )
					: null,
				isBreakoutLabelDirty
					? saveLabelSetting( config.restBaseUrls.breakoutLabel, {
							label: breakoutLabel,
						} )
					: null,
				isEntryNameDirty
					? saveEntryName( config.restBaseUrls.entryName, entryName )
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

		if ( breakoutResult?.data ) {
			setBreakoutLabel( breakoutResult.data.label );
			setSavedBreakoutLabel( breakoutResult.data.label );
		}

		if ( nameResult?.data ) {
			setEntryName( nameResult.data );
			setSavedEntryName( nameResult.data );
		}

		const results: Array<
			[ SettingsTab, ( ApiResult & { data?: unknown } ) | null ]
		> = [
			[ 'labels', nameResult ],
			[ 'status', labelsResult ],
			[ 'labels', latestResult ],
			[ 'labels', breakoutResult ],
		];
		const failedEntry = results.find(
			( [ , result ] ) => result && ! result.data
		);
		const failed = failedEntry?.[ 1 ];

		if ( failedEntry ) {
			setTab( failedEntry[ 0 ] );
			setError(
				failed?.error ??
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

	const isReady = isLoaded || Boolean( error );

	useEffect( () => {
		if ( isReady ) {
			onReady?.();
		}
	}, [ isReady, onReady ] );

	if ( ! isReady ) {
		return null;
	}

	return (
		<>
			{ confirmDialog }
			<Modal
				size="large"
				className="newspack-rolling-coverage-settings"
				title={ __( 'Settings', 'newspack-rolling-coverage' ) }
				onRequestClose={ handleClose }
			>
				<Stack direction="column" gap="xl">
					{ error && (
						<Notice
							key={ errorAttempt }
							status="error"
							isDismissible={ false }
							politeness={ isLoaded ? 'assertive' : 'polite' }
						>
							{ error }
						</Notice>
					) }
					<Tabs.Root
						orientation="vertical"
						value={ tab }
						onValueChange={ ( value ) =>
							setTab( value as SettingsTab )
						}
					>
						<Stack
							direction={ isWide ? 'row' : 'column' }
							gap="xl"
							align={ isWide ? 'flex-start' : undefined }
						>
							<Tabs.List className="newspack-rolling-coverage-settings__tabs">
								<Tabs.Tab value="labels">
									{ __(
										'Labels',
										'newspack-rolling-coverage'
									) }
								</Tabs.Tab>
								<Tabs.Tab value="status">
									{ __(
										'Coverage Status',
										'newspack-rolling-coverage'
									) }
								</Tabs.Tab>
							</Tabs.List>
							<div className="newspack-rolling-coverage-settings__panels">
								<Tabs.Panel value="labels" keepMounted>
									<Stack direction="column" gap="xl">
										<Stack direction="column" gap="md">
											<Text
												variant="heading-md"
												// eslint-disable-next-line jsx-a11y/heading-has-content -- content is supplied via the Text children through @wordpress/ui's render prop.
												render={ <h3 /> }
											>
												{ __(
													'Entry Name',
													'newspack-rolling-coverage'
												) }
											</Text>
											<Text render={ <p /> }>
												{ __(
													'Set what readers see entries called, written as they read mid-sentence, for example “update” and “updates”. Leave both empty to use “entry” and “entries”.',
													'newspack-rolling-coverage'
												) }
											</Text>
											<TextControl
												__next40pxDefaultSize
												label={ __(
													'Singular',
													'newspack-rolling-coverage'
												) }
												placeholder={
													config.entryNameDefaults
														.singular
												}
												maxLength={
													config.entryNameMaxLength
												}
												value={ entryName.singular }
												disabled={
													! isLoaded || isSaving
												}
												onChange={ ( value: string ) =>
													setEntryName(
														( prev ) => ( {
															...prev,
															singular: value,
														} )
													)
												}
											/>
											<TextControl
												__next40pxDefaultSize
												label={ __(
													'Plural',
													'newspack-rolling-coverage'
												) }
												placeholder={
													config.entryNameDefaults
														.plural
												}
												maxLength={
													config.entryNameMaxLength
												}
												value={ entryName.plural }
												disabled={
													! isLoaded || isSaving
												}
												onChange={ ( value: string ) =>
													setEntryName(
														( prev ) => ( {
															...prev,
															plural: value,
														} )
													)
												}
											/>
										</Stack>
										<Stack direction="column" gap="md">
											<Text
												variant="heading-md"
												// eslint-disable-next-line jsx-a11y/heading-has-content -- content is supplied via the Text children through @wordpress/ui's render prop.
												render={ <h3 /> }
											>
												{ __(
													'Jump to Latest',
													'newspack-rolling-coverage'
												) }
											</Text>
											<Text render={ <p /> }>
												{ __(
													'Set the text of the button that takes readers back to the live feed. When it can, the button counts the new entries instead.',
													'newspack-rolling-coverage'
												) }
											</Text>
											<TextControl
												__next40pxDefaultSize
												label={ __(
													'Button label',
													'newspack-rolling-coverage'
												) }
												placeholder={
													config.latestLabelDefault
												}
												maxLength={
													config.latestLabelMaxLength
												}
												value={ latestLabel }
												disabled={
													! isLoaded || isSaving
												}
												onChange={ setLatestLabel }
											/>
										</Stack>
										<Stack direction="column" gap="md">
											<Text
												variant="heading-md"
												// eslint-disable-next-line jsx-a11y/heading-has-content -- content is supplied via the Text children through @wordpress/ui's render prop.
												render={ <h3 /> }
											>
												{ __(
													'Full Story',
													'newspack-rolling-coverage'
												) }
											</Text>
											<Text render={ <p /> }>
												{ __(
													'Set the label that marks an entry once its full story is published. Leave empty to use “Full story”.',
													'newspack-rolling-coverage'
												) }
											</Text>
											<TextControl
												__next40pxDefaultSize
												label={ __(
													'Full story label',
													'newspack-rolling-coverage'
												) }
												placeholder={
													config.breakoutLabelDefault
												}
												maxLength={
													config.breakoutLabelMaxLength
												}
												value={ breakoutLabel }
												disabled={
													! isLoaded || isSaving
												}
												onChange={ setBreakoutLabel }
											/>
										</Stack>
									</Stack>
								</Tabs.Panel>
								<Tabs.Panel value="status" keepMounted>
									<Stack direction="column" gap="xl">
										<Text render={ <p /> }>
											{ __(
												'Set the text the status indicator shows for each coverage status. A block can still set its own.',
												'newspack-rolling-coverage'
											) }
										</Text>
										{ fields.map( ( { key, label } ) => (
											<TextControl
												key={ key }
												__next40pxDefaultSize
												label={ label }
												placeholder={
													config.statusLabelDefaults[
														key
													]
												}
												maxLength={
													config.statusLabelMaxLength
												}
												value={ labels[ key ] }
												disabled={
													! isLoaded || isSaving
												}
												onChange={ ( value: string ) =>
													setLabels( ( prev ) => ( {
														...prev,
														[ key ]: value,
													} ) )
												}
											/>
										) ) }
									</Stack>
								</Tabs.Panel>
							</div>
						</Stack>
					</Tabs.Root>
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
