/**
 * WordPress dependencies
 */
import { useLayoutEffect, useRef, useState } from '@wordpress/element';
import { FormTokenField } from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import { sprintf, _x } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PickedTerm } from '../types';

const MAX_SUGGESTIONS = 100;

/**
 * The query for a taxonomy's suggestions with no search typed, most used
 * first. The drawer invalidates it after creating terms.
 */
const TERMS_QUERY = {
	per_page: MAX_SUGGESTIONS,
	orderby: 'count',
	order: 'desc',
	_fields: 'id,name,parent',
	context: 'view',
};

interface TermTokenFieldProps {
	/** Whether the drawer holding the field is open; it only fetches then. */
	isOpen: boolean;
	taxonomy: string;
	/** Whether terms can have parents, so labels name the parent. */
	isHierarchical: boolean;
	label: string;
	help?: string;
	value: PickedTerm[];
	onChange: ( terms: PickedTerm[] ) => void;
	canCreate: boolean;
	messages: {
		added: string;
		removed: string;
		remove: string;
		__experimentalInvalid: string;
	};
}

const EMPTY: PickedTerm[] = [];

const toKey = ( label: string ) => label.trim().toLowerCase();

/**
 * Picks terms of one taxonomy from the site's existing ones, suggesting
 * the most used first and searching as the user types.
 *
 * Terms are told apart by ID. In a hierarchical taxonomy, a term with a
 * parent is labeled with the parent's name, such as "Local (Sport)", so two
 * terms with the same name under different parents stay distinct. A label
 * that matches no term is kept as a new term (`id` 0) when `canCreate` is
 * true, and refused otherwise.
 *
 * @param {TermTokenFieldProps} props Component props.
 */
function TermTokenField( {
	isOpen,
	taxonomy,
	isHierarchical,
	label,
	help,
	value,
	onChange,
	canCreate,
	messages,
}: TermTokenFieldProps ) {
	const [ search, setSearch ] = useState( '' );
	const onInputChange = useDebounce( setSearch, 300 );

	useLayoutEffect( () => {
		if ( isOpen ) {
			setSearch( '' );
		}
	}, [ isOpen ] );

	const found = useSelect(
		( select ) =>
			isOpen
				? ( select( coreStore ).getEntityRecords(
						'taxonomy',
						taxonomy,
						{
							...TERMS_QUERY,
							...( search ? { search } : {} ),
						}
					) as PickedTerm[] | null )
				: null,
		[ isOpen, taxonomy, search ]
	);

	// Every term seen so far, by ID, so a token picked from an earlier search
	// still resolves once the suggestions have moved on.
	const known = useRef( new Map< number, PickedTerm >() );
	( found ?? EMPTY ).forEach( ( term ) =>
		known.current.set( term.id, {
			id: term.id,
			name: decodeEntities( term.name ),
			parent: term.parent,
		} )
	);
	value.forEach( ( term ) => {
		if ( term.id ) {
			known.current.set( term.id, term );
		}
	} );

	const missingParents = isHierarchical
		? [ ...known.current.values() ]
				.map( ( term ) => term.parent ?? 0 )
				.filter(
					( parent, index, all ) =>
						parent &&
						! known.current.has( parent ) &&
						all.indexOf( parent ) === index
				)
				.sort( ( a, b ) => a - b )
		: [];
	const parents = useSelect(
		( select ) =>
			isOpen && missingParents.length
				? ( select( coreStore ).getEntityRecords(
						'taxonomy',
						taxonomy,
						{
							include: missingParents.join( ',' ),
							per_page: MAX_SUGGESTIONS,
							_fields: 'id,name,parent',
							context: 'view',
						}
					) as PickedTerm[] | null )
				: null,
		[ isOpen, taxonomy, missingParents.join( ',' ) ]
	);
	( parents ?? EMPTY ).forEach( ( term ) => {
		if ( ! known.current.has( term.id ) ) {
			known.current.set( term.id, {
				id: term.id,
				name: decodeEntities( term.name ),
				parent: term.parent,
			} );
		}
	} );

	const labelOf = ( term: PickedTerm ) => {
		const parent = term.parent
			? known.current.get( term.parent )
			: undefined;
		return parent
			? sprintf(
					/* translators: 1: category name, 2: its parent category's name. */
					_x(
						'%1$s (%2$s)',
						'term with parent',
						'newspack-rolling-coverage'
					),
					term.name,
					parent.name
				)
			: term.name;
	};

	const suggestions = ( found ?? EMPTY ).map( ( term ) =>
		labelOf( known.current.get( term.id ) ?? term )
	);

	const byLabel = new Map< string, PickedTerm >();
	known.current.forEach( ( term ) =>
		byLabel.set( toKey( labelOf( term ) ), term )
	);

	const handleChange = ( tokens: ( string | { value: string } )[] ) => {
		const picked = new Map< string, PickedTerm >();
		tokens.forEach( ( token ) => {
			const text = ( typeof token === 'string' ? token : token.value )
				.trim()
				.replace( /\s+/g, ' ' );
			if ( ! text ) {
				return;
			}
			const term = byLabel.get( toKey( text ) );
			if ( term ) {
				picked.set( `id:${ term.id }`, term );
			} else if ( canCreate ) {
				picked.set( `name:${ toKey( text ) }`, { id: 0, name: text } );
			}
		} );
		onChange( [ ...picked.values() ] );
	};

	return (
		<FormTokenField
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			__experimentalExpandOnFocus
			__experimentalAutoSelectFirstMatch={ ! canCreate }
			__experimentalValidateInput={
				canCreate
					? undefined
					: ( token: string ) => byLabel.has( toKey( token ) )
			}
			label={ label }
			help={ help }
			value={ value.map( labelOf ) }
			suggestions={ suggestions }
			maxSuggestions={ MAX_SUGGESTIONS }
			onChange={ handleChange }
			onInputChange={ onInputChange }
			messages={ messages }
		/>
	);
}

export { TermTokenField, TERMS_QUERY };
