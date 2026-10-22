/**
 * The tokens in the server's copies of the built-in layouts cover everything
 * the editor takes from the site: with them filled in for a site, each copy
 * matches the layout the editor builds on that site. `Layout_Templates` fills
 * them in on the server the way `fillIn()` does here.
 */
import './editor-config';

import { parse } from '@wordpress/block-serialization-default-parser';
import * as fs from 'fs';
import * as path from 'path';

import { mutedTextColor } from '../../src/blocks/shared/muted-color';
import { themeFontSize } from '../../src/blocks/shared/theme-font-size';
import {
	registerBlocks,
	serializeLayouts,
	type Site,
	TEMPLATES_DIR,
} from './layout-templates';

type ParsedBlock = ReturnType< typeof parse >[ number ];

registerBlocks();

/**
 * Sites with the Newspack themes' presets, and one with none.
 */
const SITES: Record< string, Site > = {
	'Newspack Block Theme': {
		palette: [
			'accent',
			'base',
			'base-2',
			'contrast',
			'contrast-2',
			'contrast-3',
		],
		fontSizes: [ 'small', 'medium', 'large', 'x-large', 'xx-large' ],
		timeFormat: 'g:i a',
	},
	'Newspack Theme': {
		palette: [
			'primary',
			'secondary',
			'dark-gray',
			'medium-gray',
			'white',
		],
		fontSizes: [ 'small', 'normal', 'large', 'huge' ],
		timeFormat: 'H:i',
	},
	'theme without presets': {
		palette: [],
		fontSizes: [],
		timeFormat: 'G:i',
	},
};

/**
 * A token's value on the site, or null to drop the attribute holding it.
 *
 * @param {string} name     Token name.
 * @param {string} argument What follows the token's colon.
 * @param {Site}   site     The site.
 * @return {string|null} The value.
 */
function tokenValue(
	name: string,
	argument: string,
	site: Site
): string | null {
	switch ( name ) {
		case 'i18n':
			return argument;
		case 'all_updates':
			return 'See all entries';
		case 'time_format':
			return site.timeFormat;
		case 'muted_color':
			return mutedTextColor( site.palette ) ?? null;
		case 'font_size': {
			const [ preferred, fallback ] = argument.split( '|' );
			return themeFontSize( site.fontSizes, preferred, fallback );
		}
	}

	throw new Error( `Unknown token {{${ name }}}.` );
}

const TOKEN = /\{\{([a-z_0-9]+)(?::([^}]*))?\}\}/g;

function fillInString( text: string, site: Site ): string {
	return text.replace(
		TOKEN,
		( _match, name, argument = '' ) =>
			tokenValue( name, argument, site ) ?? ''
	);
}

function fillInAttributes( attributes: Record< string, unknown >, site: Site ) {
	const filled: Record< string, unknown > = {};

	for ( const [ key, value ] of Object.entries( attributes ) ) {
		if ( typeof value === 'string' ) {
			const whole = value.match( /^\{\{([a-z_0-9]+)(?::([^}]*))?\}\}$/ );

			if (
				whole &&
				null === tokenValue( whole[ 1 ], whole[ 2 ] ?? '', site )
			) {
				continue;
			}

			filled[ key ] = fillInString( value, site );
		} else if (
			value &&
			typeof value === 'object' &&
			! Array.isArray( value )
		) {
			filled[ key ] = fillInAttributes(
				value as Record< string, unknown >,
				site
			);
		} else {
			filled[ key ] = value;
		}
	}

	return filled;
}

function fillIn( blocks: ParsedBlock[], site: Site ): ParsedBlock[] {
	return blocks.map( ( block ) => ( {
		...block,
		attrs: fillInAttributes( block.attrs ?? {}, site ),
		innerHTML: fillInString( block.innerHTML, site ),
		innerContent: block.innerContent.map( ( chunk ) =>
			null === chunk ? null : fillInString( chunk, site )
		),
		innerBlocks: fillIn( block.innerBlocks, site ),
	} ) );
}

test.each( Object.entries( SITES ) )(
	'filled in for a site with the %s, each server copy matches the layout the editor builds',
	( _name, site ) => {
		const layouts = serializeLayouts( site );

		for ( const [ slug, markup ] of Object.entries( layouts ) ) {
			const copy = fs.readFileSync(
				path.join( TEMPLATES_DIR, `${ slug }.html` ),
				'utf8'
			);

			expect( { slug, blocks: fillIn( parse( copy ), site ) } ).toEqual( {
				slug,
				blocks: parse( markup ),
			} );
		}
	}
);
