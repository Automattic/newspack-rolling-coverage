/**
 * External dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import App from './app';
import './styles/admin.scss';

const container = document.getElementById( 'newspack-rolling-coverage-admin' );

if ( container ) {
	createRoot( container ).render( <App /> );
}
