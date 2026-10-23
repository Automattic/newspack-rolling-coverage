/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	useBlockProps,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	Notice,
	PanelBody,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, _x } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import CoverageChoice from '../shared/coverage-choice';
import { useBlockCoverage } from '../shared/block-coverage';
import { nextCheckLabel } from '../rolling-coverage/entry-name';
import feedMetadata from '../rolling-coverage/block.json';
import type { UpdateTimerAttributes } from './types';

interface UpdateTimerConfig {
	minPollInterval: number;
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
const FEED_BLOCK_NAME = 'newspack-rolling-coverage/rolling-coverage';
const DEFAULT_POLL_INTERVAL = feedMetadata.attributes.pollInterval.default;

const config: UpdateTimerConfig = window.newspackUpdateTimerBlock ?? {
	minPollInterval: 0,
	sourceEntryField: 'rolling_coverage_source_entry',
	statusMetaKey: 'rolling_coverage_status',
	taxonomySlug: 'rolling_coverage',
};

/**
 * Editor for the Update Timer block: the countdown at the feed's full
 * interval beside the turning spinner, and the Coverage panel outside a
 * Rolling Coverage block.
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
	attributes: UpdateTimerAttributes;
	setAttributes: ( attrs: Partial< UpdateTimerAttributes > ) => void;
	context?: Record< string, unknown >;
} ) {
	const { coverageId, showSpinner } = attributes;
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

	const pollInterval = useSelect(
		( select ) => {
			const blockEditor = select( blockEditorStore ) as unknown as {
				getBlockParentsByBlockName: (
					id: string,
					name: string,
					ascending?: boolean
				) => string[];
				getBlocksByName: ( name: string ) => string[];
				getBlockAttributes: (
					id: string
				) => Record< string, unknown > | null;
			};
			const [ parent ] = blockEditor.getBlockParentsByBlockName(
				clientId,
				FEED_BLOCK_NAME,
				true
			);
			const feeds = (
				parent
					? [ parent ]
					: blockEditor.getBlocksByName( FEED_BLOCK_NAME )
			)
				.map( ( id ) => blockEditor.getBlockAttributes( id ) )
				.filter(
					( attrs ) =>
						attrs &&
						( parent ||
							( followed > 0 &&
								Number( attrs.coverageId ) === followed ) )
				);
			const feed =
				feeds.find( ( attrs ) => ! attrs?.latestOnly ) ?? feeds[ 0 ];

			return Math.max(
				1,
				Number( feed?.pollInterval ) || DEFAULT_POLL_INTERVAL
			);
		},
		[ clientId, followed ]
	);

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
				<PanelBody
					title={ __( 'Settings', 'newspack-rolling-coverage' ) }
				>
					<ToggleGroupControl
						__next40pxDefaultSize
						isBlock
						label={ __( 'Spinner', 'newspack-rolling-coverage' ) }
						value={ showSpinner === false ? 'hide' : 'show' }
						onChange={ ( value ) =>
							setAttributes( {
								showSpinner: value === 'show',
							} )
						}
						help={ __(
							'Turns beside the countdown. Hidden for readers who ask their device for reduced motion.',
							'newspack-rolling-coverage'
						) }
					>
						<ToggleGroupControlOption
							value="show"
							label={ _x(
								'Show',
								'spinner',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Show” option. Keep the word used to translate “Show”. */
								__(
									'Show spinner',
									'newspack-rolling-coverage'
								)
							}
						/>
						<ToggleGroupControlOption
							value="hide"
							label={ _x(
								'Hide',
								'spinner',
								'newspack-rolling-coverage'
							) }
							aria-label={
								/* translators: Screen reader name for the “Hide” option. Keep the word used to translate “Hide”. */
								__(
									'Hide spinner',
									'newspack-rolling-coverage'
								)
							}
						/>
					</ToggleGroupControl>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ showSpinner !== false && (
					<svg
						className="newspack-rolling-coverage-update-timer__spinner"
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
						/>
					</svg>
				) }
				<span className="newspack-rolling-coverage-update-timer__text">
					{ nextCheckLabel(
						Math.max(
							pollInterval,
							Number( config.minPollInterval ) || 0
						)
					) }
				</span>
			</div>
		</>
	);
}
