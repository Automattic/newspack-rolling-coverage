/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConnectionModalFooterProps } from '../../../types';

/**
 * Renders the modal footer button row. In `connected` mode shows Close and
 * Disconnect (Disconnect busy while disconnecting, Close disabled while
 * disconnecting); in `connect` mode shows Cancel and Connect (Connect busy
 * while connecting, disabled while connecting or when the channel input is
 * empty).
 *
 * @param {ConnectionModalFooterProps} props Component props.
 */
function ConnectionModalFooter( {
	mode,
	isConnecting,
	isDisconnecting,
	canConnect,
	onClose,
	onConnect,
	onDisconnect,
}: ConnectionModalFooterProps ) {
	return (
		<div className="newspack-rolling-coverage-modal-footer">
			{ mode === 'connected' ? (
				<>
					<Button
						variant="tertiary"
						onClick={ onClose }
						disabled={ isDisconnecting }
					>
						{ __( 'Close', 'newspack-rolling-coverage' ) }
					</Button>
					<Button
						variant="primary"
						isDestructive
						onClick={ onDisconnect }
						isBusy={ isDisconnecting }
						disabled={ isDisconnecting }
					>
						{ __( 'Disconnect', 'newspack-rolling-coverage' ) }
					</Button>
				</>
			) : (
				<>
					<Button
						variant="tertiary"
						onClick={ onClose }
						disabled={ isConnecting }
					>
						{ __( 'Cancel', 'newspack-rolling-coverage' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ onConnect }
						isBusy={ isConnecting }
						disabled={ isConnecting || ! canConnect }
					>
						{ __( 'Connect', 'newspack-rolling-coverage' ) }
					</Button>
				</>
			) }
		</div>
	);
}

export { ConnectionModalFooter };
