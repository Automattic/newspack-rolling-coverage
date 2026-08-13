/**
 * External dependencies
 */
import { useNavigate, useOutletContext } from 'react-router';
import { useState, useCallback, useMemo } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { plus, trash } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { filterSortAndPaginate } from '@wordpress/dataviews/wp';
import type { View } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { useCoverages } from '../hooks/useCoverages';
import { DataViewsWrapper } from './data-views-wrapper';
import { CoverageModal } from './coverage-modal';
import { SlackConnectionModal } from './slack-connection-modal';
import { getCoverageActions } from '../actions/coverage-actions';
import { getCoverageFields, defaultCoverageView } from '../fields/coverages';
import { useAdminContext } from '../hooks/useAdminContext';
import type { Context, ContextExports, Coverage } from '../types';

/**
 * Renders the coverage list DataViews with create/edit modal, Slack connection
 * modal, and row actions. Clicking a row navigates to its entries via the hash
 * router.
 */
function CoverageView() {
	const config = useAdminContext();
	const navigate = useNavigate();
	const [ context, setContext, refresh ] =
		useOutletContext< ContextExports >();
	const { refreshKey } = context;
	const fields = useMemo(
		() => getCoverageFields( config.taxMeta.statusKey ),
		[ config.taxMeta.statusKey ]
	);
	const [ view, setView ] = useState< View >( defaultCoverageView );
	const [ editingCoverage, setEditingCoverage ] = useState< Coverage | null >(
		null
	);
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ isSlackModalOpen, setIsSlackModalOpen ] = useState( false );
	const [ slackCoverage, setSlackCoverage ] = useState< Coverage | null >(
		null
	);

	const { records, isResolving, error } = useCoverages( {
		perPage: 100,
		page: 1,
		search: view.search,
		refreshKey,
	} );

	const { data: filteredData, paginationInfo } = useMemo( () => {
		return filterSortAndPaginate( records ?? [], view, fields );
	}, [ records, view, fields ] );

	const handleOpenCreate = useCallback( () => {
		setEditingCoverage( null );
		setIsModalOpen( true );
	}, [] );

	const handleOpenEdit = useCallback( ( coverage: Coverage ) => {
		setEditingCoverage( coverage );
		setIsModalOpen( true );
	}, [] );

	const handleCloseModal = useCallback( () => {
		setIsModalOpen( false );
		setEditingCoverage( null );
	}, [] );

	const handleSaved = useCallback( () => {
		refresh();
	}, [ refresh ] );

	const handleOpenSlackConnect = useCallback( ( coverage: Coverage ) => {
		setSlackCoverage( coverage );
		setIsSlackModalOpen( true );
	}, [] );

	const handleCloseSlackModal = useCallback( () => {
		setIsSlackModalOpen( false );
		setSlackCoverage( null );
	}, [] );

	const handleNavigateToEntries = useCallback(
		( coverage: Coverage ) => {
			setContext( ( prev: Context ) => ( {
				...prev,
				selectedCoverage: coverage,
			} ) );
			navigate( `/coverages/${ coverage.id }` );
		},
		[ navigate, setContext ]
	);

	const actions = useMemo(
		() =>
			getCoverageActions(
				config,
				handleSaved,
				handleNavigateToEntries,
				handleOpenEdit,
				handleOpenSlackConnect
			),
		[
			config,
			handleSaved,
			handleNavigateToEntries,
			handleOpenEdit,
			handleOpenSlackConnect,
		]
	);

	return (
		<>
			{ error && (
				<div className="newspack-rolling-coverage-error">{ error }</div>
			) }
			<DataViewsWrapper
				data={ filteredData }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ isResolving }
				onClickItem={ ( item ) =>
					handleNavigateToEntries( item as Coverage )
				}
				header={
					<>
						<Button
							variant="secondary"
							icon={ trash }
							isDestructive
							onClick={ () => navigate( '/trashed-entries' ) }
						>
							{ __(
								'Trashed Entries',
								'newspack-rolling-coverage'
							) }
						</Button>
						<Button
							variant="primary"
							icon={ plus }
							onClick={ handleOpenCreate }
						>
							{ __(
								'New Coverage',
								'newspack-rolling-coverage'
							) }
						</Button>
					</>
				}
			/>

			{ isModalOpen && (
				<CoverageModal
					coverage={ editingCoverage }
					onClose={ handleCloseModal }
					onSaved={ handleSaved }
				/>
			) }
			{ isSlackModalOpen && (
				<SlackConnectionModal
					coverage={ slackCoverage }
					onClose={ handleCloseSlackModal }
					onSaved={ handleSaved }
				/>
			) }
		</>
	);
}

export default CoverageView;
