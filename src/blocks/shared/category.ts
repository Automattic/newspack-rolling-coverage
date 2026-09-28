/**
 * WordPress dependencies
 */
import { getCategories, setCategories } from '@wordpress/blocks';
import type { BlockCategory } from '@wordpress/blocks';

/**
 * External dependencies
 */
import { activity } from 'newspack-icons';

const FALLBACK_CATEGORY = 'rolling-coverage';

/**
 * The blocks join the Newspack category when another Newspack plugin has
 * registered it, and fall back to their own category on standalone sites.
 *
 * @return {string} The category slug to register the blocks under.
 */
function getBlockCategory(): string {
	const categories = getCategories();

	if ( categories.some( ( { slug } ) => slug === 'newspack' ) ) {
		return 'newspack';
	}

	if ( ! categories.some( ( { slug } ) => slug === FALLBACK_CATEGORY ) ) {
		setCategories( [
			...categories,
			{
				slug: FALLBACK_CATEGORY,
				title: 'Rolling Coverage',
				icon: activity,
			} as BlockCategory,
		] );
	}

	return FALLBACK_CATEGORY;
}

export { getBlockCategory };
