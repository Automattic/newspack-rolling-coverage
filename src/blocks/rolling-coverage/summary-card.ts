/**
 * Internal dependencies
 */
import { getFormatter, refreshRelativeDates } from '../shared/relative-dates';
import type { PollSummary } from './types';

/**
 * The summary card, or the empty placeholder a feed holds while there is no
 * Summary, mirroring Rolling_Coverage_Block::SUMMARY_ATTRIBUTE.
 */
export const SUMMARY_ATTRIBUTE = 'data-rc-summary';

/**
 * The card's "Updated" line in its clock-time form, mirroring
 * Rolling_Coverage_Block::SUMMARY_UPDATED_ATTRIBUTE. The card's own text
 * can't stand in for it: a browser's page translation, an embed's script or
 * the relative dates the view script keeps current all change that text.
 */
export const SUMMARY_UPDATED_ATTRIBUTE = 'data-rc-updated';

/**
 * Shows the Summary a poll brings in a feed's summary card: a new one in
 * place of the empty placeholder, an edit in place of the card, the card
 * again when its "Updated" line now reads differently (the edit is no longer
 * today's), or the placeholder in place of a Summary taken down. A card
 * whose markers match the reply stays as it is. A card shown says how long
 * ago from the start, as the reply's can come from a cache.
 *
 * @param {HTMLElement}      card    The feed's summary card or placeholder.
 * @param {PollSummary|null} summary The coverage's Summary, or null for none.
 * @param {Function}         parse   Turns the reply's markup into an element,
 *                                   sanitized.
 * @return {boolean} Whether the card was replaced.
 */
export function applySummaryCard(
	card: HTMLElement,
	summary: PollSummary | null,
	parse: ( html: string ) => HTMLElement | null
): boolean {
	const shownId = card.getAttribute( SUMMARY_ATTRIBUTE ) ?? '0';

	if ( ! summary ) {
		if ( shownId === '0' ) {
			return false;
		}

		const placeholder = card.ownerDocument.createElement( 'div' );
		placeholder.className = 'newspack-rolling-coverage-summary-card';
		placeholder.setAttribute( SUMMARY_ATTRIBUTE, '0' );
		placeholder.hidden = true;
		card.replaceWith( placeholder );
		return true;
	}

	if (
		shownId === String( summary.id ) &&
		card.dataset.modifiedGmt === summary.modified &&
		card.getAttribute( SUMMARY_UPDATED_ATTRIBUTE ) === summary.updated
	) {
		return false;
	}

	const next = parse( summary.html );

	if ( ! next?.hasAttribute( SUMMARY_ATTRIBUTE ) ) {
		return false;
	}

	card.replaceWith( next );

	const formatter = getFormatter();

	if ( formatter ) {
		refreshRelativeDates( next, formatter );
	}

	return true;
}
