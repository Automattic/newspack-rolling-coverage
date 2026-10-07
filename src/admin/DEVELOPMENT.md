# Admin screens: development notes

The plugin's admin screens live in `src/admin/`: the coverages list, each coverage's entries list, Quick Edit, the Settings modal, and the Slack and AI pages. The entries list is a DataViews table; its fields are defined in `src/admin/fields/entries.tsx`.

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

- The back button. Core hardcodes its link to `edit.php?post_type=rolling_cov_entry` and shows it only when nothing else fills the slot. `src/entry-editor/back-to-coverage.tsx` fills it with `__experimentalMainDashboardButton` from `@wordpress/edit-post`, the one API for replacing it. It links to `#/coverages/<id>` for the entry's first coverage as currently edited (the first ID in the taxonomy's REST attribute), or `#/coverages` when it has none. It renders only where core's own button does: fullscreen mode at a medium viewport or wider. `coveragesUrl` already ends in `#/coverages`, so the entry's route is that URL plus `/<id>`; `Admin::get_coverages_url()` builds the same URLs server side, so change both together. The two editor registrations (back button, Push Notifications panel) are separate plugins so one failing can't unmount the other.
- The redirect after trashing. Core sends the editor to `edit.php?trashed=1&post_type=…&ids=<id>`. `Admin::redirect_entry_list()` (on `load-edit.php`) sends any plain GET for the entries list to the screen above, using `ids` to find the trashed entry's coverage. Requests carrying an `action` are left alone. After a trash it adds `rolling_coverage_trashed=1` to the page URL (not `trashed`, which core strips from admin URLs before the script runs); the admin app (`announceTrashedEntry()`) shows the "Entry trashed." snackbar for it and removes the arg with `history.replaceState`. There is no Undo: restoring would need the already-loaded list to refresh.

`Admin::enqueue_entry_editor()` loads the `entry-editor` bundle on the entry editor and passes it `window.newspackRollingCoverageEntryEditor` (the coverages URL, the taxonomy's REST base, and whether the Push Notifications panel applies).

## Quick Edit

Quick Edit (`src/admin/components/quick-edit-modal.tsx`) opens an entry in the block editor inside a centered modal laid out like P2's comment editor: one toolbar row on top, the canvas, Cancel and Save at the bottom. Once the editor is ready (the entry has loaded and the editor's own setup requests have finished) the WordPress `Modal` header is hidden, so every control in it is ours. Until then the Modal keeps its own header, close button and a spinner, so an entry that never loads can still be closed and the frame is never blank.

- **The block toolbar is pinned to the toolbar row** (`quick-edit-block-toolbar.tsx`), the way the post editor's Top Toolbar mode pins it. `EditorProvider` ignores `hasFixedToolbar` in its settings and reads the `core.fixedToolbar` preference, so the modal wraps the editor in a child data registry with its own `core/preferences` store where that flag is `true`. The page's own preferences store is off limits: WordPress core attaches the user's persistence layer to it on every page that loads `wp-preferences`, so a write there would pin the toolbar in the user's real post editor at once. A second instance of core's store would leak too: every instance saves through one shared persistence layer, and each save writes that instance's whole state over the user's saved preferences. So the child registry's `core/preferences` is an in-memory stand-in (`utils/quick-edit-preferences.ts`) that never saves. The trade-off is that the user's saved post-editor preferences (hidden block types, icon labels, focus mode, caret behavior) do not apply inside Quick Edit, and editor controls that write preferences, such as the link control's Advanced drawer, write to this throwaway store instead.
- **The block inspector is a popover under the gear** (`quick-edit-inspector.tsx`), not a sidebar. The gear is disabled until a block is selected. The popover closes when the selection goes away or focus leaves it, except when focus lands on the gear or in another popover: the color and font-size controls inside the inspector open their pickers that way. The gear cancels its own `mousedown` so a click never moves focus; Safari would otherwise close and reopen the popover in one click.
- **The inserter is a popover under "+"** (`quick-edit-toolbar.tsx`) and inserts after the selected block, or at the end.
- **Everything renders inside `EditorProvider`.** The provider runs `core/editor` and `core/block-editor` together in one sub-registry beneath the child registry above; core-data and notices resolve to the page registry. That sub-registry copies the two editor stores' private selectors and actions from its direct parent only, so the child registry registers both stores as well; without them the editor throws on mount. That layering is why the unsaved-changes guard, `useEntityRecord().hasEdits`, can read from outside the provider while undo, redo and the block toolbar have to sit inside it.
- `EditorProvider` stays inside the `Modal` so the block editor's own modals (media editor, pattern rename and duplicate) nest in it instead of closing Quick Edit. A `Popover.Slot` inside the provider keeps every popover (block library, document overview, inspector and the pickers inside it, block toolbar dropdowns, the in-canvas quick inserter) within the modal frame and its focus trap, so the stylesheet has no rules for the body-level fallback container.
- Save results come through the notices store: `SnackbarNotices` from `@wordpress/notices` renders them inside the modal above the footer. `wp.editor.EditorSnackbars`, which did the same, is deprecated in WordPress 7.0 and removed in 7.2.
