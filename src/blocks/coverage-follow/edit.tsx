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
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import CoverageChoice from '../shared/coverage-choice';
import { FOLLOW_BUTTONS_TEMPLATE } from '../shared/follow-buttons';
import { useBlockCoverage } from '../shared/page-feeds';
import type { CoverageFollowAttributes, CoverageFollowConfig } from './types';

const COVERAGE_ID_CONTEXT = 'newspack-rolling-coverage/coverageId';
const ALLOWED_BLOCKS = [ 'core/buttons' ];
const TEMPLATE = [ FOLLOW_BUTTONS_TEMPLATE ];
const VIEW_CONTEXT = { context: 'view' };

const config: CoverageFollowConfig = window.newspackCoverageFollowBlock ?? {
	onesignalConfigured: true,
	statusMetaKey: 'rolling_coverage_status',
	taxonomySlug: 'rolling_coverage',
};

/**
 * Editor for the Follow Coverage block: the locked "Follow" button, with a
 * choice of coverage when the block isn't inside a Rolling Coverage block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @param {Object}   props.context       Block context.
 */
export default function Edit( {
	attributes,
	setAttributes,
	context,
}: {
	attributes: CoverageFollowAttributes;
	setAttributes: ( attrs: Partial< CoverageFollowAttributes > ) => void;
	context?: Record< string, unknown >;
} ) {
	const { coverageId } = attributes;
	const [ customChosen, setCustomChosen ] = useState( false );

	const {
		coverageId: followed,
		isInFeed,
		isTemplate,
		isChosenGone,
	} = useBlockCoverage( {
		feedCoverageId: context?.[ COVERAGE_ID_CONTEXT ],
		chosenId: coverageId,
		taxonomySlug: config.taxonomySlug,
		statusMetaKey: config.statusMetaKey,
	} );

	const status = useSelect(
		( select ) => {
			if ( ! followed ) {
				return '';
			}

			const core = select( coreStore ) as unknown as {
				getEntityRecord: (
					kind: string,
					name: string,
					id: number,
					query: Record< string, string >
				) => { meta?: Record< string, string > } | null | undefined;
			};

			return (
				core.getEntityRecord(
					'taxonomy',
					config.taxonomySlug,
					followed,
					VIEW_CONTEXT
				)?.meta?.[ config.statusMetaKey ] ?? ''
			);
		},
		[ followed ]
	);

	const showsGone = isChosenGone && ! followed && ! isTemplate;

	const blockProps = useBlockProps();
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		template: TEMPLATE,
		templateLock: 'all',
		allowedBlocks: ALLOWED_BLOCKS,
	} );

	return (
		<>
			{ ( ! isInFeed ||
				! config.onesignalConfigured ||
				status === 'archived' ) && (
				<InspectorControls>
					<PanelBody
						title={ __( 'Settings', 'newspack-rolling-coverage' ) }
					>
						{ ! isInFeed && (
							<CoverageChoice
								value={ coverageId }
								onChange={ ( value ) =>
									setAttributes( { coverageId: value } )
								}
								customChosen={ customChosen }
								onCustomChosenChange={ setCustomChosen }
								customHelp={ __(
									'Always follows this coverage.',
									'newspack-rolling-coverage'
								) }
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
						{ status === 'archived' && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									"This coverage has ended, so this button won't appear on the site.",
									'newspack-rolling-coverage'
								) }
							</Notice>
						) }
						{ showsGone && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									"This coverage no longer exists, so this button won't appear on the site.",
									'newspack-rolling-coverage'
								) }
							</Notice>
						) }
					</PanelBody>
				</InspectorControls>
			) }
			<div { ...innerBlocksProps } />
		</>
	);
}
