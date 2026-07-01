const getWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const path = require( 'path' );

module.exports = getWebpackConfig( {
	entry: {
		admin: path.resolve( __dirname, 'src/admin/index.tsx' ),
	},
} );
