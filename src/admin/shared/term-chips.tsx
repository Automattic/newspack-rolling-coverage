/**
 * External dependencies
 */
import { Tooltip } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { ChipLink } from './chip-link';
import type { TermChipsProps } from '../types';

/** Maximum number of chips shown before the "+N" overflow indicator. */
const VISIBLE_LIMIT = 2;

/**
 * Renders up to 2 taxonomy term links as chips. When more terms are assigned,
 * a "+N" indicator reveals the remaining term names in a tooltip.
 *
 * When a term-name filter is active (`highlightName`), terms matching it are
 * shown first so the filtered term stays visible; the rest fall under "+N".
 *
 * @param {TermChipsProps} props Component props.
 */
function TermChips( { terms, highlightName }: TermChipsProps ) {
	if ( ! terms?.length ) {
		return <span>—</span>;
	}

	const needle = highlightName?.trim().toLowerCase() ?? '';
	const ordered =
		'' === needle
			? terms
			: [
					...terms.filter( ( t ) =>
						t.name.toLowerCase().includes( needle )
					),
					...terms.filter(
						( t ) => ! t.name.toLowerCase().includes( needle )
					),
				];

	const visible = ordered.slice( 0, VISIBLE_LIMIT );
	const remaining = ordered.slice( VISIBLE_LIMIT );

	return (
		<span className="newspack-rolling-coverage-term-chips">
			{ visible.map( ( t ) => (
				<ChipLink key={ t.link } href={ t.link } label={ t.name } />
			) ) }
			{ remaining.length > 0 && (
				<Tooltip text={ remaining.map( ( t ) => t.name ).join( ', ' ) }>
					<span className="newspack-rolling-coverage-term-chips__remaining">
						+{ remaining.length }
					</span>
				</Tooltip>
			) }
		</span>
	);
}

export { TermChips };
