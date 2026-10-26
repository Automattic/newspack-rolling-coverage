import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath( new URL( '../content/docs/', import.meta.url ) );
const publicDir = fileURLToPath( new URL( '../public/', import.meta.url ) );
const failures = [];

const REQUIRED_PAGES = [
	'index.mdx',
	'getting-started/index.mdx',
	'getting-started/install-the-plugin.mdx',
	'getting-started/create-your-first-coverage.mdx',
	'getting-started/add-entries.mdx',
	'getting-started/put-the-feed-on-a-page.mdx',
	'guides/index.mdx',
	'blocks/index.mdx',
	'developers/index.mdx',
];

const SIDEBAR_ORDER = {
	'meta.json': [ 'getting-started', 'guides', 'blocks', 'developers' ],
	'getting-started/meta.json': [
		'index',
		'install-the-plugin',
		'create-your-first-coverage',
		'add-entries',
		'put-the-feed-on-a-page',
	],
};

const TECHNICAL_WORDS = [ 'hook', 'hooks', 'endpoint', 'endpoints', 'attribute', 'attributes', 'meta' ];
const TECHNICAL_ACRONYMS = [ 'REST', 'API', 'PHP', 'JSON' ];
const ALLOWED = {
	'getting-started/install-the-plugin.mdx': [ 'PHP' ],
};

function technicalWord( line, allowed = [] ) {
	const words = line.match( new RegExp( `\\b(${ TECHNICAL_WORDS.join( '|' ) })\\b`, 'i' ) );
	if ( words ) {
		return words[ 1 ];
	}
	const acronyms = TECHNICAL_ACRONYMS.filter( ( acronym ) => ! allowed.includes( acronym ) );
	const match = line.match( new RegExp( `\\b(${ acronyms.join( '|' ) })\\b` ) );
	return match ? match[ 1 ] : null;
}

function walk( dir ) {
	return readdirSync( dir ).flatMap( ( name ) => {
		const path = join( dir, name );
		return statSync( path ).isDirectory() ? walk( path ) : [ path ];
	} );
}

for ( const page of REQUIRED_PAGES ) {
	if ( ! existsSync( join( root, page ) ) ) {
		failures.push( `missing page content/docs/${ page }` );
	}
}

for ( const [ file, expected ] of Object.entries( SIDEBAR_ORDER ) ) {
	const path = join( root, file );
	const pages = existsSync( path ) ? JSON.parse( readFileSync( path, 'utf8' ) ).pages : undefined;
	if ( JSON.stringify( pages ) !== JSON.stringify( expected ) ) {
		failures.push( `content/docs/${ file } pages must be ${ JSON.stringify( expected ) }, got ${ JSON.stringify( pages ) }` );
	}
}

for ( const metaPath of walk( root ).filter( ( file ) => file.endsWith( 'meta.json' ) ) ) {
	const folder = dirname( metaPath );
	const listed = JSON.parse( readFileSync( metaPath, 'utf8' ) ).pages ?? [];
	for ( const page of listed ) {
		if ( ! existsSync( join( folder, `${ page }.mdx` ) ) && ! existsSync( join( folder, page, 'index.mdx' ) ) ) {
			failures.push( `${ relative( root, metaPath ) } lists "${ page }", which has no page` );
		}
	}
	for ( const name of readdirSync( folder ) ) {
		const slug = name.replace( /\.mdx$/, '' );
		const isPage = name.endsWith( '.mdx' ) || existsSync( join( folder, name, 'index.mdx' ) );
		if ( isPage && ! ( relative( root, folder ) === '' && slug === 'index' ) && ! listed.includes( slug ) ) {
			failures.push( `${ relative( root, folder ) || 'docs' }/${ name } is missing from ${ relative( root, metaPath ) }` );
		}
	}
}

for ( const path of walk( root ).filter( ( file ) => file.endsWith( '.mdx' ) ) ) {
	const name = relative( root, path );
	const source = readFileSync( path, 'utf8' );
	const frontmatter = source.match( /^---\n([\s\S]*?)\n---/ );
	const isDeveloperPage = name.split( sep )[ 0 ] === 'developers';

	for ( const field of [ 'title', 'description' ] ) {
		if ( ! frontmatter || ! new RegExp( `^${ field }:\\s*\\S`, 'm' ).test( frontmatter[ 1 ] ) ) {
			failures.push( `${ name }: frontmatter needs a ${ field }` );
		}
	}

	source.split( '\n' ).forEach( ( line, index ) => {
		const at = `${ name }:${ index + 1 }`;
		if ( line.includes( '—' ) ) {
			failures.push( `${ at }: em dash` );
		}
		const word = ! isDeveloperPage && technicalWord( line, ALLOWED[ name ] );
		if ( word ) {
			failures.push( `${ at }: technical word "${ word }" outside the Developers section` );
		}
	} );

	for ( const [ tag ] of source.matchAll( /<Screenshot\b[^>]*>/g ) ) {
		if ( ! /\blabel="[^"]+"/.test( tag ) ) {
			failures.push( `${ name }: ${ tag } needs a non-empty label` );
		}
		const src = tag.match( /\bsrc="([^"]+)"/ )?.[ 1 ];
		if ( ! src ) {
			failures.push( `${ name }: ${ tag } needs a src, or it ships a placeholder box` );
		} else {
			if ( ! src.startsWith( '/' ) || ! existsSync( join( publicDir, src ) ) ) {
				failures.push( `${ name }: screenshot ${ src } doesn't exist in site/public` );
			}
			if ( ! /\bwidth=\{\d+\}/.test( tag ) || ! /\bheight=\{\d+\}/.test( tag ) ) {
				failures.push( `${ name }: screenshot ${ src } needs width={…} and height={…}` );
			}
		}
	}
}

if ( failures.length ) {
	console.error( failures.map( ( failure ) => `check-content: ${ failure }` ).join( '\n' ) );
	process.exit( 1 );
}

console.log( 'check-content: OK' );
