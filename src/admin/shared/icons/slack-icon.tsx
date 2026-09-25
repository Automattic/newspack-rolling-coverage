/**
 * External dependencies
 */
import type { JSX } from 'react';

/**
 * Renders the Slack logo as an inline SVG. Used in DataViews columns and
 * entry indicators to flag Slack-sourced entries.
 *
 * @param {Object} props           Component props.
 * @param {number} [props.size=16] - The icon width/height in pixels.
 */
function SlackIcon( { size = 16 }: { size?: number } ): JSX.Element {
	return (
		<svg
			width={ size }
			height={ size }
			viewBox="0 0 400 400"
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
			focusable="false"
		>
			<path
				fill="#e3066a"
				d="M84 252.8a42.1 42.1 0 0 1-84 0 42 42 0 0 1 42-42h42zm21.2 0a42.1 42.1 0 0 1 84 0V358a42.1 42.1 0 0 1-84 0z"
			/>
			<path
				fill="#00b3ff"
				d="M147.2 84a42.1 42.1 0 0 1 0-84 42 42 0 0 1 42 42v42zm0 21.2a42.1 42.1 0 0 1 0 84H42a42.1 42.1 0 0 1 0-84z"
			/>
			<path
				fill="#41b658"
				d="M316 147.2a42.1 42.1 0 0 1 84 0 42 42 0 0 1-42 42h-42zm-21.2 0a42.1 42.1 0 0 1-84 0V42a42.1 42.1 0 0 1 84 0z"
			/>
			<path
				fill="#fcc003"
				d="M252.8 316a42.1 42.1 0 0 1 0 84 42 42 0 0 1-42-42v-42zm0-21.2a42.1 42.1 0 0 1 0-84H358a42.1 42.1 0 0 1 0 84z"
			/>
		</svg>
	);
}

export { SlackIcon };
