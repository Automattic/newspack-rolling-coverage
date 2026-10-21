import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const read = ( path ) => readFileSync( fileURLToPath( new URL( path, import.meta.url ) ), 'utf8' );
const ours = read( '../src/styles/global.css' );
const fumadocs = read( '../node_modules/fumadocs-ui/css/lib/default-colors.css' );

function block( css, selector ) {
	const match = css.match( new RegExp( `(?:^|\\n)${ selector.replace( '.', '\\.' ) }\\s*\\{([^}]*)\\}` ) );
	return match ? match[ 1 ] : '';
}

function value( body, name ) {
	const match = body.match( new RegExp( `${ name }:\\s*([^;]+);` ) );
	return match ? match[ 1 ].trim() : null;
}

function toRgb( color ) {
	const hex = color.match( /^#([0-9a-f]{6})$/i );
	if ( hex ) {
		return [ 0, 2, 4 ].map( ( i ) => parseInt( hex[ 1 ].slice( i, i + 2 ), 16 ) / 255 );
	}
	const hsl = color.match( /^hsla?\(\s*([\d.]+),\s*([\d.]+)%,\s*([\d.]+)%/ );
	if ( hsl ) {
		const [ h, s, l ] = [ +hsl[ 1 ], +hsl[ 2 ] / 100, +hsl[ 3 ] / 100 ];
		const k = ( n ) => ( n + h / 30 ) % 12;
		const a = s * Math.min( l, 1 - l );
		return [ 0, 8, 4 ].map( ( n ) => l - a * Math.max( -1, Math.min( k( n ) - 3, 9 - k( n ), 1 ) ) );
	}
	throw new Error( `check-contrast: can't parse colour "${ color }"` );
}

function luminance( color ) {
	const [ r, g, b ] = toRgb( color ).map( ( c ) => ( c <= 0.03928 ? c / 12.92 : ( ( c + 0.055 ) / 1.055 ) ** 2.4 ) );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio( a, b ) {
	const [ hi, lo ] = [ luminance( a ), luminance( b ) ].sort( ( x, y ) => y - x );
	return ( hi + 0.05 ) / ( lo + 0.05 );
}

const modes = {
	light: { ours: block( ours, ':root' ), base: block( fumadocs, '@theme' ) },
	dark: { ours: block( ours, '.dark' ), base: block( fumadocs, '.dark' ) },
};

const failures = [];

// A colour set on :root also wins in dark mode, because global.css loads after Fumadocs' .dark block.
for ( const [ , name ] of modes.light.ours.matchAll( /(--color-fd-[a-z-]+):/g ) ) {
	if ( value( modes.light.ours, name )?.startsWith( 'var(' ) ) {
		continue;
	}
	if ( ! value( modes.dark.ours, name ) ) {
		failures.push( `${ name } is set on :root but not in .dark, so dark mode would use the light value` );
	}
}

for ( const [ mode, { ours: own, base } ] of Object.entries( modes ) ) {
	const background = value( own, '--color-fd-background' ) ?? value( base, '--color-fd-background' );
	const surfaces = [ background, value( own, '--color-fd-card' ) ?? value( base, '--color-fd-card' ) ];
	const accent = value( own, '--color-fd-primary' );
	const ring = value( own, '--color-fd-ring' );

	if ( ! accent || ! ring ) {
		failures.push( `${ mode }: --color-fd-primary and --color-fd-ring must be set in global.css` );
		continue;
	}

	for ( const surface of surfaces ) {
		const text = ratio( accent, surface );
		const focus = ratio( ring, surface );
		console.log( `${ mode }: accent ${ accent } on ${ surface } = ${ text.toFixed( 2 ) }:1, ring = ${ focus.toFixed( 2 ) }:1` );
		if ( text < 4.5 ) {
			failures.push( `${ mode }: accent ${ accent } on ${ surface } is ${ text.toFixed( 2 ) }:1, needs 4.5:1` );
		}
		if ( focus < 3 ) {
			failures.push( `${ mode }: ring ${ ring } on ${ surface } is ${ focus.toFixed( 2 ) }:1, needs 3:1` );
		}
	}
}

if ( failures.length ) {
	console.error( failures.map( ( failure ) => `check-contrast: ${ failure }` ).join( '\n' ) );
	process.exit( 1 );
}

console.log( 'check-contrast: OK' );
