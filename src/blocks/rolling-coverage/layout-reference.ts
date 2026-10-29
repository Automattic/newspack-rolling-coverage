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
 * Where a block sits in the editor, as far as moving it to a slug goes.
 */
export type SlugMoveContext = {
	isNested: boolean;
	isLayoutPattern: boolean;
	isPreviewMode: boolean;
	isReadOnly: boolean;
};

/**
 * The attributes that move a block pointing at a built-in layout's pattern
 * by ID to the layout's slug, or null when there's nothing to move or the
 * block can't be changed here. Named by slug, the block doesn't need the
 * pattern to exist. A nested block's reference is dropped anyway, a layout
 * pattern and a preview aren't stories, and a block shown read-only belongs
 * to other content, whose own editor moves it.
 *
 * @param {LayoutReference} reference What the block points at.
 * @param {number}          layoutId  The block's `layoutId`.
 * @param {SlugMoveContext} where     Where the block sits in the editor.
 * @return {Object|null} The attributes to set, or null.
 */
export function slugAttributesFor(
	reference: LayoutReference,
	layoutId: number,
	where: SlugMoveContext
): { layoutId: 0; layoutSlug: BuiltInLayoutSlug } | null {
	if (
		where.isNested ||
		where.isLayoutPattern ||
		where.isPreviewMode ||
		where.isReadOnly
	) {
		return null;
	}

	return reference.kind === 'built-in' && layoutId > 0
		? { layoutId: 0, layoutSlug: reference.slug }
		: null;
}
