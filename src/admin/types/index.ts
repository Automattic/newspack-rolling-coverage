/**
 * External dependencies
 */
import type { View, ViewTable, Field, Action } from '@wordpress/dataviews';
import type { JSX } from 'react';

interface AdminConfig {
	restBase: {
		liveblogs: string;
		entries: string;
	};
	nonce: string;
	capabilities: {
		canEditPosts: boolean;
		canDeletePosts: boolean;
		canManageTerms: boolean;
	};
	adminUrls: {
		editEntry: string;
		newEntry: string;
		editTerm: string;
	};
	postType: string;
	taxonomy: string;
	statusKey: string;
}

interface Liveblog {
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
	};
}

interface Entry {
	id: number;
	date: string;
	date_gmt: string;
	modified: string;
	modified_gmt: string;
	slug: string;
	status: 'publish' | 'draft' | 'pending' | 'private';
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
	meta: Record< string, unknown >;
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

type ViewType = 'liveblogs' | 'entries';

type ViewState = ViewTable;

interface PaginationInfo {
	totalItems: number;
	totalPages: number;
}

interface ApiResult {
	success: boolean;
	error?: string;
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

interface LiveblogListViewProps {
	onNavigateToEntries: ( liveblog: Liveblog ) => void;
}

interface EntryListViewProps {
	liveblog: Liveblog;
}

interface LiveblogModalProps {
	liveblog: Liveblog | null;
	onClose: () => void;
	onSaved: () => void;
}

interface LiveblogFormData {
	name: string;
	description: string;
	status: 'active' | 'paused' | 'archived';
}

interface ConfirmModalProps {
	title: string;
	message: string;
	confirmLabel?: string;
	cancelLabel?: string;
	isDestructive?: boolean;
	onConfirm: () => Promise< void >;
	onClose: () => void;
}

interface ConfirmModalContentProps {
	message: string;
	confirmLabel?: string;
	cancelLabel?: string;
	isDestructive?: boolean;
	onConfirm: () => Promise< void >;
	onClose: () => void;
}

interface UseLiveblogsOptions {
	perPage?: number;
	page?: number;
	search?: string;
	orderBy?: string;
	order?: 'asc' | 'desc';
	refreshKey?: number;
}

interface UseEntriesOptions {
	liveblogId: number | null;
	perPage?: number;
	page?: number;
	search?: string;
	orderBy?: string;
	order?: 'asc' | 'desc';
	status?: string;
	refreshKey?: number;
}

interface SaveLiveblogData {
	name: string;
	description: string;
	status: string;
}

interface ChipLinkProps {
	href: string;
	label: string;
}

interface TermChipsProps {
	terms: Array< { link: string; name: string } >;
}

export type {
	AdminConfig,
	Liveblog,
	Entry,
	ViewType,
	ViewState,
	View,
	Field,
	Action,
	PaginationInfo,
	ApiResult,
	DataViewsWrapperProps,
	LiveblogListViewProps,
	EntryListViewProps,
	LiveblogModalProps,
	LiveblogFormData,
	ConfirmModalProps,
	ConfirmModalContentProps,
	UseLiveblogsOptions,
	UseEntriesOptions,
	SaveLiveblogData,
	ChipLinkProps,
	TermChipsProps,
};
