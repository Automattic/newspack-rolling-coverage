/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { parse } from '@wordpress/block-serialization-default-parser';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

const FEED_BLOCK = 'newspack-rolling-coverage/rolling-coverage';
const PATTERN_BLOCK = 'core/block';
const POST_CONTENT_BLOCK = 'core/post-content';
const TEMPLATE_TYPES = [ 'wp_template', 'wp_template_part', 'wp_block' ];
const VIEW_CONTEXT = { context: 'view' };
const PARSED_CACHE_LIMIT = 50;

type CoverageTerm = { id: number; meta?: Record< string, string > };
type ParsedBlock = {
	blockName: string | null;
	attrs: Record< string, unknown > | null;
	innerBlocks: ParsedBlock[];
};
type FeedItem = { coverageId: number } | { ref: number };
type PostRecord = {
	status?: string;
	password?: string;
	content?: string | { raw?: string; protected?: boolean };
} & Record< string, unknown >;

interface BlockCoverageOptions {
	feedCoverageId: unknown;
	postId: unknown;
	postType: unknown;
	chosenId: number;
	taxonomySlug: string;
	statusMetaKey: string;
	sourceEntryField: string;
}

const parsedFeeds = new Map< string, FeedItem[] >();

/**
 * The uncapped feeds and synced patterns in parsed blocks, in page order.
 *
 * @param {Object[]} blocks Parsed blocks.
 * @return {Object[]} Feed coverage IDs and pattern refs.
 */
function collectFeedItems( blocks: ParsedBlock[] ): FeedItem[] {
	return blocks.flatMap( ( block ): FeedItem[] => {
		if ( block.blockName === FEED_BLOCK ) {
			return block.attrs?.latestOnly
				? []
				: [ { coverageId: Number( block.attrs?.coverageId ) || 0 } ];
		}

		if ( block.blockName === PATTERN_BLOCK ) {
			return [ { ref: Number( block.attrs?.ref ) || 0 } ];
		}

		return collectFeedItems( block.innerBlocks ?? [] );
	} );
}

/**
 * The feed items in saved content, parsed once per distinct content while
 * that content is among the most recently parsed.
 *
 * @param {string} content Saved post content.
 * @return {Object[]} Feed coverage IDs and pattern refs.
 */
function feedItems( content: string ): FeedItem[] {
	let items = parsedFeeds.get( content );

	if ( ! items ) {
		items = collectFeedItems( parse( content ) );

		if ( parsedFeeds.size >= PARSED_CACHE_LIMIT ) {
			parsedFeeds.delete( parsedFeeds.keys().next().value as string );
		}

		parsedFeeds.set( content, items );
	}

	return items;
}

/**
 * The saved content of a post record.
 *
 * @param {Object} record Post record, with raw content.
 * @return {string} Raw content.
 */
function rawContent( record: PostRecord ): string {
	return typeof record.content === 'string'
		? record.content
		: ( record.content?.raw ?? '' );
}

/**
 * Whether a post record is password protected, which keeps the site from
 * reading its content.
 *
 * @param {Object} record Post record.
 * @return {boolean} Whether it has a password.
 */
function isProtected( record: PostRecord ): boolean {
	return (
		!! record.password ||
		( typeof record.content === 'object' && !! record.content.protected )
	);
}

/**
 * The coverage a block shows or follows, decided as the site decides it:
 * the Rolling Coverage block it sits in; else its chosen coverage while that
 * exists and isn't trashed; else the first uncapped feed in the post it is
 * about, or, for a breakout post, its source entry's coverage. Templates and
 * synced patterns edited on their own have no page, so only a chosen
 * coverage counts there.
 *
 * The post is the one being edited, or, inside a Query Loop, the item the
 * block renders for. With the template shown, only feeds in the post
 * content count. An item's feeds come from its saved content, following
 * published synced patterns; its content and source entry are only readable
 * in edit context, so an item the user can't edit has no coverage here, and
 * neither does a password-protected one, as on the site.
 *
 * @param {Object} options                  Options.
 * @param {*}      options.feedCoverageId   The coverage in the block's context, if any.
 * @param {*}      options.postId           The post ID in the block's context, if any.
 * @param {*}      options.postType         The post type in the block's context, if any.
 * @param {number} options.chosenId         The block's chosen coverage ID; 0 for Automatic.
 * @param {string} options.taxonomySlug     The coverage taxonomy.
 * @param {string} options.statusMetaKey    The coverage status meta key.
 * @param {string} options.sourceEntryField The REST field holding a breakout post's source entry.
 * @return {Object} The coverage ID (0 for none), whether it comes from a surrounding feed, whether a template is being edited, and whether the chosen coverage is gone.
 */
function useBlockCoverage( {
	feedCoverageId,
	postId,
	postType,
	chosenId,
	taxonomySlug,
	statusMetaKey,
	sourceEntryField,
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
				getCurrentPostId: () => number | string | undefined;
				getCurrentPostType: () => string | undefined;
				getEditedPostAttribute: ( attribute: string ) => unknown;
				getRenderingMode: () => string;
			};
			const core = select( coreStore ) as unknown as {
				getEntityRecord: (
					kind: string,
					name: string,
					id: number,
					query?: Record< string, string >
				) => ( CoverageTerm & PostRecord ) | null | undefined;
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
			const currentType = editor.getCurrentPostType() ?? '';
			const isTemplate = TEMPLATE_TYPES.includes( currentType );
			const isChosenGone = chosenId > 0 && ! isUsable( chosenId );

			if ( chosenId > 0 && ! isChosenGone ) {
				return {
					coverageId: chosenId,
					isInFeed: false,
					isTemplate,
					isChosenGone: false,
				};
			}

			if ( isTemplate ) {
				return {
					coverageId: 0,
					isInFeed: false,
					isTemplate,
					isChosenGone,
				};
			}

			const itemId = Number( postId ) || 0;
			const isItem =
				itemId > 0 &&
				typeof postType === 'string' &&
				( itemId !== Number( editor.getCurrentPostId() ) ||
					postType !== currentType );
			let feeds: number[];
			let entryId: number;

			if ( isItem ) {
				const readPattern = ( ref: number ) => {
					const pattern = core.getEntityRecord(
						'postType',
						'wp_block',
						ref,
						VIEW_CONTEXT
					);

					return pattern?.status === 'publish' &&
						! isProtected( pattern )
						? rawContent( pattern )
						: null;
				};
				const contentFeeds = (
					content: string,
					seenRefs: number[]
				): number[] =>
					feedItems( content ).flatMap( ( item ) => {
						if ( 'coverageId' in item ) {
							return [ item.coverageId ];
						}

						const pattern =
							item.ref && ! seenRefs.includes( item.ref )
								? readPattern( item.ref )
								: null;

						return pattern === null
							? []
							: contentFeeds( pattern, [
									...seenRefs,
									item.ref,
								] );
					} );
				const record = core.getEntityRecord(
					'postType',
					postType as string,
					itemId
				);
				const readable =
					record && ! isProtected( record ) ? record : null;

				feeds = readable
					? contentFeeds( rawContent( readable ), [] )
					: [];
				entryId = Number( readable?.[ sourceEntryField ] ) || 0;
			} else {
				const showsTemplate = editor.getRenderingMode() !== 'post-only';

				feeds = blockEditor
					.getBlocksByName( FEED_BLOCK )
					.filter(
						( id ) =>
							! showsTemplate ||
							blockEditor.getBlockParentsByBlockName(
								id,
								POST_CONTENT_BLOCK
							).length > 0
					)
					.map( ( id ) => blockEditor.getBlockAttributes( id ) )
					.filter( ( attributes ) => ! attributes?.latestOnly )
					.map( ( attributes ) => Number( attributes?.coverageId ) );
				entryId =
					Number(
						editor.getEditedPostAttribute( sourceEntryField )
					) || 0;
			}

			let coverageId =
				feeds.find( ( id ) => id > 0 && isUsable( id ) ) ?? 0;

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
		[
			isInFeed,
			feedCoverageId,
			postId,
			postType,
			chosenId,
			taxonomySlug,
			statusMetaKey,
			sourceEntryField,
		]
	);
}

export { useBlockCoverage };
