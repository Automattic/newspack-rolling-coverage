/**
 * Internal dependencies
 */
import type { BuiltInLayoutSlug } from './layouts';

/**
 * What a Rolling Coverage block's layout attributes point at: a built-in
 * layout, with the pattern the site has for it now (0 when none); another
 * pattern; or no shared layout.
 */
export type LayoutReference =
	| { kind: 'built-in'; slug: BuiltInLayoutSlug; patternId: number }
	| { kind: 'pattern'; id: number }
	| { kind: 'none' };

/**
 * Reads a block's `layoutId` and `layoutSlug` as the server does
 * (`Layout::resolve_layout_blocks()`). A built-in layout's pattern ID, its
 * current one or one the page renders as the layout, counts as the built-in
 * layout, so the block follows the layout whichever way it names it. The
 * pattern ID decides when both are set.
 *
 * @param {number} layoutId          The block's `layoutId`.
 * @param {string} layoutSlug        The block's `layoutSlug`.
 * @param {Object} layoutIds         Each built-in layout's pattern ID, 0 when none.
 * @param {Object} unrenderedLayouts Built-in layout slugs by the ID of each built-in pattern the page renders as its layout; PHP sends an empty one as an array.
 * @return {LayoutReference} What the block points at.
 */
export function layoutReferenceOf(
	layoutId: number,
	layoutSlug: string,
	layoutIds: Record< BuiltInLayoutSlug, number >,
	unrenderedLayouts: Record< string, string > | string[]
): LayoutReference {
	const isBuiltIn = ( slug: string | undefined ): slug is BuiltInLayoutSlug =>
		!! slug && Object.prototype.hasOwnProperty.call( layoutIds, slug );
	const builtIn = ( slug: BuiltInLayoutSlug ): LayoutReference => ( {
		kind: 'built-in',
		slug,
		patternId: layoutIds[ slug ] || 0,
	} );

	if ( layoutId > 0 ) {
		const slug =
			( Object.keys( layoutIds ) as BuiltInLayoutSlug[] ).find(
				( key ) => layoutIds[ key ] === layoutId
			) ??
			( Array.isArray( unrenderedLayouts )
				? undefined
				: unrenderedLayouts[ String( layoutId ) ] );

		return isBuiltIn( slug )
			? builtIn( slug )
			: { kind: 'pattern', id: layoutId };
	}

	return isBuiltIn( layoutSlug ) ? builtIn( layoutSlug ) : { kind: 'none' };
}

/**
 * The attributes that move a block pointing at a built-in layout's pattern
 * by ID to the layout's slug, or null when there's nothing to move. Named
 * by slug, the block keeps following the layout if the pattern is deleted,
 * and doesn't need the pattern to exist.
 *
 * @param {LayoutReference} reference What the block points at.
 * @param {number}          layoutId  The block's `layoutId`.
 * @return {Object|null} The attributes to set, or null.
 */
export function slugAttributesFor(
	reference: LayoutReference,
	layoutId: number
): { layoutId: 0; layoutSlug: BuiltInLayoutSlug } | null {
	return reference.kind === 'built-in' && layoutId > 0
		? { layoutId: 0, layoutSlug: reference.slug }
		: null;
}
