/**
 * WordPress dependencies
 */
import { useLayoutEffect, useMemo, useState } from '@wordpress/element';
import {
	BaseControl,
	Button,
	ComboboxControl,
	Dropdown,
	Notice,
	TextControl,
} from '@wordpress/components';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalPublishDateTimePicker as PublishDateTimePicker } from '@wordpress/block-editor';
import { useDebounce, useInstanceId } from '@wordpress/compose';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { date as formatDate, format, getSettings } from '@wordpress/date';
import { decodeEntities } from '@wordpress/html-entities';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';
import { Drawer } from 'newspack-components/dist/esm/drawer';

/**
 * Internal dependencies
 */
import { useAdminContext } from '../hooks/useAdminContext';
import { editEntriesDetails } from '../utils/entries-api';
import { notifyError, notifySuccess } from '../utils/notices';
import { TermTokenField, TERMS_QUERY } from './term-token-field';
import type {
	Entry,
	EntryDetailsChanges,
	EntryDetailsDrawerProps,
	EntryTermChanges,
	PickedTerm,
} from '../types';

const AUTHORS_QUERY = {
	who: 'authors',
	per_page: 100,
	_fields: 'id,name',
	context: 'view',
};

/**
 * The terms of one taxonomy an entry already has, from its row.
 *
 * @param {Entry}  entry    The entry.
 * @param {string} taxonomy Taxonomy slug.
 * @return {PickedTerm[]} The entry's terms, names decoded.
 */
function getEntryTerms( entry: Entry | undefined, taxonomy: string ) {
	return ( entry?._embedded?.[ 'wp:term' ]?.flat() ?? [] )
		.filter( ( term ) => term.taxonomy === taxonomy )
		.map( ( term ) => ( {
			id: term.id,
			name: decodeEntities( term.name ),
		} ) );
}

/**
 * An entry's publish date as the date picker takes it: the site's local
 * date and time, with no offset. The row's date carries the site's offset,
 * so it's formatted in the site's time zone rather than cut down.
 *
 * @param {Entry} entry The entry.
 * @return {string} The date, as `YYYY-MM-DDTHH:mm:ss`, or '' with none.
 */
function getEntryDate( entry: Entry | undefined ) {
	return entry?.date ? formatDate( 'Y-m-d\\TH:i:s', entry.date ) : '';
}

/**
 * The date picker's value as the server takes it. The picker works in the
 * browser's time zone with no offset, so the value is read back through its
 * local parts, never cut from an ISO string, which would shift it by the
 * browser's offset.
 *
 * @param {string} value The picker's value.
 * @return {string} The date, as `YYYY-MM-DDTHH:mm:ss`.
 */
function toLocalDateTime( value: string ) {
	const picked = new Date( value );
	const pad = ( part: number ) => String( part ).padStart( 2, '0' );
	return `${ picked.getFullYear() }-${ pad( picked.getMonth() + 1 ) }-${ pad(
		picked.getDate()
	) }T${ pad( picked.getHours() ) }:${ pad( picked.getMinutes() ) }:${ pad(
		picked.getSeconds()
	) }`;
}

/**
 * Whether the site's time format shows AM/PM, read the way core's post
 * schedule control reads it, ignoring escaped characters.
 *
 * @return {boolean} Whether to use a 12-hour clock.
 */
function isTwelveHourClock() {
	return /a(?!\\)/i.test(
		getSettings()
			.formats.time.toLowerCase()
			.replace( /\\\\/g, '' )
			.split( '' )
			.reverse()
			.join( '' )
	);
}

/**
 * The entry's date as a row of the drawer, opening the date picker in a
 * popover beside the drawer, as the post editor does for a post's date.
 *
 * @param {Object}                    props          Component props.
 * @param {string}                    props.value    The date, as `YYYY-MM-DDTHH:mm:ss` in the site's time zone.
 * @param {( value: string ) => void} props.onChange Called with the picked date.
 */
function EntryDateField( {
	value,
	onChange,
}: {
	value: string;
	onChange: ( value: string ) => void;
} ) {
	const id = useInstanceId( EntryDateField, 'entry-date-field' ) as string;
	const [ anchor, setAnchor ] = useState< HTMLElement | null >( null );
	const popoverProps = useMemo(
		() => ( {
			anchor,
			placement: 'left-start' as const,
			offset: 36,
			shift: true,
		} ),
		[ anchor ]
	);
	const label = value ? format( getSettings().formats.datetime, value ) : '';

	return (
		<div ref={ setAnchor }>
			<BaseControl
				__nextHasNoMarginBottom
				id={ id }
				label={ __( 'Date', 'newspack-rolling-coverage' ) }
				help={ __(
					'When the entry was published, which sets its place in the coverage. A published entry can’t be dated in the future.',
					'newspack-rolling-coverage'
				) }
			>
				<Dropdown
					popoverProps={ popoverProps }
					focusOnMount
					renderToggle={ ( { onToggle, isOpen } ) => (
						<Button
							__next40pxDefaultSize
							id={ id }
							variant="secondary"
							onClick={ onToggle }
							aria-expanded={ isOpen }
						>
							{ label }
						</Button>
					) }
					renderContent={ ( { onClose } ) => (
						<PublishDateTimePicker
							title={ __( 'Date', 'newspack-rolling-coverage' ) }
							currentDate={ value || null }
							onChange={ ( picked: string | null ) =>
								onChange(
									picked
										? toLocalDateTime( picked )
										: formatDate(
												'Y-m-d\\TH:i:s',
												new Date()
											)
								)
							}
							is12Hour={ isTwelveHourClock() }
							onClose={ onClose }
						/>
					) }
				/>
			</BaseControl>
		</div>
	);
}

/**
 * A key that tells picked terms apart: the ID of an existing term, the
 * name of a new one.
 *
 * @param {PickedTerm} term The term.
 * @return {string} The key.
 */
const termKey = ( term: PickedTerm ) =>
	term.id ? `id:${ term.id }` : `name:${ term.name.toLowerCase() }`;

/**
 * Whether two lists hold the same terms, in any order.
 *
 * @param {PickedTerm[]} a One list.
 * @param {PickedTerm[]} b The other.
 * @return {boolean} Whether they match.
 */
function isSameTerms( a: PickedTerm[], b: PickedTerm[] ) {
	const keys = new Set( a.map( termKey ) );
	return (
		a.length === b.length && b.every( ( t ) => keys.has( termKey( t ) ) )
	);
}

/**
 * The request shape for picked terms.
 *
 * @param {PickedTerm[]} terms The picked terms.
 * @return {EntryTermChanges} Existing term IDs and new term names.
 */
const toTermChanges = ( terms: PickedTerm[] ): EntryTermChanges => ( {
	ids: terms.filter( ( t ) => t.id ).map( ( t ) => t.id ),
	names: terms.filter( ( t ) => ! t.id ).map( ( t ) => t.name ),
} );

/**
 * Reassigns one or more entries: their author, categories and tags, and
 * for a single entry its slug and date.
 *
 * With one entry, the fields start with its own details and the save
 * sets exactly what they show. With several, Author starts empty unless
 * they share one, the categories and tags picked are added to every
 * entry, removing none, and Slug and Date aren't offered. Only the fields
 * the user changed are saved.
 *
 * Stays mounted so the drawer can play its slide-out, and starts afresh each
 * time it opens.
 *
 * @param {EntryDetailsDrawerProps} props Component props.
 */
function EntryDetailsDrawer( {
	isOpen,
	items,
	onClose,
	onChanged,
}: EntryDetailsDrawerProps ) {
	const config = useAdminContext();
	const {
		canAssignCategories,
		canCreateCategories,
		canAssignTags,
		canCreateTags,
	} = config.capabilities;
	const isBulk = items.length > 1;

	const sharedAuthor = useMemo( () => {
		const first = items[ 0 ]?._embedded?.author?.[ 0 ];
		return first &&
			items.every(
				( item ) => item._embedded?.author?.[ 0 ]?.id === first.id
			)
			? first
			: undefined;
	}, [ items ] );

	const initialCategories = useMemo(
		() => ( isBulk ? [] : getEntryTerms( items[ 0 ], 'category' ) ),
		[ items, isBulk ]
	);
	const initialTags = useMemo(
		() => ( isBulk ? [] : getEntryTerms( items[ 0 ], 'post_tag' ) ),
		[ items, isBulk ]
	);
	const initialSlug = isBulk ? '' : ( items[ 0 ]?.slug ?? '' );
	const initialDate = useMemo(
		() => ( isBulk ? '' : getEntryDate( items[ 0 ] ) ),
		[ items, isBulk ]
	);

	const [ picked, setPicked ] = useState<
		{ id: number; name: string } | undefined
	>(
		sharedAuthor && {
			id: sharedAuthor.id,
			name: decodeEntities( sharedAuthor.name ),
		}
	);
	const [ categories, setCategories ] =
		useState< PickedTerm[] >( initialCategories );
	const [ tags, setTags ] = useState< PickedTerm[] >( initialTags );
	const [ slug, setSlug ] = useState( initialSlug );
	const [ entryDate, setEntryDate ] = useState( initialDate );
	const authorId = picked?.id;
	const isAuthorDirty = Boolean( authorId ) && authorId !== sharedAuthor?.id;
	const isCategoriesDirty =
		canAssignCategories && ! isSameTerms( categories, initialCategories );
	const isTagsDirty = canAssignTags && ! isSameTerms( tags, initialTags );
	const isSlugDirty = ! isBulk && slug !== initialSlug;
	const isDateDirty = ! isBulk && entryDate !== initialDate;
	const isDirty =
		isAuthorDirty ||
		isCategoriesDirty ||
		isTagsDirty ||
		isSlugDirty ||
		isDateDirty;
	const [ search, setSearch ] = useState( '' );
	const { invalidateResolution } = useDispatch( coreStore );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	useLayoutEffect( () => {
		if ( ! isOpen ) {
			return;
		}
		setPicked(
			sharedAuthor && {
				id: sharedAuthor.id,
				name: decodeEntities( sharedAuthor.name ),
			}
		);
		setCategories( initialCategories );
		setTags( initialTags );
		setSlug( initialSlug );
		setEntryDate( initialDate );
		setSearch( '' );
		setIsBusy( false );
		setError( '' );
	}, [
		isOpen,
		sharedAuthor,
		initialCategories,
		initialTags,
		initialSlug,
		initialDate,
	] );

	const onFilterValueChange = useDebounce( setSearch, 300 );

	const authorHelp = isBulk
		? sprintf(
				/* translators: %d: number of entries. */
				_n(
					'The person you choose replaces the current author of %d entry.',
					'The person you choose replaces the current author of all %d entries.',
					items.length,
					'newspack-rolling-coverage'
				),
				items.length
			)
		: __(
				'The person you choose replaces this entry’s current author.',
				'newspack-rolling-coverage'
			);

	const { authors, isLoading } = useSelect(
		( select ) => {
			if ( ! isOpen ) {
				return { authors: null, isLoading: false };
			}
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
		[ isOpen, search ]
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
				label: picked.name,
			} );
		}
		return fetched;
	}, [ authors, picked ] );

	const handleSubmit = async () => {
		if ( ! isDirty ) {
			return;
		}

		const changes: EntryDetailsChanges = { append: isBulk };
		if ( isAuthorDirty ) {
			changes.author_id = authorId;
		}
		if ( isCategoriesDirty ) {
			changes.categories = toTermChanges( categories );
		}
		if ( isTagsDirty ) {
			changes.tags = toTermChanges( tags );
		}
		if ( isSlugDirty ) {
			changes.slug = slug;
		}
		if ( isDateDirty ) {
			changes.date = entryDate;
		}

		setIsBusy( true );
		setError( '' );

		const result = await editEntriesDetails(
			config.restBaseUrls.restNamespace,
			items.map( ( item ) => item.id ),
			changes
		);
		const updated = ( result.results ?? [] ).filter( ( r ) => r.updated );
		const failed = ( result.results ?? [] ).filter( ( r ) => ! r.updated );

		if ( ! result.success || ! updated.length ) {
			setIsBusy( false );
			setError(
				result.error ||
					failed[ 0 ]?.error ||
					__(
						'Failed to save the changes.',
						'newspack-rolling-coverage'
					)
			);
			return;
		}

		// Quick Edit reads entries from core-data's cache, which would still
		// hold the old details.
		updated.forEach( ( { entryId } ) =>
			invalidateResolution( 'getEntityRecord', [
				'postType',
				config.postType,
				entryId,
			] )
		);
		// So terms created by this save are suggested next time.
		(
			[
				[ 'category', changes.categories ],
				[ 'post_tag', changes.tags ],
			] as const
		 ).forEach( ( [ taxonomy, termChanges ] ) => {
			if ( termChanges?.names.length ) {
				invalidateResolution( 'getEntityRecords', [
					'taxonomy',
					taxonomy,
					TERMS_QUERY,
				] );
			}
		} );

		const savedSlug = updated[ 0 ].slug;
		if ( isSlugDirty && savedSlug !== undefined && savedSlug !== slug ) {
			notifySuccess(
				sprintf(
					/* translators: %s: the entry's slug. */
					__(
						'Changes saved. The slug is now “%s”.',
						'newspack-rolling-coverage'
					),
					savedSlug
				)
			);
		} else {
			notifySuccess(
				updated.length === 1
					? __( 'Changes saved.', 'newspack-rolling-coverage' )
					: sprintf(
							/* translators: %d: number of entries. */
							_n(
								'Changes saved for %d entry.',
								'Changes saved for %d entries.',
								updated.length,
								'newspack-rolling-coverage'
							),
							updated.length
						)
			);
		}
		if ( failed.length === 1 ) {
			notifyError(
				failed[ 0 ].error ||
					__(
						'Failed to save the changes.',
						'newspack-rolling-coverage'
					)
			);
		} else if ( failed.length ) {
			notifyError(
				sprintf(
					/* translators: %d: number of entries. */
					_n(
						'Couldn’t save the changes to %d entry.',
						'Couldn’t save the changes to %d entries.',
						failed.length,
						'newspack-rolling-coverage'
					),
					failed.length
				)
			);
		}
		setIsBusy( false );
		onChanged?.();
		onClose();
	};

	return (
		<Drawer.Root
			isOpen={ isOpen }
			isDirty={ isDirty && ! isBusy }
			onRequestClose={ () => {
				if ( ! isBusy ) {
					onClose();
				}
			} }
		>
			<Drawer.Header>
				<Drawer.Title>
					{ __( 'Reassign', 'newspack-rolling-coverage' ) }
				</Drawer.Title>
				<Drawer.CloseIcon />
			</Drawer.Header>
			<Drawer.Content>
				<Stack direction="column" gap="lg">
					{ error && (
						<Notice
							status="error"
							isDismissible={ false }
							politeness="assertive"
						>
							{ error }
						</Notice>
					) }
					<ComboboxControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Author', 'newspack-rolling-coverage' ) }
						help={ authorHelp }
						options={ options }
						value={ authorId ? String( authorId ) : null }
						onChange={ ( value ) => {
							const option = options.find(
								( o ) => o.value === value
							);
							setPicked(
								option
									? {
											id: Number( option.value ),
											name: option.label,
										}
									: undefined
							);
						} }
						onFilterValueChange={ onFilterValueChange }
						isLoading={ isLoading }
						allowReset={ false }
					/>
					{ canAssignCategories && (
						<TermTokenField
							isOpen={ isOpen }
							taxonomy="category"
							label={ __(
								'Categories',
								'newspack-rolling-coverage'
							) }
							help={
								isBulk
									? sprintf(
											/* translators: %d: number of entries. */
											_n(
												'The categories you choose are added to %d entry. Its current categories stay.',
												'The categories you choose are added to all %d entries. Their current categories stay.',
												items.length,
												'newspack-rolling-coverage'
											),
											items.length
										)
									: undefined
							}
							value={ categories }
							onChange={ setCategories }
							canCreate={ canCreateCategories }
							messages={ {
								added: __(
									'Category added.',
									'newspack-rolling-coverage'
								),
								removed: __(
									'Category removed.',
									'newspack-rolling-coverage'
								),
								remove: __(
									'Remove category',
									'newspack-rolling-coverage'
								),
								__experimentalInvalid: __(
									'Choose an existing category.',
									'newspack-rolling-coverage'
								),
							} }
						/>
					) }
					{ canAssignTags && (
						<TermTokenField
							isOpen={ isOpen }
							taxonomy="post_tag"
							label={ __( 'Tags', 'newspack-rolling-coverage' ) }
							help={
								isBulk
									? sprintf(
											/* translators: %d: number of entries. */
											_n(
												'The tags you choose are added to %d entry. Its current tags stay.',
												'The tags you choose are added to all %d entries. Their current tags stay.',
												items.length,
												'newspack-rolling-coverage'
											),
											items.length
										)
									: undefined
							}
							value={ tags }
							onChange={ setTags }
							canCreate={ canCreateTags }
							messages={ {
								added: __(
									'Tag added.',
									'newspack-rolling-coverage'
								),
								removed: __(
									'Tag removed.',
									'newspack-rolling-coverage'
								),
								remove: __(
									'Remove tag',
									'newspack-rolling-coverage'
								),
								__experimentalInvalid: __(
									'Choose an existing tag.',
									'newspack-rolling-coverage'
								),
							} }
						/>
					) }
					{ ! isBulk && (
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Slug', 'newspack-rolling-coverage' ) }
							help={ __(
								'The last part of the entry’s address.',
								'newspack-rolling-coverage'
							) }
							value={ slug }
							onChange={ setSlug }
							autoComplete="off"
						/>
					) }
					{ ! isBulk && (
						<EntryDateField
							value={ entryDate }
							onChange={ setEntryDate }
						/>
					) }
				</Stack>
			</Drawer.Content>
			<Drawer.Footer>
				<Drawer.Action variant="secondary" closes disabled={ isBusy }>
					{ __( 'Cancel', 'newspack-rolling-coverage' ) }
				</Drawer.Action>
				<Drawer.Action
					variant="primary"
					onClick={ handleSubmit }
					isBusy={ isBusy }
					disabled={ isBusy || ! isDirty }
				>
					{ __( 'Save', 'newspack-rolling-coverage' ) }
				</Drawer.Action>
			</Drawer.Footer>
		</Drawer.Root>
	);
}

export { EntryDetailsDrawer };
