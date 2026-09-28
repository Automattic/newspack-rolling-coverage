/**
 * WordPress dependencies
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

const ENTRY_BINDINGS_SOURCE = 'newspack-rolling-coverage/entry';

type Binding = { args?: { key?: string } };

/**
 * Mirrors Entry_Bindings::get_value() in the editor. Links resolve to the
 * saved value only on the front end, so the editor shows labels alone.
 */
registerBlockBindingsSource( {
	name: ENTRY_BINDINGS_SOURCE,
	usesContext: [ 'postId', 'postType' ],
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
		context: { postId?: number; postType?: string };
		bindings: Record< string, Binding >;
	} ) {
		const values: Record< string, string > = {};

		for ( const [ attribute, binding ] of Object.entries( bindings ) ) {
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
			const label = meta?.rolling_coverage_breakout_read_more_text;

			values[ attribute ] =
				typeof label === 'string' && label
					? label
					: __( 'Read more', 'newspack-rolling-coverage' );
		}

		return values;
	},
} );

export { ENTRY_BINDINGS_SOURCE };
