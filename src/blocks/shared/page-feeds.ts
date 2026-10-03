/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { store as editorStore } from '@wordpress/editor';

const FEED_BLOCK = 'newspack-rolling-coverage/rolling-coverage';
const TEMPLATE_TYPES = [ 'wp_template', 'wp_template_part' ];
const VIEW_CONTEXT = { context: 'view' };

interface PageFeedsOptions {
	clientId: string;
	feedCoverageId: unknown;
	taxonomySlug: string;
	statusMetaKey: string;
}

/**
 * The coverages a block can follow: the Rolling Coverage block it sits in, or
 * else the uncapped, existing, untrashed ones on the page being edited.
 *
 * @param {Object} options                Options.
 * @param {string} options.clientId       The block's client ID.
 * @param {*}      options.feedCoverageId The coverage in the block's context, if any.
 * @param {string} options.taxonomySlug   The coverage taxonomy.
 * @param {string} options.statusMetaKey  The coverage status meta key.
 * @return {Object} The coverage IDs, whether the block can choose among them, and whether a template is being edited.
 */
function usePageFeeds( {
	clientId,
	feedCoverageId,
	taxonomySlug,
	statusMetaKey,
}: PageFeedsOptions ): {
	feeds: number[];
	canChoose: boolean;
	isTemplate: boolean;
} {
	const isInFeed = feedCoverageId !== undefined;

	const { feedKey, canChoose, isTemplate } = useSelect(
		( select ) => {
			if ( isInFeed ) {
				return {
					feedKey: String( Number( feedCoverageId ) || '' ),
					canChoose: false,
					isTemplate: false,
				};
			}

			const blockEditor = select( blockEditorStore ) as unknown as {
				getBlocksByName: ( name: string ) => string[];
				getBlockParentsByBlockName: (
					id: string,
					name: string
				) => string[];
				getBlockAttributes: ( id: string ) => {
					coverageId?: number;
					latestOnly?: boolean;
				} | null;
			};
			const editor = select( editorStore ) as unknown as {
				getCurrentPostType: () => string | undefined;
			};
			const core = select( coreStore ) as unknown as {
				getEntityRecord: (
					kind: string,
					name: string,
					id: number,
					query: Record< string, string >
				) => { meta?: Record< string, string > } | null | undefined;
				hasFinishedResolution: (
					selector: string,
					args: unknown[]
				) => boolean;
			};
			const inTemplate = TEMPLATE_TYPES.includes(
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
			const ids = inTemplate
				? []
				: blockEditor
						.getBlocksByName( FEED_BLOCK )
						.filter(
							( id: string ) =>
								! blockEditor.getBlockAttributes( id )
									?.latestOnly
						)
						.map(
							( id: string ) =>
								Number(
									blockEditor.getBlockAttributes( id )
										?.coverageId
								) || 0
						)
						.filter( Boolean )
						.filter( ( id: number ) => {
							const args = [
								'taxonomy',
								taxonomySlug,
								id,
								VIEW_CONTEXT,
							];
							const term = core.getEntityRecord(
								'taxonomy',
								taxonomySlug,
								id,
								VIEW_CONTEXT
							);
							const missing =
								term === null ||
								( term === undefined &&
									core.hasFinishedResolution(
										'getEntityRecord',
										args
									) );

							return (
								! missing &&
								term?.meta?.[ statusMetaKey ] !== 'trash'
							);
						} );

			return {
				feedKey: Array.from( new Set< number >( ids ) ).join( ',' ),
				canChoose: ! inTemplate && inContent,
				isTemplate: inTemplate,
			};
		},
		[ clientId, isInFeed, feedCoverageId, taxonomySlug, statusMetaKey ]
	);

	const feeds = useMemo(
		() => ( feedKey ? feedKey.split( ',' ).map( Number ) : [] ),
		[ feedKey ]
	);

	return { feeds, canChoose, isTemplate };
}

export { usePageFeeds };
