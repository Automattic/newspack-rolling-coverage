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
	ACCENT,
	ACCENT_TINT,
	BREAKOUT_BOX_CLASS,
	PINNED_BACKGROUND,
	isBreakoutEntry,
	isNewLabel,
	isPinnedCard,
	isRegularEntry,
} from '../../src/blocks/rolling-coverage/template';
import type { TemplateItem } from '../../src/blocks/rolling-coverage/types';
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

type TemplateNode = {
	name: string;
	attributes: {
		className?: string;
		textColor?: string;
		style?: {
			color?: { text?: string; background?: string };
			border?: { color?: string; style?: string; width?: string };
		};
		[ key: string ]: unknown;
	};
	innerBlocks: TemplateNode[];
};

/**
 * A layout's template as nested blocks, without creating them.
 *
 * @param {Array} items Template items.
 * @return {Object[]} The blocks.
 */
function templateNodes( items: TemplateItem[] ): TemplateNode[] {
	return items.map( ( [ name, attributes = {}, inner = [] ] ) => ( {
		name,
		attributes: attributes as TemplateNode[ 'attributes' ],
		innerBlocks: templateNodes( inner as TemplateItem[] ),
	} ) );
}

/**
 * Every block in a tree that matches, at any depth.
 *
 * @param {Object[]} nodes   The blocks.
 * @param {Function} matches Whether a block is wanted.
 * @return {Object[]} The matching blocks.
 */
function findNodes(
	nodes: TemplateNode[],
	matches: ( node: TemplateNode ) => boolean
): TemplateNode[] {
	return nodes.flatMap( ( node ) => [
		...( matches( node ) ? [ node ] : [] ),
		...findNodes( node.innerBlocks, matches ),
	] );
}

/**
 * A built-in layout's blocks.
 *
 * @param {string} slug The layout's slug.
 * @return {Object[]} The blocks.
 */
function layoutNodes( slug: string ): TemplateNode[] {
	const layout = LAYOUTS.find( ( item ) => item.slug === slug );

	if ( ! layout ) {
		throw new Error( `No built-in layout "${ slug }".` );
	}

	return templateNodes( layout.template() );
}

const isEntryVariant = ( node: TemplateNode ) =>
	isPinnedCard( node ) || isRegularEntry( node ) || isBreakoutEntry( node );

const hasClass = ( node: TemplateNode, className: string ) =>
	String( node.attributes.className ?? '' )
		.split( ' ' )
		.includes( className );

test( 'every built-in layout except Alert places the New label in each entry variant', () => {
	const missing: string[] = [];

	for ( const { slug } of LAYOUTS ) {
		const nodes = layoutNodes( slug );
		const labels = findNodes( nodes, isNewLabel );

		if ( slug === 'alert' ) {
			expect( labels ).toEqual( [] );
			continue;
		}

		const variants = findNodes( nodes, isEntryVariant );
		expect( variants.length ).toBeGreaterThanOrEqual( 2 );

		for ( const variant of variants ) {
			const own = findNodes(
				variant.innerBlocks.filter(
					( node ) => ! isEntryVariant( node )
				),
				isNewLabel
			);

			if ( own.length !== 1 ) {
				missing.push(
					`${ slug }: ${ variant.attributes.className } has ${ own.length }`
				);
			}
		}
	}

	expect( missing ).toEqual( [] );
} );

test( "Minute's New label is for screen readers only", () => {
	const labels = findNodes( layoutNodes( 'minute' ), isNewLabel );

	expect( labels.length ).toBeGreaterThan( 0 );

	for ( const label of labels ) {
		expect( hasClass( label, 'screen-reader-text' ) ).toBe( true );
	}
} );

test( 'no other layout hides its New label', () => {
	for ( const { slug } of LAYOUTS ) {
		if ( slug === 'minute' ) {
			continue;
		}

		for ( const label of findNodes( layoutNodes( slug ), isNewLabel ) ) {
			expect( hasClass( label, 'screen-reader-text' ) ).toBe( false );
		}
	}
} );

test( "Flash's New label takes the bar's text colour, not the accent", () => {
	const labels = findNodes( layoutNodes( 'flash' ), isNewLabel );

	expect( labels.length ).toBeGreaterThan( 0 );

	for ( const label of labels ) {
		expect( label.attributes.style?.color?.text ).toBeUndefined();
		expect( label.attributes.textColor ).toBeUndefined();
	}
} );

test( 'Bulletin, Minute and Rail pinned entries use the grey background', () => {
	for ( const slug of [ 'default', 'minute', 'rail' ] ) {
		const [ card ] = findNodes( layoutNodes( slug ), isPinnedCard );
		const backgrounds = findNodes(
			[ card ],
			( node ) => !! node.attributes.style?.color?.background
		).map( ( node ) => node.attributes.style?.color?.background );

		expect( backgrounds ).toContain( PINNED_BACKGROUND );
		expect( JSON.stringify( card ) ).not.toContain( 'accent-contrast' );
		expect(
			findNodes(
				[ card ],
				( node ) => node.attributes.style?.color?.background === ACCENT
			)
		).toEqual( [] );
	}
} );

test( 'Bulletin and Minute Full story cards use the accent tint and border', () => {
	for ( const slug of [ 'default', 'minute' ] ) {
		const boxes = findNodes( layoutNodes( slug ), ( node ) =>
			hasClass( node, BREAKOUT_BOX_CLASS )
		);

		expect( boxes.length ).toBeGreaterThan( 0 );

		for ( const box of boxes ) {
			expect( box.attributes.style?.color?.background ).toBe(
				ACCENT_TINT
			);
			expect( box.attributes.style?.border ).toMatchObject( {
				style: 'solid',
				width: '1px',
			} );
			expect( box.attributes.style?.border?.color ).toContain(
				'--newspack-rolling-coverage-accent'
			);
		}
	}
} );
