import { Button } from '@/components/ui/button';
import { applyTheme, useTheme } from '@/lib/theme';

function StylesIcon() {
	return (
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true" focusable="false">
			<path d="M12 4.75C7.99594 4.75 4.75 7.99593 4.75 12C4.75 16.0041 7.99594 19.25 12 19.25L12 4.75Z" fill="currentColor" stroke="none" />
			<path
				d="M12 19.25C16.0041 19.25 19.25 16.0041 19.25 12C19.25 7.99594 16.0041 4.75 12 4.75C7.99594 4.75 4.75 7.99594 4.75 12C4.75 16.0041 7.99594 19.25 12 19.25Z"
				strokeLinejoin="round"
			/>
		</svg>
	);
}

export function ThemeToggle( { className }: { className?: string } ) {
	const dark = useTheme() === 'dark';
	return (
		<Button type="button" variant="ghost" size="icon-sm" className={ className } onClick={ () => applyTheme( dark ? 'light' : 'dark' ) }>
			<StylesIcon />
			<span className="sr-only">{ dark ? 'Switch to light mode' : 'Switch to dark mode' }</span>
		</Button>
	);
}
