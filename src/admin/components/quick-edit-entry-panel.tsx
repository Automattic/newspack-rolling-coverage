/**
 * External dependencies
 */
import type { ComponentType } from 'react';
import { useMemo, useState } from '@wordpress/element';
import {
	Button,
	Dropdown,
	PanelBody,
	RadioControl,
} from '@wordpress/components';
import * as blockEditor from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	drafts,
	notAllowed,
	pending,
	published,
	scheduled,
} from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import type { QuickEditEntryPanelProps } from '../types';

// Exported at runtime but missing from the package's type declarations.
const InspectorPopoverHeader = (
	blockEditor as unknown as {
		__experimentalInspectorPopoverHeader: ComponentType< {
			title: string;
			onClose: () => void;
		} >;
	}
 ).__experimentalInspectorPopoverHeader;

const STATUS_INFO: Record< string, { label: string; icon: JSX.Element } > = {
	'auto-draft': {
		label: __( 'Draft', 'newspack-rolling-coverage' ),
		icon: drafts,
	},
	draft: { label: __( 'Draft', 'newspack-rolling-coverage' ), icon: drafts },
	pending: {
		label: __( 'Pending', 'newspack-rolling-coverage' ),
		icon: pending,
	},
	private: {
		label: __( 'Private', 'newspack-rolling-coverage' ),
		icon: notAllowed,
	},
	future: {
		label: __( 'Scheduled', 'newspack-rolling-coverage' ),
		icon: scheduled,
	},
	publish: {
		label: __( 'Published', 'newspack-rolling-coverage' ),
		icon: published,
	},
};

const STATUS_OPTIONS = [
	{
		label: __( 'Draft', 'newspack-rolling-coverage' ),
		value: 'draft',
		description: __( 'Not ready to publish.', 'newspack-rolling-coverage' ),
	},
	{
		label: __( 'Pending', 'newspack-rolling-coverage' ),
		value: 'pending',
		description: __(
			'Waiting for review before publishing.',
			'newspack-rolling-coverage'
		),
	},
	{
		label: __( 'Private', 'newspack-rolling-coverage' ),
		value: 'private',
		description: __(
			'Only visible to site admins and editors.',
			'newspack-rolling-coverage'
		),
	},
	{
		label: __( 'Published', 'newspack-rolling-coverage' ),
		value: 'publish',
		description: __( 'Visible to everyone.', 'newspack-rolling-coverage' ),
	},
];

/**
 * The Entry tab of the Quick Edit sidebar: the entry's status, set the way
 * the post editor's Status row sets it.
 *
 * A trimmed copy of the editor's `PostStatus`, which `@wordpress/editor`
 * doesn't export. Scheduling, passwords and stickiness stay in the full
 * editor. The status is read-only for a scheduled entry, which needs its
 * date changed too, and when `canChangeStatus` is false.
 *
 * @param {QuickEditEntryPanelProps} props Component props.
 */
function QuickEditEntryPanel( { canChangeStatus }: QuickEditEntryPanelProps ) {
	const { status, password, hasPublishAction } = useSelect( ( select ) => {
		const editor = select( editorStore ) as unknown as {
			getEditedPostAttribute: ( attribute: string ) => string;
			getCurrentPost: () => { _links?: Record< string, unknown > };
		};
		return {
			status: editor.getEditedPostAttribute( 'status' ),
			password: editor.getEditedPostAttribute( 'password' ),
			hasPublishAction: Boolean(
				editor.getCurrentPost()._links?.[ 'wp:action-publish' ]
			),
		};
	}, [] );
	const { editPost } = useDispatch( editorStore );
	const [ popoverAnchor, setPopoverAnchor ] = useState< HTMLElement | null >(
		null
	);
	const popoverProps = useMemo(
		() => ( {
			anchor: popoverAnchor,
			placement: 'left-start' as const,
			offset: 36,
			shift: true,
		} ),
		[ popoverAnchor ]
	);

	const info = STATUS_INFO[ status ];
	const isEditable =
		canChangeStatus && hasPublishAction && status !== 'future';

	const handleStatus = ( value: string ) => {
		editPost( {
			status: value,
			...( value === 'private' && password ? { password: '' } : {} ),
		} );
	};

	return (
		<PanelBody>
			<Stack
				direction="row"
				className="editor-post-panel__row"
				ref={ setPopoverAnchor }
			>
				<div className="editor-post-panel__row-label">
					{ __( 'Status', 'newspack-rolling-coverage' ) }
				</div>
				<div className="editor-post-panel__row-control">
					{ isEditable ? (
						<Dropdown
							className="editor-post-status"
							contentClassName="editor-change-status__content"
							popoverProps={ popoverProps }
							focusOnMount
							renderToggle={ ( { onToggle, isOpen } ) => (
								<Button
									className="editor-post-status__toggle"
									variant="tertiary"
									size="compact"
									onClick={ onToggle }
									icon={ info?.icon }
									aria-label={ sprintf(
										/* translators: %s: The entry's current status. */
										__(
											'Change status: %s',
											'newspack-rolling-coverage'
										),
										info?.label
									) }
									aria-expanded={ isOpen }
								>
									{ info?.label }
								</Button>
							) }
							renderContent={ ( { onClose } ) => (
								<>
									<InspectorPopoverHeader
										title={ __(
											'Status',
											'newspack-rolling-coverage'
										) }
										onClose={ onClose }
									/>
									<RadioControl
										className="editor-change-status__options"
										hideLabelFromVision
										label={ __(
											'Status',
											'newspack-rolling-coverage'
										) }
										options={ STATUS_OPTIONS }
										onChange={ handleStatus }
										selected={
											status === 'auto-draft'
												? 'draft'
												: status
										}
									/>
								</>
							) }
						/>
					) : (
						<div className="editor-post-status is-read-only">
							{ info?.label }
						</div>
					) }
				</div>
			</Stack>
		</PanelBody>
	);
}

export { QuickEditEntryPanel };
