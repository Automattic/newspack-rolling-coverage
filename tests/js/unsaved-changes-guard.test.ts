/**
 * The Settings page asks before unsaved changes are lost to a move between
 * its own routes: a click on a tab or breadcrumb, or Back and Forward.
 */
import { renderHook } from '@testing-library/react';

import { useUnsavedChangesGuard } from '../../src/admin/hooks/useUnsavedChangesGuard';

const mockRequestConfirm = jest.fn();

jest.mock(
	'newspack-components/dist/esm/hooks/use-unsaved-changes-dialog',
	() => ( {
		__esModule: true,
		default: () => ( {
			confirmDialog: null,
			requestConfirm: mockRequestConfirm,
		} ),
	} )
);

const SLACK = '#/settings/slack';
const GENERAL = '#/settings/general';

let routerPops: string[];

function onRouterPop() {
	routerPops.push( window.location.hash );
}

/** What the browser does on Back: move the URL, then fire `popstate`. */
function goBack( hash: string ) {
	window.history.replaceState( null, '', hash );
	window.dispatchEvent( new PopStateEvent( 'popstate' ) );
}

function discard() {
	const [ [ proceed ] ] = mockRequestConfirm.mock.calls;
	proceed();
}

beforeEach( () => {
	mockRequestConfirm.mockReset();
	window.history.replaceState( null, '', SLACK );
	routerPops = [];
	window.addEventListener( 'popstate', onRouterPop );
} );

afterEach( () => {
	window.removeEventListener( 'popstate', onRouterPop );
	document.body.innerHTML = '';
} );

describe( 'Back and Forward', () => {
	it( 'reach the router without asking when nothing is unsaved', () => {
		renderHook( () => useUnsavedChangesGuard( false ) );

		goBack( GENERAL );

		expect( mockRequestConfirm ).not.toHaveBeenCalled();
		expect( routerPops ).toEqual( [ GENERAL ] );
	} );

	it( 'keep the screen and ask when changes are unsaved', () => {
		renderHook( () => useUnsavedChangesGuard( true ) );

		goBack( GENERAL );

		expect( mockRequestConfirm ).toHaveBeenCalledTimes( 1 );
		expect( routerPops ).toEqual( [] );
		expect( window.location.hash ).toBe( SLACK );
	} );

	it( 'go where the user went, once, after Discard Changes', () => {
		renderHook( () => useUnsavedChangesGuard( true ) );

		goBack( GENERAL );
		discard();

		expect( mockRequestConfirm ).toHaveBeenCalledTimes( 1 );
		expect( routerPops ).toEqual( [ GENERAL ] );
		expect( window.location.hash ).toBe( GENERAL );
	} );

	it( 'stop asking once the guard is off', () => {
		const { rerender } = renderHook(
			( { isDirty } ) => useUnsavedChangesGuard( isDirty ),
			{ initialProps: { isDirty: true } }
		);

		rerender( { isDirty: false } );
		goBack( GENERAL );

		expect( mockRequestConfirm ).not.toHaveBeenCalled();
		expect( routerPops ).toEqual( [ GENERAL ] );
	} );
} );

describe( 'Links to other routes', () => {
	function clickLink( href: string ) {
		const link = document.createElement( 'a' );
		link.setAttribute( 'href', href );
		document.body.appendChild( link );
		const event = new MouseEvent( 'click', {
			bubbles: true,
			cancelable: true,
			button: 0,
		} );
		link.dispatchEvent( event );
		return event;
	}

	it( 'ask before following when changes are unsaved', () => {
		renderHook( () => useUnsavedChangesGuard( true ) );

		const event = clickLink( GENERAL );

		expect( event.defaultPrevented ).toBe( true );
		expect( mockRequestConfirm ).toHaveBeenCalledTimes( 1 );
		expect( window.location.hash ).toBe( SLACK );
	} );

	it( 'are followed without a second prompt after Discard Changes', () => {
		renderHook( () => useUnsavedChangesGuard( true ) );

		clickLink( GENERAL );
		discard();
		window.dispatchEvent( new PopStateEvent( 'popstate' ) );

		expect( mockRequestConfirm ).toHaveBeenCalledTimes( 1 );
		expect( window.location.hash ).toBe( GENERAL );
		expect( routerPops ).toContain( GENERAL );
	} );
} );
