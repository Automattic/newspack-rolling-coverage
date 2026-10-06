/**
 * External dependencies
 */
import type { ComponentType, ReactNode } from 'react';
// The only API that replaces the editor's back button.
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalMainDashboardButton } from '@wordpress/edit-post';
import { Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __, isRTL } from '@wordpress/i18n';
import { chevronLeft, chevronRight } from '@wordpress/icons';

type EntryEditorData = {
	coveragesUrl: string;
	coverageRestBase: string;
};

type EditorPostSelectors = {
	getEditedPostAttribute: ( attribute: string ) => unknown;
};

declare global {
	interface Window {
		newspackRollingCoverageEntryEditor?: EntryEditorData & {
			pushNotifications: boolean;
		};
	}
}

// The slot's component is typed with a required `children` of a different shape.
const MainDashboardButton =
	__experimentalMainDashboardButton as unknown as ComponentType< {
		children: ReactNode;
	} >;

/**
 * The first coverage the entry belongs to, as it is being edited.
 *
 * @param {string} restBase REST base of the coverage taxonomy.
 * @return {number} The coverage ID, or 0 when the entry has none.
 */
function useEntryCoverageId( restBase: string ): number {
	return useSelect(
		( select ) => {
			const editor = select(
				editorStore
			) as unknown as EditorPostSelectors;
			const ids = editor.getEditedPostAttribute( restBase );
			return Array.isArray( ids ) ? Number( ids[ 0 ] ) || 0 : 0;
		},
		[ restBase ]
	);
}

/**
 * Replaces the editor's back button, which opens the entries list core
 * hides, with a link to the entry's coverage.
 *
 * @param {Object} props
 * @param {string} props.coveragesUrl     URL of the coverages screen.
 * @param {string} props.coverageRestBase REST base of the coverage taxonomy.
 * @return {Object} The back button.
 */
export function BackToCoverage( {
	coveragesUrl,
	coverageRestBase,
}: EntryEditorData ) {
	const coverageId = useEntryCoverageId( coverageRestBase );
	const href =
		coverageId > 0
			? `${ coveragesUrl }#/coverages/${ coverageId }`
			: `${ coveragesUrl }#/coverages`;
	const label =
		coverageId > 0
			? __( 'Back to Coverage', 'newspack-rolling-coverage' )
			: __( 'Back to All Coverages', 'newspack-rolling-coverage' );

	return (
		<MainDashboardButton>
			<Button
				size="compact"
				href={ href }
				label={ label }
				showTooltip
				tooltipPosition="bottom"
				icon={ isRTL() ? chevronRight : chevronLeft }
			/>
		</MainDashboardButton>
	);
}
