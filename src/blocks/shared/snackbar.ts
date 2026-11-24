type NoticeType = 'success' | 'error';

type NewspackUI = {
	notices?: {
		createNotice?: ( message: string, type?: NoticeType ) => void;
	};
};

export type ShowSnackbar = ( message: string, type?: NoticeType ) => void;

/**
 * Newspack UI's snackbar, when Newspack UI is on the page.
 *
 * Newspack UI adds its snackbar to the first `.newspack-ui` element, which can
 * be a hidden modal, so the returned function gives it its own container
 * first.
 *
 * @return {ShowSnackbar|null} Shows a notice, or null without Newspack UI.
 */
export function getSnackbar(): ShowSnackbar | null {
	const createNotice = ( window as Window & { newspackUI?: NewspackUI } )
		.newspackUI?.notices?.createNotice;

	if ( ! createNotice ) {
		return null;
	}

	return ( message, type = 'success' ) => {
		if ( ! document.querySelector( '.newspack-ui__snackbar' ) ) {
			const wrapper = document.createElement( 'div' );
			const snackbar = document.createElement( 'div' );

			wrapper.className = 'newspack-ui';
			snackbar.className = 'newspack-ui__snackbar';
			wrapper.appendChild( snackbar );
			document.body.appendChild( wrapper );
		}

		createNotice( message, type );
	};
}
