/**
 * Renders a single labeled row in the bot user details section.
 *
 * @param {Object} props       - Component props.
 * @param {string} props.label - The label text.
 * @param {string} props.value - The value text.
 */
function BotUserDetail( { label, value }: { label: string; value: string } ) {
	return (
		<div>
			<strong>{ label }</strong> <span>{ value || '—' }</span>
		</div>
	);
}

export { BotUserDetail };
