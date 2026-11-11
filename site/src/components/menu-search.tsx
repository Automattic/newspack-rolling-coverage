import { useEffect, useId, useRef } from 'react';
import { Search } from 'lucide-react';
import { useSearchContext } from 'fumadocs-ui/contexts/search';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { setSearchQuery } from './search';

/**
 * The search field at the top of a mobile drawer. It hands off to the search
 * dialog: `onLeave` closes the drawer and moves focus to the menu button, so
 * closing search returns focus there.
 */
export function MenuSearchField( { onLeave, className }: { onLeave: () => void; className?: string } ) {
	const id = useId();
	const { open, setOpenSearch } = useSearchContext();
	const pointer = useRef( false );
	const handedOff = useRef( false );

	useEffect( () => {
		if ( ! open ) {
			handedOff.current = false;
		}
	}, [ open ] );

	const openSearch = ( query = '' ) => {
		if ( query ) {
			setSearchQuery( query );
		}
		if ( handedOff.current ) {
			return;
		}
		handedOff.current = true;
		onLeave();
		setOpenSearch( true );
	};

	return (
		<div className={ cn( 'relative', className ) }>
			<label htmlFor={ id } className="sr-only">
				Search the docs
			</label>
			<Search
				aria-hidden="true"
				className="pointer-events-none absolute start-(--newspack-ui-spacer-2) top-1/2 size-(--newspack-ui-spacer-4) -translate-y-1/2 text-(color:--newspack-ui-color-text-subtle)"
			/>
			<Input
				id={ id }
				type="search"
				placeholder="Search"
				autoComplete="off"
				value=""
				className="ps-[calc(var(--newspack-ui-spacer-2)+var(--newspack-ui-spacer-4)+var(--newspack-ui-spacer-1))]"
				onPointerDown={ ( event ) => {
					if ( document.activeElement === event.currentTarget ) {
						openSearch();
					} else {
						pointer.current = true;
					}
				} }
				onFocus={ () => {
					// Keyboard focus alone must not open a dialog (WCAG 3.2.1); typing or Enter does.
					if ( pointer.current ) {
						openSearch();
					}
				} }
				onBlur={ () => {
					pointer.current = false;
				} }
				onKeyDown={ ( event ) => {
					if ( event.key === 'Enter' || event.key === 'ArrowDown' ) {
						event.preventDefault();
						event.stopPropagation();
						openSearch();
					}
				} }
				onChange={ ( event ) => openSearch( event.target.value ) }
			/>
		</div>
	);
}
