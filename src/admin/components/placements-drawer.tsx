/**
 * External dependencies
 */
import { Field, Link, Stack, Text, VisuallyHidden } from '@wordpress/ui';
import { __, _x, sprintf } from '@wordpress/i18n';
import Divider from 'newspack-components/dist/esm/divider';
import { Drawer } from 'newspack-components/dist/esm/drawer';

/**
 * Internal dependencies
 */
import type { Placement, PlacementsDrawerProps } from '../types';

/**
 * One place that shows the coverage: its type, its title, the blocks it
 * shows the coverage with, and links to view and edit it.
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
			<Stack direction="row" justify="space-between" gap="lg">
				<Stack
					direction="column"
					gap="lg"
					className="newspack-rolling-coverage-placement__details"
				>
					<Stack direction="column" gap="xs">
						{ ( type || isMain ) && (
							<Text>
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
							</Text>
						) }
						<Text variant="heading-md">{ title }</Text>
					</Stack>
					{ blocks.length > 0 && (
						<Stack
							render={ <dl /> }
							direction="column"
							gap="sm"
							className="newspack-rolling-coverage-placement__fields"
						>
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
								<VisuallyHidden render={ <span /> }>
									{ ` ${ title }` }
								</VisuallyHidden>
							</Link>
						) }
						{ editUrl && (
							<Link href={ editUrl }>
								{ __( 'Edit', 'newspack-rolling-coverage' ) }
								<VisuallyHidden render={ <span /> }>
									{ ` ${ title }` }
								</VisuallyHidden>
							</Link>
						) }
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
					{ __( 'View Pages', 'newspack-rolling-coverage' ) }
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
