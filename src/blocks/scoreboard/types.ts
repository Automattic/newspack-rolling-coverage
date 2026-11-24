/**
 * Attributes saved on the Scoreboard block.
 */
export type ScoreboardAttributes = {
	coverageId: number;
	showStatusAsBadge: boolean;
	showDot: boolean;
	backgroundColors: Partial< Record< string, string > >;
};
