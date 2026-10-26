import type { ReactNode } from 'react';
import { Info, TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

const CAUTION = [ 'warn', 'warning', 'error' ];

export function Callout( {
	type,
	title,
	children,
}: {
	type?: 'info' | 'tip' | 'idea' | 'success' | 'warn' | 'warning' | 'error';
	title?: ReactNode;
	children?: ReactNode;
} ) {
	return (
		<Alert role="note" className="my-4 bg-background">
			{ type && CAUTION.includes( type ) ? <TriangleAlert /> : <Info /> }
			{ title && <AlertTitle>{ title }</AlertTitle> }
			<AlertDescription className="prose-no-margin [&_strong]:text-inherit">{ children }</AlertDescription>
		</Alert>
	);
}
