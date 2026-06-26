/**
 * Internal dependencies
 */
import type { SlackErrorProps } from '../../../types';

/**
 * Renders an inline error message when present, otherwise nothing.
 *
 * @param {SlackErrorProps} props Component props.
 */
function SlackError( { message }: SlackErrorProps ) {
	if ( ! message ) {
		return null;
	}

	return <div className="newspack-rolling-coverage-error">{ message }</div>;
}

export { SlackError };
