/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

const FEED_BLOCK = 'newspack-rolling-coverage/rolling-coverage';
const SOURCE_ENTRY_META = 'rolling_coverage_source_entry_id';
const TEMPLATE_TYPES = [ 'wp_template', 'wp_template_part' ];
const VIEW_CONTEXT = { context: 'view' };

type CoverageTerm = { id: number; meta?: Record< string, string > };

interface BlockCoverageOptions {
	feedCoverageId: unknown;
	chosenId: number;
	taxonomySlug: string;
	statusMetaKey: string;
}

/**
 * The coverage a block shows or follows, decided as the site decides it:
 * the Rolling Coverage block it sits in; else its chosen coverage while that
 * exists and isn't trashed; else the first uncapped feed on the page being
 * edited, or, for a breakout post, its source entry's coverage. Templates
 * have no page, so only a chosen coverage counts there.
 *
 * @param {Object} options                Options.
 * @param {*}      options.feedCoverageId The coverage in the block's context, if any.
 * @param {number} options.chosenId       The block's chosen coverage ID; 0 for Automatic.
 * @param {string} options.taxonomySlug   The coverage taxonomy.
 * @param {string} options.statusMetaKey  The coverage status meta key.
 * @return {Object} The coverage ID (0 for none), whether it comes from a surrounding feed, whether a template is being edited, and whether the chosen coverage is gone.
 */
function useBlockCoverage( {
	feedCoverageId,
	chosenId,
	taxonomySlug,
	statusMetaKey,
}: BlockCoverageOptions ): {
	coverageId: number;
	isInFeed: boolean;
	isTemplate: boolean;
	isChosenGone: boolean;
} {
	const isInFeed = feedCoverageId !== undefined;

	return useSelect(
		( select ) => {
			if ( isInFeed ) {
				return {
					coverageId: Number( feedCoverageId ) || 0,
					isInFeed: true,
					isTemplate: false,
					isChosenGone: false,
				};
			}

			const blockEditor = select( blockEditorStore ) as unknown as {
				getBlocksByName: ( name: string ) => string[];
				getBlockAttributes: ( id: string ) => {
					coverageId?: number;
					latestOnly?: boolean;
				} | null;
			};
			const editor = select( editorStore ) as unknown as {
				getCurrentPostType: () => string | undefined;
				getEditedPostAttribute: (
					attribute: string
				) => Record< string, unknown > | undefined;
			};
			const core = select( coreStore ) as unknown as {
				getEntityRecord: (
					kind: string,
					name: string,
					id: number,
					query: Record< string, string >
				) => CoverageTerm | null | undefined;
				getEntityRecords: (
					kind: string,
					name: string,
					query: Record< string, unknown >
				) => CoverageTerm[] | null;
				hasFinishedResolution: (
					selector: string,
					args: unknown[]
				) => boolean;
			};
			const isUsable = ( id: number ) => {
				const term = core.getEntityRecord(
					'taxonomy',
					taxonomySlug,
					id,
					VIEW_CONTEXT
				);
				const missing =
					term === null ||
					( term === undefined &&
						core.hasFinishedResolution( 'getEntityRecord', [
							'taxonomy',
							taxonomySlug,
							id,
							VIEW_CONTEXT,
						] ) );

				return ! missing && term?.meta?.[ statusMetaKey ] !== 'trash';
			};
			const isTemplate = TEMPLATE_TYPES.includes(
				editor.getCurrentPostType() ?? ''
			);
			const isChosenGone = chosenId > 0 && ! isUsable( chosenId );

			if ( chosenId > 0 && ! isChosenGone ) {
				return {
					coverageId: chosenId,
					isInFeed: false,
					isTemplate,
					isChosenGone: false,
				};
			}

			let coverageId = 0;

			if ( ! isTemplate ) {
				coverageId =
					blockEditor
						.getBlocksByName( FEED_BLOCK )
						.map( ( id ) => blockEditor.getBlockAttributes( id ) )
						.filter( ( attributes ) => ! attributes?.latestOnly )
						.map( ( attributes ) =>
							Number( attributes?.coverageId )
						)
						.find( ( id ) => id > 0 && isUsable( id ) ) ?? 0;
			}

			const entryId = isTemplate
				? 0
				: Number(
						editor.getEditedPostAttribute( 'meta' )?.[
							SOURCE_ENTRY_META
						]
					) || 0;

			if ( ! coverageId && entryId ) {
				coverageId =
					core
						.getEntityRecords( 'taxonomy', taxonomySlug, {
							post: entryId,
							orderby: 'id',
							order: 'asc',
							per_page: 100,
							context: 'view',
						} )
						?.find(
							( term ) => term.meta?.[ statusMetaKey ] !== 'trash'
						)?.id ?? 0;
			}

			return { coverageId, isInFeed: false, isTemplate, isChosenGone };
		},
		[ isInFeed, feedCoverageId, chosenId, taxonomySlug, statusMetaKey ]
	);
}

export { useBlockCoverage };
