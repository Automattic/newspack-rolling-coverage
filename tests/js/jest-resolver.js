/**
 * Resolves every `@wordpress/*` package to its top-level copy. Packages
 * nested under another one's node_modules are older copies, and two copies
 * of the private APIs or data stores refuse to work with each other.
 */
const path = require( 'path' );

const root = path.resolve( __dirname, '../..' );

module.exports = ( request, options ) => {
	if ( request.startsWith( '@wordpress/' ) ) {
		try {
			return options.defaultResolver( request, {
				...options,
				basedir: root,
			} );
		} catch {
			// Not installed at the top level: resolve it where it was asked for.
		}
	}

	return options.defaultResolver( request, options );
};
