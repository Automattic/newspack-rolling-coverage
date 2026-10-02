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
	clockInnerTemplate,
	marginInnerTemplate,
	minuteInnerTemplate,
} from './layout';
import type { TemplateItem } from './types';

export type BuiltInLayoutSlug =
	'default' | 'stream' | 'rail' | 'clock' | 'margin' | 'minute';

export type BuiltInLayout = {
	slug: BuiltInLayoutSlug;
	title: string;
	template: () => TemplateItem[];
	latest?: number;
	hidesWhenEnded?: boolean;
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
		{
			slug: 'clock',
			title: _x( 'Clock', 'layout name', 'newspack-rolling-coverage' ),
			template: clockInnerTemplate,
		},
		{
			slug: 'margin',
			title: _x( 'Margin', 'layout name', 'newspack-rolling-coverage' ),
			template: marginInnerTemplate,
		},
		{
			slug: 'minute',
			title: _x( 'Minute', 'layout name', 'newspack-rolling-coverage' ),
			template: minuteInnerTemplate,
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

/**
 * The cap attributes a built-in layout sets when it is picked.
 *
 * @param {BuiltInLayoutSlug} slug The layout's slug.
 * @return {Object} The attributes to set.
 */
export function layoutCapAttributes( slug: BuiltInLayoutSlug ): {
	latestOnly: boolean;
	latestCount?: number;
	hideWhenEnded: boolean;
} {
	const layout = getBuiltInLayouts().find( ( item ) => item.slug === slug );

	if ( layout?.latest ) {
		return {
			latestOnly: true,
			latestCount: layout.latest,
			hideWhenEnded: !! layout.hidesWhenEnded,
		};
	}

	return { latestOnly: false, hideWhenEnded: false };
}
