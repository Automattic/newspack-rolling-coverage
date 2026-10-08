/**
 * WordPress dependencies
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import CoverageChoice from '../shared/coverage-choice';
import { useBlockCoverage } from '../shared/block-coverage';
import { nextCheckLabel } from '../rolling-coverage/entry-name';
import type { UpdateTimerAttributes } from './types';

interface UpdateTimerConfig {
	sourceEntryField: string;
	statusMetaKey: string;
	taxonomySlug: string;
}

declare global {
	interface Window {
		newspackUpdateTimerBlock?: UpdateTimerConfig;
	}
}

const COVERAGE_ID_CONTEXT = 'newspack-rolling-coverage/coverageId';
const VIEW_CONTEXT = { context: 'view' };
const PREVIEW_SECONDS = 7;
const PREVIEW_OFFSET = 30;

const config: UpdateTimerConfig = window.newspackUpdateTimerBlock ?? {
	sourceEntryField: 'rolling_coverage_source_entry',
	statusMetaKey: 'rolling_coverage_status',
	taxonomySlug: 'rolling_coverage',
};

/**
 * Editor for the Update Timer block: a still of the countdown, and the
 * Coverage panel outside a Rolling Coverage block.
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
	attributes: UpdateTimerAttributes;
	setAttributes: ( attrs: Partial< UpdateTimerAttributes > ) => void;
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
		postId: context?.postId,
		postType: context?.postType,
		chosenId: coverageId,
		taxonomySlug: config.taxonomySlug,
		statusMetaKey: config.statusMetaKey,
		sourceEntryField: config.sourceEntryField,
	} );

	const hasEnded = useSelect(
		( select ) => {
			if ( ! followed ) {
				return false;
			}

			const coverage = (
				select( coreStore ) as unknown as {
					getEntityRecord: (
						kind: string,
						name: string,
						id: number,
						query: Record< string, string >
					) => { meta?: Record< string, string > } | undefined;
				}
			 ).getEntityRecord(
				'taxonomy',
				config.taxonomySlug,
				followed,
				VIEW_CONTEXT
			);

			return coverage?.meta?.[ config.statusMetaKey ] === 'archived';
		},
		[ followed ]
	);

	const blockProps = useBlockProps();
	const endedNotice = __(
		"This coverage has ended, so the timer won't show on the site.",
		'newspack-rolling-coverage'
	);
	let goneNotice = '';

	if ( isChosenGone ) {
		goneNotice =
			followed || isTemplate
				? __(
						'This coverage no longer exists, so the page’s coverage is used.',
						'newspack-rolling-coverage'
					)
				: __(
						'This coverage no longer exists, so this timer won’t appear on the site.',
						'newspack-rolling-coverage'
					);
	}

	return (
		<>
			<InspectorControls>
				{ hasEnded && (
					<PanelBody>
						<Notice
							status="warning"
							isDismissible={ false }
							spokenMessage={ endedNotice }
						>
							{ endedNotice }
						</Notice>
					</PanelBody>
				) }
				{ ! isInFeed && (
					<PanelBody
						title={ __( 'Coverage', 'newspack-rolling-coverage' ) }
					>
						<CoverageChoice
							value={ coverageId }
							onChange={ ( value ) =>
								setAttributes( { coverageId: value } )
							}
							customChosen={ customChosen }
							onCustomChosenChange={ setCustomChosen }
							automaticHelp={ __(
								'Counts down to the next check of the coverage on this page.',
								'newspack-rolling-coverage'
							) }
							customHelp={ __(
								'Always counts down for this coverage.',
								'newspack-rolling-coverage'
							) }
							taxonomySlug={ config.taxonomySlug }
							statusMetaKey={ config.statusMetaKey }
						/>
						{ goneNotice && (
							<Notice
								status="warning"
								isDismissible={ false }
								spokenMessage={ goneNotice }
							>
								{ goneNotice }
							</Notice>
						) }
					</PanelBody>
				) }
			</InspectorControls>
			<div { ...blockProps }>
				<svg
					className="newspack-rolling-coverage-update-timer__ring"
					viewBox="0 0 18 18"
					aria-hidden="true"
					focusable="false"
				>
					<circle
						cx="9"
						cy="9"
						r="8.25"
						pathLength={ 100 }
						strokeWidth="1.5"
						style={ { strokeDashoffset: PREVIEW_OFFSET } }
					/>
				</svg>
				<span className="newspack-rolling-coverage-update-timer__text">
					{ nextCheckLabel( PREVIEW_SECONDS ) }
				</span>
			</div>
		</>
	);
}
