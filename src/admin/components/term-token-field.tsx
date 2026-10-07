/**
 * WordPress dependencies
 */
import { useLayoutEffect, useMemo, useRef, useState } from '@wordpress/element';
import { FormTokenField } from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';

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
	_fields: 'id,name',
	context: 'view',
};

interface TermTokenFieldProps {
	/** Whether the drawer holding the field is open; it only fetches then. */
	isOpen: boolean;
	taxonomy: string;
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

const toKey = ( name: string ) => name.trim().toLowerCase();

/**
 * Picks terms of one taxonomy from the site's existing ones, suggesting
 * the most used first and searching as the user types. A name that matches
 * no term is kept as a new term (`id` 0) when `canCreate` is true, and
 * refused otherwise.
 *
 * @param {TermTokenFieldProps} props Component props.
 */
function TermTokenField( {
	isOpen,
	taxonomy,
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

	// Every term seen so far, by name, so a token picked from an earlier
	// search still resolves to its ID once the suggestions have moved on.
	const known = useRef( new Map< string, PickedTerm >() );
	const suggestions = useMemo( () => {
		const names: string[] = [];
		( found ?? [] ).forEach( ( term ) => {
			const name = decodeEntities( term.name );
			known.current.set( toKey( name ), { id: term.id, name } );
			names.push( name );
		} );
		return names;
	}, [ found ] );
	value.forEach( ( term ) => {
		if ( term.id ) {
			known.current.set( toKey( term.name ), term );
		}
	} );

	const handleChange = ( tokens: ( string | { value: string } )[] ) => {
		const picked = new Map< string, PickedTerm >();
		tokens.forEach( ( token ) => {
			const name = ( typeof token === 'string' ? token : token.value )
				.trim()
				.replace( /\s+/g, ' ' );
			const key = toKey( name );
			if ( ! name || picked.has( key ) ) {
				return;
			}
			const term = known.current.get( key );
			if ( term ) {
				picked.set( key, term );
			} else if ( canCreate ) {
				picked.set( key, { id: 0, name } );
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
					: ( token: string ) => known.current.has( toKey( token ) )
			}
			label={ label }
			help={ help }
			value={ value.map( ( term ) => term.name ) }
			suggestions={ suggestions }
			maxSuggestions={ MAX_SUGGESTIONS }
			onChange={ handleChange }
			onInputChange={ onInputChange }
			messages={ messages }
		/>
	);
}

export { TermTokenField, TERMS_QUERY };
