const ASPECTS = {
	'16/10': 'aspect-[16/10]',
	'4/3': 'aspect-[4/3]',
	'390/844': 'aspect-[390/844] max-w-xs',
} as const;

export function Screenshot( {
	label,
	src,
	width,
	height,
	aspect = '16/10',
}: {
	label: string;
	src?: string;
	width?: number;
	height?: number;
	aspect?: keyof typeof ASPECTS;
} ) {
	if ( src ) {
		return (
			<figure className="not-prose my-6">
				<img
					src={ src }
					alt={ label }
					width={ width }
					height={ height }
					loading="lazy"
					decoding="async"
					className="h-auto w-full rounded-xl border border-fd-border"
				/>
			</figure>
		);
	}

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
