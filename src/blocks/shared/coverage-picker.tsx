/**
 * WordPress dependencies
 */
import { ComboboxControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useDebounce } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import LoadingState from '../rolling-coverage/components/loading-state';

type CoverageTerm = {
	id: number;
	name?: string;
	meta?: Record< string, string >;
};

interface CoveragePickerProps {
	value: number;
	onChange: ( coverageId: number ) => void;
	taxonomySlug: string;
	statusMetaKey: string;
}

const SEARCH_QUERY = { per_page: 50, context: 'view' };

/**
 * A searchable picker for any coverage that isn't trashed, keeping the
 * chosen one listed even once it is.
 *
 * @param {Object}   props               Props.
 * @param {number}   props.value         The chosen coverage ID, or 0 for none yet.
 * @param {Function} props.onChange      Called with the new coverage ID.
 * @param {string}   props.taxonomySlug  The coverage taxonomy.
 * @param {string}   props.statusMetaKey The coverage status meta key.
 */
export default function CoveragePicker( {
	value,
	onChange,
	taxonomySlug,
	statusMetaKey,
}: CoveragePickerProps ) {
	const [ search, setSearch ] = useState( '' );
	const setSearchDebounced = useDebounce( setSearch, 300 );
	const lastFound = useRef< CoverageTerm[] >( [] );
	const [ hasLoaded, setHasLoaded ] = useState( false );

	const { found, current, isLoading } = useSelect(
		( select ) => {
			const core = select( coreStore ) as unknown as {
				getEntityRecords: (
					kind: string,
					name: string,
					query: Record< string, unknown >
				) => CoverageTerm[] | null;
				getEntityRecord: (
					kind: string,
					name: string,
					id: number,
					query: Record< string, string >
				) => CoverageTerm | null | undefined;
				hasFinishedResolution: (
					selector: string,
					args: unknown[]
				) => boolean;
			};
			const query = { ...SEARCH_QUERY, search };

			return {
				found: core.getEntityRecords( 'taxonomy', taxonomySlug, query ),
				isLoading: ! core.hasFinishedResolution( 'getEntityRecords', [
					'taxonomy',
					taxonomySlug,
					query,
				] ),
				current: value
					? core.getEntityRecord( 'taxonomy', taxonomySlug, value, {
							context: 'view',
						} )
					: null,
			};
		},
		[ taxonomySlug, search, value ]
	);

	if ( found ) {
		lastFound.current = found;
	}

	useEffect( () => {
		if ( ! isLoading ) {
			setHasLoaded( true );
		}
	}, [ isLoading ] );

	const options = useMemo( () => {
		const terms = ( found ?? lastFound.current ).filter(
			( term ) => term.meta?.[ statusMetaKey ] !== 'trash'
		);

		if ( current && ! terms.some( ( term ) => term.id === current.id ) ) {
			terms.unshift( current );
		}

		return terms.map( ( term ) => ( {
			value: String( term.id ),
			label: decodeEntities( term.name ?? String( term.id ) ),
		} ) );
	}, [ found, current, statusMetaKey ] );

	if ( ! hasLoaded && isLoading ) {
		return (
			<LoadingState
				label={ __(
					'Loading coverages…',
					'newspack-rolling-coverage'
				) }
			/>
		);
	}

	return (
		<ComboboxControl
			__next40pxDefaultSize
			label={ __( 'Choose a coverage', 'newspack-rolling-coverage' ) }
			hideLabelFromVision
			placeholder={ __(
				'Search for a coverage…',
				'newspack-rolling-coverage'
			) }
			value={ value ? String( value ) : null }
			options={ options }
			onChange={ ( next ) => onChange( parseInt( next ?? '', 10 ) || 0 ) }
			onFilterValueChange={ setSearchDebounced }
			isLoading={ isLoading }
		/>
	);
}
