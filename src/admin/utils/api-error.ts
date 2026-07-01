/**
 * Extracts a human-readable error message from an unknown API error.
 *
 * @param {Object} error         - The caught error value.
 * @param {string} error.message - The error message.
 * @return {string} The error message string.
 */
function handleApiError( error: { message?: string } ): string {
	return error?.message ?? 'Unknown error';
}

export { handleApiError };
