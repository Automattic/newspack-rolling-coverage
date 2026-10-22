/**
 * The server's copies of the built-in layouts, `includes/blocks/layouts/`,
 * match the templates the editor builds the layouts from. When a template
 * changes, `npm run build:layouts` writes them again.
 *
 * What the editor takes from the site is written as a token, which
 * `Layout_Templates` fills in for the site the layout renders on:
 *
 * - `{{i18n:Text}}`: a word or phrase the editor translates.
 * - `{{all_updates}}`: the link to the coverage page, in the site's name for
 *   entries.
 * - `{{time_format}}`: the site's time format.
 * - `{{muted_color}}`: the theme's muted text color, or none.
 * - `{{font_size:preferred|fallback}}`: the theme's font size preset.
 */
import './editor-config';

import { parse } from '@wordpress/block-serialization-default-parser';
import { addFilter } from '@wordpress/hooks';
import * as fs from 'fs';
import * as path from 'path';

import { getBuiltInLayouts } from '../../src/blocks/rolling-coverage/layouts';
import {
	registerBlocks,
	serializeLayouts,
	TEMPLATES_DIR,
} from './layout-templates';

const MUTED_SENTINEL = 'rc-token-muted';

const mockFontSizes: string[] = [];

jest.mock( '../../src/blocks/shared/muted-color', () => ( {
	...jest.requireActual( '../../src/blocks/shared/muted-color' ),
	mutedTextColor: () => 'rc-token-muted',
} ) );

jest.mock( '../../src/blocks/shared/theme-font-size', () => ( {
	themeFontSize: (
		_sizes: string[],
		preferred: string,
		fallback: string
	) => {
		const pair = `${ preferred }|${ fallback }`;

		if ( ! mockFontSizes.includes( pair ) ) {
			mockFontSizes.push( pair );
		}

		return `rc-token-size-${ mockFontSizes.indexOf( pair ) }`;
	},
} ) );

/**
 * The texts the link to the coverage page starts with, without and with the
 * site's name for entries (`allUpdatesText()` in `template.ts`).
 */
const ALL_UPDATES_TEXTS = [ 'See all entries', 'See all %s' ];

const DOMAIN = 'newspack-rolling-coverage';

// Before the filters below, which only the templates go through: registering
// translates the blocks' titles, and listing the layouts their names.
registerBlocks();
const LAYOUTS = getBuiltInLayouts();

addFilter(
	`i18n.gettext_${ DOMAIN }`,
	`${ DOMAIN }/layout-templates`,
	( _translation: string, text: string ) => {
		if ( ALL_UPDATES_TEXTS.includes( text ) ) {
			return '{{all_updates}}';
		}

		// The token goes into markup and block attributes as it is.
		if ( /[{}|<>&"\\]/.test( text ) || text.includes( '--' ) ) {
			throw new Error( `"${ text }" can't be written as a token.` );
		}

		return `{{i18n:${ text }}}`;
	}
);

for ( const hook of [
	'gettext_with_context',
	'ngettext',
	'ngettext_with_context',
] ) {
	addFilter(
		`i18n.${ hook }_${ DOMAIN }`,
		`${ DOMAIN }/layout-templates`,
		() => {
			throw new Error(
				`The layout templates have no token for ${ hook }(); add one to Layout_Templates.`
			);
		}
	);
}

/**
 * Swaps the stand-ins for the theme's muted color and font sizes for their
 * tokens.
 *
 * @param {string} markup Serialized layout.
 * @return {string} The markup with tokens.
 */
function withTokens( markup: string ): string {
	const tokenized = markup
		.split( MUTED_SENTINEL )
		.join( '{{muted_color}}' )
		.replace(
			/rc-token-size-(\d+)/g,
			( _match, index ) =>
				`{{font_size:${ mockFontSizes[ Number( index ) ] }}}`
		);

	expect( tokenized ).not.toContain( 'rc-token' );

	return tokenized;
}

/**
 * The HTML the blocks save, without their comment delimiters.
 *
 * @param {Array} blocks Parsed blocks.
 * @return {string} The blocks' saved HTML.
 */
function savedHtml( blocks: ReturnType< typeof parse > ): string {
	return blocks
		.map(
			( block ) =>
				block.innerContent.filter( Boolean ).join( '' ) +
				savedHtml( block.innerBlocks )
		)
		.join( '' );
}

test( 'the server copies of the built-in layouts match their templates (npm run build:layouts writes them)', () => {
	const layouts = serializeLayouts(
		{ palette: [], fontSizes: [], timeFormat: '{{time_format}}' },
		LAYOUTS
	);
	const outOfDate: string[] = [];

	for ( const [ slug, markup ] of Object.entries( layouts ) ) {
		const tokenized = withTokens( markup );
		const file = path.join( TEMPLATES_DIR, `${ slug }.html` );

		// The server can fill in a color attribute, but not the classes a
		// static block saves from it.
		expect( savedHtml( parse( tokenized ) ) ).not.toContain(
			'{{muted_color}}'
		);

		if ( process.env.UPDATE_LAYOUT_TEMPLATES ) {
			fs.writeFileSync( file, tokenized );
		}

		if (
			! fs.existsSync( file ) ||
			fs.readFileSync( file, 'utf8' ) !== tokenized
		) {
			outOfDate.push( slug );
		}
	}

	expect( outOfDate ).toEqual( [] );

	const files = fs
		.readdirSync( TEMPLATES_DIR )
		.filter( ( file ) => file.endsWith( '.html' ) );

	expect( files.sort() ).toEqual(
		Object.keys( layouts )
			.map( ( slug ) => `${ slug }.html` )
			.sort()
	);
} );
