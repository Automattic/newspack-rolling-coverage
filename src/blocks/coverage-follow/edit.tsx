/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { Notice, PanelBody } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import CoveragePicker from '../shared/coverage-picker';
import { FOLLOW_BUTTONS_TEMPLATE } from '../shared/follow-buttons';
import { usePageFeeds } from '../shared/page-feeds';
import type { CoverageFollowAttributes, CoverageFollowConfig } from './types';

const COVERAGE_ID_CONTEXT = 'newspack-rolling-coverage/coverageId';
const ALLOWED_BLOCKS = [ 'core/buttons' ];
const TEMPLATE = [ FOLLOW_BUTTONS_TEMPLATE ];

const config: CoverageFollowConfig = window.newspackCoverageFollowBlock ?? {
	onesignalConfigured: true,
	statusMetaKey: 'rolling_coverage_status',
	taxonomySlug: 'rolling_coverage',
};

/**
 * Editor for the Follow Coverage block: the locked "Follow" button, with a
 * coverage to follow when the block isn't inside a Rolling Coverage block.
 *
 * @param {Object}   props               Block props.
 * @param {string}   props.clientId      Block client ID.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @param {Object}   props.context       Block context.
 */
export default function Edit( {
	clientId,
	attributes,
	setAttributes,
	context,
}: {
	clientId: string;
	attributes: CoverageFollowAttributes;
	setAttributes: ( attrs: Partial< CoverageFollowAttributes > ) => void;
	context?: Record< string, unknown >;
} ) {
	const { coverageId } = attributes;
	const feedCoverageId = context?.[ COVERAGE_ID_CONTEXT ];
	const isInFeed = feedCoverageId !== undefined;

	const { feeds, isTemplate } = usePageFeeds( {
		clientId,
		feedCoverageId,
		taxonomySlug: config.taxonomySlug,
		statusMetaKey: config.statusMetaKey,
	} );

	const needsCoverage =
		! isInFeed && ! isTemplate && ! coverageId && feeds.length === 0;

	const chosenState = useSelect(
		( select ) => {
			if ( isInFeed || ! coverageId ) {
				return '';
			}

			const core = select( coreStore ) as unknown as {
				getEntityRecord: (
					kind: string,
					name: string,
					id: number,
					query: Record< string, string >
				) => { meta?: Record< string, string > } | null | undefined;
				hasFinishedResolution: (
					selector: string,
					args: unknown[]
				) => boolean;
			};
			const args = [
				'taxonomy',
				config.taxonomySlug,
				coverageId,
				{ context: 'view' },
			];
			const term = core.getEntityRecord(
				'taxonomy',
				config.taxonomySlug,
				coverageId,
				{ context: 'view' }
			);

			if (
				term === null ||
				( term === undefined &&
					core.hasFinishedResolution( 'getEntityRecord', args ) )
			) {
				return 'missing';
			}

			return term?.meta?.[ config.statusMetaKey ] ?? '';
		},
		[ isInFeed, coverageId ]
	);

	const isChosenGone =
		chosenState === 'trash' ||
		( chosenState === 'missing' && feeds.length === 0 );

	const blockProps = useBlockProps();
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		template: TEMPLATE,
		templateLock: 'all',
		allowedBlocks: ALLOWED_BLOCKS,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Settings', 'newspack-rolling-coverage' ) }
				>
					{ ! isInFeed && (
						<CoveragePicker
							value={ coverageId }
							onChange={ ( value ) =>
								setAttributes( { coverageId: value } )
							}
							taxonomySlug={ config.taxonomySlug }
							statusMetaKey={ config.statusMetaKey }
						/>
					) }
					{ ! config.onesignalConfigured && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								"Push notifications aren't set up, so this button won't appear on the site.",
								'newspack-rolling-coverage'
							) }
						</Notice>
					) }
					{ chosenState === 'archived' && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								"This coverage has ended, so this button won't appear on the site.",
								'newspack-rolling-coverage'
							) }
						</Notice>
					) }
					{ isChosenGone && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								"This coverage no longer exists, so this button won't appear on the site.",
								'newspack-rolling-coverage'
							) }
						</Notice>
					) }
				</PanelBody>
			</InspectorControls>
			<div { ...innerBlocksProps }>
				{ needsCoverage && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Choose a coverage for this button to follow.',
							'newspack-rolling-coverage'
						) }
					</Notice>
				) }
				{ innerBlocksProps.children as ReactNode }
			</div>
		</>
	);
}
