/**
 * WordPress dependencies
 */
import { InnerBlocks } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import type { RollingCoverageAttributes } from './types';

export default function Save( {
	attributes,
}: {
	attributes: RollingCoverageAttributes;
} ) {
	return attributes.layoutId ? null : <InnerBlocks.Content />;
}
