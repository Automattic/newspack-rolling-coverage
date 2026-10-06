/**
 * External dependencies
 */
import { Badge, Link, Stack, Text, VisuallyHidden } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';
import { Drawer } from 'newspack-components/dist/esm/drawer';

/**
 * Internal dependencies
 */
import type { Placement, PlacementsDrawerProps } from '../types';

/**
 * One place that shows the coverage: its title, what it is, the blocks it
 * shows the coverage with, and links to view and edit it.
 *
 * @param {Object}    props           Component props.
 * @param {Placement} props.placement The place.
 */
function PlacementRow( { placement }: { placement: Placement } ) {
	const { title, type, tags, viewUrl, editUrl, isMain } = placement;
	const hasBadges = isMain || tags.length > 0;

	return (
		<li className="newspack-rolling-coverage-placement">
			<Stack direction="row" justify="space-between" gap="lg">
				<Stack
					direction="column"
					gap="xs"
					className="newspack-rolling-coverage-placement__details"
				>
					<Text variant="heading-md">{ title }</Text>
					{ type && (
						<Text
							variant="body-sm"
							className="newspack-rolling-coverage-detail-help"
						>
							{ type }
						</Text>
					) }
					{ hasBadges && (
						<Stack direction="row" gap="xs" wrap="wrap">
							{ isMain && (
								<Badge intent="informational">
									{ __(
										'Main page',
										'newspack-rolling-coverage'
									) }
								</Badge>
							) }
							{ tags.map( ( tag ) => (
								<Badge key={ tag }>{ tag }</Badge>
							) ) }
						</Stack>
					) }
				</Stack>
				{ ( viewUrl || editUrl ) && (
					<Stack
						direction="row"
						gap="md"
						align="baseline"
						className="newspack-rolling-coverage-placement__links"
					>
						{ viewUrl && (
							<Link href={ viewUrl } openInNewTab>
								{ __( 'View', 'newspack-rolling-coverage' ) }
								<VisuallyHidden>{ ` ${ title }` }</VisuallyHidden>
							</Link>
						) }
						{ editUrl && (
							<Link href={ editUrl }>
								{ __( 'Edit', 'newspack-rolling-coverage' ) }
								<VisuallyHidden>{ ` ${ title }` }</VisuallyHidden>
							</Link>
						) }
					</Stack>
				) }
			</Stack>
		</li>
	);
}

/**
 * Lists every published place that shows a coverage, the main page first,
 * for a coverage shown in more than one place.
 *
 * Stays mounted so the drawer can play its slide-out.
 *
 * @param {PlacementsDrawerProps} props Component props.
 */
function PlacementsDrawer( {
	isOpen,
	coverage,
	onClose,
}: PlacementsDrawerProps ) {
	return (
		<Drawer.Root isOpen={ isOpen } onRequestClose={ onClose }>
			<Drawer.Header>
				<Drawer.Title>
					{ __( 'View Pages', 'newspack-rolling-coverage' ) }
				</Drawer.Title>
				<Drawer.CloseIcon />
			</Drawer.Header>
			<Drawer.Content>
				<ul className="newspack-rolling-coverage-placements">
					{ ( coverage?.placements ?? [] ).map( ( placement ) => (
						<PlacementRow
							key={ placement.id }
							placement={ placement }
						/>
					) ) }
				</ul>
			</Drawer.Content>
		</Drawer.Root>
	);
}

export { PlacementsDrawer };
