/**
 * Attributes saved on the Coverage Status block.
 */
export type CoverageStatusAttributes = {
	coverageId: number;
	showLastUpdated: boolean;
	labels: Partial< Record< string, string > >;
	textColor?: string;
	style?: {
		color?: { text?: string };
		spacing?: { blockGap?: string | Record< string, string > };
	};
};
