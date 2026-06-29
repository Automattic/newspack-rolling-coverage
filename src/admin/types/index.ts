/**
 * External dependencies
 */
import type { View, ViewTable, Field, Action } from '@wordpress/dataviews';
import type { JSX } from 'react';

interface AdminConfig {
	restBase: {
		coverages: string;
		entries: string;
	};
	restBaseUrls: {
		coverages: string;
		entries: string;
	};
	nonce: string;
	capabilities: {
		canEditPosts: boolean;
		canManageTerms: boolean;
	};
	adminUrls: {
		editEntry: string;
		newEntry: string;
		editTerm: string;
	};
	postType: string;
	taxonomy: string;
	taxMeta: {
		statusKey: string;
	};
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
		[ key: string ]: unknown;
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

interface ChipLinkProps {
	href: string;
	label: string;
}

interface TermChipsProps {
	terms: Array< { link: string; name: string } >;
}

export type {
	AdminConfig,
	Context,
	ContextExports,
	Coverage,
	Entry,
	ViewState,
	View,
	Field,
	Action,
	PaginationInfo,
	ApiResult,
	DataViewsWrapperProps,
	CoverageModalProps,
	CoverageFormData,
	UseCoveragesOptions,
	UseEntriesOptions,
	SaveCoverageData,
	ChipLinkProps,
	TermChipsProps,
};
