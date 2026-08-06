/**
 * WordPress dependencies
 */
import type { View, ViewTable, Field, Action } from '@wordpress/dataviews';

/**
 * External dependencies
 */
import type { JSX } from 'react';

interface AdminConfig {
	page: '/coverages' | '/connection';
	availableAdapters?: Record< string, string >;
	restBase: {
		coverages: string;
		entries: string;
		slack: string;
	};
	restBaseUrls: {
		coverages: string;
		entries: string;
		slack: string;
		breakout: string;
	};
	nonce: string;
	capabilities: {
		canEditPosts: boolean;
		canManageTerms: boolean;
	};
	adminUrls: {
		editEntry: string;
		newEntry: string;
		editUser: string;
		editTerm: string;
	};
	postType: string;
	taxonomy: string;
	taxMeta: {
		statusKey: string;
	};
	slack: {
		isConfigured: boolean;
	};
	blockEditorSettings: Record< string, unknown >;
}

interface Context {
	selectedCoverage: Coverage | null;
}

type ContextExports = [
	context: Context,
	setContext: React.Dispatch< React.SetStateAction< Context > >,
];

interface Coverage {
	id: number;
	name: string;
	slug: string;
	taxonomy: string;
	description: string;
	count: number;
	meta: {
		rolling_coverage_status?: 'active' | 'paused' | 'archived';
		created_at?: string;
		modified_at?: string;
		rolling_coverage_slack_channel_id?: string;
		rolling_coverage_slack_channel_name?: string;
		[ key: string ]: unknown;
	};
}

type PostStatus =
	| 'publish'
	| 'draft'
	| 'pending'
	| 'future'
	| 'private'
	| 'trash';

interface Entry {
	id: number;
	date: string;
	date_gmt: string;
	modified: string;
	modified_gmt: string;
	slug: string;
	status: PostStatus;
	type: string;
	link: string;
	title: {
		rendered: string;
		raw?: string;
	};
	content: {
		rendered: string;
		raw?: string;
	};
	author: number;
	meta: {
		rolling_coverage_breakout_post_id?: number;
		rolling_coverage_breakout_read_more_text?: string;
		[ key: string ]: unknown;
	};
	rolling_coverage_breakout_status?: PostStatus | null;
	_embedded?: {
		author?: Array< {
			id: number;
			name: string;
			link: string;
		} >;
		'wp:term'?: Array<
			Array< {
				id: number;
				name: string;
				slug: string;
				taxonomy: string;
				link: string;
			} >
		>;
	};
	_links?: Record< string, Array< { href: string } > >;
}

type ViewState = ViewTable;

interface PaginationInfo {
	totalItems: number;
	totalPages: number;
}

interface ChannelMapping {
	channel_id: string;
	channel_name: string;
	term_id: number;
	term_name: string;
	autopublish: boolean;
	last_sync_ts: string;
}

interface ApiResult {
	success: boolean;
	error?: string;
	message?: string;
}

interface SlackConnectResult {
	success: boolean;
	error?: string;
	channel_id?: string;
	channel_name?: string;
}

interface SlackVerifyResult {
	success: boolean;
	team?: string;
	error?: string;
}

interface SlackChannelsResult {
	success: boolean;
	channels: ChannelMapping[];
	error?: string;
}

interface SettingsNotice {
	type: 'success' | 'error' | 'info';
	message: string;
}

interface AdminTab {
	name: string;
	title: string;
}

interface DataViewsWrapperProps< T > {
	data: T[];
	fields: Field< T >[];
	view: View;
	onChangeView: ( view: View ) => void;
	actions: Action< T >[];
	paginationInfo: PaginationInfo;
	isLoading: boolean;
	onClickItem?: ( item: T ) => void;
	header?: JSX.Element;
	defaultLayouts?: Record< string, unknown >;
}

interface CoverageModalProps {
	coverage: Coverage | null;
	onClose: () => void;
	onSaved: () => void;
}

interface CoverageFormData {
	name: string;
	description: string;
	status: 'active' | 'paused' | 'archived';
}

interface ConfirmModalContentProps {
	message: string;
	confirmLabel?: string;
	cancelLabel?: string;
	isDestructive?: boolean;
	onConfirm: () => Promise< void >;
	onClose: () => void;
}

interface ConfirmModalProps extends ConfirmModalContentProps {
	title: string;
}

interface UseCoveragesOptions {
	perPage?: number;
	page?: number;
	search?: string;
	refreshKey?: number;
}

interface UseEntriesOptions {
	coverageId: number | null;
	perPage?: number;
	page?: number;
	search?: string;
	orderBy?: string;
	order?: 'asc' | 'desc';
	status?: string;
	refreshKey?: number;
}

interface SaveCoverageData {
	name: string;
	description: string;
	status: string;
}

interface BreakoutModalProps {
	entry: Entry;
	onClose: () => void;
	onSaved: () => void;
}

interface BreakoutFormData {
	rolling_coverage_breakout_read_more_text: string;
}

interface CreateBreakoutResponse {
	breakoutPostId: number;
	editLink: string;
	status: string;
}

interface CreateBreakoutResult extends ApiResult {
	data?: CreateBreakoutResponse;
}

interface ChipLinkProps {
	href: string;
	label: string;
	variant?: string;
}

interface TermChipsProps {
	terms: Array< { link: string; name: string } >;
}

interface SlackMonitorLogEntry {
	timestamp: string;
	level: string;
	message: string;
	context: Record< string, unknown >;
}

interface SlackMonitorLogsResult extends ApiResult {
	lines?: SlackMonitorLogEntry[];
	offset?: number;
	active?: boolean;
}

interface AdminHeaderProps {
	selectedCoverage: Coverage | null;
}

interface SlackConnectionModalProps {
	coverage: Coverage | null;
	onClose: () => void;
	onSaved: () => void;
}
interface QuickEditModalProps {
	entryId: number;
	onClose: () => void;
	onSaved: () => void;
}

interface SlackErrorProps {
	message?: string | null;
}

interface ConnectedChannelViewProps {
	channelName: string;
	channelId: string;
	autopublish: boolean;
	onAutopublishChange: ( value: boolean ) => void;
	isUpdatingAutopublish: boolean;
	error?: string | null;
}

interface ConnectChannelFormProps {
	channel: string;
	onChannelChange: ( value: string ) => void;
	autopublish: boolean;
	onAutopublishChange: ( value: boolean ) => void;
	isConnecting: boolean;
	error?: string | null;
}

interface ConnectionModalFooterProps {
	mode: 'connected' | 'connect';
	isConnecting: boolean;
	isDisconnecting: boolean;
	canConnect: boolean;
	onClose: () => void;
	onConnect: () => void;
	onDisconnect: () => void;
}

interface ChannelsTableProps {
	channels: ChannelMapping[];
	disconnectingChannelId: string | null;
	updatingAutopublishChannelId: string | null;
	onUnlink: ( channelId: string ) => void;
	onAutopublishChange: ( channelId: string, autopublish: boolean ) => void;
}

interface SlackBotUserInfo {
	id: number;
	login: string;
	display_name: string;
	email: string;
	roles: string[];
	edit_url: string;
}

interface SlackSettingsInfo {
	connected: boolean;
	workspace_name: string;
	workspace_id: string;
	ignore_prefix: string;
	bot_user_id: number;
	slack_bot_user_id?: string;
	masked_token: string;
	bot_user?: SlackBotUserInfo;
}

interface CredentialsTabProps {
	isConfigured: boolean;
	botToken: string;
	setBotToken: ( v: string ) => void;
	signingSecret: string;
	setSigningSecret: ( v: string ) => void;
	isVerifying: boolean;
	isDisconnecting: boolean;
	workspaceInfo: SlackSettingsInfo | null;
	onVerify: () => void;
	onDisconnect: () => void;
}

interface IngestionSettingsTabProps {
	isConfigured: boolean;
	ignorePrefix: string;
	setIgnorePrefix: ( v: string ) => void;
	isSavingSettings: boolean;
	onSaveSettings: () => void;
	workspaceInfo: SlackSettingsInfo | null;
	editUserUrl: string;
}

interface ChannelsTabProps {
	isConfigured: boolean;
	channels: ChannelMapping[];
	disconnectingChannelId: string | null;
	updatingAutopublishChannelId: string | null;
	onUnlink: ( channelId: string ) => void;
	onAutopublishChange: ( channelId: string, autopublish: boolean ) => void;
}

interface SetupGuideTabProps {
	manifestJson: string;
}

interface IncomingMessage {
	source: string;
	/** Platform-native message id (Slack `ts`, Telegram `message_id`, WhatsApp `wamid`). */
	source_ref: string;
	conversation_ref: string;
	author_external_id: string | null;
	author_display_name: string | null;
	content_html: string;
	thread_ref: string | null;
	external_timestamp: string;
	raw_payload: unknown;
}

interface SettingField {
	key: string;
	label: string;
	type: 'text' | 'password' | 'boolean';
	secret?: boolean;
	help?: string;
}
interface QuickEditSaveBarProps {
	onClose: () => void;
	onSaved: () => void;
}

interface EntityRecord {
	id: number;
	type: string;
	title?: { raw?: string };
	content?: { raw?: string };
	status?: string;
}

/** Selectors from the editor store, typed for the sub-registry. */
type EditorSelectors = {
	__unstableIsEditorReady?: () => boolean;
	isSavingPost: () => boolean;
	didPostSaveRequestFail: () => boolean;
	getCurrentPostType: () => string;
	getCurrentPostId: () => number;
};

/** Selectors from the core-data store. */
type CoreSelectors = {
	getLastEntitySaveError: (
		kind: string,
		name: string,
		recordId: number
	) => { message?: string } | undefined;
};

export type {
	AdminConfig,
	Context,
	ContextExports,
	Coverage,
	Entry,
	PostStatus,
	ViewState,
	View,
	Field,
	Action,
	PaginationInfo,
	ApiResult,
	ChannelMapping,
	DataViewsWrapperProps,
	CoverageModalProps,
	CoverageFormData,
	UseCoveragesOptions,
	BreakoutModalProps,
	BreakoutFormData,
	CreateBreakoutResponse,
	CreateBreakoutResult,
	ConfirmModalProps,
	ConfirmModalContentProps,
	UseEntriesOptions,
	SaveCoverageData,
	ChipLinkProps,
	TermChipsProps,
	AdminHeaderProps,
	SlackConnectionModalProps,
	SlackErrorProps,
	ConnectedChannelViewProps,
	ConnectChannelFormProps,
	ConnectionModalFooterProps,
	ChannelsTableProps,
	CredentialsTabProps,
	ChannelsTabProps,
	SetupGuideTabProps,
	IngestionSettingsTabProps,
	SlackSettingsInfo,
	SlackBotUserInfo,
	IncomingMessage,
	SettingField,
	SlackConnectResult,
	SlackVerifyResult,
	SlackChannelsResult,
	SlackMonitorLogEntry,
	SlackMonitorLogsResult,
	SettingsNotice,
	AdminTab,
	QuickEditModalProps,
	QuickEditSaveBarProps,
	EntityRecord,
	EditorSelectors,
	CoreSelectors,
};
