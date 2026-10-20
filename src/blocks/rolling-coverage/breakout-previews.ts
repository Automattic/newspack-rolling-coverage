/**
 * WordPress dependencies
 */
import { dispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { escapeHTML } from '@wordpress/escape-html';

/**
 * Internal dependencies
 */
import type { BreakoutPreview } from './types';

/**
 * How far below an entry's ID its breakout preview's ID sits. Kept far from
 * the sample entries and their authors and images, whose negative IDs are
 * small.
 */
const BREAKOUT_PREVIEW_OFFSET = 1000000;

/**
 * The breakout preview loaded for each entry, by entry ID.
 */
const previews = new Map< number, { id: number; hasTitle: boolean } >();

/**
 * The ID of the post record that previews an entry's breakout post, so its
 * blocks in the Full Story Entry group read the post's title, summary and
 * image (see full-story-entry-context.tsx), with whether it has a title.
 * Undefined until receiveBreakoutPreviews() loads one for the entry.
 *
 * @param {unknown} entryId The entry's post ID, as block context holds it.
 * @return {Object|undefined} The record's ID and whether it has a title.
 */
function breakoutPreviewFor(
	entryId: unknown
): { id: number; hasTitle: boolean } | undefined {
	return typeof entryId === 'number' ? previews.get( entryId ) : undefined;
}

/**
 * Puts a post record for each entry's breakout post into the data store,
 * under a negative ID no real post can have, holding what the site shows
 * for it: its title, its summary as the excerpt, its link and its featured
 * image. Every selector core's Post Title, Post Excerpt and Post Featured
 * Image read it through is marked resolved, so nothing is fetched for it.
 *
 * @param {Object[]} entries The entries, each with its breakout post.
 */
function receiveBreakoutPreviews(
	entries: { postId: number; breakout?: BreakoutPreview | null }[]
): void {
	const core = dispatch( coreStore ) as unknown as {
		receiveEntityRecords: (
			kind: string,
			name: string,
			records: object[]
		) => void;
		finishResolution: ( selector: string, args: unknown[] ) => void;
		receiveUserPermission: ( key: string, isAllowed: boolean ) => void;
	};
	const records = entries.flatMap( ( { postId, breakout } ) => {
		if ( ! breakout ) {
			return [];
		}

		const id = -( BREAKOUT_PREVIEW_OFFSET + postId );
		const summary = escapeHTML( breakout.summary );

		previews.set( postId, { id, hasTitle: breakout.title !== '' } );

		return [
			{
				id,
				type: 'post',
				status: 'publish',
				title: {
					raw: breakout.title,
					rendered: escapeHTML( breakout.title ),
				},
				excerpt: {
					raw: breakout.summary,
					rendered: summary ? `<p>${ summary }</p>` : '',
					protected: false,
				},
				content: { raw: '', rendered: '', protected: false },
				featured_media: breakout.imageId,
				link: breakout.url,
				meta: {},
			},
		];
	} );

	if ( ! records.length ) {
		return;
	}

	core.receiveEntityRecords( 'postType', 'post', records );
	records.forEach( ( { id } ) => {
		[
			'getEntityRecord',
			'getEditedEntityRecord',
			'getRawEntityRecord',
		].forEach( ( selector ) =>
			core.finishResolution( selector, [ 'postType', 'post', id ] )
		);
		core.receiveUserPermission( `update/postType/post/${ id }`, false );
		core.finishResolution( 'canUser', [
			'update',
			{ kind: 'postType', name: 'post', id },
		] );
	} );
}

export { breakoutPreviewFor, receiveBreakoutPreviews };
