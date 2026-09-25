declare module 'newspack-components/dist/esm/page' {
	import type { ComponentType, ReactNode } from 'react';

	const Page: ComponentType< {
		breadcrumbItems: { label: string; url?: string; count?: number }[];
		tabbedNavigation?: ReactNode;
		badges?: ReactNode;
		subTitle?: ReactNode;
		actions?: ReactNode;
		className?: string;
		children?: ReactNode;
	} >;
	export default Page;
}

declare module 'newspack-components/dist/esm/status-indicator' {
	import type { ComponentType, ReactNode } from 'react';
	import type { StatusName } from './admin/types';

	const StatusIndicator: ComponentType< {
		status?: StatusName;
		icon?: JSX.Element;
		className?: string;
		children?: ReactNode;
	} >;
	export default StatusIndicator;
}

declare module 'newspack-components/dist/esm/drawer' {
	import type { ComponentType, ReactNode } from 'react';

	type Slot = ComponentType< { className?: string; children?: ReactNode } >;

	const Drawer: {
		Root: ComponentType< {
			isOpen: boolean;
			onRequestClose: () => void;
			isDirty?: boolean;
			size?: 'small' | 'medium' | 'large' | 'x-large' | 'full';
			className?: string;
			children?: ReactNode;
		} >;
		Header: Slot;
		Title: Slot;
		CloseIcon: ComponentType< { label?: string } >;
		Content: ComponentType< {
			gap?: number;
			padding?: number;
			className?: string;
			children?: ReactNode;
		} >;
		Divider: ComponentType;
		Footer: Slot;
		Action: ComponentType< {
			variant?: 'primary' | 'secondary' | 'tertiary';
			closes?: boolean;
			onClick?: () => void;
			isBusy?: boolean;
			disabled?: boolean;
			children?: ReactNode;
		} >;
	};
	export { Drawer };
}

declare module 'newspack-components/dist/esm/tabbed-navigation' {
	import type { ComponentType } from 'react';

	const TabbedNavigation: ComponentType< {
		items: { label: string; href: string; selected?: boolean }[];
		className?: string;
	} >;
	export default TabbedNavigation;
}

declare module 'newspack-components/dist/esm/empty-state' {
	import type { ComponentType, ReactNode, JSX } from 'react';

	const EmptyState: {
		Root: ComponentType< {
			size?: 'default' | 'small';
			className?: string;
			children?: ReactNode;
		} >;
		Header: ComponentType< {
			icon?: JSX.Element;
			title: string;
			description?: ReactNode;
			heading?: 1 | 2 | 3 | 4 | 5 | 6;
			className?: string;
		} >;
		Actions: ComponentType< {
			orientation?: 'row' | 'column';
			className?: string;
			children?: ReactNode;
		} >;
	};
	export { EmptyState };
}

declare module 'newspack-icons' {
	import type { JSX } from 'react';

	export const activity: JSX.Element;
}
