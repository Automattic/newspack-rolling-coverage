const getWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const path = require( 'path' );
const { getWebpackEntryPoints } = require( '@wordpress/scripts/utils' );

module.exports = getWebpackConfig( {
	entry: {
		admin: path.resolve( __dirname, 'src/admin/index.tsx' ),
		...getWebpackEntryPoints( 'script' )(),
	},
} );
