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
	compactInnerTemplate,
	cardsInnerTemplate,
} from './layout';
import type { TemplateItem } from './types';

export type BuiltInLayoutSlug = 'default' | 'compact' | 'cards';

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
			title: _x( 'Classic', 'layout name', 'newspack-rolling-coverage' ),
			template: innerTemplate,
		},
		{
			slug: 'compact',
			title: _x( 'Compact', 'layout name', 'newspack-rolling-coverage' ),
			template: compactInnerTemplate,
		},
		{
			slug: 'cards',
			title: _x( 'Cards', 'layout name', 'newspack-rolling-coverage' ),
			template: cardsInnerTemplate,
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
