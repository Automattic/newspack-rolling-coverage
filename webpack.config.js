const getWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const path = require( 'path' );
const { getWebpackEntryPoints } = require( '@wordpress/scripts/utils' );

const config = getWebpackConfig( {
	entry: {
		admin: path.resolve( __dirname, 'src/admin/index.tsx' ),
		...getWebpackEntryPoints( 'script' )(),
	},
} );

// newspack-icons publishes untranspiled JSX.
config.module.rules.push( {
	test: /\.js$/,
	include: /node_modules[\\/]newspack-icons[\\/]/,
	use: {
		loader: 'babel-loader',
		options: {
			presets: [ '@babel/preset-react' ],
		},
	},
} );

module.exports = config;
