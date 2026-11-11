"use client"

import { Icon, styles } from '@wordpress/icons';
import { Button } from '@/components/ui/button';
import { applyTheme, useTheme } from '@/lib/theme';
import { cn } from '@/lib/utils';

export function ThemeToggle( { className }: { className?: string } ) {
	const dark = useTheme() === 'dark';
	return (
		<Button
			type="button"
			variant="ghost"
			size="icon-sm"
			className={ cn( 'max-md:hidden', className ) }
			onClick={ () => applyTheme( dark ? 'light' : 'dark' ) }
		>
			<Icon icon={ styles } />
			<span className="sr-only">{ dark ? 'Switch to light mode' : 'Switch to dark mode' }</span>
		</Button>
	);
}
