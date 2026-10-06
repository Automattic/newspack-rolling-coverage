/**
 * External dependencies
 */
import type { ComponentType, ReactNode } from 'react';
import { forwardRef, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Dropdown,
	Icon,
	PanelBody,
	RadioControl,
} from '@wordpress/components';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalInspectorPopoverHeader as InspectorPopoverHeader } from '@wordpress/block-editor';
import {
	PostAuthorPanel,
	PostScheduleLabel,
	PostSchedulePanel,
	store as editorStore,
} from '@wordpress/editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import {
	drafts,
	notAllowed,
	page,
	pending,
	published,
	scheduled,
} from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { QuickEditEntryActions } from './quick-edit-entry-actions';
import type { QuickEditEntryPanelProps } from '../types';

// The editor's JS components carry inferred types that mark optional props
// as required.
const SchedulePanel = PostSchedulePanel as unknown as ComponentType;
const ScheduleLabel = PostScheduleLabel as unknown as ComponentType;
const AuthorPanel = PostAuthorPanel as unknown as ComponentType;

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
			'Hidden from readers. Only visible in the admin.',
			'newspack-rolling-coverage'
		),
	},
	{
		label: __( 'Scheduled', 'newspack-rolling-coverage' ),
		value: 'future',
		description: __(
			'Publish automatically on a chosen date.',
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
 * A labelled row, laid out like the post editor's summary rows.
 */
const PanelRow = forwardRef<
	HTMLDivElement,
	{ label: string; children: ReactNode }
>( ( { label, children }, ref ) => (
	<Stack direction="row" className="editor-post-panel__row" ref={ ref }>
		<div className="editor-post-panel__row-label">{ label }</div>
		<div className="editor-post-panel__row-control">{ children }</div>
	</Stack>
) );

/**
 * The Status row: a trimmed copy of the editor's `PostStatus`, which
 * `@wordpress/editor` doesn't export. Scheduling is done from the Publish
 * row, so Scheduled is only listed while the entry is scheduled; passwords
 * and stickiness stay in the full editor.
 *
 * @param {Object}  props                 Component props.
 * @param {boolean} props.canChangeStatus Whether the status can be changed.
 */
function StatusRow( { canChangeStatus }: { canChangeStatus: boolean } ) {
	const { status, date, password, hasPublishAction } = useSelect(
		( select ) => {
			const editor = select( editorStore ) as unknown as {
				getEditedPostAttribute: ( attribute: string ) => string;
				getCurrentPost: () => { _links?: Record< string, unknown > };
			};
			return {
				status: editor.getEditedPostAttribute( 'status' ),
				date: editor.getEditedPostAttribute( 'date' ),
				password: editor.getEditedPostAttribute( 'password' ),
				hasPublishAction: Boolean(
					editor.getCurrentPost()._links?.[ 'wp:action-publish' ]
				),
			};
		},
		[]
	);
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
	const isEditable = canChangeStatus && hasPublishAction && Boolean( info );
	// Private can't keep a password, and the password field stays in the
	// full editor, so Private isn't offered while the entry has one.
	const options = STATUS_OPTIONS.filter(
		( option ) =>
			( option.value !== 'future' || status === 'future' ) &&
			( option.value !== 'private' || ! password )
	);

	const handleStatus = ( value: string ) => {
		editPost( {
			status: value,
			...( status === 'future' && new Date( date ) > new Date()
				? { date: null }
				: {} ),
		} );
	};

	return (
		<PanelRow
			label={ __( 'Status', 'newspack-rolling-coverage' ) }
			ref={ setPopoverAnchor }
		>
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
							icon={ info.icon }
							aria-label={ sprintf(
								/* translators: %s: The entry's current status. */
								__(
									'Change status: %s',
									'newspack-rolling-coverage'
								),
								info.label
							) }
							aria-expanded={ isOpen }
						>
							{ info.label }
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
								options={ options }
								onChange={ handleStatus }
								selected={
									status === 'auto-draft' ? 'draft' : status
								}
							/>
						</>
					) }
				/>
			) : (
				<div className="editor-post-status is-read-only">
					{ info?.label ?? status }
				</div>
			) }
		</PanelRow>
	);
}

/**
 * The Entry tab of the Quick Edit sidebar, laid out like the post editor's
 * summary: the entry's title and actions, then its Status, Publish date and
 * Author. A locked entry's status and date are read-only.
 *
 * @param {QuickEditEntryPanelProps} props Component props.
 */
function QuickEditEntryPanel( {
	entry,
	actions,
	canChangeStatus,
}: QuickEditEntryPanelProps ) {
	const title = useSelect(
		( select ) =>
			(
				select( editorStore ) as unknown as {
					getEditedPostAttribute: ( attribute: string ) => string;
				}
			 ).getEditedPostAttribute( 'title' ),
		[]
	);

	return (
		<PanelBody className="editor-post-summary">
			<Stack direction="column" gap="lg">
				<div className="editor-post-card-panel">
					<Stack
						direction="row"
						gap="sm"
						align="flex-start"
						className="editor-post-card-panel__header"
					>
						<Icon
							className="editor-post-card-panel__icon"
							icon={ page }
						/>
						<h2 className="editor-post-card-panel__title">
							<span className="editor-post-card-panel__title-name">
								{ title
									? decodeEntities( title )
									: __(
											'No title',
											'newspack-rolling-coverage'
										) }
							</span>
						</h2>
						<QuickEditEntryActions
							entry={ entry }
							actions={ actions }
						/>
					</Stack>
				</div>
				<Stack direction="column" gap="xs">
					<StatusRow canChangeStatus={ canChangeStatus } />
					{ canChangeStatus ? (
						<SchedulePanel />
					) : (
						<PanelRow
							label={ __(
								'Publish',
								'newspack-rolling-coverage'
							) }
						>
							<div className="editor-post-status is-read-only">
								<ScheduleLabel />
							</div>
						</PanelRow>
					) }
					<AuthorPanel />
				</Stack>
			</Stack>
		</PanelBody>
	);
}

export { QuickEditEntryPanel };
