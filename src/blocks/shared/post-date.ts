/**
 * Post date attributes that show the post's own publish date. Without the
 * binding, core treats the block as a custom date and the editor saves the
 * current time into it, so every entry would show the same date.
 */
const POST_DATE_ATTRIBUTES = {
	metadata: {
		bindings: {
			datetime: { source: 'core/post-data', args: { field: 'date' } },
		},
	},
};

export { POST_DATE_ATTRIBUTES };
