/**
 * WordPress dependencies
 */
import { getCategories } from '@wordpress/blocks';

/**
 * The category Block_Category registered server-side: Newspack on Newspack
 * sites, the plugin's own on standalone ones.
 *
 * @return {string} The category slug to register the blocks under.
 */
function getBlockCategory(): string {
	return getCategories().some( ( { slug } ) => slug === 'newspack' )
		? 'newspack'
		: 'rolling-coverage';
}

export { getBlockCategory };
