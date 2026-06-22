# Feature: DataViews Admin Interface

## Summary

Replaces the default WordPress admin list tables for liveblogs and entries with a modern SPA built on WordPress `DataViews`. The plugin now provides a dedicated admin page where users manage liveblogs (taxonomy terms) and their entries (CPT posts) through a two-level navigable interface.

## What Changed

### Backend (PHP)

- **Taxonomy** — Two term meta keys are registered (`created_at`, `modified_at`) to track lifecycle state and timestamps, all exposed via REST.

- **Term count accuracy** — Filters `update_post_term_count_statuses` so entry counts reflect all visible statuses (publish, draft, pending, future, private) rather than just published.

- **Auto-assignment of terms to entries** — When creating a new entry from the SPA, the term ID is passed via URL param, captured into user meta on `load-post-new.php`, rendered as a hidden field with nonce in the classic editor, and consumed on `save_post` to automatically assign the term. This dual-path approach (form field + user meta fallback) ensures it works for both classic editor and REST API saves, while always cleaning up stale user meta.

- **Admin page & asset enqueuing** — Registers a top-level menu page (`dashicons-megaphone`) that renders a `<div>` mount point. Conditionally enqueues the compiled JS/CSS bundles only on that page. Localizes REST base URLs, nonce, capabilities, and admin URLs for the SPA.

- **Initialization & lifecycle** — Wires all classes together in the initializer. Registers activation/deactivation hooks for future use. Admin class is only loaded in admin context.

### Frontend (TypeScript/React SPA)

- **Two-view navigation** — The app manages `liveblogs` and `entries` views. Selecting a liveblog navigates to its filtered entry list. A back button returns to the liveblog list. Each entry view is keyed by liveblog ID so switching liveblogs fully resets state.

- **Liveblog list** — DataViews table with columns for term ID, name, entry count, status (filterable), created/modified dates. Row click navigates to entries. Row actions: Edit (opens modal), Entries (navigates), Delete (with confirmation). "New Liveblog" button in the header opens a create modal.

- **Liveblog create/edit modal** — Uses `DataForm` for field rendering (name, description, status dropdown). Distinguishes create vs. edit mode based on whether a liveblog object is passed. Saves via REST API (`POST /wp/v2/rolling-coverage`).

- **Entry list** — DataViews table with columns for post ID, title, created/modified dates, author (linked), status (filterable), categories, and tags. Author and terms are extracted from `_embedded` data. Row actions: Edit (links to post editor), View (links to post), Delete (with confirmation). "New Entry" button links to the classic editor with the term ID in the URL (triggers auto-assignment). Hidden when the liveblog is archived.

- **Delete actions** — Shared `createDeleteAction` factory that renders a `ConfirmModalContent` with singular/plural confirmation messages and bulk support. Uses `Promise.allSettled` for bulk deletes, refreshing data on success.

- **Data fetching** — `useLiveblogs` and `useEntries` hooks wrap `useEntityRecords` from `@wordpress/core-data`, deriving error state from null records after resolution. Entries are filtered by the `rolling-coverage` taxonomy param and request `context=edit` for all-status visibility. Both hooks support a `refreshKey` for cache busting after mutations.

- **Field utilities** — `truncate`, `safeFormatUTCDate`, `getEmbeddedTerms` for consistent rendering of field values.

- **Type-safe DataViews wrapper** — Bridges the complex conditional prop types of `DataViews` with double-assertion through `unknown`, filling in sensible defaults (`getItemId`, `defaultLayouts`, `isItemClickable`).

- **Styling** — SCSS partials for variables, base (imports WP component/DataViews styles), header, error, modal footer, and chip components. All selectors scoped under the `newspack-rolling-coverage-` prefix.

### Build & Config

- **TypeScript** — Added `tsconfig.json` with strict mode, React JSX, and ES module settings. Added `typecheck` script and integrated it into the `lint` command.

- **Webpack** — Entry point configured via `newspack-scripts` for `src/admin/index.tsx`.

- **Dependencies** — Added `@wordpress/api-fetch`, `@wordpress/components`, `@wordpress/core-data`, `@wordpress/data`, `@wordpress/dataviews`, `@wordpress/element`, `@wordpress/i18n`, and `@wordpress/icons`. Upgraded `newspack-scripts` to `^5.9.8`.