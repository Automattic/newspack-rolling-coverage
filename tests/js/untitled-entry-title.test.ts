/**
 * An editable layout's own Post Title blocks preview an untitled entry the
 * way the site renders it: no title, or the entry's opening words in a title
 * that links to its entry, never core's "(no title)" placeholder. The layout
 * picker hands its untitled sample entry to the editable Entry group.
 */
import './editor-config';

import { render, renderHook, waitFor } from '@testing-library/react';
import { createBlocksFromInnerBlocksTemplate } from '@wordpress/blocks';
import { createElement } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';

import { getBuiltInLayouts } from '../../src/blocks/rolling-coverage/layouts';
import { useLayoutPreview } from '../../src/blocks/rolling-coverage/layout';
import { useSampleEntries } from '../../src/blocks/rolling-coverage/samples';
import { feedItems } from '../../src/blocks/rolling-coverage/template';
import UntitledEntriesContext from '../../src/blocks/rolling-coverage/untitled-entry-title';
import type {
	EntryContext,
	TemplateBlocks,
} from '../../src/blocks/rolling-coverage/types';
import { registerBlocks } from './layout-templates';

jest.mock( '@wordpress/block-editor', () => ( {
	...jest.requireActual( '@wordpress/block-editor' ),
	useBlockProps: () => ( { className: 'wp-block-post-title' } ),
} ) );

/** How many entries each layout picker card previews. */
const PICKER_ENTRIES = 3;

const ENTRY_LINK_CLASS = 'newspack-rolling-coverage-entry-link';

/**
 * Stands in for core's Post Title edit, which prints "(no title)" for a post
 * without one.
 *
 * @param {Object} props                Block edit props.
 * @param {Object} props.context        Block context.
 * @param {number} props.context.postId The entry the block previews.
 */
function PostTitleEdit( { context }: { context: { postId: number } } ) {
	return createElement(
		'h2',
		null,
		context.postId === 1 ? 'Polls close at 8' : '(no title)'
	);
}

const FilteredEdit = applyFilters(
	'editor.BlockEdit',
	PostTitleEdit
) as typeof PostTitleEdit;

/**
 * Renders a block's edit through the editor's filters, previewing the entry
 * given.
 *
 * @param {Object}             block           The block.
 * @param {string}             block.name      Block name.
 * @param {Object}             block.attrs     Block attributes.
 * @param {number}             postId          The entry the block previews.
 * @param {Map<number,string>} untitledEntries Untitled entries' opening words.
 */
function renderTitle(
	{ name, attrs }: { name: string; attrs: Record< string, unknown > },
	postId: number,
	untitledEntries: Map< number, string >
) {
	return render(
		createElement(
			UntitledEntriesContext.Provider,
			{ value: untitledEntries },
			createElement(
				FilteredEdit as unknown as string,
				{
					name,
					attributes: attrs,
					context: { postId, postType: 'entry', queryId: 0 },
				} as never
			)
		)
	);
}

describe( 'an editable Post Title', () => {
	const untitledEntries = new Map( [
		[ 2, 'Halvorsen at full stretch' ],
		[ 3, '' ],
	] );

	it( 'shows nothing for an untitled entry', () => {
		const { container } = renderTitle(
			{ name: 'core/post-title', attrs: { level: 2 } },
			2,
			untitledEntries
		);

		expect( container.textContent ).toBe( '' );
	} );

	it( "shows an untitled entry's opening words where the title links to the entry", () => {
		const { container } = renderTitle(
			{
				name: 'core/post-title',
				attrs: { level: 4, isLink: true, className: ENTRY_LINK_CLASS },
			},
			2,
			untitledEntries
		);
		const title = container.querySelector( 'h4.wp-block-post-title' );

		expect( title?.querySelector( 'a' )?.textContent ).toBe(
			'Halvorsen at full stretch'
		);
		expect( container.textContent ).not.toContain( '(no title)' );
	} );

	it( 'shows nothing for an untitled entry without opening words', () => {
		const { container } = renderTitle(
			{
				name: 'core/post-title',
				attrs: { level: 4, isLink: true, className: ENTRY_LINK_CLASS },
			},
			3,
			untitledEntries
		);

		expect( container.textContent ).toBe( '' );
	} );

	it( "leaves a titled entry's title to core", () => {
		const { container } = renderTitle(
			{ name: 'core/post-title', attrs: { level: 2 } },
			1,
			untitledEntries
		);

		expect( container.textContent ).toBe( 'Polls close at 8' );
	} );

	it( 'leaves other blocks alone', () => {
		const { container } = renderTitle(
			{ name: 'core/heading', attrs: {} },
			2,
			untitledEntries
		);

		expect( container.textContent ).toBe( '(no title)' );
	} );
} );

describe( "a layout preview's untitled entries", () => {
	// Bulletin, which has a Full Story Entry group.
	let bulletin: TemplateBlocks;

	beforeAll( () => {
		registerBlocks();
		bulletin = feedItems(
			createBlocksFromInnerBlocksTemplate(
				getBuiltInLayouts()[ 0 ].template()
			) as unknown as TemplateBlocks
		);
	} );

	/**
	 * The sample entries a layout picker card previews.
	 *
	 * @return {Promise<EntryContext[]>} The samples, newest first.
	 */
	async function pickerSamples(): Promise< EntryContext[] > {
		const { result } = renderHook( () => useSampleEntries( true ) );

		await waitFor( () =>
			expect( result.current.length ).toBeGreaterThan( 0 )
		);

		return result.current.slice( 0, PICKER_ENTRIES );
	}

	it( "list the layout picker's untitled sample, with its opening words", async () => {
		const samples = await pickerSamples();
		const untitled = samples.find(
			( context ) => context.hasTitle === false
		) as EntryContext;
		const { result } = renderHook( () =>
			useLayoutPreview( bulletin, samples, PICKER_ENTRIES )
		);

		expect( untitled.fallbackTitle ).toMatch(
			/^Halvorsen at full stretch/
		);
		expect( [ ...result.current.untitledEntries ] ).toEqual( [
			[ untitled.postId, untitled.fallbackTitle ],
		] );
	} );

	/**
	 * An untitled Full Story entry, whose post has the title given.
	 *
	 * @param {string} postTitle The breakout post's title.
	 * @return {EntryContext} The entry.
	 */
	function untitledFullStory( postTitle: string ): EntryContext {
		return {
			postId: 5,
			postType: 'entry',
			queryId: 0,
			hasBreakout: true,
			hasTitle: false,
			fallbackTitle: 'Riverbend rehearsed',
			breakout: {
				title: postTitle,
				summary: '',
				url: '#',
				label: '',
				imageId: 0,
			},
		};
	}

	it( 'count an untitled Full Story entry as titled when its post has a title', () => {
		const { result } = renderHook( () =>
			useLayoutPreview(
				bulletin,
				[ untitledFullStory( 'How Riverbend turned a corner' ) ],
				10
			)
		);

		expect( result.current.untitledEntries.size ).toBe( 0 );
	} );

	it( 'count an untitled Full Story entry as untitled when its post has no title either', () => {
		const { result } = renderHook( () =>
			useLayoutPreview( bulletin, [ untitledFullStory( '' ) ], 10 )
		);

		expect( [ ...result.current.untitledEntries ] ).toEqual( [
			[ 5, 'Riverbend rehearsed' ],
		] );
	} );
} );
