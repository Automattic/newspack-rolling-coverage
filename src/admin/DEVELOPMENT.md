# Admin screens: development notes

The plugin's admin screens live in `src/admin/`: the coverages list, each coverage's entries list, Quick Edit, the Settings modal, and the Slack and AI pages. The entries list is a DataViews table; its fields are defined in `src/admin/fields/entries.tsx`. What the plugin adds to the entry editor lives in `src/entry-editor/`.

## Screens and routes

`Admin` (`includes/class-admin.php`) adds the Rolling Coverage menu with three pages: All Coverages (`edit_posts`), Slack Connection (`manage_options`) and AI (`edit_others_posts`). Each page renders the same root element and loads the `admin` bundle, a React app with a hash router (`src/admin/app.tsx`). The page sets the route the app starts on.

| Route | Screen |
| --- | --- |
| `#/coverages` | All Coverages (`coverage-view.tsx`) |
| `#/coverages/<id>` | A coverage's entries (`entry-view.tsx`) |
| `#/connection/<tab>` | Slack Connection (`slack-settings-page.tsx`), with its tabs in `src/admin/utils/slack-tabs.ts` |
| `#/ai` | AI prompt settings (`ai-page.tsx`) |

The app reads its config from `window.newspackRollingCoverageAdmin` (`Admin::get_script_data()`): REST URLs, labels, editor settings and a `capabilities` object. The capabilities only decide what the app shows; every route checks permissions again.

| Capability | Check | Gates |
| --- | --- | --- |
| `canEditPosts` | `edit_posts` | Add Entry |
| `canEditEntries` | `edit_others_posts` | Most entry actions, on any entry |
| `canChangeAuthors` | `Post_Type::can_change_authors()` | Change Author |
| `canManageTerms` | `manage_categories` | Add Coverage, and editing and trashing coverages |
| `canManageOptions` | `manage_options` | Slack connections |
| `canManageAiSettings` | `edit_others_posts` | Editing the AI prompts |
| `canManageSettings` | `edit_others_posts` | The Settings modal |

The admin pages load the block editor's assets so Quick Edit can run a block editor. `Newspack\Blocks::enqueue_block_editor_assets` is unhooked while they load, since newspack-plugin's editor UI crashes inside Quick Edit's `EditorProvider`.

## All Coverages

The header holds Settings and Add Coverage. Add Coverage and the Edit action open `CoverageDrawer` (`coverage-drawer.tsx`), a DataForm with Name, Description, Status, Canonical URL and Advertising. Status options are named after the site's status labels.

Row actions live in `src/admin/actions/coverage-actions.ts`:

| Action | Shown | Does |
| --- | --- | --- |
| Edit | `canManageTerms`, coverage not trashed | Opens the coverage drawer. |
| Entries | Always | Opens the coverage's entries. |
| View Page, View Pages | The coverage has placements | See [Placements](#placements-view-page-and-view-pages). |
| Slack Connection | `canManageOptions`, once Slack is configured | Opens `SlackConnectionDrawer`. |
| Trash | `canManageTerms`, coverage not trashed | Confirms, then `POST rolling-coverage/v1/coverages/<id>/trash`. The coverage's status becomes `trash`, and its entries are hidden on the site until it is restored. |
| Restore | `canManageTerms`, coverage trashed | `POST rolling-coverage/v1/coverages/<id>/restore`. |
| Delete Permanently | `canManageTerms`, coverage trashed | Confirms, then `DELETE rolling-coverage/v1/coverages/<id>`. Its entries are deleted too, apart from any already in the trash. |

The routes live in `Taxonomy` (`includes/class-taxonomy.php`).

## Settings

`SettingsModal` (`settings-modal.tsx`) opens from the All Coverages header for users with `edit_others_posts`. It has three tabs, each saving one option through its own route:

| Tab | Fields | Route |
| --- | --- | --- |
| Entry Name | Singular, Plural | `rolling-coverage/v1/settings/entry-name` |
| Coverage Status | Live label, Paused label, Ended label | `rolling-coverage/v1/settings/status-labels` |
| Jump to Latest | Button label | `rolling-coverage/v1/settings/latest-label` |

The modal loads all three when it opens and saves only the ones that changed. It refuses an entry name with one word and not the other before sending anything. A failed save switches to that tab and shows the error. Closing with unsaved changes asks to discard them.

The options, their limits and what reads them are documented with the blocks: the entry name and the Jump to Latest label in `src/blocks/rolling-coverage/DEVELOPMENT.md`, the status labels in `src/blocks/coverage-status/DEVELOPMENT.md`.

## A coverage's entries

`EntryView` (`entry-view.tsx`) lists one coverage's entries. Its header holds the Slack channel button ("Connect Slack", or the connected channel's name), View Page or View Pages, and Add Entry. Add Entry creates a draft entry in the coverage through the core entries route and opens it in the block editor. On an ended coverage, Add Entry stays disabled, with a tooltip saying which status allows new entries; on a trashed coverage it is hidden.

### Data and live sync

The list reads `GET rolling-coverage/v1/coverages/<id>/entries-view` (`Post_Type::get_entries_view()`, `edit_posts`), which pages, sorts, searches and filters on the server. Below Editor, users who can publish (Authors) see their own entries and everyone's published and scheduled ones, and others (Contributors) see only their own (`Post_Type::entry_visibility_scope()`, applied by `author_scope_where()`). Each row carries the current user's `can_edit` (`edit_post`), `can_publish` (`publish_post`) and `is_own`, so the list can offer exactly what WordPress allows. The list opens without trashed entries (Status is not Trashed).

`useEntries` (`src/admin/hooks/useEntries.ts`) then polls the same route with a `since` cursor every 10 seconds (`SYNC_INTERVAL_MS`), and pauses while the tab is hidden. It merges the changed rows and shows a snackbar for each change, or one "N updates in the last 10s" snackbar for more than five. When too many entries changed for one response (`overflow`), it reloads the page.

The empty state replaces the table and its filters, so it shows only when the coverage has no entries at all, trashed ones included: the Trashed filter is the only way back to them.

### Row actions

Row actions live in `src/admin/actions/entry-actions.ts`. Editors and above (`canEditEntries`) can act on any entry. Below that, the row's own capabilities decide: Authors act on their own entries, Contributors on their own drafts.

An entry is locked when it is archived or its coverage has ended (`isEntryLocked()` in `entries-api.ts`). A locked entry offers no status, author, pin or breakout actions.

| Action | Bulk | Shown | Does |
| --- | --- | --- | --- |
| Quick Edit | No | The user can edit the entry | Opens [Quick Edit](#quick-edit). |
| Edit | No | The user can edit the entry | Opens the entry in the block editor in a new tab. |
| Publish | Yes | Draft or pending, not locked, the user can publish it | Confirms, then publishes. See below. |
| Move to Draft | Yes | Published, pending or private, not locked, the user can edit it | Moves it to draft at once. See below. |
| Create Breakout Post | No | Editors, not locked, no breakout post yet | `POST rolling-coverage/v1/entries/<id>/breakout` (`Breakout`). |
| Restore Breakout Post, Permanently Delete Breakout Post | No | Editors, not locked, the breakout post is in the trash | Through the core posts route. |
| Change Author | Yes | `canChangeAuthors`, not trashed, not locked | Opens the [Change Author](#change-author) drawer. |
| Archive, Unarchive | Yes | Editors; Archive on a published entry; the coverage hasn't ended | `POST rolling-coverage/v1/entries/<id>/archive` (`Archive_Mode`). |
| Pin, Unpin | No | Editors, not locked | `POST rolling-coverage/v1/entries/<id>/pin`. |
| Trash | Yes | Not trashed, not locked; Editors, or the author of an entry they can publish | Confirms, then `DELETE` on the core entries route. |
| Restore | Yes | Trashed, the user can edit it | `POST rolling-coverage/v1/entries/restore`, in one request for all of them. An entry whose coverage no longer exists comes back in a recovery coverage, shared by entries from the same coverage. |
| Delete Permanently | Yes | Editors, trashed | Confirms, then `DELETE` on the core entries route with `force`. |

Quick Edit and Edit ask for confirmation first on an archived entry, or one whose coverage is paused or ended, naming the coverage's status as the site labels it.

Confirmations go through `useConfirmDialog()` (`confirm-dialog.tsx`), an `AlertDialog` from `@wordpress/ui`. When every item of a bulk action fails, `onConfirm` returns `{ error }` and the dialog stays open with the error. When only some fail, the dialog closes, the list refreshes and the first error shows as a snackbar, so a retry doesn't resend the items that went through.

### Publish and Move to Draft

`setEntryStatus()` (`entries-api.ts`) saves `status` through the core entries route, one request per entry, rather than a route of its own. WordPress then checks the user's publish and edit capabilities, and the status hooks run as they do for a save in the editor: open feeds pick up the change on their next poll, and an entry opted in to notify followers sends its push notification on the next cron run, as REST publishes do (see "How notifications are sent" in `src/blocks/coverage-follow/DEVELOPMENT.md`).

- **Publish** confirms first, since readers can't unsee an entry published by mistake. When OneSignal is configured, the confirmation says that entries set to notify followers send a push notification: the list doesn't show which entries are, and Slack entries opt in on their own.
- **Move to Draft** runs at once, since publishing again undoes it.
- **Scheduled entries** get neither action. Publishing one now would also need its date moved to now, so they are left to the editor.

Each saved entry is written to core-data's cache (`receiveEntityRecords()`), which the request bypasses, so Quick Edit opens it with its new status.

### Change Author

`ChangeAuthorDrawer` (`change-author-drawer.tsx`) is a Newspack `Drawer` that gives every selected entry one new author. Its Author combobox lists users who can be authors (`who: 'authors'`, 100 at a time, searched by name as the user types) and starts on the entries' author when they all share one. Save is enabled once a different user is picked.

It sends `POST rolling-coverage/v1/entries/author` with `entry_ids` (1 to 100) and `author_id`. `Post_Type::handle_bulk_change_author()`:

- Needs `Post_Type::can_change_authors()`: `edit_others_posts`, and, when Co-Authors Plus is on for entries, its `current_user_can_set_authors()`.
- Refuses an author who can't `edit_posts`.
- Skips, with an error, each entry that is missing, trashed, not editable by the user, or locked by Archive Mode.
- With Co-Authors Plus on for entries, makes the user the entry's only co-author first (`set_coauthor()`), since Co-Authors Plus reads `post_author` back from its author terms on every save.
- Saves `post_author` through `Post_Type::touch_entry()`, which also bumps the modified date so open feeds re-render the entry, and keeps the content as stored.

It answers 200 with `{ results: [ { entryId, updated, error } ] }`, whether or not some entries failed. The drawer shows one snackbar for the entries that changed and another for those that didn't, invalidates core-data's cached records of the changed entries so Quick Edit shows the new author, and refreshes the list.

Tests: `tests/test-entry-author.php`. The entries-view route is covered by `tests/test-entries-view.php`, restoring entries by `tests/test-entry-restore.php`, and Archive Mode's locks by `tests/test-archive-mode.php`.

## Quick Edit

`QuickEditModal` (`quick-edit-modal.tsx`) edits one entry in a full-screen modal without leaving the list. It holds a block editor (`EditorProvider` with `BlockCanvas`, the post title and the block list) and the block inspector in a sidebar, which the header's Settings button toggles. There are no document settings, so status, date, author and coverage are changed elsewhere. The header's Cancel and Save (`quick-edit-save-bar.tsx`) close the modal and call the editor's `savePost()`.

- **Unsaved edits.** Closing with unsaved edits asks to discard them. Escape, a click outside and the modal's close button are all off, so every close goes through that check. Edits are read from core-data (`useEntityRecord().hasEdits`), since the editor store lives in the provider's sub-registry, out of reach of selectors outside it.
- **Nesting.** `EditorProvider` stays inside the modal, so the editor's own modals (keyboard shortcuts, pattern rename and duplicate, the media editor) nest in it rather than closing it. Cancel and Save sit outside the provider, so `EditorRegistryBridge` hands them its sub-registry.
- **Setup.** The admin page isn't a post editor screen, so core blocks are registered on first open (`ensureEditorInitialized()`), and the editor settings come from `get_block_editor_settings()` in the script data, with template mode off.

## Entry sources

Every entry records where it came from in the `rolling_coverage_entry_source` meta (`Post_Type::META_ENTRY_SOURCE`): `slack` for entries ingested from Slack, `wordpress` for entries written in the editor. Entries with no meta count as `wordpress`. `getEntrySource()` (`src/admin/utils/fields.ts`) applies that default, so any slug it doesn't recognize also reads as `wordpress`.

The entries list has no Source column. The source shows as a marker before the title, rendered by the `title` field:

- An unpinned entry shows its source's logo, a 10px glyph centered in the marker box (`.newspack-rolling-coverage-entry-title__source`). The box is 24px wide and one line of the title tall (`1lh`), so the marker centers on the title's first line whether the title wraps or not.
- A pinned entry shows only the pin, never a pin and a logo. The pin's color carries the source instead: the regular text color for WordPress, the source's brand color for anything else (`#e3066a` for Slack, from `.newspack-rolling-coverage-entry-title__pin--slack`).
- The marker's text ("From Slack", "Pinned, from WordPress") is hidden text for screen readers and the tooltip on hover, so color is never the only cue.

The `source` field stays defined, with no `render` and `enableHiding: false`. DataViews builds filters from every field, whether or not it's shown, so Source is offered under **Add filter** but never as a column.

## Adding a source

The taxonomy already anticipates other chat platforms (Beeper, WhatsApp, Telegram). A new source needs each of these:

1. A `SOURCE_*` constant in `src/admin/utils/fields.ts`, recognized by `getEntrySource()`.
2. An option in the `source` field's `elements`, so the filter offers it.
3. A logo for the unpinned marker. Draw it to fill its frame edge to edge, like `SlackIcon` (`src/admin/shared/icons/`), so it renders at 10px like the others. The WordPress icon from `@wordpress/icons` fills 20 of its 24 units, so it renders at `size={ 12 }`.
4. A pin color: a `.newspack-rolling-coverage-entry-title__pin--<source>` modifier in `src/admin/styles/components/_dataviews.scss` set to the source's brand color, applied in the `title` field's `render`. Without one, a pinned entry from the new source looks like a pinned WordPress entry.
5. Marker labels for both states ("From …" and "Pinned, from …").

Until a source has them, its entries show the WordPress marker, yet "Source is WordPress" leaves them out, because the filter matches the stored slug.

The server needs no change beyond the ingest path writing the slug to the meta. The entries endpoint's `source` and `source_exclude` filters accept any slug, with `wordpress` special-cased to include entries that have no meta.

## Placements: View Page and View Pages

A coverage's placements are every published place where the plugin's blocks show it. The coverage header and the All Coverages row actions use them to send editors to those places.

### What counts

`Placements` (`includes/class-placements.php`) looks for three blocks:

- **Rolling Coverage**, by its `coverageId`. A full feed is tagged "Full"; a capped one (`latestOnly`) takes the name of its layout's pattern (Flash, Ticker, Wire, Digest, or a custom layout's title), or "Latest" when it has a detached layout of its own.
- **Coverage Status** and **Follow Coverage**, tagged "Status" and "Follow". Custom (`coverageId > 0`) counts for the chosen coverage while it exists and isn't trashed; otherwise the block is Automatic, as it is on the site.

Blocks inside a Rolling Coverage block are part of its layout and show its coverage, so they add no tags. The Check for Updates block only lives there.

The places it looks in, all published and for the active theme:

| Place | Row | View | Edit |
| --- | --- | --- | --- |
| Posts and pages of any viewable post type, except entries and attachments. Password-protected ones are skipped. | The post's title | Its permalink | The block editor |
| Templates (`wp_template`), customized or from the theme's files, through `get_block_templates()`. Only when the theme uses block templates. | The template's title | Front Page: the home page. Blog Home: the posts page. Others: none. | The Site Editor |
| Template parts (`wp_template_part`), when the theme uses them | The part's title | None | The Site Editor |
| Synced patterns (`wp_block`) that published content uses, directly or through other patterns | The pattern's title | None | On block themes, the Site Editor for users who can edit the theme; otherwise the block editor |
| Widget areas with block widgets, for registered areas only | The area's name | None | The Widgets screen |

Edit links only show for users who can edit that place.

### Automatic Status and Follow Coverage blocks

An Automatic block shows the coverage of the page being viewed (see `src/blocks/coverage-status/DEVELOPMENT.md`), so where it counts depends on where it sits:

- **In a post's own content**, or in a synced pattern the post uses, it shows the post's first uncapped feed, or a breakout post's coverage. Its tag joins that post's row, so a page with a feed and an Automatic Status block is one row tagged "Full" and "Status".
- **In a template, template part or widget area**, it shows the coverage on single posts. The rows only cover breakout posts: the single post template (the first of `single-post`, `single`, `singular` and `index` the theme has), the template parts it holds, and every widget area with an Automatic block are each listed once, as "…, on this coverage's breakout posts", for coverages with at least one published breakout post. View opens the newest one. Each breakout post isn't listed on its own. The check looks at the `MAX_BREAKOUT_POSTS` (500) newest entries that have a published breakout post, so a coverage whose only breakout posts are older than that gets no breakout rows.
- **In a synced pattern's row**, it doesn't count, since it shows whichever page uses the pattern.

An Automatic block in other templates, such as the page template, isn't listed.

### Order and the main page

Posts come first, newest first, then templates, template parts, widget areas and synced patterns, then the breakout rows. When the coverage has a canonical URL, the row whose View link has the same path (compared decoded and in lowercase) and query is marked as the main page and moves to the top. A canonical URL that matches no place gets a row of its own at the top, with only a View link.

### The stored map

Scanning every post on each request would be too slow, so the map is built once and kept in the `rolling_coverage_placements` option: the places of each coverage, the template and widget rows for breakout posts, every synced pattern that leads to one of the blocks (directly or by wrapping another pattern), the theme it was built for, and the newest post with an uncapped feed for each coverage (`page_id()`, which `Taxonomy::get_coverage_page_url()` uses when there is no canonical URL). Titles, links and labels are filled in when the REST field is read, so renaming a post or layout, or a permalink changing, needs no rebuild. Whether a coverage has a published breakout post is checked then too, with one query for all coverages.

A build lists the IDs of every matching published post, newest first, and reads their content `SCAN_BATCH` (100) at a time straight from the database, so memory stays bounded and the object cache isn't filled.

#### When it is rebuilt

A change never rebuilds the map in the request that made it. `Placements::flush()` writes a new token to the `rolling_coverage_placements_stale` option, straight to the database with an upsert so a long request whose cached copy is out of date still marks it. As the request ends, `schedule_rebuild()` reloads the scheduled events (dropping the request's cached copy, which a long request may hold after the event already ran) and, unless a `newspack_rolling_coverage_rebuild_placements` event is already due, schedules one through core's cron functions, so cron replacements such as Cron Control keep working. A change made from a `shutdown` callback, or while switched to another site, schedules at once. WP-Cron runs the event on a following request.

Until it runs:

- **The front end** (`page_id()`, and so `get_coverage_page_url()`) keeps reading the stored map. It never builds one: when there is none, it has the request schedule a build (once per request, whatever the out-of-date mark says, in case an earlier build died before storing a map) and returns no page until the event runs.
- **Admin reads** of the `placements` field call `ensure_fresh()`, which rebuilds at once when the map is marked out of date, missing, or was built for another theme or theme version, so an editor who has just saved a page sees it in the list. It checks once per request until the next change. The `pageUrl` field doesn't, so the block editor never waits on a rebuild.

A rebuild holds a lock: the `rolling_coverage_placements_lock` row, inserted only if absent and holding when it expires (`LOCK_TTL`, five minutes), after which another rebuild takes it over. A build that outlives its lock checks it still holds it before storing anything, and releases only its own lock. While it is held, an admin read uses the stored map and the event tries again after `LOCK_TTL`. Inside the lock, the rebuild reads the token, builds, stores the map, and deletes the token only if it is still the one it read, so a change saved while it ran keeps the map marked for the next read or event.

#### What marks it out of date

- **Posts and patterns:** a save that changes what a published post or synced pattern contributes: the coverages and tags its blocks show, its Automatic blocks, its uncapped feeds, the patterns and template parts it uses, its post type, password or date (which orders the rows and picks `page_id()`). A new title, or an edit to the text around a capped feed, keeps the map. Publishing, unpublishing or permanently deleting a post that holds one of the blocks, or uses a pattern in the stored set, marks it too.
- **Templates and template parts:** any save, status change or deletion, whatever they hold. They are saved rarely, and which of them a breakout post renders with depends on their slugs and the parts they hold; deleting a customization hands the slug back to the theme's file.
- **Widgets:** any change to `widget_block` or `sidebars_widgets`.
- **Themes and plugins:** `after_switch_theme`, and `upgrader_process_complete` for theme and plugin updates (not translations).
- **Coverages:** a coverage moving into or out of the trash, or being deleted, since a Custom block whose coverage is trashed falls back to Automatic. Pausing, ending or resuming one doesn't.
- **Breakout entries:** an entry with a breakout post moving to another coverage, since an Automatic block in the breakout post's own content follows it.

On multisite, a change made while switched to another site marks that site's map and schedules its rebuild at once, in that site's own cron, so the scan runs on that site with its own post types, widget areas and patterns.

Activating the plugin schedules the first build; deactivating it deletes the map, the token, the lock and any pending event.

Not tracked: theme files edited without an update, and a breakout post's entry being unpublished. Both are picked up by the next rebuild.

### REST field

`placements` on the coverage term, for users who can `edit_posts` (others get an empty list), in both the `view` and `edit` contexts. It is only worked out when the request names it in `_fields`; otherwise it is an empty list, so the block editor's coverage lookups stay cheap. The coverages list (`useCoverages.ts`) and the single coverage fetch (`getCoverage()` in `src/admin/utils/coverage-api.ts`) both name it. Each row has `id`, `title`, `type` (what the place is, such as "Page" or "Template part"), `tags`, `viewUrl`, `editUrl`, `isMain` and `breakout`.

The older `pageUrl` field stays: the canonical URL, or else the newest post with an uncapped feed, read from the stored map as it is.

### The button

`getPlacementsLink()` (`src/admin/utils/placements.ts`) decides what the header button and the row action do:

- **No placements:** no button and no row action.
- **One placement with a View link:** "View Page", a link that opens it in a new tab. A breakout row is the exception: its View link is only the newest of many breakout posts, so it opens the drawer.
- **Anything else:** "View Pages", which opens `PlacementsDrawer` (`src/admin/components/placements-drawer.tsx`), a Newspack `Drawer` listing every row with its tags and View and Edit links. A single place without a page of its own, such as a template part, also opens the drawer, so its Edit link is reachable.

The header button lives in `entry-view.tsx`; the row actions (`view-page`, `view-pages`) in `src/admin/actions/coverage-actions.ts`.

Tests: `tests/test-placements.php`, plus the page lookup and its rebuild in `tests/test-taxonomy.php`.

## Leaving the entry editor

Entries are managed in the coverages screen, not in core's entries list, which stays registered but hidden (`show_in_menu` is false). Two exits from the editor point at that list, so both are redirected:

- The back button. Core hardcodes its link to `edit.php?post_type=rolling_cov_entry` and shows it only when nothing else fills the slot. `src/entry-editor/back-to-coverage.tsx` fills it with `__experimentalMainDashboardButton` from `@wordpress/edit-post`, the one API for replacing it. It links to `#/coverages/<id>` for the entry's first coverage as currently edited (the first ID in the taxonomy's REST attribute), or `#/coverages` when it has none. Its label, read by screen readers and shown as a tooltip, is "Back to Coverage", or "Back to All Coverages" when the entry has no coverage. It renders only where core's own button does: fullscreen mode at a medium viewport or wider. `coveragesUrl` already ends in `#/coverages`, so the entry's route is that URL plus `/<id>`; `Admin::get_coverages_url()` builds the same URLs server side, so change both together. The two editor registrations (back button, Push Notifications panel) are separate plugins so one failing can't unmount the other.
- The redirect after trashing. Core sends the editor to `edit.php?trashed=1&post_type=…&ids=<id>`. `Admin::redirect_entry_list()` (on `load-edit.php`) sends any plain GET for the entries list to the screen above, using `ids` to find the trashed entry's coverage. Requests carrying an `action` are left alone. After a trash it adds `rolling_coverage_trashed=1` to the page URL (not `trashed`, which core strips from admin URLs before the script runs); the admin app (`announceTrashedEntry()`) shows the "Entry trashed." snackbar for it and removes the arg with `history.replaceState`. There is no Undo: restoring would need the already-loaded list to refresh.

Tests: `tests/test-entry-editor-navigation.php`.

`Admin::enqueue_entry_editor()` loads the `entry-editor` bundle on the entry editor and passes it `window.newspackRollingCoverageEntryEditor` (the coverages URL, the taxonomy's REST base, and whether the Push Notifications panel applies).

The bundle's other registration, the Push Notifications panel, is described in `src/blocks/coverage-follow/DEVELOPMENT.md`.

The entry post type registers core's `item_*` labels (`Post_Type::register()`), so the editor's notices name an entry, as in "Entry published." and "Entry updated.", rather than a post.
