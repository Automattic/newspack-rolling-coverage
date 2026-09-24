# Newspack Rolling Coverage

Live blog and rolling coverage of ongoing news events for [Newspack](https://newspack.com/).

Rolling Coverage lets newsrooms publish a continuous feed of short, timestamped entries for a developing story. Entries can be authored in WordPress, ingested automatically from Slack channels, embedded on any post or page with a Gutenberg block, and consumed by readers in real time without a page reload.

This file is the technical/developer reference. For the end-user feature overview see [`readme.txt`](readme.txt).

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Key concepts](#key-concepts)
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
- [Development](#development)
- [Testing](#testing)
- [Release and versioning](#release-and-versioning)

## Requirements

| Requirement | Version |
| :--- | :--- |
| PHP | 8.0+ (developed and CI-tested on PHP 8.3; the code uses union return types and constructor property promotion) |
| WordPress | Latest (block editor / REST API required) |
| Node.js | `lts/*` (see `.nvmrc`), for building assets |
| Optional: [Newspack Ads](https://github.com/Automattic/newspack-ads) | For in-feed advertising |
| Optional: [OneSignal](https://wordpress.org/plugins/onesignal-free-web-push-notifications/) | For follow/push notifications |
| Optional: [WordPress AI](https://github.com/WordPress/ai) plugin + a provider | For AI key takeaways |
| Optional: WordPress 6.9+ | For the Abilities API registration |

## Installation

1. Build or download the plugin, place it in `wp-content/plugins/newspack-rolling-coverage`, and activate it.
2. A **Rolling Coverage** menu (megaphone icon) appears in WP Admin.
3. Create a coverage, add or embed its block on a post, and optionally connect a Slack channel.

For local development:

```bash
composer install
npm install
npm run build
```

## Key concepts

- **Coverage** — a `rolling_coverage` taxonomy term representing one ongoing news event. It carries a status (`active`, `paused`, `archived`, `trash`), an optional canonical URL, and an optional linked Slack channel.
- **Entry** — a `rolling_cov_entry` custom post type assigned to exactly one coverage. Entries are the individual posts in the live feed.
- **Coverage block** — the Gutenberg block that renders the live feed on the frontend and polls for new entries.
- **Cohost/embedding post** — the post or page containing a coverage block. Entries deep-link back to it.
- **Breakout** — a standard WordPress post cloned from an entry, used to promote a single update to its own article.

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
| Coverage Follow Button | `coverage-follow` | Push-notification follow button (requires OneSignal). |
| Coverage Archived Notice | `coverage-archived-notice` | Notice shown when a coverage is archived. |
| Deep Link CTA | `deep-link-cta` | Call-to-action shown to readers arriving from an old deep link. |
| Share | `share` | Copies a shareable entry URL to the clipboard. |

The Rolling Coverage block exposes these attributes:

| Attribute | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `coverageId` | number | `0` | Coverage term to display. |
| `pollInterval` | number | `10` | Seconds between polls for new entries. |
| `entriesPerPage` | number | `20` | Entries loaded initially and per load-more page (max 100). |
| `enableAds` | boolean | `true` | Insert ads between entries (requires Newspack Ads). |
| `adsInterval` | number | `4` | Insert an ad every N entries. |

The feed supports live forward polling (new/updated entries), backward pagination ("load more" on scroll), off-page update reconciliation, overflow detection with automatic reload, and intersection-observer analytics events (`coverage_entry_seen`, `coverage_poll_error`).

### Slack ingestion

Connect a Slack channel to a coverage and new messages become entries automatically.

- **Channel mapping** — link a Slack channel to a coverage from the admin connection modal or with the `/rolling-coverage-connect` slash command.
- **Auto-publish** — per-channel toggle; off publishes Slack messages as drafts, on publishes them immediately.
- **Ignore prefix** — messages beginning with a configurable prefix (default `~~`) are skipped.
- **Filtering** — bot messages, message edits/deletes, and channel join/leave events are ignored.
- **Content conversion** — Slack `mrkdwn` user/channel/link mentions are converted to readable text and wrapped in a Gutenberg paragraph block.
- **Dedup** — a per-message mutex plus a unique source-reference meta key prevents duplicate entries on webhook retries.
- **Security** — all webhook requests are authenticated with HMAC-SHA256 signature verification (timing-safe `hash_equals`) and a 5-minute replay window.
- **Slash commands** — `/rolling-coverage-connect`, `/rolling-coverage-unlink`, `/rolling-coverage-status`.
- **Setup guide & manifest** — the admin generates a ready-to-paste Slack app manifest with the required scopes and request URLs.
- **Live monitor** — a log viewer streams connection, ingestion, and security events from a protected log file.

> The Slack integration is generic by design: ingestion flows through `Entry_Ingestion_Service` and a chat-source adapter protocol, so additional sources can be added.

### Archive mode

A coverage can be archived when a news event concludes. Archiving makes the feed static and freezes its entries.

- Coverage statuses: `active`, `paused`, `archived`, `trash`.
- Archiving a coverage records an end time, hides the follow button, and renders an archived notice.
- Entries in an archived coverage are **locked**: they cannot be deleted, restored, pinned, broken out, or reassigned to a new (non-archived) coverage.
- Individual entries can also be archived, which collapses long content behind a "read more" summary in the feed.

### Breakout posts

Promote a single entry to a standalone article.

- Creates a **draft** `post` (owned by the acting user) copying title, content, categories, tags, and featured image.
- Links the entry and breakout bidirectionally; the `breakout-post-link` block renders the "Read more" link once the breakout is published.
- Editable "read more" label stored on the entry.
- Status changes are synced back to the entry so polling re-renders the button; deleting the breakout cleans up the link.

### Push notifications

When the OneSignal plugin is installed, configured, and v3-active:

- A per-entry meta box can opt a newly published entry into a push notification.
- Notifications are sent **only to followers of the relevant coverage** using a tag filter (`coverage_{id}`).
- The follow block lets readers subscribe to a coverage's tag.
- Notification URLs deep-link to the coverage's canonical URL and the entry's slug anchor, and are scoped to followers via a tag filter.
- Notifications require the coverage to have a canonical URL set.

### Social sharing and deep links

- The share block copies a link that includes `?rc_source={host_post_id}` so the entry can redirect back to the embedding page and scroll to the entry anchor.
- Entries with a canonical URL redirect to the canonical coverage page with `#newspack-rolling-coverage-entry-{id}`.
- Social crawlers receive the entry's own Open Graph tags (JS redirect), while visitors get a server-side 302.
- The `deep-link-cta` block shows returning visitors a CTA (optionally linking to a breakout post) via a native `<dialog>` modal.

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
  blocks/                        Server render callbacks for the six blocks
  slack/                         Slack config, API client, content processor, verifier, webhook controller, monitor
  sources/
    class-entry-ingestion-service.php  Generic ingestion + dedup/mutex
    class-source-event-payload.php     Normalized payload value object
src/
  admin/                         React/TypeScript admin app (DataViews, modals, Slack settings, AI page)
  blocks/                        Block editor + frontend view sources (edit.tsx, view.ts, block.json)
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
- Term meta: `rolling_coverage_status`, `rolling_coverage_canonical_url`, `created_at`, `modified_at`, `rolling_coverage_end_time`, `rolling_coverage_last_modified`, `rolling_coverage_slack_channel_id`, `rolling_coverage_slack_channel_name`, `rolling_coverage_source`, `rolling_coverage_source_ref`, `rolling_coverage_ads_disabled`, `rolling_coverage_template_hash`.

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
| `rolling_coverage_original_coverage_id`, `rolling_coverage_original_coverage_name`, `rolling_coverage_original_coverage_slug` | Recovery context snapshotted at first term assignment (not at trash time). Writes require `edit_post`; **readable over REST in both contexts** (not stripped). | view + edit |
| `rolling_coverage_breakout_post_id` | Linked breakout post ID | edit only |
| `rolling_coverage_breakout_read_more_text` | Breakout link label | edit + view |
| `rolling_coverage_breakout_status` | Cached breakout post status | edit only |
| `rolling_coverage_source_entry_id` | Reverse link on the breakout post | — |
| `_rolling_coverage_archived_at` | Entry archived timestamp (non-empty = archived) | — |
| `rolling_coverage_notify_on_publish` | Push-notification opt-in flag, read and cleared by the publish transition | — |

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
| POST | `/entries/{entry_id}/breakout` | `edit_others_posts` | Create breakout post. |
| POST | `/entries/{entry_id}/archive` | `edit_others_posts` | Archive/unarchive an entry. |
| GET/POST | `/ai/settings` | `edit_others_posts` | Read/update the key-takeaways prompt. |

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

The `entries-view` endpoint accepts `page`, `per_page`, `orderby` (`date`\|`modified`), `order`, `search`, `status`, `status_exclude`, `source`, `source_exclude`, `author`, `title`, `post_id`, `breakout_status`, `breakout_status_exclude`, `category_search`, `tag_search`, `date_filter`, `modified_filter`, and `since`. The sync cursor format is `{id}:{modified_gmt}`.

## Developer reference

### Actions

| Hook | Arguments | Fired in |
| :--- | :--- | :--- |
| `rolling_coverage_activation` | — | `class-initializer.php` |
| `rolling_coverage_deactivation` | — | `class-initializer.php` |
| `rolling_coverage_slack_channel_linked` | `$channel_id`, `$term_id` | Slack webhook controller |
| `rolling_coverage_slack_channel_unlinked` | `$channel_id` | Slack config/webhook controller |
| `rolling_coverage_slack_security_event` | `$event`, `$context` | Slack signature verifier |
| `newspack_ads_before_placement_ad` / `newspack_ads_after_placement_ad` | placement key, hook key, data | Ads |

### Filters

| Hook | Signature | Purpose |
| :--- | :--- | :--- |
| `newspack_rolling_coverage_schema_metadata` | `(array $metadata, int $coverage_id, WP_Post $post)` | Alter LiveBlogPosting JSON-LD. |
| `newspack_rolling_coverage_schema_article_body` | `(string $body, WP_Post $entry)` | Alter per-entry article body. |
| `newspack_rolling_coverage_entry_redirect_url` | `(string $url, WP_Post $entry)` | Override/disable the canonical entry redirect. |
| `newspack_rolling_coverage_entry_redirect_status` | `(int $status, WP_Post $entry, string $url)` | Change the redirect status code (default 302). |
| `newspack_rolling_coverage_entry_archived_notice` | `(string $notice)` | Change the archived-entry notice text. |
| `onesignal_send_notification` | `(array $fields, int $post_id)` | Consumed to scope push notifications. |
| `newspack_ads_gam_bounds_selectors` | `(array $selectors, $ad_unit, $sizes)` | GAM bounds selectors for in-feed ad slots. |
| `newspack_ads_gam_bounds_bleed` | `(int $bleed, $ad_unit, $sizes)` | GAM bounds bleed (default 40). |

### Options, meta, and transients

- `rolling_coverage_pinned_entries` (autoloaded array of pinned post IDs).
- `rolling_coverage_ai_settings` (AI prompt settings).
- `rolling_coverage_slack_bot_token`, `rolling_coverage_slack_signing_secret`, `rolling_coverage_slack_settings`, `rolling_coverage_slack_channel_map`, `rolling_coverage_slack_bot_user_id` (all non-autoloaded).
- `rolling_coverage_slack_monitor_last_seen`, `rolling_coverage_slack_monitor_filename`.
- `rolling_coverage_source_ingest_{md5}` (short-lived ingestion mutex, 60s TTL).
- `rc_tpl_{coverage_id}_{hash}` (hashed block template/config for polling renders).
- Transient `rolling_coverage_slack_user_{id}` (Slack user cache, 5 min).
- Transient `rolling_coverage_ai_available` (AI availability cache, 5 min).
- Schema cache transient `nrc_{coverage_id}_{hash}` (1 week).

Useful query vars for developers on `WP_Query`:

- `rolling_coverage_author_scope` (`all`\|`author`\|`own`\|`editable`) — scope entry results to the current user.
- `rolling_coverage_skip_pin_order` (bool) — opt out of pinned-first ordering.

### Cron events

- `rolling_coverage_cleanup_orphaned_entries` — permanently deletes entries orphaned by coverage deletion, in batches of 50, rescheduling while entries remain. Scheduled on coverage deletion and cleared on deactivation.

### Capabilities

| Capability | Grants |
| :--- | :--- |
| `edit_posts` | Access the plugin pages, view/author entries, generate takeaways, view trashed entries. |
| `publish_posts` | Publish own entries. |
| `edit_others_posts` / Editor+ | View and manage all entries, pin, archive, breakout, and access the AI page. |
| `manage_categories` | Create/edit/trash/restore/delete coverages. |
| `manage_options` | Slack connection and monitor, and admin-UI-only Slack term meta writes. |

## Development

```bash
npm run build     # Production webpack build (cleans dist first)
npm start         # npm ci + watch
npm run watch     # Watch mode
npm run lint      # SCSS + JS + PHP + TypeScript
npm run lint:js   # ESLint
npm run lint:php  # PHPCS
npm run typecheck # tsc --noEmit
npm run fix:js    # ESLint autofix
npm run fix:php   # PHPCBF autofix
```

Notes:

- PHP uses a Composer **classmap** over `./includes`. After adding a new PHP file, run `composer dump-autoload`.
- New feature classes must also be registered in `Initializer::includes()`.
- New webpack entry points must be declared in `webpack.config.js`; block entries are auto-discovered from `block.json`.
- Coding standards: WordPress + VIP (`phpcs.xml`), extending `newspack-scripts` ESLint/Stylelint configs.

## Testing

```bash
npm run test:php   # ./vendor/bin/phpunit
```

The PHPUnit suite lives in `tests/` and runs against the WordPress test library. Install it with:

```bash
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
```

The suite covers ingestion, archiving, the entries REST/entries-view endpoints, restore/cleanup, the reader feed, Slack configuration/content/signature/webhook handling, push notifications, the taxonomy lifecycle, and theme FSE block integration. There are currently no JS unit tests (`npm test` is a no-op placeholder).

## Release and versioning

- Commits follow [Conventional Commits](https://www.conventionalcommits.org/) (`feat`, `fix`, `chore`, `docs`, `test`, `refactor`, `perf`, `ci`, `build`, `style`, `revert`). Use `npm run cm` for an interactive prompt.
- Releases run via `semantic-release` through `newspack-scripts` on the `release` branch; `alpha`, `hotfix/*`, and `epic/*` produce prereleases. `trunk` is development only.
- The version is bumped in `newspack-rolling-coverage.php` (and `package.json`), translated strings are regenerated by the i18n workflow, and a distributable zip is built with `npm run release:archive`.
- The distributable is filtered by `.distignore` (sources, tests, tooling, and developer docs are excluded; compiled `dist/`, `includes/`, `languages/`, and production `vendor/` ship).
