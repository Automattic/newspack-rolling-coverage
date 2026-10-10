# Contributing to Newspack Rolling Coverage

Thank you for your interest in contributing to Newspack Rolling Coverage! This file is the technical reference for the plugin: its features, architecture, data model, REST API, and hooks.

For the contribution process (bug reports, feature requests, pull requests, and code review), follow the [Newspack contributing guidelines](https://github.com/Automattic/newspack-workspace/blob/main/.github/CONTRIBUTING.md). For local setup, build commands, testing, and releases, see the [README](../README.md#development).

## Table of contents

- [Features](#features)
  - [Editorial workflow](#editorial-workflow)
  - [Frontend blocks](#frontend-blocks)
  - [Slack ingestion](#slack-ingestion)
  - [Archive mode](#archive-mode)
  - [Breakout posts](#breakout-posts)
  - [Push notifications](#push-notifications)
  - [Social sharing and deep links](#social-sharing-and-deep-links)
  - [Advertising](#advertising)
  - [AI key takeaways](#ai-key-takeaways)
  - [Structured data](#structured-data)
- [Architecture](#architecture)
- [Data model](#data-model)
- [REST API](#rest-api)
- [Developer reference](#developer-reference)
  - [Actions](#actions)
  - [Filters](#filters)
  - [Options, meta, and transients](#options-meta-and-transients)
  - [Cron events](#cron-events)
  - [Capabilities](#capabilities)

## Features

### Editorial workflow

- **Coverage management UI** — a React admin app (`Rolling Coverage`) with a DataViews table of coverages: name, entry count, status, Slack channel, created and last-modified dates.
- **Entry management UI** — a per-coverage DataViews table with server-side pagination, sorting, and filtering by status, source, author, title, post ID, breakout status, category, tag, created date, and modified date.
- **Live sync in the admin** — the entry list polls a delta endpoint every 10 seconds and surfaces new/updated entries via snackbar notices (collapsed when more than five changes arrive at once).
- **Quick Edit** — edit the entry title and content in a full block editor rendered inside a modal, without leaving the list.
- **Pinning** — editors (`edit_others_posts`) can pin entries to the top of the feed. Pin order is stored per-site and applied globally to entry queries.
- **Trashed Entries view** — a dedicated view for trashed and orphaned entries with bulk restore and permanent delete. Restoring an entry whose coverage was deleted can create a `{name} - recovery` term.
- **Author scoping** — the query layer limits non-editors to their own entries (plus other authors' published entries), mirroring WordPress meta capabilities.

### Frontend blocks

Six blocks ship with the plugin (namespace `newspack-rolling-coverage`):

| Block | Name | Purpose |
| :--- | :--- | :--- |
| Rolling Coverage | `rolling-coverage` | The live feed container. Renders entries from an inner-block template and polls for updates. |
| Breakout Post Link | `breakout-post-link` | "Read more" link to a published breakout post, shown inside an entry. |
| Follow Coverage | `coverage-follow` | Push-notification follow button (requires OneSignal). Not rendered for an archived coverage. |
| Coverage Status | `coverage-status` | Shows whether a coverage is live, paused, or ended. |
| Check for Updates | `check-updates` | A button readers press to load new entries. A Rolling Coverage feed whose layout holds it checks for new entries only when they do; anywhere else it renders nothing. |
| Share | `share` | Opens the device's share sheet for an entry's shareable URL, or copies the URL to the clipboard where there is no share sheet. |

The Rolling Coverage block exposes these attributes:

| Attribute | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `coverageId` | number | `0` | Coverage term to display. |
| `pollInterval` | number | `10` | Seconds between polls for new entries. |
| `entriesPerPage` | number | `20` | Entries loaded initially and per page of older entries (max 100). |
| `olderEntries` | string | `"scroll"` | How older entries load: `scroll` (as the reader nears the end of the feed), `button` (a Load More button), or `none` (first page only). |
| `enableAds` | boolean | `true` | Insert ads between entries (requires Newspack Ads). |
| `adsInterval` | number | `4` | Insert an ad every N entries. |
| `archivedNoticeShow` | boolean | `true` | Show a notice at the top of the feed once the coverage is archived. |
| `archivedNotice` | string | `""` | Notice text. When empty, a default naming the coverage is used. |
| `archivedNoticeShowLink` | boolean | `true` | Follow the notice with a link. |
| `archivedNoticeLinkUrl` | string | `""` | Link URL. When empty, the coverage's latest breakout post is used. |
| `archivedNoticeLinkLabel` | string | `""` | Link label. When empty, "Read more" is used. |
| `layoutId` | number | `0` | Synced pattern holding a shared layout. `0` uses the block's own inner blocks. |
| `latestOnly` | boolean | `false` | Show only the latest entries instead of the full feed. |
| `latestCount` | number | `5` | Number of entries shown when `latestOnly` is on (1–100). |
| `allUpdatesLink` | boolean | `true` | When `latestOnly` is on, link to the coverage page. Hidden on that page. |
| `allUpdatesLinkText` | string | `""` | This block's own wording for that link. When empty, the layout's text is used. |
| `hideWhenEnded` | boolean | `false` | Remove the whole block once the coverage is archived. |

The feed supports live forward polling (new/updated entries), backward pagination (on scroll or with a Load More button), off-page update reconciliation, overflow detection with automatic reload, and intersection-observer analytics events (`coverage_entry_seen`, `coverage_poll_error`).

### Slack ingestion

Connect a Slack channel to a coverage and new messages become entries automatically.

- **Channel mapping** — link a Slack channel to a coverage from the admin connection modal or with the `/rolling-coverage-connect` slash command.
- **Auto-publish** — per-channel toggle; off publishes Slack messages as drafts, on publishes them immediately.
- **Ignore prefix** — messages beginning with a configurable prefix (default `~~`) are skipped.
- **Filtering** — bot messages, message edits/deletes, and channel join/leave events are ignored.
- **Content conversion** — the message's Slack rich text (or its `mrkdwn` as the fallback) is converted to Gutenberg blocks, with user/channel/link mentions turned into readable text. Images uploaded with a message are imported into the media library and added as image blocks; other uploads are left out.
- **Dedup** — a per-message mutex plus a unique source-reference meta key prevents duplicate entries on webhook retries.
- **Security** — all webhook requests are authenticated with HMAC-SHA256 signature verification (timing-safe `hash_equals`) and a 5-minute replay window.
- **Slash commands** — `/rolling-coverage-connect`, `/rolling-coverage-unlink`, `/rolling-coverage-status`.
- **Setup guide & manifest** — the admin generates a ready-to-paste Slack app manifest with the required scopes and request URLs.
- **Live monitor** — a log viewer streams connection, ingestion, and security events from a protected log file.

> The Slack integration is generic by design: ingestion flows through `Entry_Ingestion_Service` and a chat-source adapter protocol, so additional sources can be added.

### Archive mode

A coverage can be archived when a news event concludes. Archiving makes the feed static and freezes its entries.

- Coverage statuses: `active`, `paused`, `archived`, `trash`.
- Archiving a coverage records an end time, hides the follow button, and renders an archived notice at the top of the feed. The notice is part of the Rolling Coverage block and can be edited or turned off there.
- Entries in an archived coverage are **locked**: they cannot be deleted, restored, pinned, or broken out.
- Individual entries can also be archived, which collapses long content behind a "read more" summary in the feed.

### Breakout posts

Promote a single entry to a standalone article.

- Creates a **draft** `post` (owned by the acting user) copying title, content, categories, tags, and featured image.
- Links the entry and breakout bidirectionally; the `breakout-post-link` block renders the "Read more" link once the breakout is published.
- A "Read more" paragraph in the entry template is linked to the breakout post the same way, and renders nothing until one is published.
- Status changes are synced back to the entry so polling re-renders the button; deleting the breakout cleans up the link.

### Push notifications

When the OneSignal plugin is installed, configured, and v3-active:

- A Push Notifications panel in the entry editor can opt a newly published entry into a push notification. Entries created from a chat source such as Slack are opted in automatically.
- Notifications are sent **only to followers of the relevant coverage** using a tag filter (`coverage_{id}`).
- The follow block lets readers subscribe to a coverage's tag.
- Notification URLs deep-link to the coverage's canonical URL and the entry's slug anchor, and are scoped to followers via a tag filter.
- Notifications require the coverage to have a canonical URL set.

### Social sharing and deep links

- The share block shares or copies a link that includes `?rc_source={host_post_id}` so the entry can redirect back to the embedding page and scroll to the entry anchor.
- Entries with a canonical URL redirect to the canonical coverage page with `#newspack-rolling-coverage-entry-{id}`.
- Social crawlers receive the entry's own Open Graph tags (JS redirect), while visitors get a server-side 302.

### Advertising

When [Newspack Ads](https://github.com/Automattic/newspack-ads) is active, Rolling Coverage registers a `rolling_coverage_entry` placement and inserts ad units between entries at the configured interval. Ads can be disabled per coverage and are capped in the initial render and load-more backlog (`INITIAL_AD_CAP`); polled ads advance a separate counter without the cap. Polled and load-more responses include GPT slot data for client-side rendering.

### AI key takeaways

When the WordPress AI plugin and a provider are configured:

- A **Generate Key Takeaways** action in the block editor summarizes up to 20 published entries (pinned first) into a configurable number of takeaways (1–10).
- The prompt is editable from the **AI** admin page (`rolling-coverage-ai`), with a `{max_takeaways}` placeholder.
- Exposed both as a REST endpoint and as a WordPress Abilities API ability (`rolling-coverage/generate-key-takeaways`) on WP 6.9+.
- Prompt and entry context are capped, and the entries are wrapped in delimiters with an anti-prompt-injection instruction.

### Structured data

Coverage blocks output schema.org `LiveBlogPosting` JSON-LD with a `liveBlogUpdate` array of `BlogPosting` items, including `coverageStartTime`/`coverageEndTime`, `datePublished`/`dateModified`, per-entry `BlogPosting` bodies, and authors. Output is cached in a week-long transient keyed by status and last-modified time.

## Architecture

```
newspack-rolling-coverage.php    Plugin bootstrap: constants, autoloader, Initializer::init()
includes/
  class-initializer.php          Wires every feature class into the WP lifecycle
  class-post-type.php            rolling_cov_entry CPT, meta, REST fields, entries-view endpoint, pinning, restore
  class-taxonomy.php             rolling_coverage taxonomy, term meta, coverage lifecycle REST routes
  class-archive-mode.php         Archive/lock rules and the entry archive endpoint
  class-placements.php           Finds and stores every published place the plugin's blocks show a coverage
  class-newest-entry.php         Keeps each coverage's newest-entry time in term meta
  class-breakout.php             Breakout post creation, linking, status sync
  class-social-sharing.php       Canonical redirects, share URLs, deep-link query vars
  class-schema.php               LiveBlogPosting JSON-LD
  class-push-notifications.php   OneSignal integration
  class-ads.php                  Newspack Ads placement
  class-admin.php                Admin menu, assets, localized config, block-editor bootstrap
  class-slack.php                Slack integration orchestrator
  ai/
    class-ai-service.php         Prompt building, availability detection, generation
    class-ai-settings.php        Prompt settings + REST route
    class-abilities.php          WordPress Abilities API registration
    class-key-takeaways-feature.php  AI plugin per-feature model config
  blocks/                        Server render callbacks for the six blocks, entry bindings, shared layouts, status labels, entry name and Jump to Latest label settings, Lite Site feed support
  slack/                         Slack config, API client, content processor, media importer, author resolver, ingestion service, verifier, webhook controller, monitor
  sources/
    class-entry-ingestion-service.php  Generic ingestion + dedup/mutex
    class-source-event-payload.php     Normalized payload value object
src/
  admin/                         React/TypeScript admin app (DataViews, modals, Slack settings, AI page)
  blocks/                        Block editor + frontend view sources (edit.tsx, view.ts, block.json)
  entry-editor/                  Entry editor script (Push Notifications panel, back-to-coverage link)
tests/                           PHPUnit suite
```

All PHP classes live under the `Newspack_Rolling_Coverage` namespace and are loaded via Composer's `classmap` autoloader over `./includes`. The admin app is compiled by webpack to `dist/admin.js` / `dist/admin.css`; blocks compile to `dist/blocks/<slug>/`.

## Data model

### Custom post type: `rolling_cov_entry`

- REST base `rolling-coverage-entries`, rewrite slug `rolling-cov-entry`.
- Supports title, editor, author, revisions, custom fields, thumbnail.
- Attached taxonomies: `rolling_coverage`, `category`, `post_tag`.
- Uses core `post` capabilities (no custom capability type).

### Taxonomy: `rolling_coverage`

- REST base `rolling-coverage`, non-hierarchical, not publicly queryable or shown in menus.
- Term meta: `rolling_coverage_status`, `rolling_coverage_canonical_url`, `created_at`, `modified_at`, `rolling_coverage_end_time`, `rolling_coverage_last_modified`, `rolling_coverage_slack_channel_id`, `rolling_coverage_slack_channel_name`, `rolling_coverage_source`, `rolling_coverage_source_ref`, `rolling_coverage_ads_disabled`, `rolling_coverage_template_hashes`, `rolling_coverage_newest_entry`.

### Entry post meta

| Meta key | Description | REST |
| :--- | :--- | :--- |
| `rolling_coverage_entry_source` | `wordpress` or a platform slug (e.g. `slack`) | view + edit |
| `rolling_coverage_source_ref` | Canonical dedup key | edit only |
| `rolling_coverage_slack_ts` | Slack message timestamp | edit only |
| `rolling_coverage_slack_user_id` | Slack author ID | edit only |
| `rolling_coverage_slack_author_name` | Slack author display name | edit only |
| `rolling_coverage_slack_channel_id` | Source Slack channel | edit only |
| `rolling_coverage_slack_thread_ts` | Thread timestamp | edit only |
| `_rolling_coverage_published_gmt` | GMT first-publish time (protected) | — |
| `_rolling_coverage_unpublished_gmt` | GMT time the entry last left `publish` (protected) | — |
| `rolling_coverage_original_coverage_id`, `rolling_coverage_original_coverage_name`, `rolling_coverage_original_coverage_slug` | Recovery context snapshotted at first term assignment (not at trash time). Writes require `edit_post`; **readable over REST in both contexts** (not stripped). | view + edit |
| `rolling_coverage_breakout_post_id` | Linked breakout post ID | — |
| `rolling_coverage_breakout_status` | Cached breakout post status | edit only (as a REST field, not as meta) |
| `rolling_coverage_source_entry_id` | Reverse link on the breakout post | — |
| `_rolling_coverage_archived_at` | Entry archived timestamp (non-empty = archived) | — |
| `_rolling_coverage_notify_on_publish` | Push-notification opt-in flag, read and cleared by the publish transition | edit only |

Sensitive Slack/source meta (`RESTRICTED_META` in `Post_Type`) is stripped from REST responses outside the `edit` context. On entry post meta, WordPress does not gate reads by `auth_callback` — writes are gated by the post's `edit_post` capability; the strip filter is what protects those keys on read.

## REST API

Namespace: **`rolling-coverage/v1`** (constant `NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE`).

### Coverages and entries

| Method | Route | Permission | Purpose |
| :--- | :--- | :--- | :--- |
| GET | `/coverages/{term_id}/entries-view` | `edit_posts` | Admin entries DataViews; page mode, or sync mode when `since` is passed. |
| GET | `/coverages/{term_id}/entries` | public | Frontend poll/load-more feed. |
| GET | `/coverages/{term_id}/entries-preview` | `edit_posts` | Entry IDs for editor previews. |
| POST | `/coverages/{coverage_id}/generate-key-takeaways` | `edit_posts` | AI summary generation. |
| POST | `/coverages/{coverage_id}/trash` | `manage_categories` | Soft-delete a coverage. |
| POST | `/coverages/{coverage_id}/restore` | `manage_categories` | Restore a trashed coverage. |
| DELETE | `/coverages/{coverage_id}` | `manage_categories` | Permanently delete a coverage (schedules orphan cleanup). |
| POST | `/entries/{entry_id}/pin` | `edit_others_posts` | Toggle pin. |
| POST | `/entries/{entry_id}/restore` | `edit_post` | Restore one trashed entry. |
| POST | `/entries/restore` | `edit_posts` | Bulk restore. |
| POST | `/entries/author` | `edit_others_posts` | Make one user the author of several entries (up to 100). |
| POST | `/entries/{entry_id}/breakout` | `edit_others_posts` | Create breakout post. |
| POST | `/entries/{entry_id}/archive` | `edit_others_posts` | Archive/unarchive an entry. |
| GET/POST | `/ai/settings` | `edit_others_posts` | Read/update the key-takeaways prompt. |
| GET/POST | `/settings/status-labels` | `edit_others_posts` | Read/update the coverage status labels. |
| GET/POST | `/settings/entry-name` | `edit_others_posts` | Read/update what readers see entries called (singular and plural). |
| GET/POST | `/settings/latest-label` | `edit_others_posts` | Read/update the Jump to Latest button label. |
| POST | `/layouts/{slug}` | create and publish patterns (`wp_block`) | Create the shared layout pattern for a built-in layout, or return the existing one. |

### Slack

| Method | Route | Permission | Purpose |
| :--- | :--- | :--- | :--- |
| POST | `/slack/verify` | `manage_options` | Validate and store credentials. |
| POST | `/slack/disconnect` | `manage_options` | Remove all Slack configuration. |
| GET/POST | `/slack/settings` | `manage_options` | Read/update ingestion settings. |
| GET | `/slack/channels` | `manage_options` | List channel mappings. |
| GET/DELETE/POST | `/slack/channel/{id:[CG][A-Z0-9]+}` | `manage_options` | Read/unlink/update a channel mapping. |
| POST | `/slack/connect` | `manage_options` | Connect a channel to a coverage. |
| POST | `/slack/disconnect-term` | `manage_options` | Disconnect a coverage's channel. |
| GET | `/slack/search-terms` | `manage_options` | Search coverages for the picker. |
| GET | `/slack/monitor/logs` | `manage_options` | Stream monitor log lines. |
| POST | `/slack/events` | Slack signature | Event subscriptions. |
| POST | `/slack/commands` | Slack signature | Slash commands. |
| POST | `/slack/interactions` | Slack signature | Interactive components. |

Core WordPress routes are also used: `/wp/v2/rolling-coverage` (coverages) and `/wp/v2/rolling-coverage-entries` (entries, including trash/force delete).

The `entries-view` endpoint accepts `page`, `per_page`, `orderby` (`date`\|`modified`), `order`, `search`, `status`, `status_exclude`, `source`, `source_exclude`, `author`, `title`, `post_id`, `breakout_status`, `breakout_status_exclude`, `archived`, `category_search`, `tag_search`, `date_filter`, `modified_filter`, and `since`. The sync cursor format is `{id}:{modified_gmt}`.

## Developer reference

### Actions

| Hook | Arguments | Fired in |
| :--- | :--- | :--- |
| `rolling_coverage_activation` | — | `class-initializer.php` |
| `rolling_coverage_deactivation` | — | `class-initializer.php` |
| `rolling_coverage_slack_channel_linked` | `$channel_id`, `$term_id` | Slack webhook controller |
| `rolling_coverage_slack_channel_unlinked` | `$channel_id` | Slack config/webhook controller |
| `rolling_coverage_slack_security_event` | `$event`, `$context` | Slack signature verifier |
| `newspack_rolling_coverage_entry_ingested` | `$post_id` | Entry ingestion service |
| `newspack_ads_before_placement_ad` / `newspack_ads_after_placement_ad` | placement key, hook key, data | Ads |

### Filters

| Hook | Signature | Purpose |
| :--- | :--- | :--- |
| `newspack_rolling_coverage_schema_metadata` | `(array $metadata, int $coverage_id, WP_Post $post)` | Alter LiveBlogPosting JSON-LD. |
| `newspack_rolling_coverage_schema_article_body` | `(string $body, WP_Post $entry)` | Alter per-entry article body. |
| `newspack_rolling_coverage_entry_redirect_url` | `(string $url, WP_Post $entry)` | Override/disable the canonical entry redirect. |
| `newspack_rolling_coverage_entry_redirect_status` | `(int $status, WP_Post $entry, string $url)` | Change the redirect status code (default 302). |
| `newspack_rolling_coverage_entry_archived_notice` | `(string $notice)` | Change the archived-entry notice text. |
| `newspack_rolling_coverage_min_poll_interval` | `(mixed $interval)` | Set a minimum number of seconds between a reader's polls. Anything but a positive number means no minimum. |
| `newspack_rolling_coverage_defer_notification` | `(bool $defer, WP_Post $post)` | Whether an entry's push notification is scheduled instead of sent during the request that published it. |
| `onesignal_send_notification` | `(array $fields, int $post_id)` | Consumed to scope push notifications. |
| `newspack_ads_gam_bounds_selectors` | `(array $selectors, $ad_unit, $sizes)` | GAM bounds selectors for in-feed ad slots. |
| `newspack_ads_gam_bounds_bleed` | `(int $bleed, $ad_unit, $sizes)` | GAM bounds bleed (default 40). |

### Options, meta, and transients

- `rolling_coverage_pinned_entries` (autoloaded array of pinned post IDs).
- `rolling_coverage_ai_settings` (AI prompt settings).
- `rolling_coverage_slack_bot_token`, `rolling_coverage_slack_signing_secret`, `rolling_coverage_slack_settings`, `rolling_coverage_slack_channel_map`, `rolling_coverage_slack_bot_user_id` (all non-autoloaded).
- `rolling_coverage_slack_monitor_last_seen`, `rolling_coverage_slack_monitor_filename`.
- `rolling_coverage_placements` (stored map of the published places that show each coverage), `rolling_coverage_placements_stale`, `rolling_coverage_placements_lock` (rebuild lock, 300s TTL).
- `rolling_coverage_entry_name`, `rolling_coverage_latest_label` (reader-facing label settings).
- `rolling_coverage_status_labels` (custom coverage status labels).
- `rolling_coverage_{slug}_layout_id` (pattern ID of each built-in shared layout).
- `rolling_coverage_notification_lock_{post_id}` (push-notification send lock, 60s TTL).
- `rolling_coverage_source_ingest_{md5}` (short-lived ingestion mutex, 60s TTL).
- `rc_tpl_{coverage_id}_{hash}` (hashed block template/config for polling renders).
- Transient `rolling_coverage_slack_user_{id}` (Slack user cache, 5 min).
- Schema cache transient `nrc_{coverage_id}_{hash}` (1 week).

Useful query vars for developers on `WP_Query`:

- `rolling_coverage_author_scope` (`all`\|`author`\|`own`\|`editable`) — scope entry results to the current user.
- `rolling_coverage_skip_pin_order` (bool) — opt out of pinned-first ordering.

### Cron events

- `rolling_coverage_cleanup_orphaned_entries` — permanently deletes entries orphaned by coverage deletion, in batches of 50, rescheduling while entries remain. Scheduled on coverage deletion and cleared on deactivation.
- `newspack_rolling_coverage_send_notification` — single event that sends an entry's deferred push notification.
- `newspack_rolling_coverage_rebuild_placements` — single event that rebuilds the stored placements map after a change.

### Capabilities

| Capability | Grants |
| :--- | :--- |
| `edit_posts` | Access the plugin pages, view/author entries, generate takeaways, view trashed entries. |
| `publish_posts` | Publish own entries. |
| `edit_others_posts` / Editor+ | View and manage all entries, pin, archive, breakout, change entry authors, edit status labels and reader-facing labels, and access the AI page. |
| `manage_categories` | Create/edit/trash/restore/delete coverages. |
| `manage_options` | Slack connection and monitor, and admin-UI-only Slack term meta writes. |
