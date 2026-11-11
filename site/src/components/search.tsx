import { useEffect, useLayoutEffect, useRef, useSyncExternalStore } from 'react';
import { SearchDialog, SearchDialogList, useSearch, type SharedProps } from 'fumadocs-ui/components/dialog/search';
import { useStaticSearch } from 'fumadocs-core/search/client';
import { Button } from '@/components/ui/button';
import { DialogClose, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { CloseIcon } from '@/lib/newspack-ui-icons';

let applyQuery: ( ( query: string ) => void ) | null = null;

/**
 * Sets the search dialog's query from outside it, such as the drawer's search field.
 */
export function setSearchQuery( query: string ) {
	applyQuery?.( query );
}

function SearchField() {
	const { search, onSearchChange, getActive, subscribeActive } = useSearch();
	const active = useSyncExternalStore( subscribeActive, getActive, getActive );

	return (
		<div className="flex items-center gap-(--newspack-ui-spacer-2) p-(--newspack-ui-spacer-3)">
			<Input
				role="combobox"
				aria-expanded={ active !== null }
				aria-activedescendant={ active !== null ? `fd-search-option-${ active }` : undefined }
				aria-label="Search the docs"
				aria-autocomplete="list"
				aria-controls="fd-search-list"
				placeholder="Search the docs"
				value={ search }
				onChange={ ( event ) => onSearchChange( event.target.value ) }
			/>
			<DialogClose asChild>
				<Button
					variant="ghost"
					size="icon"
					aria-label="Close search"
					className="shrink-0 rounded-(--newspack-ui-radius-pill) max-[599px]:size-(--newspack-ui-touch-target) [&_svg]:size-(--newspack-ui-spacer-4)"
				>
					<CloseIcon />
				</Button>
			</DialogClose>
		</div>
	);
}

function SearchEmpty() {
	const { error } = useSearch();
	return (
		<div
			role="status"
			className="px-(--newspack-ui-spacer-5) py-(--newspack-ui-spacer-8) text-center text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small) text-muted-foreground"
		>
			{ error ? "Search couldn't load. Reload the page and try again." : 'No pages match. Try another word, such as Slack or layout.' }
		</div>
	);
}

export default function DocsSearchDialog( props: SharedProps ) {
	const search = useStaticSearch();
	const returnFocus = useRef< HTMLElement | null >( null );
	const { onSearchChange } = search;

	useEffect( () => {
		applyQuery = onSearchChange;
		return () => {
			if ( applyQuery === onSearchChange ) {
				applyQuery = null;
			}
		};
	}, [ onSearchChange ] );

	useLayoutEffect( () => {
		if ( props.open ) {
			returnFocus.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
		}
	}, [ props.open ] );

	return (
		<SearchDialog { ...search } { ...props }>
			<DialogContent
				showCloseButton={ false }
				aria-describedby={ undefined }
				onOpenAutoFocus={ ( event ) => {
					const input = event.currentTarget instanceof HTMLElement ? event.currentTarget.querySelector( 'input' ) : null;
					if ( input ) {
						event.preventDefault();
						input.focus();
						input.setSelectionRange( input.value.length, input.value.length );
					}
				} }
				onCloseAutoFocus={ ( event ) => {
					if ( returnFocus.current?.isConnected ) {
						event.preventDefault();
						returnFocus.current.focus();
					}
				} }
				className="top-[calc(50%-15rem)] max-w-(--newspack-ui-width-m) translate-y-0 gap-0 overflow-hidden p-0 max-[599px]:top-(--newspack-ui-spacer-3) max-[599px]:bottom-auto max-[599px]:left-1/2 max-[599px]:w-[calc(100%-var(--newspack-ui-spacer-3))] max-[599px]:-translate-x-1/2 max-[599px]:rounded-(--newspack-ui-radius-surface)"
			>
				<DialogTitle className="sr-only">Search the docs</DialogTitle>
				<SearchField />
				<SearchDialogList Empty={ () => <SearchEmpty /> } />
			</DialogContent>
		</SearchDialog>
	);
}
