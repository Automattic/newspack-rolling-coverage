/**
 * WordPress dependencies
 */
import { useMemo, useState } from '@wordpress/element';
import { Button, ComboboxControl, Notice } from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Stack, Text } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { changeEntriesAuthor } from '../utils/entries-api';
import { notifyError, notifySuccess } from '../utils/notices';
import type { ChangeAuthorModalProps } from '../types';

const AUTHORS_QUERY = {
	who: 'authors',
	per_page: 100,
	_fields: 'id,name',
	context: 'view',
};

/**
 * Picks a new author for one or more entries. The chosen user replaces each
 * entry's author, and its co-authors when Co-Authors Plus is on.
 *
 * Renders inside a DataViews RenderModal, which supplies the modal frame.
 *
 * @param {ChangeAuthorModalProps} props Component props.
 */
function ChangeAuthorModal( {
	items,
	restNamespace,
	postType,
	hasCoauthors,
	onClose,
	onChanged,
}: ChangeAuthorModalProps ) {
	const sharedAuthor = useMemo( () => {
		const first = items[ 0 ]?._embedded?.author?.[ 0 ];
		return first &&
			items.every(
				( item ) => item._embedded?.author?.[ 0 ]?.id === first.id
			)
			? first
			: undefined;
	}, [ items ] );

	const [ picked, setPicked ] = useState<
		{ id: number; name: string } | undefined
	>( sharedAuthor );
	const authorId = picked?.id;
	const [ search, setSearch ] = useState( '' );
	const { invalidateResolution } = useDispatch( coreStore );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const onFilterValueChange = useDebounce( setSearch, 300 );

	const { authors, isLoading } = useSelect(
		( select ) => {
			const query = search
				? { ...AUTHORS_QUERY, search, search_columns: [ 'name' ] }
				: AUTHORS_QUERY;
			const { getUsers, isResolving } = select( coreStore );
			return {
				authors: getUsers( query ) as
					{ id: number; name: string }[] | null,
				isLoading: isResolving( 'getUsers', [ query ] ),
			};
		},
		[ search ]
	);

	const options = useMemo( () => {
		const fetched = ( authors ?? [] ).map( ( author ) => ( {
			value: String( author.id ),
			label: decodeEntities( author.name ),
		} ) );
		if (
			picked &&
			! fetched.some( ( option ) => option.value === String( picked.id ) )
		) {
			fetched.unshift( {
				value: String( picked.id ),
				label: decodeEntities( picked.name ),
			} );
		}
		return fetched;
	}, [ authors, picked ] );

	const handleSubmit = async () => {
		if ( ! authorId ) {
			return;
		}

		setIsBusy( true );
		setError( '' );

		const result = await changeEntriesAuthor(
			restNamespace,
			items.map( ( item ) => item.id ),
			authorId
		);
		const updated = ( result.results ?? [] ).filter( ( r ) => r.updated );
		const failed = ( result.results ?? [] ).filter( ( r ) => ! r.updated );

		if ( ! result.success || ! updated.length ) {
			setIsBusy( false );
			setError(
				result.error ||
					failed[ 0 ]?.error ||
					__(
						'Failed to change the author.',
						'newspack-rolling-coverage'
					)
			);
			return;
		}

		// Quick Edit keeps its own copy of each entry, which would still hold
		// the old author and could save it back.
		updated.forEach( ( { entryId } ) =>
			invalidateResolution( 'getEntityRecord', [
				'postType',
				postType,
				entryId,
			] )
		);

		notifySuccess(
			updated.length === 1
				? __( 'Author changed.', 'newspack-rolling-coverage' )
				: sprintf(
						/* translators: %d: number of entries. */
						_n(
							'Author changed for %d entry.',
							'Author changed for %d entries.',
							updated.length,
							'newspack-rolling-coverage'
						),
						updated.length
					)
		);
		if ( failed.length === 1 ) {
			notifyError(
				failed[ 0 ].error ||
					__(
						'Failed to change the author.',
						'newspack-rolling-coverage'
					)
			);
		} else if ( failed.length ) {
			notifyError(
				sprintf(
					/* translators: %d: number of entries. */
					_n(
						'Couldn’t change the author of %d entry.',
						'Couldn’t change the author of %d entries.',
						failed.length,
						'newspack-rolling-coverage'
					),
					failed.length
				)
			);
		}
		onChanged?.();
		onClose();
	};

	return (
		<Stack direction="column" gap="xl">
			{ error && (
				<Notice
					status="error"
					isDismissible={ false }
					politeness="assertive"
				>
					{ error }
				</Notice>
			) }
			<Text render={ <p /> }>
				{ items.length === 1
					? __(
							'The person you choose replaces this entry’s current author.',
							'newspack-rolling-coverage'
						)
					: sprintf(
							/* translators: %d: number of entries. */
							_n(
								'The person you choose replaces the current author of %d entry.',
								'The person you choose replaces the current author of all %d entries.',
								items.length,
								'newspack-rolling-coverage'
							),
							items.length
						) }
			</Text>
			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Author', 'newspack-rolling-coverage' ) }
				options={ options }
				value={ authorId ? String( authorId ) : null }
				onChange={ ( value ) => {
					const option = options.find( ( o ) => o.value === value );
					setPicked(
						option
							? { id: Number( option.value ), name: option.label }
							: undefined
					);
				} }
				onFilterValueChange={ onFilterValueChange }
				isLoading={ isLoading }
				allowReset={ false }
			/>
			<Stack direction="row" gap="sm" justify="flex-end">
				<Button
					variant="tertiary"
					onClick={ onClose }
					disabled={ isBusy }
				>
					{ __( 'Cancel', 'newspack-rolling-coverage' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ handleSubmit }
					isBusy={ isBusy }
					disabled={
						isBusy ||
						! authorId ||
						( ! hasCoauthors && authorId === sharedAuthor?.id )
					}
				>
					{ __( 'Change Author', 'newspack-rolling-coverage' ) }
				</Button>
			</Stack>
		</Stack>
	);
}

export { ChangeAuthorModal };
