/**
 * WordPress dependencies
 */
import { _x } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getLayoutId } from './utils';
import {
	innerTemplate,
	streamInnerTemplate,
	railInnerTemplate,
} from './layout';
import type { TemplateItem } from './types';

export type BuiltInLayoutSlug = 'default' | 'stream' | 'rail';

export type BuiltInLayout = {
	slug: BuiltInLayoutSlug;
	title: string;
	template: () => TemplateItem[];
};

/**
 * The layouts that ship with the plugin, in the order the picker lists them.
 *
 * @return {BuiltInLayout[]} The built-in layouts.
 */
export function getBuiltInLayouts(): BuiltInLayout[] {
	return [
		{
			slug: 'default',
			title: _x( 'Bulletin', 'layout name', 'newspack-rolling-coverage' ),
			template: innerTemplate,
		},
		{
			slug: 'stream',
			title: _x( 'Stream', 'layout name', 'newspack-rolling-coverage' ),
			template: streamInnerTemplate,
		},
		{
			slug: 'rail',
			title: _x( 'Rail', 'layout name', 'newspack-rolling-coverage' ),
			template: railInnerTemplate,
		},
	];
}

/**
 * The built-in layout a pattern is, if it is one.
 *
 * @param {number} patternId The pattern's ID.
 * @return {BuiltInLayoutSlug|null} The layout's slug, or null.
 */
export function builtInLayoutSlugFor(
	patternId: number
): BuiltInLayoutSlug | null {
	if ( ! patternId ) {
		return null;
	}

	return (
		getBuiltInLayouts().find(
			( layout ) => getLayoutId( layout.slug ) === patternId
		)?.slug ?? null
	);
}
