/**
 * Internal dependencies
 */
import { ChipLink } from './chip-link';
import type { TermChipsProps } from '../types';

/**
 * Renders up to 2 taxonomy term links as chips, with a "+N" overflow indicator
 * for additional terms.
 *
 * @param {TermChipsProps} props Component props.
 */
function TermChips( { terms }: TermChipsProps ) {
	if ( ! terms?.length ) {
		return <span>—</span>;
	}
	const visible = terms.slice( 0, 2 );
	const remaining = terms.length - 2;
	return (
		<span className="newspack-rolling-coverage-term-chips">
			{ visible.map( ( t ) => (
				<ChipLink key={ t.link } href={ t.link } label={ t.name } />
			) ) }
			{ remaining > 0 && (
				<span className="newspack-rolling-coverage-term-chips__remaining">
					+{ remaining }
				</span>
			) }
		</span>
	);
}

export { TermChips };
