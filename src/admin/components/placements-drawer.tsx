/**
 * External dependencies
 */
import {
	Field,
	Link,
	Stack,
	Text,
	Tooltip,
	VisuallyHidden,
} from '@wordpress/ui';
import { __, _x, sprintf } from '@wordpress/i18n';
import Divider from 'newspack-components/dist/esm/divider';
import { Drawer } from 'newspack-components/dist/esm/drawer';

/**
 * Internal dependencies
 */
import type { Placement, PlacementsDrawerProps } from '../types';

/**
 * One place that shows the coverage: its type, its title linking to its
 * editor, the blocks it shows the coverage with, and a link to view it.
 *
 * @param {Object}    props             Component props.
 * @param {Placement} props.placement   The place.
 * @param {boolean}   props.isSeparated Whether a divider sets it apart from the row above.
 */
function PlacementRow( {
	placement,
	isSeparated,
}: {
	placement: Placement;
	isSeparated: boolean;
} ) {
	const { title, type, blocks, viewUrl, editUrl, isMain } = placement;
	const mainPage = __( 'Main page', 'newspack-rolling-coverage' );

	return (
		<Stack
			render={ <li /> }
			direction="column"
			gap="lg"
			className="newspack-rolling-coverage-placement"
		>
			{ isSeparated && (
				<Divider
					aria-hidden="true"
					marginTop={ 0 }
					marginBottom={ 0 }
				/>
			) }
			<Stack
				render={ <dl /> }
				direction="column"
				gap="lg"
				className="newspack-rolling-coverage-placement__details"
			>
				<Stack direction="column" gap="sm">
					<Field.VisualLabel render={ <dt /> }>
						{ type && isMain ? (
							<>
								<span aria-hidden="true">
									{ sprintf(
										/* translators: %s: what the place is, such as Page */
										_x(
											'%s · Main',
											'visible label of the main page',
											'newspack-rolling-coverage'
										),
										type
									) }
								</span>
								<VisuallyHidden render={ <span /> }>
									{ sprintf(
										/* translators: %s: what the place is, such as Page */
										__(
											'%s, Main page',
											'newspack-rolling-coverage'
										),
										type
									) }
								</VisuallyHidden>
							</>
						) : (
							type || mainPage
						) }
					</Field.VisualLabel>
					<Stack
						render={ <dd /> }
						direction="row"
						justify="space-between"
						align="baseline"
						gap="lg"
					>
						{ editUrl ? (
							<Tooltip.Root>
								<Tooltip.Trigger
									render={
										<Link
											href={ editUrl }
											tone="neutral"
											className="newspack-rolling-coverage-placement__title"
											aria-label={ sprintf(
												/* translators: %s: title of the place */
												__(
													'Open %s in the editor',
													'newspack-rolling-coverage'
												),
												title
											) }
										/>
									}
								>
									{ title }
								</Tooltip.Trigger>
								<Tooltip.Popup>
									{ __(
										'Open in the editor',
										'newspack-rolling-coverage'
									) }
								</Tooltip.Popup>
							</Tooltip.Root>
						) : (
							<Text className="newspack-rolling-coverage-placement__title">
								{ title }
							</Text>
						) }
						{ viewUrl && (
							<Link
								href={ viewUrl }
								openInNewTab
								className="newspack-rolling-coverage-placement__view"
							>
								{ __( 'View', 'newspack-rolling-coverage' ) }
								<VisuallyHidden render={ <span /> }>
									{ ` ${ title }` }
								</VisuallyHidden>
							</Link>
						) }
					</Stack>
				</Stack>
				{ blocks.length > 0 && (
					<Stack direction="column" gap="sm">
						<Field.VisualLabel render={ <dt /> }>
							{ __( 'Blocks', 'newspack-rolling-coverage' ) }
						</Field.VisualLabel>
						<dd>
							<Stack
								render={ <ul /> }
								direction="column"
								gap="xs"
								className="newspack-rolling-coverage-placement__blocks"
							>
								{ blocks.map( ( block ) => (
									<Text key={ block } render={ <li /> }>
										{ block }
									</Text>
								) ) }
							</Stack>
						</dd>
					</Stack>
				) }
			</Stack>
		</Stack>
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
					{ __( 'Placements', 'newspack-rolling-coverage' ) }
				</Drawer.Title>
				<Drawer.CloseIcon />
			</Drawer.Header>
			<Drawer.Content>
				<Stack
					render={ <ul /> }
					direction="column"
					gap="lg"
					className="newspack-rolling-coverage-placements"
				>
					{ ( coverage?.placements ?? [] ).map(
						( placement, index ) => (
							<PlacementRow
								key={ placement.id }
								placement={ placement }
								isSeparated={ index > 0 }
							/>
						)
					) }
				</Stack>
			</Drawer.Content>
		</Drawer.Root>
	);
}

export { PlacementsDrawer };
