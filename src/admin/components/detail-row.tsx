/**
 * External dependencies
 */
import { Stack } from '@wordpress/ui';
import type { ReactNode } from 'react';

/**
 * A read-only label with its value on the line below, as used in the Slack
 * drawers and the bot user details.
 *
 * @param {Object}    props          - Component props.
 * @param {string}    props.label    - The label text.
 * @param {ReactNode} props.children - The value.
 */
function DetailRow( {
	label,
	children,
}: {
	label: string;
	children: ReactNode;
} ) {
	return (
		<Stack direction="column" gap="sm" align="flex-start">
			<span className="newspack-rolling-coverage-detail-label">
				{ label }
			</span>
			{ children }
		</Stack>
	);
}

export { DetailRow };
