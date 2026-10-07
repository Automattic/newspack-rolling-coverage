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
 * The query for a taxonomy's suggestions, most used first; a search adds
 * `search` to it.
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

// Every suggestion query run, by taxonomy, so a save that creates terms can
// invalidate them all, searches included.
const usedQueries = new Map<
	string,
	Map< string, Record< string, unknown > >
>();

/**
 * The suggestion queries the field has run for a taxonomy.
 *
 * @param {string} taxonomy Taxonomy slug.
 * @return {Object[]} The queries, as passed to `getEntityRecords`.
 */
function getTermQueries( taxonomy: string ): Record< string, unknown >[] {
	return [ ...( usedQueries.get( taxonomy )?.values() ?? [] ) ];
}

/**
 * Records a suggestion query for getTermQueries().
 *
 * @param {string} taxonomy Taxonomy slug.
 * @param {Object} query    The query.
 * @return {Object} The query.
 */
function trackQuery< T extends Record< string, unknown > >(
	taxonomy: string,
	query: T
): T {
	const queries = usedQueries.get( taxonomy ) ?? new Map();
	queries.set( JSON.stringify( query ), query );
	usedQueries.set( taxonomy, queries );
	return query;
}

const toKey = ( label: string ) => label.trim().toLowerCase();

/**
 * Picks terms of one taxonomy from the site's existing ones, suggesting
 * the most used first and searching as the user types.
 *
 * Terms are told apart by ID. In a hierarchical taxonomy, a term with a
 * parent is labeled with the parent's name, such as "Local (Sport)", or
 * with its whole path, such as "Local (News › Sport)", when that label is
 * shared. A term whose label can't yet be told apart from another's isn't
 * suggested until it can. A label that matches no term is kept as a new
 * term (`id` 0) when `canCreate` is true, and refused otherwise.
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
						trackQuery( taxonomy, {
							...TERMS_QUERY,
							...( search ? { search } : {} ),
						} )
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

	const withParent = ( name: string, parentName: string ) =>
		sprintf(
			/* translators: 1: category name, 2: its parent category's name, or the path of its ancestors. */
			_x(
				'%1$s (%2$s)',
				'term with parent',
				'newspack-rolling-coverage'
			),
			name,
			parentName
		);

	// "Local (Sport)", or null while the parent hasn't loaded.
	const shortLabel = ( term: PickedTerm ): string | null => {
		if ( ! isHierarchical || ! term.parent ) {
			return term.name;
		}
		const parent = known.current.get( term.parent );
		return parent ? withParent( term.name, parent.name ) : null;
	};

	// "Local (News › Sport)", or null until every ancestor has loaded.
	const fullLabel = ( term: PickedTerm ): string | null => {
		if ( ! isHierarchical || ! term.parent ) {
			return term.name;
		}
		const path: string[] = [];
		const seen = new Set< number >();
		let parentId = term.parent;
		while ( parentId && ! seen.has( parentId ) ) {
			seen.add( parentId );
			const parent = known.current.get( parentId );
			if ( ! parent ) {
				return null;
			}
			path.unshift( parent.name );
			parentId = parent.parent ?? 0;
		}
		return withParent(
			term.name,
			path.reduce( ( ancestors, name ) =>
				sprintf(
					/* translators: 1: a category's ancestors, 2: the next category down the path. */
					_x(
						'%1$s › %2$s',
						'category path',
						'newspack-rolling-coverage'
					),
					ancestors,
					name
				)
			)
		);
	};

	const countLabels = ( labels: ( string | null )[] ) => {
		const counts = new Map< string, number >();
		labels.forEach( ( text ) => {
			if ( text !== null ) {
				counts.set(
					toKey( text ),
					( counts.get( toKey( text ) ) ?? 0 ) + 1
				);
			}
		} );
		return counts;
	};

	// Each known term's label, or null when it can't yet be told apart from
	// another term: its ancestors haven't loaded, or even its full path is
	// shared. Such a term isn't suggested or matched until it can be.
	const knownTerms = [ ...known.current.values() ];
	const shortCounts = countLabels( knownTerms.map( shortLabel ) );
	const labels = new Map< number, string | null >();
	knownTerms.forEach( ( term ) => {
		const short = shortLabel( term );
		labels.set(
			term.id,
			short !== null && shortCounts.get( toKey( short ) ) === 1
				? short
				: fullLabel( term )
		);
	} );
	const counts = countLabels( [ ...labels.values() ] );
	labels.forEach( ( text, id ) => {
		if ( text !== null && counts.get( toKey( text ) ) !== 1 ) {
			labels.set( id, null );
		}
	} );

	const byLabel = new Map< string, PickedTerm >();
	knownTerms.forEach( ( term ) => {
		const text = labels.get( term.id );
		if ( text ) {
			byLabel.set( toKey( text ), term );
		}
	} );

	const displayLabel = ( term: PickedTerm ) =>
		( term.id ? labels.get( term.id ) : null ) ??
		shortLabel( term ) ??
		term.name;

	const suggestions = ( found ?? EMPTY ).flatMap( ( term ) => {
		const text = labels.get( term.id );
		return text ? [ text ] : [];
	} );

	const handleChange = ( tokens: ( string | { value: string } )[] ) => {
		// Tokens already in the field map back to the terms they show first,
		// whatever the labels resolve to now.
		const current = new Map< string, PickedTerm >();
		value.forEach( ( term ) =>
			current.set( toKey( displayLabel( term ) ), term )
		);
		const picked = new Map< string, PickedTerm >();
		tokens.forEach( ( token ) => {
			const text = ( typeof token === 'string' ? token : token.value )
				.trim()
				.replace( /\s+/g, ' ' );
			if ( ! text ) {
				return;
			}
			const term =
				current.get( toKey( text ) ) ?? byLabel.get( toKey( text ) );
			if ( term ) {
				picked.set(
					term.id
						? `id:${ term.id }`
						: `name:${ toKey( term.name ) }`,
					term
				);
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
			value={ value.map( displayLabel ) }
			suggestions={ suggestions }
			maxSuggestions={ MAX_SUGGESTIONS }
			onChange={ handleChange }
			onInputChange={ onInputChange }
			messages={ messages }
		/>
	);
}

export { TermTokenField, getTermQueries };
