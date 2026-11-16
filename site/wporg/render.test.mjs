import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import sharp from 'sharp';
import { render } from './render.mjs';

const hex = ( data, offset ) =>
	[ 0, 1, 2 ].map( ( i ) => data[ offset + i ].toString( 16 ).padStart( 2, '0' ) ).join( '' );

async function pixel( file, x, y ) {
	const { data, info } = await sharp( file ).removeAlpha().raw().toBuffer( { resolveWithObject: true } );
	return hex( data, ( y * info.width + x ) * info.channels );
}

async function fixture( ground = '#ffffff' ) {
	const dir = await mkdtemp( join( tmpdir(), 'wporg-render-' ) );
	const card = await sharp( {
		create: { width: 1412, height: 1964, channels: 3, background: '#cccccc' },
	} )
		.png()
		.toBuffer();
	const capture = join( dir, 'capture.png' );
	await sharp( {
		create: { width: 3024, height: 1964, channels: 3, background: ground },
	} )
		.composite( [ { input: card, left: 900, top: 0 } ] )
		.png()
		.toFile( capture );
	const box = { y: 0, height: 100 };
	await writeFile(
		join( dir, 'capture.png.boxes.json' ),
		JSON.stringify( {
			L: { x: 395, width: 10, ...box },
			R: { x: 1150, width: 6, ...box },
			A: { x: 395, width: 10, y: 400, height: 100 },
		} )
	);
	const config = {
		name: 'Test Plugin',
		markPath: 'M12 10A2 2 0 1 1 12 14A2 2 0 1 1 12 10Z',
		headline: { before: 'Say it ', accent: 'live', after: '.' },
		headlineSize: 34,
		description: 'A short description of the plugin.',
		leftWidth: 300,
		frame: { x: 360 },
		crop: { left: 'L', right: 'R', anchor: 'A', anchorY: 112, drawWidth: 372, frameHeight: 250 },
	};
	return { dir, capture, config };
}

test( 'render writes the five listing assets at the right sizes', async () => {
	const { dir, capture, config } = await fixture();
	const out = join( dir, 'out' );
	const files = await render( { config, capture, outDir: out } );
	assert.equal( files.length, 5 );
	const sizes = {
		'banner-772x250.png': [ 772, 250 ],
		'banner-1544x500.png': [ 1544, 500 ],
		'icon-128x128.png': [ 128, 128 ],
		'icon-256x256.png': [ 256, 256 ],
	};
	for ( const [ name, [ width, height ] ] of Object.entries( sizes ) ) {
		const meta = await sharp( join( out, name ) ).metadata();
		assert.deepEqual( [ meta.width, meta.height ], [ width, height ], name );
	}
	assert.ok( files.some( ( f ) => f.endsWith( 'icon.svg' ) ) );
} );

test( 'the banner keeps a white gap right of the screenshot and the card ends at the crop edge', async () => {
	const { dir, capture, config } = await fixture();
	const out = join( dir, 'out' );
	await render( { config, capture, outDir: out } );
	const banner = join( out, 'banner-772x250.png' );
	for ( let x = 733; x <= 771; x++ ) {
		assert.equal( await pixel( banner, x, 125 ), 'ffffff', `x=${ x }` );
	}
	assert.notEqual( await pixel( banner, 720, 125 ), 'ffffff' );
} );

test( 'the icon is a blue square with a white mark in the middle', async () => {
	const { dir, capture, config } = await fixture();
	const out = join( dir, 'out' );
	await render( { config, capture, outDir: out } );
	const icon = join( out, 'icon-128x128.png' );
	assert.equal( await pixel( icon, 64, 64 ), 'ffffff' );
	const corner = await pixel( icon, 2, 2 );
	[ 0x00, 0x3d, 0xa5 ].forEach( ( want, i ) => {
		const got = parseInt( corner.slice( i * 2, i * 2 + 2 ), 16 );
		assert.ok( Math.abs( got - want ) <= 2, `corner ${ corner }` );
	} );
} );

test( 'render refuses to draw when Inter did not load', async () => {
	const { dir, capture, config } = await fixture();
	const broken = join( dir, 'not-a-font.woff2' );
	await writeFile( broken, 'not a font' );
	await assert.rejects(
		render( { config: { ...config, fontPath: broken }, capture, outDir: join( dir, 'out' ) } ),
		/Inter did not load/
	);
} );

test( 'render refuses a capture whose ground is not white', async () => {
	const { dir, capture, config } = await fixture( '#fdfdfd' );
	await assert.rejects(
		render( { config, capture, outDir: join( dir, 'out' ) } ),
		/capture ground is not white \(#fdfdfd\)/
	);
} );
