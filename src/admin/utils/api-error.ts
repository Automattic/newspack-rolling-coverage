/**
 * Extracts a human-readable error message from an unknown API error.
 *
 * @param {unknown} error - The caught error value.
 * @return {string} The error message string.
 */
function handleApiError( error: unknown ): string {
	return error instanceof Error ? error.message : 'Unknown error';
}

export { handleApiError };
