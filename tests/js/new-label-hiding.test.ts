import './editor-config';

import {
	NEW_LABEL_BLOCK_NAME,
	hiddenNewLabelIds,
} from '../../src/blocks/rolling-coverage/template';

const label = ( id: string ) => ( {
	name: NEW_LABEL_BLOCK_NAME,
	clientId: id,
	attributes: {},
	innerBlocks: [],
} );
const group = ( id: string, className: string ) => ( {
	name: 'core/group',
	clientId: id,
	attributes: { className },
	innerBlocks: [ label( `${ id }-label` ) ],
} );

const card = group( 'card', 'newspack-rolling-coverage-pinned-card' );
const entry = group( 'entry', 'newspack-rolling-coverage-regular-entry' );
const newest = { postId: 1 };
const other = { postId: 2 };

describe( 'hiddenNewLabelIds', () => {
	it( 'hides the label in groups that preview another sample', () => {
		expect(
			hiddenNewLabelIds(
				[ card, entry ],
				[],
				{ card: other, entry: newest },
				newest
			)
		).toEqual( [ 'card-label' ] );
	} );

	it( 'reaches a Pinned card inside the Pinned stack', () => {
		const stack = {
			name: 'core/group',
			clientId: 'stack',
			attributes: { className: 'newspack-rolling-coverage-pinned-stack' },
			innerBlocks: [ card ],
		};

		expect(
			hiddenNewLabelIds(
				[ entry, ...stack.innerBlocks ],
				stack.innerBlocks,
				{ card: other, entry: newest },
				newest
			)
		).toEqual( [ 'card-label' ] );
	} );

	it( 'hides nothing when every group previews the newest sample', () => {
		expect(
			hiddenNewLabelIds(
				[ card, entry ],
				[],
				{ card: newest, entry: newest },
				newest
			)
		).toEqual( [] );
	} );

	it( 'leaves other blocks alone', () => {
		const paragraph = {
			name: 'core/paragraph',
			clientId: 'card-paragraph',
			attributes: {
				className: 'newspack-rolling-coverage-new-label',
				content: 'New',
			},
			innerBlocks: [],
		};

		expect(
			hiddenNewLabelIds(
				[ { ...card, innerBlocks: [ paragraph ] }, entry ],
				[],
				{ card: other, entry: newest },
				newest
			)
		).toEqual( [] );
	} );
} );
