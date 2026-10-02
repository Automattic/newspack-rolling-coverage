/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	useBlockProps,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { humanTimeDiff } from '@wordpress/date';
import { store as editorStore } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { BADGE_CLASSES, badgeStatus } from '../shared/status-badges';
import type { CoverageStatusAttributes } from './types';

interface CoverageStatusConfig {
	statusLabels: Record< string, string >;
	statusMetaKey: string;
	taxonomySlug: string;
	taxonomyRestBase: string;
	entryPostType: string;
}

declare global {
	interface Window {
		newspackCoverageStatusBlock?: CoverageStatusConfig;
	}
}

const FEED_BLOCK = 'newspack-rolling-coverage/rolling-coverage';
const TEMPLATE_TYPES = [ 'wp_template', 'wp_template_part' ];
const SAMPLE_AGE_MS = 2 * 60 * 1000;

const config: CoverageStatusConfig = window.newspackCoverageStatusBlock ?? {
	statusLabels: {
		active: __( 'Live', 'newspack-rolling-coverage' ),
		paused: __( 'Paused', 'newspack-rolling-coverage' ),
		archived: __( 'Ended', 'newspack-rolling-coverage' ),
	},
	statusMetaKey: 'rolling_coverage_status',
	taxonomySlug: 'rolling_coverage',
	taxonomyRestBase: 'rolling-coverage',
	entryPostType: 'rolling_cov_entry',
};

const LABEL_FIELDS: Record< string, string > = {
	active: __( 'Live label', 'newspack-rolling-coverage' ),
	paused: __( 'Paused label', 'newspack-rolling-coverage' ),
	archived: __( 'Ended label', 'newspack-rolling-coverage' ),
};

/**
 * Editor for the Coverage Status block: the badge of the Rolling Coverage
 * block it follows on this page, or a sample where the page isn't known.
 *
 * @param {Object}   props               Block props.
 * @param {string}   props.clientId      Block client ID.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 */
export default function Edit( {
	clientId,
	attributes,
	setAttributes,
}: {
	clientId: string;
	attributes: CoverageStatusAttributes;
	setAttributes: ( attrs: Partial< CoverageStatusAttributes > ) => void;
} ) {
	const { coverageId, showLastUpdated, labels } = attributes;

	const { feeds, canChoose } = useSelect(
		( select ) => {
			const blockEditor = select( blockEditorStore ) as unknown as {
				getBlocksByName: ( name: string ) => string[];
				getBlockParentsByBlockName: (
					id: string,
					name: string
				) => string[];
				getBlockAttributes: ( id: string ) => {
					coverageId?: number;
				} | null;
			};
			const editor = select( editorStore ) as unknown as {
				getCurrentPostType: () => string | undefined;
			};
			const isTemplate = TEMPLATE_TYPES.includes(
				editor.getCurrentPostType() ?? ''
			);
			const showsTemplate =
				blockEditor.getBlocksByName( 'core/post-content' ).length > 0;
			const inContent =
				! showsTemplate ||
				blockEditor.getBlockParentsByBlockName(
					clientId,
					'core/post-content'
				).length > 0;
			const ids = isTemplate
				? []
				: blockEditor
						.getBlocksByName( FEED_BLOCK )
						.map(
							( id: string ) =>
								Number(
									blockEditor.getBlockAttributes( id )
										?.coverageId
								) || 0
						)
						.filter( Boolean );

			return {
				feeds: Array.from( new Set< number >( ids ) ),
				canChoose: ! isTemplate && inContent,
			};
		},
		[ clientId ]
	);

	const followed = feeds.includes( coverageId )
		? coverageId
		: ( feeds[ 0 ] ?? 0 );

	const { names, status, newest } = useSelect(
		( select ) => {
			const core = select( coreStore ) as unknown as {
				getEntityRecord: (
					kind: string,
					name: string,
					id: number
				) => unknown;
				getEntityRecords: (
					kind: string,
					name: string,
					query: object
				) => unknown;
			};
			const coverageNames: Record< number, string > = {};

			feeds.forEach( ( id ) => {
				const term = core.getEntityRecord(
					'taxonomy',
					config.taxonomySlug,
					id
				) as { name?: string } | undefined;
				coverageNames[ id ] = term?.name ?? String( id );
			} );

			if ( ! followed ) {
				return { names: coverageNames, status: 'active', newest: null };
			}

			const coverage = core.getEntityRecord(
				'taxonomy',
				config.taxonomySlug,
				followed
			) as { meta?: Record< string, string > } | undefined;
			const entries = showLastUpdated
				? ( core.getEntityRecords( 'postType', config.entryPostType, {
						[ config.taxonomyRestBase ]: followed,
						per_page: 1,
						orderby: 'date',
						order: 'desc',
					} ) as { date_gmt?: string }[] | null )
				: null;

			return {
				names: coverageNames,
				status: badgeStatus( coverage?.meta?.[ config.statusMetaKey ] ),
				newest: entries?.[ 0 ]?.date_gmt ?? null,
			};
		},
		[ feeds, followed, showLastUpdated ]
	);

	const label =
		( typeof labels?.[ status ] === 'string' &&
			labels[ status ]?.trim() ) ||
		config.statusLabels[ status ];

	let updated: string | null = null;

	if ( showLastUpdated && status === 'active' ) {
		if ( ! followed ) {
			updated = humanTimeDiff( new Date( Date.now() - SAMPLE_AGE_MS ) );
		} else if ( newest ) {
			updated = humanTimeDiff( `${ newest }Z` );
		}
	}

	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Settings', 'newspack-rolling-coverage' ) }
				>
					<p>
						{ __(
							'Shows the status of the Rolling Coverage block on this page. Shows nothing on pages without one.',
							'newspack-rolling-coverage'
						) }
					</p>
					{ canChoose && feeds.length > 1 && (
						<SelectControl
							__next40pxDefaultSize
							label={ __(
								'Coverage',
								'newspack-rolling-coverage'
							) }
							value={ String(
								feeds.includes( coverageId ) ? coverageId : 0
							) }
							options={ [
								{
									value: '0',
									label: __(
										'First on the page',
										'newspack-rolling-coverage'
									),
								},
								...feeds.map( ( id ) => ( {
									value: String( id ),
									label: names[ id ],
								} ) ),
							] }
							onChange={ ( value: string ) =>
								setAttributes( {
									coverageId: parseInt( value, 10 ) || 0,
								} )
							}
						/>
					) }
					<ToggleControl
						label={ __(
							'Show last updated',
							'newspack-rolling-coverage'
						) }
						checked={ showLastUpdated }
						onChange={ ( value: boolean ) =>
							setAttributes( { showLastUpdated: value } )
						}
					/>
					{ Object.entries( LABEL_FIELDS ).map(
						( [ key, field ] ) => (
							<TextControl
								key={ key }
								__next40pxDefaultSize
								label={ field }
								placeholder={ config.statusLabels[ key ] }
								value={
									typeof labels?.[ key ] === 'string'
										? labels[ key ]
										: ''
								}
								onChange={ ( value: string ) =>
									setAttributes( {
										labels: { ...labels, [ key ]: value },
									} )
								}
							/>
						)
					) }
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<span
					className={ `newspack-ui__badge ${ BADGE_CLASSES[ status ] }` }
				>
					{ label }
				</span>
				{ updated && (
					<>
						{ ' ' }
						<span className="newspack-rolling-coverage-updated">
							{ sprintf(
								/* translators: %s: How long ago the newest entry was published, e.g. "2 minutes ago". */
								__( 'Updated %s', 'newspack-rolling-coverage' ),
								updated
							) }
						</span>
					</>
				) }
			</div>
		</>
	);
}
