/**
 * Extracts a human-readable error message from an unknown API error.
 *
 * @param {unknown} error - The caught error value.
 * @return {string} The error message string.
 */
function handleApiError( error: unknown ): string {
	if ( error && typeof error === 'object' ) {
		const err = error as {
			code?: string;
			error?: string;
			message?: string;
			data?: { error?: string; message?: string };
		};

		return (
			err.message ||
			err.data?.message ||
			err.error ||
			err.data?.error ||
			'Unknown error'
		);
	}

	if ( typeof error === 'string' && error ) {
		return error;
	}

	return 'Unknown error';
}

export { handleApiError };
