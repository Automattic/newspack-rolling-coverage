/**
 * WordPress dependencies
 */
import {
	Fragment,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { Button, Notice, TextControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import Divider from 'newspack-components/dist/esm/divider';
import Grid from 'newspack-components/dist/esm/grid';
import SectionHeader from 'newspack-components/dist/esm/section-header';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { useHeader } from '../hooks/useHeader';
import { useReportDirty } from '../hooks/useReportDirty';
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
import { LoadingState } from '../shared/loading-state';
import type { ApiResult, EntryName, StatusLabels } from '../types';
import type { ReactNode } from 'react';

const EMPTY_LABELS: StatusLabels = { active: '', paused: '', archived: '' };
const EMPTY_NAME: EntryName = { singular: '', plural: '' };

/**
 * The General tab of Settings: what readers see entries called, the text
 * of the "Jump to latest" button every feed shows, the label over a
 * broken-out entry's full story, and the Coverage Status block's default
 * labels, used by every block that doesn't set its own. Each group saves
 * its own option through its own route; Save in the header saves the
 * groups that changed.
 *
 * @param {Object}    props                  Component props.
 * @param {ReactNode} props.tabbedNavigation The Settings tabs.
 * @param {Function}  props.onDirtyChange    Receives whether the tab has unsaved changes.
 */
function GeneralSettings( {
	tabbedNavigation,
	onDirtyChange,
}: {
	tabbedNavigation: ReactNode;
	onDirtyChange: ( isDirty: boolean ) => void;
} ) {
	const config = useAdminContext();
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
	const [ isLoaded, setIsLoaded ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ errorAttempt, setErrorAttempt ] = useState( 0 );

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

	useReportDirty( onDirtyChange, isDirty && ! isSaving );

	const handleSave = async () => {
		if (
			isEntryNameDirty &&
			! entryName.singular.trim() !== ! entryName.plural.trim()
		) {
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

		const results: Array< ( ApiResult & { data?: unknown } ) | null > = [
			nameResult,
			latestResult,
			breakoutResult,
			labelsResult,
		];
		const failed = results.find( ( result ) => result && ! result.data );

		if ( failed ) {
			setErrorAttempt( ( attempt ) => attempt + 1 );
			setError(
				failed.error ??
					__(
						'The settings couldn’t be saved.',
						'newspack-rolling-coverage'
					)
			);
			return;
		}

		notifySuccess( __( 'Settings saved.', 'newspack-rolling-coverage' ) );
	};

	const saveRef = useRef( handleSave );
	saveRef.current = handleSave;

	const headerActions = useMemo(
		() =>
			isLoaded ? (
				<Button
					variant="primary"
					onClick={ () => saveRef.current() }
					isBusy={ isSaving }
					disabled={ isSaving || ! isDirty }
					accessibleWhenDisabled
				>
					{ __( 'Save', 'newspack-rolling-coverage' ) }
				</Button>
			) : null,
		[ isLoaded, isSaving, isDirty ]
	);

	useHeader( {
		tabbedNavigation,
		actions: headerActions,
		subTitle: __(
			'Set the labels readers see in every liveblog.',
			'newspack-rolling-coverage'
		),
	} );

	const statusFields: Array< { key: keyof StatusLabels; label: string } > = [
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

	const isDisabled = ! isLoaded || isSaving;

	const sections: Array< {
		key: string;
		title: string;
		description: string;
		fields: ReactNode;
	} > = [
		{
			key: 'entry-name',
			title: __( 'Entry Name', 'newspack-rolling-coverage' ),
			description: __(
				'Set what readers see entries called, written as they read mid-sentence, for example “update” and “updates”. Leave both empty to use “entry” and “entries”.',
				'newspack-rolling-coverage'
			),
			fields: (
				<>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Singular', 'newspack-rolling-coverage' ) }
						placeholder={ config.entryNameDefaults.singular }
						maxLength={ config.entryNameMaxLength }
						value={ entryName.singular }
						disabled={ isDisabled }
						onChange={ ( value: string ) =>
							setEntryName( ( prev ) => ( {
								...prev,
								singular: value,
							} ) )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Plural', 'newspack-rolling-coverage' ) }
						placeholder={ config.entryNameDefaults.plural }
						maxLength={ config.entryNameMaxLength }
						value={ entryName.plural }
						disabled={ isDisabled }
						onChange={ ( value: string ) =>
							setEntryName( ( prev ) => ( {
								...prev,
								plural: value,
							} ) )
						}
					/>
				</>
			),
		},
		{
			key: 'jump-to-latest',
			title: __( 'Jump to Latest', 'newspack-rolling-coverage' ),
			description: __(
				'Set the text of the button that takes readers back to the live feed. When it can, the button counts the new entries instead.',
				'newspack-rolling-coverage'
			),
			fields: (
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Button label', 'newspack-rolling-coverage' ) }
					placeholder={ config.latestLabelDefault }
					maxLength={ config.latestLabelMaxLength }
					value={ latestLabel }
					disabled={ isDisabled }
					onChange={ setLatestLabel }
				/>
			),
		},
		{
			key: 'full-story',
			title: __( 'Full Story', 'newspack-rolling-coverage' ),
			description: sprintf(
				/* translators: %s: the default Full story label. */
				__(
					'Set the label shown on an entry once its breakout post is published. Each breakout post can set its own in the post editor. Leave empty to use “%s”.',
					'newspack-rolling-coverage'
				),
				config.breakoutLabelDefault
			),
			fields: (
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __(
						'Full story label',
						'newspack-rolling-coverage'
					) }
					placeholder={ config.breakoutLabelDefault }
					maxLength={ config.breakoutLabelMaxLength }
					value={ breakoutLabel }
					disabled={ isDisabled }
					onChange={ setBreakoutLabel }
				/>
			),
		},
		{
			key: 'coverage-status',
			title: __( 'Coverage Status', 'newspack-rolling-coverage' ),
			description: __(
				'Set the text the status indicator shows for each liveblog status. A block can still set its own.',
				'newspack-rolling-coverage'
			),
			fields: statusFields.map( ( { key, label } ) => (
				<TextControl
					key={ key }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ label }
					placeholder={ config.statusLabelDefaults[ key ] }
					maxLength={ config.statusLabelMaxLength }
					value={ labels[ key ] }
					disabled={ isDisabled }
					onChange={ ( value: string ) =>
						setLabels( ( prev ) => ( {
							...prev,
							[ key ]: value,
						} ) )
					}
				/>
			) ),
		},
	];

	return (
		<Stack
			direction="column"
			gap="xl"
			className="newspack-rolling-coverage-settings"
		>
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
			{ ! isLoaded && ! error && (
				<LoadingState
					label={ __(
						'Fetching settings…',
						'newspack-rolling-coverage'
					) }
				/>
			) }
			{ isLoaded && (
				<div>
					{ sections.map( ( section, index ) => (
						<Fragment key={ section.key }>
							{ index > 0 && (
								<Divider
									alignment="full-width"
									variant="tertiary"
								/>
							) }
							<Grid columns={ 2 } gutter={ 32 } noMargin>
								<SectionHeader
									heading={ 2 }
									noMargin
									title={ section.title }
									description={ section.description }
								/>
								<Stack direction="column" gap="xl">
									{ section.fields }
								</Stack>
							</Grid>
						</Fragment>
					) ) }
				</div>
			) }
		</Stack>
	);
}

export default GeneralSettings;
