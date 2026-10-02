/**
 * WordPress dependencies
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';

const ENTRY_BINDINGS_SOURCE = 'newspack-rolling-coverage/entry';

/**
 * Mirrors Entry_Bindings::get_value() in the editor. Values are resolved per
 * entry on the server, so the editor leaves every bound value empty.
 */
registerBlockBindingsSource( {
	name: ENTRY_BINDINGS_SOURCE,
	getValues( { bindings }: { bindings: Record< string, unknown > } ) {
		return Object.fromEntries(
			Object.keys( bindings ).map( ( attribute ) => [ attribute, '' ] )
		);
	},
} );

export { ENTRY_BINDINGS_SOURCE };
