type Deferred = ( os: unknown ) => void;

type TestWindow = Window & {
	newspackUI?: unknown;
	OneSignalDeferred?: Deferred[];
};

const ERROR = 'Something went wrong. Please try again.';
const ON = 'You’ll get alerts for “Kettle River flood”.';
const OFF = 'Alerts for “Kettle River flood” are off.';
const BLOCKED = 'Notifications are blocked in your browser.';
const ON_SHORT = 'Alerts on';
const OFF_SHORT = 'Alerts off';

const addButtons = ( count = 1, tags = [ 'coverage_1' ] ) => {
	document.body.innerHTML = Array.from(
		{ length: count },
		( _, index ) =>
			`<div class="wp-block-button"><button class="wp-block-button__link" data-rc-follow data-tag="${
				tags[ index ] ?? tags[ 0 ]
			}" aria-pressed="false" data-error-message="${ ERROR }" data-blocked-message="${ BLOCKED }" data-on-notice="${ ON_SHORT }" data-off-notice="${ OFF_SHORT }" data-on-message="${ ON }" data-off-message="${ OFF }"><span data-rc-label="off">Get alerts</span></button></div>`
	).join( '' );

	return Array.from( document.querySelectorAll( 'button' ) );
};

// Stands in for Newspack UI's createNotice(), which returns the notice it adds.
const fakeCreateNotice = () =>
	jest.fn( ( message: string ) => {
		const item = document.createElement( 'div' );
		const content = document.createElement( 'div' );

		content.className = 'newspack-ui__snackbar__content';
		content.textContent = message;
		item.appendChild( content );
		document.body.appendChild( item );

		return item;
	} );

const shownText = ( notice: HTMLElement ) =>
	Array.from(
		notice.querySelectorAll( '.newspack-ui__snackbar__content > *' )
	)
		.filter( ( node ) => ! node.classList.contains( 'screen-reader-text' ) )
		.map( ( node ) => node.textContent )
		.join( '' ) ||
	notice.querySelector( '.newspack-ui__snackbar__content' )?.textContent;

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
		delete ( window as { Notification?: unknown } ).Notification;
	} );

	it( 'shows the spinner for at least a second, then the new state', async () => {
		const createNotice = fakeCreateNotice();
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
	} );

	it( 'shows "Alerts on" on a page with one liveblog, and reads out which liveblog', async () => {
		const createNotice = fakeCreateNotice();
		( window as TestWindow ).newspackUI = { notices: { createNotice } };

		const [ button ] = addButtons( 2 );
		button.click();
		await runSdk( fakeOneSignal() );
		jest.advanceTimersByTime( 1000 );

		expect( createNotice ).toHaveBeenCalledTimes( 1 );
		expect( createNotice ).toHaveBeenCalledWith( ON, 'success' );

		const notice = createNotice.mock.results[ 0 ].value as HTMLElement;
		expect( shownText( notice ) ).toBe( ON_SHORT );
		expect(
			notice.querySelector( '.screen-reader-text' )?.textContent
		).toBe( ON );
	} );

	it( 'shows the full sentence on a page with several liveblogs', async () => {
		const createNotice = fakeCreateNotice();
		( window as TestWindow ).newspackUI = { notices: { createNotice } };

		const [ button ] = addButtons( 2, [ 'coverage_1', 'coverage_2' ] );
		button.click();
		await runSdk( fakeOneSignal() );
		jest.advanceTimersByTime( 1000 );

		const notice = createNotice.mock.results[ 0 ].value as HTMLElement;
		expect( shownText( notice ) ).toBe( ON );
		expect( notice.querySelector( '.screen-reader-text' ) ).toBeNull();
	} );

	it( 'reads "Alerts on" until focus leaves after switching on', async () => {
		const [ button ] = addButtons();
		button.focus();
		button.click();
		await runSdk( fakeOneSignal() );
		jest.advanceTimersByTime( 1000 );

		expect( button.hasAttribute( 'data-rc-fresh' ) ).toBe( true );

		button.blur();
		expect( button.hasAttribute( 'data-rc-fresh' ) ).toBe( false );
	} );

	it( 'lets a button the reader already left read "Stop alerts" on the next hover', async () => {
		const [ button ] = addButtons();
		button.click();
		await runSdk( fakeOneSignal() );
		jest.advanceTimersByTime( 1000 );

		expect( button.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect( button.hasAttribute( 'data-rc-fresh' ) ).toBe( false );
	} );

	it( 'follows once the reader allows notifications in the prompt', async () => {
		const OneSignal = fakeOneSignal();
		OneSignal.Notifications.permission = false;
		OneSignal.Notifications.requestPermission.mockImplementation(
			async () => {
				const [ , onChange ] =
					OneSignal.Notifications.addEventListener.mock.calls[ 0 ];
				OneSignal.Notifications.permission = true;
				onChange( true );
			}
		);

		const [ button ] = addButtons();
		button.click();
		await runSdk( OneSignal );
		jest.advanceTimersByTime( 1000 );

		expect( OneSignal.User.addTag ).toHaveBeenCalledWith(
			'coverage_1',
			'1'
		);
		expect(
			OneSignal.Notifications.removeEventListener
		).toHaveBeenCalled();
		expect( button.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
	} );

	it( 'returns a button to its old state, with no message, when the prompt is closed', async () => {
		const createNotice = jest.fn();
		( window as TestWindow ).newspackUI = { notices: { createNotice } };
		( window as { Notification?: unknown } ).Notification = {
			permission: 'default',
		};
		const OneSignal = fakeOneSignal();
		OneSignal.Notifications.permission = false;
		OneSignal.Notifications.requestPermission.mockResolvedValue(
			undefined
		);

		const [ button ] = addButtons();
		button.click();
		const sdk = runSdk( OneSignal );
		await jest.advanceTimersByTimeAsync( 500 );
		await sdk;
		jest.advanceTimersByTime( 1000 );

		expect( OneSignal.User.addTag ).not.toHaveBeenCalled();
		expect( button.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
		expect( button.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( createNotice ).not.toHaveBeenCalled();
		expect( inlineMessage() ).toBeNull();
	} );

	it( 'gives the outcome to a button that arrived while the toggle was pending', async () => {
		const [ button ] = addButtons();
		button.click();

		const late = button.cloneNode( true ) as HTMLButtonElement;
		late.removeAttribute( 'aria-busy' );
		late.removeAttribute( 'aria-disabled' );
		document.body.appendChild( late );

		await runSdk( fakeOneSignal() );
		jest.advanceTimersByTime( 1000 );

		expect( late.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
	} );

	it( 'confirms alerts going off', async () => {
		const createNotice = fakeCreateNotice();
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
		expect(
			shownText( createNotice.mock.results[ 0 ].value as HTMLElement )
		).toBe( OFF_SHORT );
	} );

	it( 'announces the change without Newspack UI', async () => {
		const [ button ] = addButtons();
		button.click();

		expect( announcer()?.textContent ).toBe( '' );

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

	it( 'explains a blocked browser in the error snackbar', async () => {
		const createNotice = jest.fn();
		( window as TestWindow ).newspackUI = { notices: { createNotice } };
		( window as { Notification?: unknown } ).Notification = {
			permission: 'denied',
		};
		const OneSignal = fakeOneSignal();
		OneSignal.Notifications.permission = false;

		const [ button ] = addButtons();
		button.click();
		await runSdk( OneSignal );
		jest.advanceTimersByTime( 1000 );

		expect(
			OneSignal.Notifications.requestPermission
		).not.toHaveBeenCalled();
		expect( createNotice ).toHaveBeenCalledWith( BLOCKED, 'error' );
		expect( button.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
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
