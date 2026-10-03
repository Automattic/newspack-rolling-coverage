/**
 * Attributes saved on the Coverage Status block.
 */
export type CoverageStatusAttributes = {
	coverageId: number;
	showLastUpdated: boolean;
	hideWhenEnded: boolean;
	showDot: boolean;
	backgroundColors: Partial< Record< string, string > >;
	labels: Partial< Record< string, string > >;
	textColor?: string;
	style?: {
		color?: { text?: string };
		spacing?: { blockGap?: string | Record< string, string > };
	};
};
