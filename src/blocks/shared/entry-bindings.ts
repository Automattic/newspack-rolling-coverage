/**
 * WordPress dependencies
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { READ_MORE_TEXT_META_KEY } from '../rolling-coverage/config';

const ENTRY_BINDINGS_SOURCE = 'newspack-rolling-coverage/entry';

const PINNED_LABEL_CONTEXT = 'newspack-rolling-coverage/pinnedLabel';

type Binding = { args?: { key?: string } };

/**
 * Mirrors Entry_Bindings::get_value() in the editor. Links are resolved per
 * entry on the server, so the editor shows labels only.
 */
registerBlockBindingsSource( {
	name: ENTRY_BINDINGS_SOURCE,
	usesContext: [ 'postId', 'postType', PINNED_LABEL_CONTEXT ],
	getValues( {
		select,
		context,
		bindings,
	}: {
		select: ( store: typeof coreStore ) => {
			getEditedEntityRecord: (
				kind: string,
				name: string,
				key: number
			) => { meta?: Record< string, unknown > } | undefined;
		};
		context: {
			postId?: number;
			postType?: string;
			[ PINNED_LABEL_CONTEXT ]?: string;
		};
		bindings: Record< string, Binding >;
	} ) {
		const values: Record< string, string > = {};

		for ( const [ attribute, binding ] of Object.entries( bindings ) ) {
			if ( binding.args?.key === 'pinnedLabel' ) {
				values[ attribute ] =
					context[ PINNED_LABEL_CONTEXT ]?.trim() ||
					__( 'Pinned', 'newspack-rolling-coverage' );
				continue;
			}

			if ( binding.args?.key !== 'breakoutLabel' ) {
				values[ attribute ] = '';
				continue;
			}

			const meta =
				context.postId && context.postType
					? select( coreStore ).getEditedEntityRecord(
							'postType',
							context.postType,
							context.postId
						)?.meta
					: undefined;
			const label = meta?.[ READ_MORE_TEXT_META_KEY ];

			values[ attribute ] =
				typeof label === 'string' && label
					? label
					: __( 'Read more', 'newspack-rolling-coverage' );
		}

		return values;
	},
} );

export { ENTRY_BINDINGS_SOURCE, PINNED_LABEL_CONTEXT };
