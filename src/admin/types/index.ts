/**
 * External dependencies
 */
import type { JSX, MutableRefObject, Dispatch, SetStateAction } from 'react';

/**
 * WordPress dependencies
 */
import type { View, ViewTable, Field, Action } from '@wordpress/dataviews';

interface AdminConfig {
	page: string;
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
		entriesView: string;
		restNamespace: string;
		aiSettings: string;
		posts: string;
	};
	nonce: string;
	capabilities: {
		canEditPosts: boolean;
		canManageTerms: boolean;
		canManageAiSettings: boolean;
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
		lastModifiedKey: string;
		canonicalUrlKey: string;
	};
	aiSettings: AiSettings;
	aiDefaultSettings: AiSettings;
	aiAvailable: boolean;
	aiMaxPromptLength: number;
	slack: {
		isConfigured: boolean;
	};
	blockEditorSettings: Record< string, unknown >;
}

interface Context {
	selectedCoverage: Coverage | null;
	refreshKey: number;
}

type ContextExports = [
	context: Context,
	setContext: React.Dispatch< React.SetStateAction< Context > >,
	refresh: () => void,
];

interface Coverage {
	id: number;
	name: string;
	slug: string;
	taxonomy: string;
	description: string;
	count: number;
	meta: {
		rolling_coverage_status?: 'active' | 'paused' | 'archived' | 'trash';
		rolling_coverage_canonical_url?: string;
		created_at?: string;
		modified_at?: string;
		rolling_coverage_last_modified?: string;
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
	pinned?: boolean;
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

interface TogglePinResult extends ApiResult {
	pinned?: boolean;
}

interface CreateEntryResult extends ApiResult {
	id?: number;
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
	canonicalUrl: string;
}

interface BulkRestoreEntryResult {
	entryId: number;
	restored: boolean;
	coverageId?: number;
	coverageStatus?: string;
	coverageCreated?: boolean;
	entryStatus?: string;
	error?: string;
}

interface BulkRestoreResult extends ApiResult {
	results?: BulkRestoreEntryResult[];
}

interface ConfirmModalContentProps {
	message: string;
	confirmLabel?: string;
	cancelLabel?: string;
	isDestructive?: boolean;
	onConfirm: () => Promise< void >;
	onClose: () => void;
}

interface UseCoveragesOptions {
	perPage?: number;
	page?: number;
	search?: string;
	refreshKey?: number;
}

interface UseEntriesOptions {
	coverageId: number | null;
	page: number;
	perPage?: number;
	search?: string;
	orderBy?: string;
	order?: 'asc' | 'desc';
	status?: string;
	statusExclude?: string;
	source?: string;
	sourceExclude?: string;
	author?: string;
	title?: string;
	postId?: string;
	breakoutStatus?: string;
	breakoutStatusExclude?: string;
	categorySearch?: string;
	tagSearch?: string;
	dateFilter?: string;
	modifiedFilter?: string;
	refreshKey?: number;
}

interface UseEntriesResult {
	rows: EntryViewRow[] | null;
	isResolving: boolean;
	hasResolved: boolean;
	totalItems: number;
	totalPages: number;
	syncNotices: SyncNotice[];
	error: string | null;
}

interface SaveCoverageData {
	name: string;
	description: string;
	status: string;
	canonicalUrl: string;
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

interface AiSettings {
	key_takeaways_prompt: string;
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

interface EntryViewRow {
	id: number;
	title: string;
	date: string;
	modified: string;
	status: PostStatus;
	pinned: boolean;
	author: { id: number; name: string; link: string } | null;
	source: 'wordpress' | 'slack';
	categories: Array< {
		id: number;
		name: string;
		slug: string;
		link: string;
	} >;
	tags: Array< { id: number; name: string; slug: string; link: string } >;
	breakout_post_id: number;
	breakout_status: PostStatus | null;
	/** Set by the sync endpoint: 'new' = inserted after cursor, 'update' = edited. Absent on page-mode rows. */
	change_type?: 'new' | 'update';
}

interface EntryPageResponse {
	entries: EntryViewRow[];
	totalItems: number;
	totalPages: number;
	page: number;
	cursor: string;
}

interface EntrySyncDelta {
	changed: EntryViewRow[];
	cursor: string;
	overflow: boolean;
}

interface SyncNoticeEntry {
	id: number;
	title: string;
	status: PostStatus;
	source: 'wordpress' | 'slack';
}

interface SyncNotice {
	type: 'added' | 'updated' | 'removed';
	count: number;
	entries: SyncNoticeEntry[];
}

interface SyncPollContext {
	baseUrl: string;
	perPage: number;
	cursorRef: MutableRefObject< string | null >;
	coverageIdRef: MutableRefObject< number | null >;
	rowsRef: MutableRefObject< EntryViewRow[] | null >;
	pageRef: MutableRefObject< number >;
	isMountedRef: MutableRefObject< boolean >;
	setRows: Dispatch< SetStateAction< EntryViewRow[] | null > >;
	setSyncNotices: Dispatch< SetStateAction< SyncNotice[] > >;
}

export type {
	AdminConfig,
	Context,
	ContextExports,
	Coverage,
	Entry,
	EntryViewRow,
	EntryPageResponse,
	EntrySyncDelta,
	PostStatus,
	ViewState,
	View,
	Field,
	Action,
	PaginationInfo,
	ApiResult,
	CreateEntryResult,
	ChannelMapping,
	DataViewsWrapperProps,
	CoverageModalProps,
	CoverageFormData,
	UseCoveragesOptions,
	UseEntriesOptions,
	UseEntriesResult,
	BreakoutModalProps,
	BreakoutFormData,
	CreateBreakoutResponse,
	CreateBreakoutResult,
	ConfirmModalContentProps,
	SaveCoverageData,
	ChipLinkProps,
	TermChipsProps,
	BulkRestoreEntryResult,
	BulkRestoreResult,
	AiSettings,
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
	SettingsNotice,
	AdminTab,
	SyncNotice,
	SyncNoticeEntry,
	SyncPollContext,
	QuickEditModalProps,
	QuickEditSaveBarProps,
	EntityRecord,
	EditorSelectors,
	CoreSelectors,
	TogglePinResult,
};
