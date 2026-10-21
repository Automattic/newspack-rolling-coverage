const ASPECTS = {
	'16/10': 'aspect-[16/10]',
	'4/3': 'aspect-[4/3]',
	'390/844': 'aspect-[390/844] max-w-xs',
} as const;

export function Screenshot( {
	label,
	aspect = '16/10',
}: {
	label: string;
	aspect?: keyof typeof ASPECTS;
} ) {
	return (
		<figure
			role="img"
			aria-label={ `Screenshot placeholder: ${ label }` }
			className={ `not-prose my-6 flex w-full items-center justify-center rounded-xl border border-dashed border-fd-border bg-fd-muted p-6 text-center text-sm text-fd-muted-foreground ${ ASPECTS[ aspect ] }` }
		>
			<span aria-hidden="true">Screenshot: { label }</span>
		</figure>
	);
}
