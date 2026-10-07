/**
 * WordPress dependencies
 */
import { dispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { date as formatDate } from '@wordpress/date';
import { escapeHTML } from '@wordpress/escape-html';
import { useEffect, useState } from '@wordpress/element';
import { __, _x } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ENTRY_POST_TYPE, SAMPLE_AVATAR_URLS, SHOW_AVATARS } from './config';
import type { EntryContext } from './types';

const AUTHORS = [
	{ id: -900, name: 'Marisol Quinn', slug: 'marisol-quinn', initials: 'MQ' },
	{
		id: -901,
		name: 'Theo Abernathy',
		slug: 'theo-abernathy',
		initials: 'TA',
	},
	{ id: -902, name: 'Ines Okafor', slug: 'ines-okafor', initials: 'IO' },
];
const IMAGE_ID = -800;

/**
 * How many opening words an untitled entry's headline keeps, as
 * Entry_Bindings::UNTITLED_FALLBACK_WORDS.
 */
const UNTITLED_FALLBACK_WORDS = 15;

const SAMPLE_IMAGE =
	'data:image/svg+xml,' +
	encodeURIComponent(
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 675"><rect width="1200" height="675" fill="#dcdcde"/><g fill="none" stroke="#f6f7f7" stroke-width="6"><rect x="60" y="60" width="1080" height="555"/><line x1="600" y1="60" x2="600" y2="615"/><circle cx="600" cy="337.5" r="90"/><rect x="60" y="197.5" width="165" height="280"/><rect x="975" y="197.5" width="165" height="280"/></g></svg>'
	);

type Sample = {
	id: number;
	authorId: number;
	minutesAgo: number;
	title: string;
	content: string;
	pinned?: boolean;
	hasBreakout?: boolean;
	hasImage?: boolean;
};

/**
 * The headline the site gives an untitled entry without an excerpt: its
 * opening words (see Entry_Bindings::get_fallback_title()).
 *
 * @param {string} content The entry's text.
 * @return {string} The headline.
 */
function openingWords( content: string ): string {
	const text = content.trim();
	// wp_trim_words() counts characters where translators set the word count type to characters, as for Chinese and Japanese.
	const countsCharacters = _x(
		'words',
		'Word count type. Do not translate!'
	).startsWith( 'characters' );
	const units = countsCharacters
		? Array.from( text.replace( /\s+/g, ' ' ) )
		: text.split( /\s+/ );

	if ( units.length <= UNTITLED_FALLBACK_WORDS ) {
		return text;
	}

	return (
		units
			.slice( 0, UNTITLED_FALLBACK_WORDS )
			.join( countsCharacters ? '' : ' ' ) + '…'
	);
}

/**
 * One live coverage of a match, newest first, with an entry for each way an
 * entry can render.
 */
function getSamples(): Sample[] {
	return [
		{
			id: -101,
			authorId: -900,
			minutesAgo: 2,
			title: __(
				'Full time: Riverbend 2, Fresno Verde 1',
				'newspack-rolling-coverage'
			),
			content: __(
				"Two goals from Bree Kowalski and a stoppage-time save from Petra Halvorsen. The champions' first meeting with Fresno Verde since the final ended the way the final did.",
				'newspack-rolling-coverage'
			),
			pinned: true,
			hasBreakout: true,
		},
		{
			id: -102,
			authorId: -901,
			minutesAgo: 9,
			title: '',
			content: __(
				"Halvorsen at full stretch to push Renee Vargas's header over the bar, four minutes into stoppage time. The North Bank greets it like a third goal.",
				'newspack-rolling-coverage'
			),
		},
		{
			id: -103,
			authorId: -902,
			minutesAgo: 40,
			title: __(
				'Kowalski again, straight off the training ground',
				'newspack-rolling-coverage'
			),
			content: __(
				'Sonia Aguilar drives a corner to the near post, Josie Vandermeer flicks it on, and Kowalski turns it in from inside the six-yard box. 2–0 in the 61st minute.',
				'newspack-rolling-coverage'
			),
			hasImage: true,
			hasBreakout: true,
		},
		{
			id: -104,
			authorId: -901,
			minutesAgo: 55,
			title: __( '14,213 at DS Stadium', 'newspack-rolling-coverage' ),
			content: __(
				"The women's largest crowd of the season so far. Foundry Row was full two hours before kickoff.",
				'newspack-rolling-coverage'
			),
		},
		{
			id: -105,
			authorId: -900,
			minutesAgo: 120,
			title: __( 'Team news', 'newspack-rolling-coverage' ),
			content: __(
				'Bethany Osei rotates less than usual for the rematch. Vandermeer captains, Kowalski leads the line and Nia Fletcher plays behind her.',
				'newspack-rolling-coverage'
			),
		},
	];
}

let isLoaded = false;

/**
 * Puts the sample entries, their author and their image into the data store
 * under IDs no real post can have, marked as resolved so nothing is fetched.
 */
function loadSampleRecords(): void {
	if ( isLoaded ) {
		return;
	}
	isLoaded = true;

	const core = dispatch( coreStore ) as unknown as {
		receiveEntityRecords: (
			kind: string,
			name: string,
			records: object[],
			query?: object
		) => void;
		finishResolution: ( selector: string, args: unknown[] ) => void;
		receiveUserPermission: ( key: string, isAllowed: boolean ) => void;
	};

	const users = AUTHORS.map( ( { id, name, slug, initials } ) => {
		const avatar =
			SHOW_AVATARS && SAMPLE_AVATAR_URLS?.[ initials.toLowerCase() ];

		return {
			id,
			name,
			slug,
			...( avatar && {
				avatar_urls: { 24: avatar, 48: avatar, 96: avatar },
			} ),
		};
	} );
	core.receiveEntityRecords( 'root', 'user', users );
	core.receiveEntityRecords( 'root', 'user', users, { context: 'view' } );
	AUTHORS.forEach( ( { id } ) => {
		core.finishResolution( 'getEntityRecord', [ 'root', 'user', id ] );
		core.finishResolution( 'getEntityRecord', [
			'root',
			'user',
			id,
			{ context: 'view' },
		] );
		core.finishResolution( 'getUser', [ id ] );
		core.finishResolution( 'getUser', [ id, { context: 'view' } ] );
	} );

	core.receiveEntityRecords(
		'postType',
		'attachment',
		[
			{
				id: IMAGE_ID,
				type: 'attachment',
				source_url: SAMPLE_IMAGE,
				alt_text: __(
					'Illustration of a soccer field',
					'newspack-rolling-coverage'
				),
				media_type: 'image',
				mime_type: 'image/svg+xml',
				media_details: { sizes: {} },
			},
		],
		{ context: 'view' }
	);
	core.finishResolution( 'getEntityRecord', [
		'postType',
		'attachment',
		IMAGE_ID,
		{ context: 'view' },
	] );

	const now = Date.now();
	const records = getSamples().map( ( sample ) => {
		const timestamp = now - sample.minutesAgo * 60000;
		const paragraph = escapeHTML( sample.content );

		return {
			id: sample.id,
			type: ENTRY_POST_TYPE,
			status: 'publish',
			date: formatDate( 'Y-m-d\\TH:i:s', timestamp, undefined ),
			date_gmt: new Date( timestamp ).toISOString().slice( 0, 19 ),
			modified: formatDate( 'Y-m-d\\TH:i:s', timestamp, undefined ),
			title: { raw: sample.title, rendered: escapeHTML( sample.title ) },
			content: {
				raw: `<!-- wp:paragraph --><p>${ paragraph }</p><!-- /wp:paragraph -->`,
				rendered: `<p>${ paragraph }</p>`,
				protected: false,
			},
			excerpt: {
				raw: sample.content,
				rendered: `<p>${ paragraph }</p>`,
				protected: false,
			},
			author: sample.authorId,
			featured_media: sample.hasImage ? IMAGE_ID : 0,
			link: '#',
			meta: {},
		};
	} );

	core.receiveEntityRecords( 'postType', ENTRY_POST_TYPE, records );
	records.forEach( ( record ) => {
		core.finishResolution( 'getEntityRecord', [
			'postType',
			ENTRY_POST_TYPE,
			record.id,
		] );
		core.receiveUserPermission(
			`update/postType/${ ENTRY_POST_TYPE }/${ record.id }`,
			false
		);
		core.finishResolution( 'canUser', [
			'update',
			{ kind: 'postType', name: ENTRY_POST_TYPE, id: record.id },
		] );
	} );
}

/**
 * The sample entries' block contexts, loading their records the first time.
 * Empty until the records are in the data store.
 *
 * @param {boolean} enabled Whether the block is previewing samples.
 * @return {EntryContext[]} Sample entry contexts, newest first.
 */
export function useSampleEntries( enabled: boolean ): EntryContext[] {
	const [ contexts, setContexts ] = useState< EntryContext[] >( [] );

	useEffect( () => {
		if ( ! enabled ) {
			setContexts( ( previous ) => ( previous.length ? [] : previous ) );
			return;
		}

		loadSampleRecords();

		setContexts(
			getSamples().map( ( sample ) => ( {
				postId: sample.id,
				postType: ENTRY_POST_TYPE,
				queryId: 0,
				pinned: Boolean( sample.pinned ),
				hasBreakout: Boolean( sample.hasBreakout ),
				hasTitle: '' !== sample.title,
				hidesByline: false,
				fallbackTitle:
					'' === sample.title ? openingWords( sample.content ) : '',
			} ) )
		);
	}, [ enabled ] );

	return contexts;
}
