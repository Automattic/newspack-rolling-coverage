/**
 * WordPress dependencies
 */
import { ComboboxControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useMemo, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

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
 * A searchable picker for a coverage, with an "Automatic" choice (0) for the
 * page's own coverage.
 *
 * @param {Object}   props               Props.
 * @param {number}   props.value         The chosen coverage ID, or 0 for Automatic.
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

	const { found, current } = useSelect(
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
			};

			return {
				found: core.getEntityRecords( 'taxonomy', taxonomySlug, {
					...SEARCH_QUERY,
					search,
				} ),
				current: value
					? core.getEntityRecord( 'taxonomy', taxonomySlug, value, {
							context: 'view',
						} )
					: null,
			};
		},
		[ taxonomySlug, search, value ]
	);

	const options = useMemo( () => {
		const terms = ( found ?? [] ).filter(
			( term ) => term.meta?.[ statusMetaKey ] !== 'trash'
		);

		if ( current && ! terms.some( ( term ) => term.id === current.id ) ) {
			terms.unshift( current );
		}

		return [
			{
				value: '0',
				label: __( 'Automatic', 'newspack-rolling-coverage' ),
			},
			...terms.map( ( term ) => ( {
				value: String( term.id ),
				label: decodeEntities( term.name ?? String( term.id ) ),
			} ) ),
		];
	}, [ found, current, statusMetaKey ] );

	return (
		<ComboboxControl
			__next40pxDefaultSize
			label={ __( 'Coverage', 'newspack-rolling-coverage' ) }
			value={ String( value || 0 ) }
			options={ options }
			onChange={ ( next ) =>
				onChange( parseInt( next ?? '0', 10 ) || 0 )
			}
			onFilterValueChange={ setSearch }
		/>
	);
}
