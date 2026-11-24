type TestWindow = Window & {
	newspackUI?: unknown;
	OneSignalDeferred?: unknown[];
};

const MESSAGE = 'Something went wrong. Please try again.';

const addButton = () => {
	document.body.innerHTML = `<div class="wp-block-buttons"><div class="wp-block-button"><button class="wp-block-button__link" data-rc-follow data-tag="coverage_1" data-error-message="${ MESSAGE }">Follow</button></div></div>`;

	return document.querySelector( 'button' ) as HTMLButtonElement;
};

const inlineMessage = () =>
	document.querySelector( '.newspack-rolling-coverage-follow__message' );

describe( 'a failed follow', () => {
	beforeAll( async () => {
		await import( '../../src/blocks/coverage-follow/view' );
	} );

	beforeEach( () => {
		jest.useFakeTimers();
		delete ( window as TestWindow ).newspackUI;
		delete ( window as TestWindow ).OneSignalDeferred;
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'shows the error in the Newspack UI snackbar', () => {
		const createNotice = jest.fn();
		( window as TestWindow ).newspackUI = { notices: { createNotice } };

		const button = addButton();
		button.click();
		jest.advanceTimersByTime( 10000 );

		expect( createNotice ).toHaveBeenCalledWith( MESSAGE, 'error' );
		expect( inlineMessage() ).toBeNull();
		expect( button.textContent ).toBe( 'Follow' );
	} );

	it( 'shows the error after the button without Newspack UI', () => {
		const button = addButton();
		button.click();
		jest.advanceTimersByTime( 10000 );

		expect( inlineMessage()?.textContent ).toBe( MESSAGE );
		expect( button.textContent ).toBe( 'Follow' );
	} );
} );
