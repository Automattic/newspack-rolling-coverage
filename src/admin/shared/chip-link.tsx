/**
 * Internal dependencies
 */
import type { ChipLinkProps } from '../types';

/**
 * Renders an external-link chip styled with the plugin's chip-link class.
 *
 * @param {ChipLinkProps} props Component props.
 */
function ChipLink( { href, label, variant }: ChipLinkProps ) {
	const statusModifier = variant ? `is-status-${ variant }` : '';

	return (
		<a
			href={ href }
			target="_blank"
			rel="noopener noreferrer"
			className={ `newspack-rolling-coverage-chip-link ${ statusModifier }` }
		>
			{ label }
		</a>
	);
}

export { ChipLink };
