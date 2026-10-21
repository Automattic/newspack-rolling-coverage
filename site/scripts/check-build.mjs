import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const dist = fileURLToPath( new URL( '../dist/', import.meta.url ) );
const failures = [];

function walk( dir ) {
	return readdirSync( dir ).flatMap( ( name ) => {
		const path = join( dir, name );
		return statSync( path ).isDirectory() ? walk( path ) : [ path ];
	} );
}

if ( ! existsSync( dist ) ) {
	console.error( 'check-build: dist/ missing, run npm run build first' );
	process.exit( 1 );
}

for ( const file of [ 'index.html', 'docs/index.html' ] ) {
	if ( ! existsSync( join( dist, file ) ) ) {
		failures.push( `dist/${ file } missing` );
	}
}

const searchIndex = [ 'api/search', 'api/search.json' ]
	.map( ( file ) => join( dist, file ) )
	.find( ( path ) => existsSync( path ) && statSync( path ).isFile() );

if ( ! searchIndex ) {
	failures.push( 'search index (dist/api/search) missing' );
} else {
	try {
		const data = JSON.parse( readFileSync( searchIndex, 'utf8' ) );
		if ( ! data || Object.keys( data ).length === 0 ) {
			failures.push( 'search index is empty' );
		}
	} catch {
		failures.push( 'search index is not valid JSON' );
	}
}

if ( existsSync( join( dist, 'server' ) ) ) {
	failures.push( 'dist/server exists: the build must be static' );
}

if ( existsSync( join( dist, 'docs' ) ) ) {
	for ( const page of walk( join( dist, 'docs' ) ).filter( ( path ) => path.endsWith( 'index.html' ) ) ) {
		if ( ! /<title>[^<]+<\/title>/.test( readFileSync( page, 'utf8' ) ) ) {
			failures.push( `${ relative( dist, page ) } has no <title>` );
		}
	}
}

const pages = walk( dist ).filter( ( path ) => path.endsWith( '.html' ) );
for ( const page of pages ) {
	for ( const [ , href ] of readFileSync( page, 'utf8' ).matchAll( /href="(\/[^"#?]*)/g ) ) {
		if ( href.startsWith( '/_astro/' ) || href === '/favicon.svg' ) {
			continue;
		}
		const target = join( dist, href.replace( /\/?$/, '/' ), 'index.html' );
		if ( ! existsSync( target ) && ! existsSync( join( dist, href ) ) ) {
			failures.push( `${ relative( dist, page ) } links to ${ href }, which doesn't exist` );
		}
	}
}

if ( failures.length ) {
	console.error( failures.map( ( failure ) => `check-build: ${ failure }` ).join( '\n' ) );
	process.exit( 1 );
}

console.log( 'check-build: OK' );
