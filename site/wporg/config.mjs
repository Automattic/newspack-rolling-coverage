const OUTER_ARCS =
	'M17.657 6.343A8 8 0 0 1 17.657 17.657L16.596 16.596A6.5 6.5 0 0 0 16.596 7.404ZM6.343 17.657A8 8 0 0 1 6.343 6.343L7.404 7.404A6.5 6.5 0 0 0 7.404 16.596Z';
const INNER_ARCS =
	'M15.536 8.464A5 5 0 0 1 15.536 15.536L14.475 14.475A3.5 3.5 0 0 0 14.475 9.525ZM8.464 15.536A5 5 0 0 1 8.464 8.464L9.525 9.525A3.5 3.5 0 0 0 9.525 14.475Z';
const DOT = 'M12 10A2 2 0 1 1 12 14A2 2 0 1 1 12 10ZM12 11.5A0.5 0.5 0 1 0 12 12.5A0.5 0.5 0 1 0 12 11.5Z';

/**
 * Rolling Coverage listing config. The mark is the broadcast glyph from
 * site/src/components/live-icon.tsx. `crop` holds the selectors that frame the
 * screenshot, the ones the capture records with --boxes.
 */
export default {
	name: 'Rolling Coverage',
	markPath: OUTER_ARCS + INNER_ARCS + DOT,
	headline: { before: 'Cover it live, whatever the ', accent: 'story', after: '' },
	headlineSize: 34,
	description: 'Live updates for developing stories, posted from WordPress or Slack.',
	leftWidth: 300,
	frame: { x: 360 },
	crop: {
		left: '.newspack-rolling-coverage-entries .wp-block-columns > .wp-block-column:first-child',
		right: '.newspack-rolling-coverage-entries .newspack-rolling-coverage-timeline',
		anchor: '.newspack-rolling-coverage-entry[data-new] .wp-block-post-title',
		anchorY: 83,
		drawWidth: 372,
		frameHeight: 250,
	},
};
