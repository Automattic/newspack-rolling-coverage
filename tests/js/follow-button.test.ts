type Deferred = ( os: unknown ) => void;

type TestWindow = Window & {
	newspackUI?: unknown;
	OneSignalDeferred?: Deferred[];
};

const ERROR = 'Something went wrong. Please try again.';
const ON = 'You’ll get alerts for Kettle River flood.';
const OFF = 'Alerts for Kettle River flood are off.';

const addButtons = ( count = 1 ) => {
	document.body.innerHTML = Array.from(
		{ length: count },
		() =>
			`<div class="wp-block-button"><button class="wp-block-button__link" data-rc-follow data-tag="coverage_1" aria-pressed="false" data-error-message="${ ERROR }" data-on-message="${ ON }" data-off-message="${ OFF }"><span data-rc-label="off">Get alerts</span></button></div>`
	).join( '' );

	return Array.from( document.querySelectorAll( 'button' ) );
};

const fakeOneSignal = () => ( {
	Notifications: {
		permission: true,
		isPushSupported: () => true,
		requestPermission: jest.fn(),
		addEventListener: jest.fn(),
		removeEventListener: jest.fn(),
	},
	User: {
		addTag: jest.fn(),
		removeTag: jest.fn(),
		getTags: () => ( {} ),
	},
} );

const runSdk = async ( OneSignal: unknown ) => {
	const queue = ( window as TestWindow ).OneSignalDeferred ?? [];
	( window as TestWindow ).OneSignalDeferred = [];
	for ( const callback of queue ) {
		await callback( OneSignal );
	}
};

const inlineMessage = () =>
	document.querySelector( '.newspack-rolling-coverage-follow__message' );

const announcer = () =>
	document.getElementById( 'newspack-rolling-coverage-follow-announcer' );

describe( 'the follow button', () => {
	beforeAll( async () => {
		await import( '../../src/blocks/coverage-follow/view' );
	} );

	beforeEach( () => {
		jest.useFakeTimers();
		delete ( window as TestWindow ).newspackUI;
		( window as TestWindow ).OneSignalDeferred = [];
		document.body.innerHTML = '';
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'shows the spinner for at least a second, then the new state', async () => {
		const createNotice = jest.fn();
		( window as TestWindow ).newspackUI = { notices: { createNotice } };
		const OneSignal = fakeOneSignal();

		const [ button ] = addButtons();
		button.click();
		await runSdk( OneSignal );

		expect( OneSignal.User.addTag ).toHaveBeenCalledWith(
			'coverage_1',
			'1'
		);
		expect( button.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect( button.getAttribute( 'aria-pressed' ) ).toBe( 'false' );

		jest.advanceTimersByTime( 999 );
		expect( button.getAttribute( 'aria-busy' ) ).toBe( 'true' );

		jest.advanceTimersByTime( 1 );
		expect( button.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( button.hasAttribute( 'aria-disabled' ) ).toBe( false );
		expect( button.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect( createNotice ).toHaveBeenCalledWith( ON, 'success' );
	} );

	it( 'reads "Alerts on" until the pointer leaves after switching on', async () => {
		const [ button ] = addButtons();
		button.click();
		await runSdk( fakeOneSignal() );
		jest.advanceTimersByTime( 1000 );

		expect( button.hasAttribute( 'data-rc-fresh' ) ).toBe( true );

		button.dispatchEvent( new Event( 'pointerout', { bubbles: true } ) );
		expect( button.hasAttribute( 'data-rc-fresh' ) ).toBe( false );
	} );

	it( 'confirms alerts going off, naming the liveblog', async () => {
		const createNotice = jest.fn();
		( window as TestWindow ).newspackUI = { notices: { createNotice } };
		const OneSignal = fakeOneSignal();

		const [ button ] = addButtons();
		button.setAttribute( 'aria-pressed', 'true' );
		button.click();
		await runSdk( OneSignal );
		jest.advanceTimersByTime( 1000 );

		expect( OneSignal.User.removeTag ).toHaveBeenCalledWith( 'coverage_1' );
		expect( button.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
		expect( button.hasAttribute( 'data-rc-fresh' ) ).toBe( false );
		expect( createNotice ).toHaveBeenCalledWith( OFF, 'success' );
	} );

	it( 'announces the change without Newspack UI', async () => {
		const [ button ] = addButtons();
		button.click();
		await runSdk( fakeOneSignal() );
		jest.advanceTimersByTime( 1000 );

		expect( announcer()?.textContent ).toBe( ON );
		expect( inlineMessage() ).toBeNull();
	} );

	it( 'ignores clicks while a toggle is pending', async () => {
		const OneSignal = fakeOneSignal();

		const [ button ] = addButtons();
		button.click();
		button.click();
		await runSdk( OneSignal );

		expect( OneSignal.User.addTag ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps every button for the liveblog in step', async () => {
		const buttons = addButtons( 2 );
		buttons[ 0 ].click();

		expect( buttons[ 1 ].getAttribute( 'aria-busy' ) ).toBe( 'true' );

		await runSdk( fakeOneSignal() );
		jest.advanceTimersByTime( 1000 );

		buttons.forEach( ( button ) =>
			expect( button.getAttribute( 'aria-pressed' ) ).toBe( 'true' )
		);
	} );

	it( 'shows a failure in the Newspack UI snackbar and keeps the old state', () => {
		const createNotice = jest.fn();
		( window as TestWindow ).newspackUI = { notices: { createNotice } };

		const [ button ] = addButtons();
		button.click();
		jest.advanceTimersByTime( 10000 );

		expect( createNotice ).toHaveBeenCalledWith( ERROR, 'error' );
		expect( inlineMessage() ).toBeNull();
		expect( button.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
		expect( button.hasAttribute( 'aria-busy' ) ).toBe( false );
	} );

	it( 'shows a failure after the button without Newspack UI', () => {
		const [ button ] = addButtons();
		button.click();
		jest.advanceTimersByTime( 10000 );

		expect( inlineMessage()?.textContent ).toBe( ERROR );
		expect( button.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
	} );
} );
