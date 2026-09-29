/**
 * WordPress dependencies
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

const ENTRY_BINDINGS_SOURCE = 'newspack-rolling-coverage/entry';

const PINNED_LABEL_CONTEXT = 'newspack-rolling-coverage/pinnedLabel';

type Binding = { args?: { key?: string } };

/**
 * Mirrors Entry_Bindings::get_value() in the editor. Links are resolved per
 * entry on the server, so the editor shows labels only.
 */
registerBlockBindingsSource( {
	name: ENTRY_BINDINGS_SOURCE,
	usesContext: [ PINNED_LABEL_CONTEXT ],
	getValues( {
		context,
		bindings,
	}: {
		context: {
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

			values[ attribute ] =
				binding.args?.key === 'breakoutLabel'
					? __( 'Read more', 'newspack-rolling-coverage' )
					: '';
		}

		return values;
	},
} );

export { ENTRY_BINDINGS_SOURCE, PINNED_LABEL_CONTEXT };
