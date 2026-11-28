/**
 * External dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import {
	BlockInspector,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Tabs } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { QuickEditEntryPanel } from './quick-edit-entry-panel';
import { QuickEditSettingsPanels } from './quick-edit-settings-panels';
import type { BlockEditorSelectors, QuickEditSidebarProps } from '../types';

/**
 * The Quick Edit sidebar: Entry and Block tabs, switching between them as
 * blocks are selected and deselected, as the post editor's sidebar does.
 *
 * @param {QuickEditSidebarProps} props Component props.
 */
function QuickEditSidebar( {
	entry,
	actions,
	canChangeStatus,
	canNotify,
}: QuickEditSidebarProps ) {
	const hasBlockSelection = useSelect(
		( select ) =>
			Boolean(
				(
					select(
						blockEditorStore
					) as unknown as BlockEditorSelectors
				 ).getBlockSelectionStart()
			),
		[]
	);
	const [ tab, setTab ] = useState( () =>
		hasBlockSelection ? 'block' : 'entry'
	);

	useEffect( () => {
		setTab( hasBlockSelection ? 'block' : 'entry' );
	}, [ hasBlockSelection ] );

	return (
		<Tabs.Root
			value={ tab }
			onValueChange={ ( value ) => setTab( String( value ) ) }
		>
			<div className="components-panel__header editor-sidebar__panel-tabs">
				<Tabs.List activateOnFocus={ false }>
					<Tabs.Tab value="entry">
						{ __( 'Entry', 'newspack-rolling-coverage' ) }
					</Tabs.Tab>
					<Tabs.Tab value="block">
						{ __( 'Block', 'newspack-rolling-coverage' ) }
					</Tabs.Tab>
				</Tabs.List>
			</div>
			<Tabs.Panel value="entry" tabIndex={ -1 }>
				<QuickEditEntryPanel
					entry={ entry }
					actions={ actions }
					canChangeStatus={ canChangeStatus }
				/>
				<QuickEditSettingsPanels canNotify={ canNotify } />
			</Tabs.Panel>
			<Tabs.Panel value="block" tabIndex={ -1 }>
				<BlockInspector />
			</Tabs.Panel>
		</Tabs.Root>
	);
}

export { QuickEditSidebar };
