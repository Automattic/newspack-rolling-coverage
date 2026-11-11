import { Moon, Sun } from 'lucide-react';
import { SegmentedControl, SegmentedControlItem } from '@/components/ui/segmented-control';
import { applyTheme, useTheme, type Theme } from '@/lib/theme';

const ITEM = '[&_svg]:size-(--newspack-ui-spacer-3)';

export function ThemeToggle( { className }: { className?: string } ) {
	const theme = useTheme();
	return (
		<SegmentedControl
			size="sm"
			aria-label="Theme"
			value={ theme }
			onValueChange={ ( value ) => applyTheme( value as Theme ) }
			className={ className }
		>
			<SegmentedControlItem value="light" aria-label="Light" className={ ITEM }>
				<Sun aria-hidden="true" />
			</SegmentedControlItem>
			<SegmentedControlItem value="dark" aria-label="Dark" className={ ITEM }>
				<Moon aria-hidden="true" />
			</SegmentedControlItem>
		</SegmentedControl>
	);
}
