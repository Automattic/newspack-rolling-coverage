/**
 * External dependencies
 */
import { Notice } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import type { ErrorNoticeProps } from '../types';

/**
 * Renders an inline error message when present, otherwise nothing.
 *
 * @param {ErrorNoticeProps} props Component props.
 */
function ErrorNotice( { message, className }: ErrorNoticeProps ) {
	if ( ! message ) {
		return null;
	}

	return (
		<Notice.Root
			className={ className }
			intent="error"
			spokenMessage={ message }
		>
			<Notice.Description>{ message }</Notice.Description>
		</Notice.Root>
	);
}

export { ErrorNotice };
