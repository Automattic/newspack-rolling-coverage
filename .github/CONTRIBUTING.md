# Contributing to Newspack Rolling Coverage

Thank you for your interest in contributing to Newspack Rolling Coverage! This file is the plugin-wide technical reference: where each feature is documented, the architecture and data model, and every REST route, hook, option, cron event, and capability.

For the contribution process (bug reports, feature requests, pull requests, and code review), follow the [Newspack contributing guidelines](https://github.com/Automattic/newspack-workspace/blob/main/.github/CONTRIBUTING.md). For local setup, build commands, testing, and releases, see the [README](../README.md#development).

## Table of contents

- [Features](#features)
  - [Editorial workflow](#editorial-workflow)
  - [Blocks](#blocks)
  - [Slack ingestion](#slack-ingestion)
  - [Ending a coverage](#ending-a-coverage)
  - [Breakout posts](#breakout-posts)
  - [Push notifications](#push-notifications)
  - [Social sharing and deep links](#social-sharing-and-deep-links)
  - [Advertising](#advertising)
  - [AI key takeaways](#ai-key-takeaways)
  - [Structured data](#structured-data)
  - [Updated date](#updated-date)
- [Architecture](#architecture)
- [Data model](#data-model)
- [REST API](#rest-api)
- [Developer reference](#developer-reference)
  - [Actions](#actions)
  - [Filters](#filters)
  - [Constants](#constants)
  - [Options, meta, and caches](#options-meta-and-caches)
  - [Cron events](#cron-events)
  - [Capabilities](#capabilities)

## Features

How each feature works is documented next to its code; this section says what each one does and where to read more. Blocks have two docs: `README.md` for publishers and `DEVELOPMENT.md` for developers.

### Editorial workflow

The Rolling Coverage admin menu holds the coverages list, each coverage's entries list, Quick Edit, the Settings modal, and the Slack Connection and AI pages. The entries list picks up changes as they happen and offers row and bulk actions, among them Reassign, pinning, and restoring entries from its "Trashed" status filter. See [`src/admin/DEVELOPMENT.md`](../src/admin/DEVELOPMENT.md).

### Blocks

Nine blocks ship with the plugin (namespace `newspack-rolling-coverage`). Each block's attributes are in its `block.json`.

| Block | Name | Purpose | Docs |
| :--- | :--- | :--- | :--- |
| Rolling Coverage | `rolling-coverage` | The live feed: shows a coverage's entries through a layout, checks for new ones, and loads older ones. | [README](../src/blocks/rolling-coverage/README.md), [DEVELOPMENT](../src/blocks/rolling-coverage/DEVELOPMENT.md) |
| Breakout Post Link | `breakout-post-link` | Links an entry to its published breakout post. | [Share and Read more](../src/blocks/rolling-coverage/DEVELOPMENT.md#share-and-read-more) |
| Share | `share` | Opens the device's share sheet for an entry's link, or copies the link where there is no share sheet. | [Share and Read more](../src/blocks/rolling-coverage/DEVELOPMENT.md#share-and-read-more) |
| Follow Coverage | `coverage-follow` | Lets readers follow a coverage to get push notifications (requires OneSignal). | [README](../src/blocks/coverage-follow/README.md), [DEVELOPMENT](../src/blocks/coverage-follow/DEVELOPMENT.md) |
| Coverage Status | `coverage-status` | Shows whether a coverage is Live, Paused, or Ended. | [README](../src/blocks/coverage-status/README.md), [DEVELOPMENT](../src/blocks/coverage-status/DEVELOPMENT.md) |
| Check for Updates | `check-updates` | A button readers press to load new entries. A feed whose layout holds it stops checking for new entries on its own. | [README](../src/blocks/check-updates/README.md), [DEVELOPMENT](../src/blocks/check-updates/DEVELOPMENT.md) |
| Update Timer | `update-timer` | Shows readers when the feed next checks for new entries, and what each check found. | [README](../src/blocks/update-timer/README.md), [DEVELOPMENT](../src/blocks/update-timer/DEVELOPMENT.md) |
| Load More | `load-more` | The button that loads older entries, shown when the feed's Older entries setting is "Load more" button. | [README](../src/blocks/load-more/README.md), [DEVELOPMENT](../src/blocks/load-more/DEVELOPMENT.md) |
| New Label | `new-label` | Marks an entry that arrives while the reader has the page open, until they've seen it. | [README](../src/blocks/new-label/README.md), [DEVELOPMENT](../src/blocks/new-label/DEVELOPMENT.md) |

The feed also sends analytics events as readers see entries and when a poll fails (`coverage_entry_seen`, `coverage_poll_error`, in `src/blocks/rolling-coverage/analytics.ts`).

### Slack ingestion

Connect a Slack channel to a coverage, from the Slack Connection page or with the `/rolling-coverage-connect` slash command, and its messages become entries. Each channel's Publish Mode publishes them right away, saves them as drafts, or waits for an emoji reaction. The Slack sections of [`src/admin/DEVELOPMENT.md`](../src/admin/DEVELOPMENT.md) cover which messages become entries, Publish Mode, how authors are matched, titles and headings, slash commands, and webhooks. The rest:

- **Ignore prefix** — in Auto-publish and Draft channels, messages beginning with a configurable prefix (default `~~`) are skipped.
- **Content conversion** — the message's Slack rich text (or its `mrkdwn` as the fallback) is converted to Gutenberg blocks, with user/channel/link mentions turned into readable text. Images uploaded with a message are imported into the media library and added as image blocks; other uploads are left out.
- **Slash commands** — `/rolling-coverage-connect`, `/rolling-coverage-unlink`, `/rolling-coverage-status`. They're recognized by the end of their names, so a site can rename them when another site's app in the workspace has the same ones, and every reply and every message the bot posts in a channel names the site it came from.
- **Ended and trashed coverages** — messages for a coverage that has ended or is in the trash don't become entries, and the publish emoji leaves their drafts alone.
- **Security** — every webhook request is authenticated with HMAC-SHA256 signature verification (timing-safe `hash_equals`) and a 5-minute replay window.
- **Dedup** — a per-message mutex plus a unique source-reference meta key prevents duplicate entries on webhook retries.
- **Setup guide and manifest** — the admin generates a ready-to-paste Slack app manifest with the required scopes and request URLs.
- **Live monitor** — a log viewer streams connection, ingestion, and security events from a protected log file.

> Ingestion flows through `Entry_Ingestion_Service` and a chat-source adapter protocol, so other sources can be added. See [Adding a source](../src/admin/DEVELOPMENT.md#adding-a-source).

### Ending a coverage

A coverage's status is Live (`active`), Paused (`paused`), or Ended (`archived`), and trashing it sets `trash`; restoring it puts back the status it had. Sites can rename the three labels. Setting a coverage to Ended records an end time, hides the follow button, stops the feed from checking for new entries, and shows a notice at the top of the feed, set in the Rolling Coverage block's [Ended settings](../src/blocks/rolling-coverage/README.md#ended). `Archive_Mode` enforces the rest:

- Entries in an ended coverage are **locked**: they cannot be trashed, restored, pinned, broken out, or reassigned, and the coverage takes no new entries. An individually archived entry is locked the same way. Content edits stay allowed.
- Archiving a single entry also collapses its content behind a summary in the feed.

### Breakout posts

Promote an entry to a standalone article. Breaking out creates a **draft** `post`, owned by the acting user, that copies the entry's title, content, categories, tags, and featured image, and links the entry and the post both ways. Once the post is published, the entry renders through the layout's Full Story Entry group, which most built-in layouts show as a card linking to the post. Status changes sync back to the entry, as do edits to the published post's title, summary, featured image, or label, so open pages pick them up; deleting the post removes the link. See [Breakout cards](../src/blocks/rolling-coverage/DEVELOPMENT.md#breakout-cards) and [The breakout post editor](../src/admin/DEVELOPMENT.md#the-breakout-post-editor).

### Push notifications

When the OneSignal plugin is installed, configured, and v3-active, readers can follow a coverage with the Follow Coverage block. An entry opted into a notification, from the entry editor's Push Notifications panel or automatically when it comes from a chat source such as Slack, notifies only that coverage's followers (tag `coverage_{id}`) when published. The notification links to the entry on the coverage's canonical URL, so it needs one set. See the Follow Coverage block's [README](../src/blocks/coverage-follow/README.md) and [DEVELOPMENT.md](../src/blocks/coverage-follow/DEVELOPMENT.md).

### Social sharing and deep links

- **Share links.** The Share block shares the entry's permalink with `?rc_source={host_post_id}`, the page it was shared from. The entry page serves its own Open Graph tags to social crawlers, which don't run JavaScript, and a script sends visitors on to the host page.
- **Plain permalinks.** When the entry's coverage has a canonical URL, a request for the entry's permalink without `rc_source` is redirected (302 by default) to that URL, for crawlers and visitors alike. Without a canonical URL, the entry page renders.
- Both destinations carry `?rolling-coverage-entry={slug}` and the `#newspack-rolling-coverage-entry-{id}` anchor, so the feed opens at the entry.
- **Entries hidden by their coverage.** When none of an entry's coverages exists outside the trash, its permalink answers with a 404 for every visitor, before either redirect. Front-end listings of entries, such as `?post_type=rolling_cov_entry` and its feed, leave it out, and so do oEmbed and Yoast SEO's head route (`Post_Type::is_hidden_by_coverage()`, `Post_Type::hide_entries_from_main_query()`).

See [Share and Read more](../src/blocks/rolling-coverage/DEVELOPMENT.md#share-and-read-more).

### Advertising

When [Newspack Ads](https://github.com/Automattic/newspack-ads) is active, Rolling Coverage registers a `rolling_coverage_entry` placement and inserts ad units between entries at the configured interval. Ads can be disabled per coverage and are capped in the initial render and load-more backlog (`INITIAL_AD_CAP`); polled ads advance a separate counter without the cap. Polled and load-more responses include GPT slot data for client-side rendering. For the block's settings, see [Ads](../src/blocks/rolling-coverage/README.md#ads).

### AI key takeaways

When the WordPress AI plugin and a provider are configured:

- A **Generate Key Takeaways** action in the block editor summarizes up to 20 published entries (pinned first) into a configurable number of takeaways (1–10), and copies the result to the clipboard.
- Administrators edit the prompt on the **AI** admin page (`rolling-coverage-ai`), with a `{max_takeaways}` placeholder. The block's AI panel links to it for users who can `manage_options`. When key takeaways can't run, the page shows what to set up instead of the prompt.
- Exposed both as a REST endpoint and as a WordPress Abilities API ability (`rolling-coverage/generate-key-takeaways`).
- Prompt and entry context are capped, and the entries are wrapped in delimiters with an anti-prompt-injection instruction.

For the block's AI panel, see [AI](../src/blocks/rolling-coverage/README.md#ai).

### Structured data

A Rolling Coverage block that shows all entries outputs schema.org `LiveBlogPosting` JSON-LD with a `liveBlogUpdate` array of `BlogPosting` items, including `coverageStartTime`/`coverageEndTime`, `datePublished`/`dateModified`, per-entry `BlogPosting` bodies, and authors. With Yoast SEO active, the page's first such coverage is merged into Yoast's Article instead, which keeps its type and adds `LiveBlogPosting`. Output is cached in the object cache for up to a week, and a change to the page, the coverage, or its entries starts a new cache entry. See [Live blog markup](../src/blocks/rolling-coverage/README.md#live-blog-markup).

### Updated date

While a post's coverage is live or paused, the post shows when it last changed: Newspack's "Updated" date in its byline, and the same date in place of the publish date on its Homepage Posts card, whatever the site's Last updated date setting says. The date is when an entry was last published or edited, or the post's own last edit when that came later. Once the coverage ends, the site's settings decide again. See [Updated date](../src/blocks/rolling-coverage/README.md#updated-date) and its [development notes](../src/blocks/rolling-coverage/DEVELOPMENT.md#updated-date).

## Architecture

```
newspack-rolling-coverage.php    Plugin bootstrap: constants, autoloader, Initializer::init()
includes/
  class-initializer.php          Wires every feature class into the WP lifecycle
  class-post-type.php            rolling_cov_entry CPT, meta, REST fields, entries-view endpoint, pinning, restore
  class-taxonomy.php             rolling_coverage taxonomy, term meta, coverage lifecycle REST routes
  class-coverage-rest-controller.php  Core terms route for coverages; creating one needs manage_categories
  class-archive-mode.php         Archive/lock rules and the entry archive endpoint
  class-placements.php           Finds and stores every published place the plugin's blocks show a coverage
  class-newest-entry.php         Keeps each coverage's newest-entry time in term meta
  class-poll-cursor.php          Poll cursors: which of a coverage's changes a page already holds
  class-breakout.php             Breakout post creation, linking, status sync
  class-social-sharing.php       Canonical redirects, share URLs, deep-link query vars
  class-schema.php               LiveBlogPosting JSON-LD
  class-updated-date.php         Newspack's "Updated" date on posts whose coverage hasn't ended
  class-push-notifications.php   OneSignal integration
  class-ads.php                  Newspack Ads placement
  class-admin.php                Admin menu, assets, localized config, block-editor bootstrap
  class-slack.php                Slack integration orchestrator
  ai/
    class-ai-service.php         Prompt building, availability detection, generation
    class-ai-settings.php        Prompt settings + REST route
    class-abilities.php          WordPress Abilities API registration
    class-key-takeaways-feature.php  AI plugin per-feature model config
  blocks/                        Server render callbacks for the nine blocks, entry bindings, shared layouts and their server copies (`layouts/`), breakout cards, status labels, entry name, Jump to Latest and Full story label settings, Lite Site feed support
  slack/                         Slack config, API client, content processor, media importer, author resolver, ingestion service, verifier, webhook controller, monitor
  sources/
    class-entry-ingestion-service.php  Generic ingestion + dedup/mutex
    class-source-event-payload.php     Normalized payload value object
src/
  admin/                         React/TypeScript admin app (DataViews, modals, Slack settings, AI page)
  blocks/                        Block editor + frontend view sources (edit.tsx, view.ts, block.json)
  entry-editor/                  Entry editor script (Push Notifications panel, back-to-coverage link)
  breakout-editor/               Full story label field in a breakout post's settings sidebar
tests/                           PHPUnit suite; tests/js/ holds the poll cursor, layout template and entries list sync checks `npm test` runs
```

All PHP classes live under the `Newspack_Rolling_Coverage` namespace and are loaded via Composer's `classmap` autoloader over `./includes`. The admin app is compiled by webpack to `dist/admin.js` / `dist/admin.css`, the editor scripts to `dist/entry-editor.js` and `dist/breakout-editor.js`, and blocks to `dist/blocks/<slug>/`.

## Data model

### Custom post type: `rolling_cov_entry`

- REST base `rolling-coverage-entries`, rewrite slug `rolling-cov-entry`.
- Supports title, editor, author, revisions, custom fields, thumbnail.
- Attached taxonomies: `rolling_coverage`, `category`, `post_tag`.
- Uses core `post` capabilities (no custom capability type).

### Taxonomy: `rolling_coverage`

- REST base `rolling-coverage`, non-hierarchical, not publicly queryable or shown in menus.
- The term description is an internal note: REST responses outside the `edit` context leave it out (`Taxonomy::filter_rest_response()`).
- Term meta: `rolling_coverage_status`, `rolling_coverage_status_before_trash`, `rolling_coverage_canonical_url`, `created_at`, `modified_at`, `rolling_coverage_end_time`, `rolling_coverage_last_modified`, `rolling_coverage_slack_channel_id`, `rolling_coverage_slack_channel_name`, `rolling_coverage_source`, `rolling_coverage_source_ref`, `rolling_coverage_ads_disabled`, `rolling_coverage_template_hashes`, `rolling_coverage_newest_entry`, `rolling_coverage_change_marker`.

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
| `_rolling_coverage_title_from_source` | Set when the entry's title came from its source message, such as a Slack message's first line (protected) | — |
| `rolling_coverage_original_coverage_id`, `rolling_coverage_original_coverage_name`, `rolling_coverage_original_coverage_slug` | Recovery context snapshotted at first term assignment (not at trash time). Writes require `edit_post`; **readable over REST in both contexts** (not stripped). | view + edit |
| `rolling_coverage_breakout_post_id` | Linked breakout post ID | — |
| `rolling_coverage_breakout_status` | Cached breakout post status | edit only (as a REST field, not as meta) |
| `_rolling_coverage_archived_at` | Entry archived timestamp (non-empty = archived) | — |
| `_rolling_coverage_notify_on_publish` | Push-notification opt-in flag, read and cleared by the publish transition | edit only |

Sensitive Slack/source meta (`RESTRICTED_META` in `Post_Type`) is stripped from REST responses outside the `edit` context. On entry post meta, WordPress does not gate reads by `auth_callback` — writes are gated by the post's `edit_post` capability; the strip filter is what protects those keys on read.

### Breakout post meta

| Meta key | Description | REST |
| :--- | :--- | :--- |
| `rolling_coverage_source_entry_id` | Reverse link to the entry the post broke out of | edit only, read-only (as the REST field `rolling_coverage_source_entry`, not as meta; 0 unless the entry is publicly viewable) |
| `rolling_coverage_breakout_label` | The post's own Full story label; empty uses the site's | edit only |

### User meta

- `rolling_coverage_slack_handle` — the user's Slack member ID or handle, matched against Slack message authors. See [Slack authors](../src/admin/DEVELOPMENT.md#slack-authors).

### Layout pattern meta

- `_rolling_coverage_layout` — on each built-in layout's `wp_block` pattern, the layout's slug. When the `rolling_coverage_{slug}_layout_id` option is missing, the plugin finds the pattern by this key and repairs the option, so deleting it breaks that fallback.
- `_rolling_coverage_layout_hash` — on each built-in layout's pattern, the MD5 hash of the content the pattern last got from the plugin: when it was created, or when a save stored the plugin's layout unchanged. While the content still matches, the layout renders as the plugin ships it ([Layouts](../src/blocks/rolling-coverage/DEVELOPMENT.md#layouts)). A pattern from before the key existed gets it on its first update, if it was still unedited.

## REST API

Namespace: **`rolling-coverage/v1`** (constant `NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE`).

### Coverages and entries

| Method | Route | Permission | Purpose |
| :--- | :--- | :--- | :--- |
| GET | `/coverages/{term_id}/entries-view` | `edit_posts` | Admin entries DataViews; page mode, or sync mode when `since` is passed. |
| GET | `/coverages/{term_id}/entries` | public | Frontend poll/load-more feed. |
| GET | `/coverages/{term_id}/entries-preview` | `edit_posts` | Entry IDs for editor previews. |
| POST | `/coverages/{coverage_id}/entries` | `create_posts` on the entry type | Start a new entry in the coverage, for Quick Edit. |
| POST | `/coverages/{coverage_id}/generate-key-takeaways` | `edit_posts` | AI summary generation. |
| POST | `/coverages/{coverage_id}/trash` | `manage_categories` | Soft-delete a coverage. |
| POST | `/coverages/{coverage_id}/restore` | `manage_categories` | Restore a trashed coverage to the status it had. |
| DELETE | `/coverages/{coverage_id}` | `manage_categories` | Permanently delete a coverage (schedules orphan cleanup). |
| POST | `/entries/{entry_id}/pin` | `edit_others_posts` | Toggle pin. |
| POST | `/entries/{entry_id}/restore` | `edit_post` | Restore one trashed entry. |
| POST | `/entries/restore` | `edit_posts` | Bulk restore. |
| POST | `/entries/details` | `edit_posts`, plus `edit_others_posts` to change the author and `assign_terms` for categories or tags | Reassign up to 100 entries: author, categories, tags, slug, and date. |
| POST | `/entries/{entry_id}/breakout` | `edit_others_posts` | Create breakout post. |
| POST | `/entries/{entry_id}/archive` | `edit_others_posts` | Archive/unarchive an entry. |
| GET/POST | `/ai/settings` | `manage_options` | Read/update the key-takeaways prompt. |
| GET/POST | `/settings/status-labels` | `edit_others_posts` | Read/update the coverage status labels. |
| GET/POST | `/settings/entry-name` | `edit_others_posts` | Read/update what readers see entries called (singular and plural). |
| GET/POST | `/settings/latest-label` | `edit_others_posts` | Read/update the Jump to Latest button label. |
| GET/POST | `/settings/breakout-label` | `edit_others_posts` | Read/update the site's Full story label for breakout cards. |
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

Core WordPress routes are also used: `/wp/v2/rolling-coverage` (coverages) and `/wp/v2/rolling-coverage-entries` (entries, including trash/force delete). Creating a coverage through the coverages route needs `manage_categories` (`Coverage_REST_Controller`), while assigning an entry to one needs only `edit_posts`. Outside edit context, the entries route leaves out entries whose coverage is trashed or deleted, on top of the request's own `rolling-coverage`, `categories` and `tags` filters (`Post_Type::filter_rest_query()`), and answers a request for one of them with a 404 (`Post_Type::hide_entry_rest_record()`). The plugin adds these read-only fields to their responses:

| Route | Field | Context | Value |
| :--- | :--- | :--- | :--- |
| Entries | `pinned` | view, edit | Whether the entry is pinned. |
| Entries | `coverageStatus` | view, edit | The status of the entry's coverage. |
| Entries | `rolling_coverage_breakout_status` | edit | The linked breakout post's status. |
| Entries | `rolling_coverage_has_notifiable_coverage` | edit | Whether the entry's coverage has the canonical URL push notifications need. |
| Coverages | `pageUrl` | view, edit | The coverage's page; empty without `edit_posts`. |
| Coverages | `placements` | view, edit | Published places that show the coverage, the main page first. |
| Coverages | `newestEntry` | view, edit | When the newest published entry went out (ISO 8601); null without `edit_posts`. |
| Posts | `rolling_coverage_source_entry` | edit | The entry the post broke out of, or 0. |

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
| `newspack_rolling_coverage_shared_polling` | `(bool $enabled)` | Whether pages poll the URL every reader shares (default `true`). Returning false moves open pages to their own cursor on their next poll. |
| `newspack_rolling_coverage_defer_notification` | `(bool $defer, WP_Post $post)` | Whether an entry's push notification is scheduled instead of sent during the request that published it. |
| `onesignal_send_notification` | `(array $fields, int $post_id)` | Consumed to scope push notifications. |
| `newspack_updated_date_override` | `(bool\|null $override, WP_Post $post)` | Consumed to show the updated date in a live post's byline. |
| `newspack_blocks_display_updated_date` | `(bool $display, WP_Post $post)` | Consumed to show a live post's updated date on its Homepage Posts card. |
| `newspack_ads_gam_bounds_selectors` | `(array $selectors, $ad_unit, $sizes)` | GAM bounds selectors for in-feed ad slots. |
| `newspack_ads_gam_bounds_bleed` | `(int $bleed, $ad_unit, $sizes)` | GAM bounds bleed (default 40). |

### Constants

Each sets the default its filter starts from, so a filter callback still wins.

| Constant | Filter |
| :--- | :--- |
| `NEWSPACK_ROLLING_COVERAGE_MIN_POLL_INTERVAL` | `newspack_rolling_coverage_min_poll_interval` |
| `NEWSPACK_ROLLING_COVERAGE_SHARED_POLLING` | `newspack_rolling_coverage_shared_polling` |

### Options, meta, and caches

- `rolling_coverage_pinned_entries` (autoloaded array of pinned post IDs).
- `rolling_coverage_ai_settings` (AI prompt settings).
- `rolling_coverage_slack_bot_token`, `rolling_coverage_slack_signing_secret`, `rolling_coverage_slack_settings`, `rolling_coverage_slack_channel_map`, `rolling_coverage_slack_bot_user_id` (all non-autoloaded).
- `rolling_coverage_slack_monitor_last_seen`, `rolling_coverage_slack_monitor_filename`.
- `rolling_coverage_placements` (stored map of the published places that show each coverage), `rolling_coverage_placements_stale`, `rolling_coverage_placements_lock` (rebuild lock, 300s TTL).
- `rolling_coverage_entry_name`, `rolling_coverage_latest_label`, `rolling_coverage_breakout_label` (reader-facing label settings).
- `rolling_coverage_status_labels` (custom coverage status labels).
- `rolling_coverage_{slug}_layout_id` (pattern ID of each built-in shared layout), `rolling_coverage_{slug}_layout_lock` (creation lock, 30s timeout), `rolling_coverage_deleted_layout_{id}` (built-in layout slug of a deleted built-in layout pattern, one per pattern ID; not autoloaded).
- `rolling_coverage_notification_lock_{post_id}` (push-notification send lock, 60s TTL).
- `rolling_coverage_source_ingest_{md5}` (short-lived ingestion mutex, 60s TTL).
- `rc_tpl_{coverage_id}_{hash}` (hashed block template/config for polling renders).
- Transient `rolling_coverage_slack_user_{id}` (Slack user cache, 5 min).
- Schema cache: object cache key `nrc_{coverage_id}_{hash}` in the `newspack_rolling_coverage_schema` group (1 week).
- Object cache groups `newspack_rolling_coverage_breakout_card` (breakout posts' generated summaries), `newspack_rolling_coverage_url_post_id` (the post IDs embed URLs on this site resolve to), and `newspack-rolling-coverage-breakouts` (each coverage's latest published breakout post, for the ended notice's link).

Useful query vars for developers on `WP_Query`:

- `rolling_coverage_author_scope` (`all`\|`author`\|`own`\|`editable`) — scope entry results to the current user. The plugin's own entry queries use it to limit what non-editors see: Authors see their own entries plus everyone's published and scheduled ones, and Contributors see only their own, mirroring WordPress meta capabilities.
- `rolling_coverage_skip_pin_order` (bool) — opt out of pinned-first ordering.

### Cron events

- `rolling_coverage_cleanup_orphaned_entries` — permanently deletes entries orphaned by coverage deletion, in batches of 50, rescheduling while entries remain. Scheduled on coverage deletion and cleared on deactivation.
- `newspack_rolling_coverage_send_notification` — single event that sends an entry's deferred push notification.
- `newspack_rolling_coverage_rebuild_placements` — single event that rebuilds the stored placements map after a change.

### Capabilities

| Capability | Grants |
| :--- | :--- |
| `edit_posts` | Access All Coverages, view/author entries, generate takeaways, view trashed entries. |
| `publish_posts` | Publish own entries. |
| `edit_others_posts` / Editor+ | View and manage all entries, pin, archive, breakout, change entry authors, and edit status labels and reader-facing labels. |
| `manage_categories` | Create/edit/trash/restore/delete coverages. |
| `manage_options` | Slack connection and monitor, the AI page and its prompt settings, and admin-UI-only Slack term meta writes. |
