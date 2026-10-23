/**
 * Jest config for the built-in layout template checks.
 *
 * The editor modules the layouts are built from expect WordPress's own
 * scripts, so this loads them from node_modules: one copy of each package
 * (`jest-resolver.js`), with the ES module dependencies transformed and the
 * stylesheet-only packages stubbed.
 */
const path = require( 'path' );

const scripts = path.dirname(
	require.resolve( 'newspack-scripts/package.json' )
);

module.exports = {
	rootDir: path.resolve( __dirname, '../..' ),
	testMatch: [ '<rootDir>/tests/js/**/*.test.ts' ],
	setupFilesAfterEnv: [ path.join( scripts, 'scripts/utils/jestSetup.js' ) ],
	transform: {
		'^.+\\.(m?j|t)sx?$': path.join(
			scripts,
			'scripts/utils/babelJestTransformer.js'
		),
	},
	transformIgnorePatterns: [
		'/node_modules/(?!(newspack-scripts|@wordpress|is-plain-obj|memize|uuid|marked|parsel-js|colord|change-case)/)',
	],
	moduleNameMapper: {
		'^newspack-colors$': path.join( __dirname, 'stubs/newspack-colors.js' ),
		'\\.(scss|css)$': path.join(
			scripts,
			'scripts/utils/babelJestTransformer.js'
		),
	},
	resolver: path.join( __dirname, 'jest-resolver.js' ),
	testEnvironment: 'jsdom',
};
