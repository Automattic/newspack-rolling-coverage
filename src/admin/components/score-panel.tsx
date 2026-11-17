/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	DropdownMenu,
	Modal,
	TextControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { moreVertical } from '@wordpress/icons';
import { Stack, Text } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { SCORE_META_KEY, isScore, type Score } from '../../blocks/shared/score';
import { handleApiError } from '../utils/api-error';
import { notifyError, notifySuccess } from '../utils/notices';
import { useAdminContext } from '../hooks/useAdminContext';
import { useConfirmDialog } from './confirm-dialog';
import type { ContextExports, Coverage } from '../types';

/**
 * A score waiting to be sent, and the promises of the changes it carries.
 */
type PendingSave = {
	score: Score | null;
	waiters: ( ( ok: boolean ) => void )[];
};

/**
 * An item for a `DropdownMenu`'s controls.
 */
type MenuControl = {
	title: string;
	onClick: () => void;
};

// Score::MAX_TEXT_LENGTH and Score::MAX_SCORE.
const MAX_TEXT_LENGTH = 40;
const MAX_SCORE = 999;

/**
 * The form that names the two teams, for Add Score and Edit Teams.
 *
 * @param {Object}   props             Props.
 * @param {string}   props.title       The modal's title.
 * @param {string}   props.submitLabel The submit button's label.
 * @param {string[]} props.names       The names to start from.
 * @param {Function} props.onSubmit    Called with the two names.
 * @param {Function} props.onClose     Closes the modal.
 */
function TeamsModal( {
	title,
	submitLabel,
	names,
	onSubmit,
	onClose,
}: {
	title: string;
	submitLabel: string;
	names: [ string, string ];
	onSubmit: ( names: [ string, string ] ) => Promise< boolean >;
	onClose: () => void;
} ) {
	const [ first, setFirst ] = useState( names[ 0 ] );
	const [ second, setSecond ] = useState( names[ 1 ] );
	const [ isBusy, setIsBusy ] = useState( false );
	const isValid = !! first.trim() && !! second.trim();

	const submit = async ( event: React.FormEvent ) => {
		event.preventDefault();

		if ( ! isValid || isBusy ) {
			return;
		}

		setIsBusy( true );

		if ( await onSubmit( [ first.trim(), second.trim() ] ) ) {
			onClose();
			return;
		}

		setIsBusy( false );
	};

	return (
		<Modal title={ title } onRequestClose={ onClose } size="small">
			<form onSubmit={ submit }>
				<Stack direction="column" gap="lg">
					<TextControl
						__next40pxDefaultSize
						label={ __(
							'First team',
							'newspack-rolling-coverage'
						) }
						help={ __(
							'Shown on the left.',
							'newspack-rolling-coverage'
						) }
						value={ first }
						onChange={ setFirst }
						maxLength={ MAX_TEXT_LENGTH }
						required
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __(
							'Second team',
							'newspack-rolling-coverage'
						) }
						help={ __(
							'Shown on the right.',
							'newspack-rolling-coverage'
						) }
						value={ second }
						onChange={ setSecond }
						maxLength={ MAX_TEXT_LENGTH }
						required
					/>
					<Stack direction="row" gap="sm" justify="flex-end">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ onClose }
							disabled={ isBusy }
						>
							{ __( 'Cancel', 'newspack-rolling-coverage' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							type="submit"
							isBusy={ isBusy }
							disabled={ ! isValid || isBusy }
							accessibleWhenDisabled
						>
							{ submitLabel }
						</Button>
					</Stack>
				</Stack>
			</form>
		</Modal>
	);
}

/**
 * The score as text, for users who can't change it.
 *
 * @param {Object} props       Props.
 * @param {Score}  props.score The score.
 */
function ScoreText( { score }: { score: Score } ) {
	return (
		<div className="newspack-rolling-coverage-score">
			<Text>
				{ score.status
					? sprintf(
							/* translators: 1: The first team's name. 2: Its score. 3: The second team's name. 4: Its score. 5: The status line, such as "Second half". */
							__(
								'Score: %1$s %2$d, %3$s %4$d (%5$s)',
								'newspack-rolling-coverage'
							),
							score.teams[ 0 ].name,
							score.teams[ 0 ].score,
							score.teams[ 1 ].name,
							score.teams[ 1 ].score,
							score.status
						)
					: sprintf(
							/* translators: 1: The first team's name. 2: Its score. 3: The second team's name. 4: Its score. */
							__(
								'Score: %1$s %2$d, %3$s %4$d',
								'newspack-rolling-coverage'
							),
							score.teams[ 0 ].name,
							score.teams[ 0 ].score,
							score.teams[ 1 ].name,
							score.teams[ 1 ].score
						) }
			</Text>
		</div>
	);
}

/**
 * One liveblog's score: the row above its entries list, and the Add Score
 * and Edit Teams modals. It is mounted per liveblog (keyed by its ID), so
 * nothing it holds, a modal, a pending save or the score shown, carries
 * over to another liveblog, and a save it started only ever writes to and
 * reports on its own liveblog.
 *
 * @param {Object}   props            Props.
 * @param {Coverage} props.coverage   The listed coverage.
 * @param {boolean}  props.canEdit    Whether the user can change the score.
 * @param {boolean}  props.isAdding   Whether Add Score was chosen in the header.
 * @param {Function} props.onAddClose Closes the Add Score modal.
 * @param {Function} props.onSaved    Called with the coverage ID and each saved score.
 */
function ScorePanel( {
	coverage,
	canEdit,
	isAdding,
	onAddClose,
	onSaved,
}: {
	coverage: Coverage;
	canEdit: boolean;
	isAdding: boolean;
	onAddClose: () => void;
	onSaved: ( coverageId: number, score: Score | null ) => void;
} ) {
	const config = useAdminContext();
	const coverageId = coverage.id;
	const stored = coverage.meta?.[ SCORE_META_KEY ];
	const saved = isScore( stored ) ? stored : null;
	const isTrashed = coverage.meta?.rolling_coverage_status === 'trash';

	const [ score, setScore ] = useState< Score | null >( saved );
	const [ status, setStatus ] = useState( saved?.status ?? '' );
	const [ isEditingTeams, setIsEditingTeams ] = useState( false );
	const [ drafts, setDrafts ] = useState< [ string | null, string | null ] >(
		[ null, null ]
	);
	const confirmed = useRef< Score | null >( saved );
	const pending = useRef< PendingSave | null >( null );
	const isSaving = useRef( false );
	const isStatusEdited = useRef( false );
	const { requestConfirm, dialog } = useConfirmDialog();

	const savedKey = JSON.stringify( saved );

	useEffect( () => {
		if ( isSaving.current ) {
			return;
		}

		const next = JSON.parse( savedKey ) as Score | null;

		confirmed.current = next;
		setScore( next );

		if ( ! isStatusEdited.current ) {
			setStatus( next?.status ?? '' );
		}
	}, [ savedKey ] );

	/**
	 * Sends the newest score asked for, one request at a time. Steps taken
	 * while a save is out go in the next request, which carries the whole
	 * score, so a slow request never lands after a newer one. Each change's
	 * promise settles with the request that carried it: a change folded into
	 * a later request, or one whose request failed while a later one was
	 * waiting, settles with that later request.
	 */
	const flush = useCallback( async () => {
		if ( isSaving.current ) {
			return;
		}

		isSaving.current = true;

		while ( pending.current ) {
			const { score: next, waiters } = pending.current;

			pending.current = null;

			try {
				const response = await apiFetch< {
					meta?: Record< string, unknown >;
				} >( {
					url: `${ config.restBaseUrls.coverages }/${ coverageId }`,
					method: 'POST',
					data: { meta: { [ SCORE_META_KEY ]: next } },
				} );
				const kept = response?.meta?.[ SCORE_META_KEY ];

				confirmed.current = isScore( kept ) ? kept : null;

				if ( ! pending.current ) {
					setScore( confirmed.current );

					if ( ! isStatusEdited.current ) {
						setStatus( confirmed.current?.status ?? '' );
					}
				}

				waiters.forEach( ( settle ) => settle( true ) );
			} catch ( error ) {
				const later = pending.current as PendingSave | null;

				if ( later ) {
					later.waiters.push( ...waiters );
					continue;
				}

				setScore( confirmed.current );

				if ( ! isStatusEdited.current ) {
					setStatus( confirmed.current?.status ?? '' );
				}
				notifyError(
					sprintf(
						/* translators: %s: Why the score couldn't be saved. */
						__(
							'The score couldn’t be saved: %s',
							'newspack-rolling-coverage'
						),
						handleApiError( error )
					)
				);
				waiters.forEach( ( settle ) => settle( false ) );
			}
		}

		isSaving.current = false;
		onSaved( coverageId, confirmed.current );
	}, [ config.restBaseUrls.coverages, coverageId, onSaved ] );

	const save = useCallback(
		( next: Score | null ): Promise< boolean > =>
			new Promise( ( settle ) => {
				setScore( next );
				pending.current = {
					score: next,
					waiters: [ ...( pending.current?.waiters ?? [] ), settle ],
				};
				flush();
			} ),
		[ flush ]
	);

	const step = ( index: 0 | 1, value: number ) => {
		if ( ! score ) {
			return;
		}

		const next = Math.min( MAX_SCORE, Math.max( 0, value ) );

		if ( next === score.teams[ index ].score ) {
			return;
		}

		const teams = score.teams.map( ( team, i ) =>
			i === index ? { ...team, score: next } : team
		) as Score[ 'teams' ];

		save( { ...score, teams } );
	};

	const setDraft = ( index: 0 | 1, value: string | null ) =>
		setDrafts( ( previous ) =>
			index === 0 ? [ value, previous[ 1 ] ] : [ previous[ 0 ], value ]
		);

	/**
	 * Saves a typed score once the field is left or Enter is pressed, so
	 * readers never get the digits typed on the way, such as 1 before 12. An
	 * empty or non-numeric field goes back to the saved score.
	 *
	 * @param {number} index The team.
	 */
	const commitDraft = ( index: 0 | 1 ) => {
		const draft = drafts[ index ]?.trim() ?? '';

		setDraft( index, null );

		if ( /^\d+$/.test( draft ) ) {
			step( index, Number( draft ) );
		}
	};

	/**
	 * The + and − buttons, and the arrow keys, save at once; typing waits for
	 * commitDraft().
	 *
	 * @param {number} index            The team.
	 * @param {string} value            The field's value.
	 * @param {Object} extra            The change's details.
	 * @param {Object} extra.event      The event behind it.
	 * @param {string} extra.event.type The event's type.
	 * @param {string} extra.event.key  The key pressed, for a keydown.
	 */
	const changeScore = (
		index: 0 | 1,
		value: string | undefined,
		extra?: { event?: { type?: string; key?: string } }
	) => {
		const event = extra?.event;
		const isStep =
			event?.type === 'click' ||
			( event?.type === 'keydown' &&
				( event.key === 'ArrowUp' || event.key === 'ArrowDown' ) );

		if ( ! isStep ) {
			setDraft( index, value ?? '' );
			return;
		}

		setDraft( index, null );

		if ( /^\d+$/.test( value ?? '' ) ) {
			step( index, Number( value ) );
		}
	};

	const saveStatus = () => {
		const next = status.trim();

		isStatusEdited.current = false;

		if ( ! score || next === score.status ) {
			return;
		}

		save( { ...score, status: next } );
	};

	let body: JSX.Element | null = null;

	if ( score && ( ! canEdit || isTrashed ) ) {
		body = <ScoreText score={ score } />;
	} else if ( score ) {
		body = (
			<div
				className="newspack-rolling-coverage-score"
				role="group"
				aria-label={ __( 'Score', 'newspack-rolling-coverage' ) }
			>
				<Stack direction="row" gap="lg" align="flex-end" wrap="wrap">
					{ ( [ 0, 1 ] as const ).map( ( index ) => (
						<div
							key={ index }
							className="newspack-rolling-coverage-score__team"
						>
							<NumberControl
								__next40pxDefaultSize
								label={ score.teams[ index ].name }
								value={
									drafts[ index ] ??
									score.teams[ index ].score
								}
								min={ 0 }
								max={ MAX_SCORE }
								step={ 1 }
								spinControls="custom"
								isDragEnabled={ false }
								onChange={ ( value, extra ) =>
									changeScore( index, value, extra )
								}
								onBlur={ () => commitDraft( index ) }
								onKeyDown={ ( event: { key: string } ) => {
									if ( event.key === 'Enter' ) {
										commitDraft( index );
									}
								} }
							/>
						</div>
					) ) }
					<div className="newspack-rolling-coverage-score__status">
						<TextControl
							__next40pxDefaultSize
							label={ __(
								'Status',
								'newspack-rolling-coverage'
							) }
							placeholder={ __(
								'For example, Second half',
								'newspack-rolling-coverage'
							) }
							value={ status }
							maxLength={ MAX_TEXT_LENGTH }
							onChange={ ( value ) => {
								isStatusEdited.current = true;
								setStatus( value );
							} }
							onBlur={ saveStatus }
							onKeyDown={ ( event ) => {
								if ( event.key === 'Enter' ) {
									saveStatus();
								}
							} }
						/>
					</div>
					<DropdownMenu
						icon={ moreVertical }
						label={ __(
							'Score actions',
							'newspack-rolling-coverage'
						) }
						controls={ [
							{
								title: __(
									'Edit Teams',
									'newspack-rolling-coverage'
								),
								onClick: () => setIsEditingTeams( true ),
							},
							{
								title: __(
									'Remove Score',
									'newspack-rolling-coverage'
								),
								onClick: () =>
									requestConfirm( {
										title: __(
											'Remove the score?',
											'newspack-rolling-coverage'
										),
										description: __(
											'Scoreboard blocks for this liveblog stop showing on the site. Open pages hide theirs at their next check.',
											'newspack-rolling-coverage'
										),
										confirmLabel: __(
											'Remove Score',
											'newspack-rolling-coverage'
										),
										onConfirm: async () => {
											if ( await save( null ) ) {
												notifySuccess(
													__(
														'Score removed.',
														'newspack-rolling-coverage'
													)
												);
											}
										},
									} ),
							},
						] }
					/>
				</Stack>
			</div>
		);
	}

	return (
		<>
			{ body }
			{ isAdding && (
				<TeamsModal
					title={ __( 'Add Score', 'newspack-rolling-coverage' ) }
					submitLabel={ __(
						'Add Score',
						'newspack-rolling-coverage'
					) }
					names={ [ '', '' ] }
					onSubmit={ async ( [ first, second ] ) => {
						const ok = await save( {
							teams: [
								{ name: first, score: 0 },
								{ name: second, score: 0 },
							],
							status: '',
						} );

						if ( ok ) {
							notifySuccess(
								__(
									'Score added.',
									'newspack-rolling-coverage'
								)
							);
						}

						return ok;
					} }
					onClose={ onAddClose }
				/>
			) }
			{ isEditingTeams && score && (
				<TeamsModal
					title={ __( 'Edit Teams', 'newspack-rolling-coverage' ) }
					submitLabel={ __( 'Save', 'newspack-rolling-coverage' ) }
					names={ [ score.teams[ 0 ].name, score.teams[ 1 ].name ] }
					onSubmit={ async ( [ first, second ] ) => {
						const ok = await save( {
							...score,
							teams: [
								{ ...score.teams[ 0 ], name: first },
								{ ...score.teams[ 1 ], name: second },
							],
						} );

						if ( ok ) {
							notifySuccess(
								__(
									'Changes saved.',
									'newspack-rolling-coverage'
								)
							);
						}

						return ok;
					} }
					onClose={ () => setIsEditingTeams( false ) }
				/>
			) }
			{ dialog }
		</>
	);
}

/**
 * The score of the liveblog whose entries are listed: an Add Score item
 * for the header's More actions menu while it has none, and the liveblog's
 * score panel (see ScorePanel) for above the list.
 *
 * @param {Coverage|null} coverage   The listed coverage.
 * @param {Function}      setContext Sets the admin context, whose copy of the coverage takes each saved score.
 * @return {Object} The menu item and the panel, either of which can be null.
 */
function useScorePanel(
	coverage: Coverage | null,
	setContext: ContextExports[ 1 ]
): { addControl: MenuControl | null; panel: JSX.Element | null } {
	const config = useAdminContext();
	const canEdit = config.capabilities.canManageTerms;
	const coverageId = coverage?.id ?? 0;
	const hasScore = isScore( coverage?.meta?.[ SCORE_META_KEY ] );
	const isTrashed = coverage?.meta?.rolling_coverage_status === 'trash';
	const [ addingFor, setAddingFor ] = useState< number | null >( null );

	const onSaved = useCallback(
		( id: number, next: Score | null ) =>
			setContext( ( prev ) =>
				prev.selectedCoverage?.id === id
					? {
							...prev,
							selectedCoverage: {
								...prev.selectedCoverage,
								meta: {
									...prev.selectedCoverage.meta,
									[ SCORE_META_KEY ]: next,
								},
							},
						}
					: prev
			),
		[ setContext ]
	);
	const onAddClose = useCallback( () => setAddingFor( null ), [] );

	const addControl = useMemo(
		() =>
			canEdit && coverageId && ! hasScore && ! isTrashed
				? {
						title: __( 'Add Score', 'newspack-rolling-coverage' ),
						onClick: () => setAddingFor( coverageId ),
					}
				: null,
		[ canEdit, coverageId, hasScore, isTrashed ]
	);

	return {
		addControl,
		panel: coverage ? (
			<ScorePanel
				key={ coverage.id }
				coverage={ coverage }
				canEdit={ canEdit }
				isAdding={ addingFor === coverage.id }
				onAddClose={ onAddClose }
				onSaved={ onSaved }
			/>
		) : null,
	};
}

export { useScorePanel };
export type { MenuControl };
