/**
 * Builds the built-in layouts in Node the way the editor builds a layout's
 * pattern: each layout's template from `getBuiltInLayouts()`, serialized once
 * `registerBlocks()` has registered the block types. The site the editor
 * would take its palette, font sizes and time format from is passed in.
 */
import './editor-config';

import { registerCoreBlocks } from '@wordpress/block-library';
import {
	InnerBlocks,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	createBlocksFromInnerBlocksTemplate,
	registerBlockType,
	serialize,
} from '@wordpress/blocks';
import { dispatch } from '@wordpress/data';
import { getSettings, setSettings } from '@wordpress/date';
import { createElement } from '@wordpress/element';
import * as fs from 'fs';
import * as path from 'path';

import {
	getBuiltInLayouts,
	type BuiltInLayout,
} from '../../src/blocks/rolling-coverage/layouts';

export const TEMPLATES_DIR = path.resolve(
	__dirname,
	'../../includes/blocks/layouts'
);

const BLOCKS_DIR = path.resolve( __dirname, '../../src/blocks' );

export type Site = {
	palette: string[];
	fontSizes: string[];
	timeFormat: string;
};

/**
 * Registers core's blocks and the plugin's. The plugin's blocks save either
 * nothing or their inner blocks, so each registers from its block.json with
 * a save that prints its inner blocks, which is empty for a block without
 * any; their editor scripts would pull in the whole editor.
 */
export function registerBlocks() {
	registerCoreBlocks();

	for ( const dir of fs.readdirSync( BLOCKS_DIR ) ) {
		const file = path.join( BLOCKS_DIR, dir, 'block.json' );

		if ( fs.existsSync( file ) ) {
			registerBlockType( JSON.parse( fs.readFileSync( file, 'utf8' ) ), {
				edit: () => null,
				save: () => createElement( InnerBlocks.Content ),
			} );
		}
	}
}

/**
 * Every built-in layout's inner blocks, serialized as the editor would on
 * the given site.
 *
 * @param {Site}            site    The site's palette, font sizes and time format.
 * @param {BuiltInLayout[]} layouts The layouts, when already listed.
 * @return {Record<string, string>} Markup by layout slug.
 */
export function serializeLayouts(
	site: Site,
	layouts: BuiltInLayout[] = getBuiltInLayouts()
): Record< string, string > {
	dispatch( blockEditorStore ).updateSettings( {
		__experimentalFeatures: {
			color: {
				palette: {
					theme: site.palette.map( ( slug ) => ( { slug } ) ),
				},
			},
			typography: {
				fontSizes: {
					theme: site.fontSizes.map( ( slug ) => ( { slug } ) ),
				},
			},
		},
	} );

	const settings = getSettings();
	setSettings( {
		...settings,
		formats: { ...settings.formats, time: site.timeFormat },
	} );

	return Object.fromEntries(
		layouts.map( ( layout ) => [
			layout.slug,
			serialize(
				createBlocksFromInnerBlocksTemplate( layout.template() )
			) + '\n',
		] )
	);
}
