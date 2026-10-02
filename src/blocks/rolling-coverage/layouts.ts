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
	wireInnerTemplate,
	digestInnerTemplate,
	flashInnerTemplate,
} from './layout';
import type { TemplateItem } from './types';

export type BuiltInLayoutSlug =
	| 'default'
	| 'stream'
	| 'rail'
	| 'clock'
	| 'margin'
	| 'minute'
	| 'wire'
	| 'digest'
	| 'flash';

export type BuiltInLayout = {
	slug: BuiltInLayoutSlug;
	title: string;
	template: () => TemplateItem[];
	latest?: number;
	hidesWhenEnded?: boolean;
	align?: string;
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
		{
			slug: 'wire',
			title: _x( 'Wire', 'layout name', 'newspack-rolling-coverage' ),
			template: wireInnerTemplate,
			latest: 5,
		},
		{
			slug: 'digest',
			title: _x( 'Digest', 'layout name', 'newspack-rolling-coverage' ),
			template: digestInnerTemplate,
			latest: 3,
		},
		{
			slug: 'flash',
			title: _x( 'Flash', 'layout name', 'newspack-rolling-coverage' ),
			template: flashInnerTemplate,
			latest: 1,
			hidesWhenEnded: true,
			align: 'full',
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
	align?: string;
} {
	const layout = getBuiltInLayouts().find( ( item ) => item.slug === slug );

	if ( layout?.latest ) {
		return {
			latestOnly: true,
			latestCount: layout.latest,
			hideWhenEnded: !! layout.hidesWhenEnded,
			...( layout.align ? { align: layout.align } : {} ),
		};
	}

	return { latestOnly: false, hideWhenEnded: false };
}

/**
 * The attributes a built-in layout sets when picked in place of another: its
 * cap, and its alignment. A layout that sets neither clears the values a
 * replaced layout set, while values chosen by hand stay.
 *
 * @param {BuiltInLayoutSlug}   slug         The picked layout's slug.
 * @param {BuiltInLayoutSlug[]} replaced     The built-in layouts that may have set the block's current values.
 * @param {string}              currentAlign The block's current alignment.
 * @return {Object} The attributes to set.
 */
export function switchLayoutAttributes(
	slug: BuiltInLayoutSlug,
	replaced: BuiltInLayoutSlug[],
	currentAlign?: string
): Partial< ReturnType< typeof layoutCapAttributes > > {
	const attributes: Partial< ReturnType< typeof layoutCapAttributes > > =
		layoutCapAttributes( slug );
	const replacedLayouts = getBuiltInLayouts().filter( ( layout ) =>
		replaced.includes( layout.slug )
	);

	if (
		! attributes.latestOnly &&
		! replacedLayouts.some( ( layout ) => layout.latest )
	) {
		delete attributes.latestOnly;
		delete attributes.hideWhenEnded;
	}

	if ( attributes.align || ! currentAlign ) {
		return attributes;
	}

	const setByLayout = replacedLayouts.some(
		( layout ) => layout.align === currentAlign
	);

	return setByLayout ? { ...attributes, align: undefined } : attributes;
}
