# Admin screens: development notes

The plugin's admin screens live in `src/admin/`: the coverages list, each coverage's entries list, Quick Edit, the Settings modal, and the Slack and AI pages. The entries list is a DataViews table; its fields are defined in `src/admin/fields/entries.tsx`. What the plugin adds to the entry editor lives in `src/entry-editor/`.

## Screens and routes

`Admin` (`includes/class-admin.php`) adds the Rolling Coverage menu with three pages: All Coverages (`edit_posts`), Slack Connection (`manage_options`) and AI (`manage_options`), so only administrators see and change the AI prompts; the settings route checks the same. Each page renders the same root element and loads the `admin` bundle, a React app with a hash router (`src/admin/app.tsx`). The page sets the route the app starts on.

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
| `canChangeAuthors` | `Post_Type::can_change_authors()` | Reassign's Author field |
| `canAssignCategories`, `canAssignTags` | `Post_Type::can_assign_terms()` | Reassign's Categories and Tags fields |
| `canCreateCategories`, `canCreateTags` | `Post_Type::can_create_terms()` | Typing a new category or tag in Reassign |
| `canManageTerms` | `manage_categories` | Add Coverage, and editing and trashing coverages |
| `canManageOptions` | `manage_options` | Slack connections |
| `canManageSettings` | `edit_others_posts` | The Settings modal |

The admin pages load the block editor's assets so Quick Edit can run a block editor. `Newspack\Blocks::enqueue_block_editor_assets` is unhooked while they load, since newspack-plugin's editor UI crashes inside Quick Edit's `EditorProvider`.

## All Coverages

The header holds Settings and Add Coverage. Add Coverage and the Edit action open `CoverageDrawer` (`coverage-drawer.tsx`), a DataForm with Name, Description, Canonical URL, Status and Advertising. Status options are named after the site's status labels. In Edit, Save stays disabled until a field changes. A successful save closes the drawer with a snackbar, "Changes saved." for an edit or "Coverage added." for a new coverage; a failed one keeps it open with the error. The drawer saves through the core terms route and sends every field each time, so each meta key it sends must be writable by anyone who can edit a coverage. A stricter `auth_callback` on one of them fails those users' saves after the other fields are already written.

Row actions live in `src/admin/actions/coverage-actions.ts`:

| Action | Shown | Does |
| --- | --- | --- |
| Edit | `canManageTerms`, coverage not trashed | Opens the coverage drawer. |
| Entries | Always | Opens the coverage's entries. |
| Placements | The coverage has placements | See [Placements](#placements). |
| Slack Connection | `canManageOptions`, once Slack is configured | Opens `SlackConnectionDrawer`. |
| Trash | `canManageTerms`, coverage not trashed | Confirms, then `POST rolling-coverage/v1/coverages/<id>/trash`. The coverage's status becomes `trash`, and its entries are hidden on the site until it is restored. |
| Restore | `canManageTerms`, coverage trashed | `POST rolling-coverage/v1/coverages/<id>/restore`. |
| Delete Permanently | `canManageTerms`, coverage trashed | Confirms, then `DELETE rolling-coverage/v1/coverages/<id>`. Its entries are deleted too, apart from any already in the trash, by a cleanup cron event a minute later, in batches of 50. |

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

`EntryView` (`entry-view.tsx`) lists one coverage's entries. Its header holds the Slack channel button ("Connect Slack", or the connected channel's name), Placements, and Add Entry. Add Entry opens [Quick Edit](#quick-edit) on a new entry in the coverage, without leaving the list. On an ended coverage, Add Entry stays disabled, with a tooltip saying which status allows new entries; on a trashed coverage it is hidden.

### Data and live sync

The list reads `GET rolling-coverage/v1/coverages/<id>/entries-view` (`Post_Type::get_entries_view()`, `edit_posts`), which pages, sorts, searches and filters on the server. Below Editor, users who can publish (Authors) see their own entries and everyone's published and scheduled ones, and others (Contributors) see only their own (`Post_Type::entry_visibility_scope()`, applied by `author_scope_where()`). Each row carries the current user's `can_edit` (`edit_post`), `can_publish` (`publish_post`) and `is_own`, so the list can offer exactly what WordPress allows. The list opens without trashed entries (Status is not Trashed).

`useEntries` (`src/admin/hooks/useEntries.ts`) then polls the same route with a `since` cursor every 10 seconds (`SYNC_INTERVAL_MS`), and pauses while the tab is hidden. It merges the changed rows and shows a snackbar for each change, or one "N updates in the last 10s" snackbar for more than five. When too many entries changed for one response (`overflow`), it reloads the page.

The empty state replaces the table and its filters, so it shows only when the coverage has no entries at all, trashed ones included: the Trashed filter is the only way back to them.

### Row actions

Row actions live in `src/admin/actions/entry-actions.ts`. Editors and above (`canEditEntries`) can act on any entry. Below that, the row's own capabilities decide: Authors act on their own entries, Contributors on their own drafts.

An entry is locked when it is archived or its coverage has ended (`isEntryLocked()` in `entries-api.ts`). A locked entry offers no status, Reassign, pin or breakout actions.

| Action | Bulk | Shown | Does |
| --- | --- | --- | --- |
| Quick Edit | No | The user can edit the entry | Opens [Quick Edit](#quick-edit). |
| Edit | No | The user can edit the entry | Opens the entry in the block editor in a new tab. |
| Publish | Yes | Draft or pending, not locked, the user can publish it | Confirms, then publishes. See below. |
| Move to Draft | Yes | Published, pending or private, not locked, the user can edit it | Moves it to draft at once. See below. |
| Create Breakout Post | No | Editors, not locked, no breakout post yet | `POST rolling-coverage/v1/entries/<id>/breakout` (`Breakout`). |
| Restore Breakout Post, Permanently Delete Breakout Post | No | Editors, not locked, the breakout post is in the trash | Through the core posts route. |
| Reassign | Yes | The user can edit the entry, not trashed, not locked | Opens the [Reassign](#reassign) drawer. |
| Archive, Unarchive | Yes | Editors; Archive on a published entry; the coverage hasn't ended | `POST rolling-coverage/v1/entries/<id>/archive` (`Archive_Mode`). |
| Pin, Unpin | No | Editors, not locked | `POST rolling-coverage/v1/entries/<id>/pin`. |
| Trash | Yes | Not trashed, not locked; Editors, or the author of an entry they can publish | Confirms, then `DELETE` on the core entries route. |
| Restore | Yes | Trashed, the user can edit it | `POST rolling-coverage/v1/entries/restore`, in one request for all of them. An entry whose coverage no longer exists comes back in a recovery coverage, shared by entries from the same coverage; creating or reusing it needs `manage_categories`, so without it the entry stays in the trash with an error. Archived entries, and entries locked because their coverage ended, can't be restored. |
| Delete Permanently | Yes | Editors, trashed | Confirms, then `DELETE` on the core entries route with `force`. |

Quick Edit and Edit ask for confirmation first on an archived entry, or one whose coverage is paused or ended, naming the coverage's status as the site labels it.

Confirmations go through `useConfirmDialog()` (`confirm-dialog.tsx`), an `AlertDialog` from `@wordpress/ui`. When every item of a bulk action fails, `onConfirm` returns `{ error }` and the dialog stays open with the error. When only some fail, the dialog closes, the list refreshes and the first error shows as a snackbar, so a retry doesn't resend the items that went through.

### Publish and Move to Draft

`setEntryStatus()` (`entries-api.ts`) saves `status` through the core entries route, one request per entry, rather than a route of its own. WordPress then checks the user's publish and edit capabilities, and the status hooks run as they do for a save in the editor: open feeds pick up the change on their next poll, and an entry opted in to notify followers sends its push notification on the next cron run, as REST publishes do (see "How notifications are sent" in `src/blocks/coverage-follow/DEVELOPMENT.md`).

- **Publish** confirms first, since readers can't unsee an entry published by mistake. When OneSignal is configured, the confirmation says that entries set to notify followers send a push notification: the list doesn't show which entries are, and Slack entries opt in on their own.
- **Move to Draft** runs at once, since publishing again undoes it.
- **Scheduled entries** get neither action. Publishing one now would also need its date moved to now, so they are left to the editor.

Each saved entry is written to core-data's cache (`receiveEntityRecords()`), which the request bypasses, so Quick Edit opens it with its new status.

### Reassign

`EntryDetailsDrawer` (`entry-details-drawer.tsx`) is a Newspack `Drawer` that edits the author, categories and tags of the selected entries, and the slug and date of a single entry. Reassign is offered, as a row action and in the bulk-actions bar, on every entry the user can edit that isn't trashed or locked, like the other entry actions. Each field shows only to users allowed to change it, and the server checks every field again. When several entries are selected and the user may change none of Author, Categories and Tags (possible only with custom roles), the drawer says so instead of showing an empty form.

| Field | Shown | One entry | Several entries |
| --- | --- | --- | --- |
| Author | `canChangeAuthors` | Starts on the entry's author. | Starts on the author they share, or empty. |
| Categories | `canAssignCategories` | Starts with the entry's categories; the save sets exactly what the field shows. | Starts empty; what is picked is added to every entry and nothing is removed. |
| Tags | `canAssignTags` | As Categories. | As Categories. |
| Slug | One entry only | Starts on the entry's slug, decoded for display. | Not shown. |
| Date | One entry only | Starts on the entry's publish date, in the site's time zone. | Not shown. |

- **Author** is a combobox of users who can be authors (`who: 'authors'`, 100 at a time, searched by name as the user types). The person picked replaces the entry's author and, with Co-Authors Plus on for entries, all its co-authors.
- **Categories** and **Tags** are `TermTokenField`s (`term-token-field.tsx`), a `FormTokenField` suggesting the site's terms, most used first, and searching as the user types. Terms are told apart by ID. A category with a parent is labeled with the parent's name, such as "Local (Sport)", or with its whole path, such as "Local (News › Sport)", when that label is shared, so two categories with the same name under different parents stay distinct. A category whose label can't yet be told apart from another's (its ancestors haven't loaded, or even its path is shared) isn't suggested until it can be, and tokens already in the field always map back to the terms they show. A label that matches no term becomes a new term, created on save, when the user can create terms there (`canCreateCategories`, `canCreateTags`); otherwise only existing terms are accepted. Creating follows core's REST rule: categories need `edit_terms` (`manage_categories`), tags only `assign_terms` (`edit_posts`).
- **Slug** is a text field. The server sanitizes it, and WordPress makes it unique when the entry is published or scheduled. When the saved slug of a published or scheduled entry differs from the one typed, the success notice names it; for a draft it doesn't, since the slug isn't final yet.
- **Date** is a button showing the entry's date in the site's date and time format (`dateI18n()`). It opens core's `__experimentalPublishDateTimePicker` in a popover beside the drawer, as the post editor does for a post's date, with a 12-hour clock when the site's time format shows AM/PM. The row's date carries the site's offset, so the drawer formats it in the site's time zone (`getEntryDate()`). The picker works in the site's time zone and gives back the date with no offset, which is sent as it is; a value with an offset would be formatted in the site's time zone first (`toSiteDateTime()`). The picker's Now sets the current time in the site's time zone, following the post editor's Now. The help text says a published entry can't be dated in the future.

Save stays disabled until a field changes, and only changed fields are sent. Closing with unsaved changes asks first. The drawer stays open with an error when nothing was saved; otherwise it closes with one snackbar for the entries that changed and another for those that didn't, invalidates core-data's cached records of the changed entries so Quick Edit shows them as saved, invalidates every term suggestion query the field has run, searches included, when terms were created, and refreshes the list.

It sends `POST rolling-coverage/v1/entries/details` with `entry_ids` (1 to 100) and any of `author_id`, `categories` and `tags` (each `{ ids, names }`), `slug`, `date` (the site's local time, `YYYY-MM-DDTHH:mm:ss`) and `append`, which the drawer sends for several entries. `Post_Type::handle_bulk_edit_details()` works in three passes:

1. **The request.** The permission callback (`can_edit_details()`) needs `edit_posts`, plus `can_change_authors()` (`edit_others_posts`, and Co-Authors Plus's `current_user_can_set_authors()` when it is on for entries) when `author_id` is sent, and the taxonomy's `assign_terms` for each of `categories` and `tags` sent. The request is then refused, before anything is written, when it changes nothing, sets a slug or date on more than one entry, names an author who can't `edit_posts`, has a date that can't be read, sends a term ID from another taxonomy, includes a term the user can't `assign_term`, or names new terms the user can't create. Names are matched to terms by name, ignoring case, never by slug, as `wp_insert_term()` matches them (`find_term_by_name()`); the database's collation also ignores accents, so candidates are compared again and "Cafe" doesn't match "Café". In a hierarchical taxonomy only top-level terms match, since a typed name is a new top-level term; a category under a parent is picked by ID.
2. **Each entry** (`check_entry_details()`), without writing anything. It must exist and not be trashed, and the user must be able to `edit_post` it. An archived entry, or one locked because its coverage ended (with Archive Mode's "coverage ended" message), is refused. Dates follow `wp_insert_post()`'s one-minute rule, which it applies on every save: a published entry given a date a minute or more ahead is refused, since WordPress would quietly schedule it, and saving a scheduled entry dated less than a minute ahead publishes it, so that needs `publish_post`. This covers a scheduled entry whose time passed without cron publishing it, even when the request sends no date.
3. **The writes**, only when at least one entry passed. Terms named but not found are created then, so a request where every entry is refused creates none, and a term the request created that no entry ended up with (because every save failed, or a later term couldn't be created) is deleted again. Each entry that passed gets its co-author and terms written first, because the save's hooks need them (Co-Authors Plus reads `post_author` back from its author terms on every save, and the save re-applies categories and tags), and is then saved through `Post_Type::touch_entry()` with any new author, slug and date. That save keeps the content as stored, re-applies the entry's tags by ID rather than by name (names are looked up by slug first and can land on a different tag), and bumps the modified date so open feeds re-render the entry. If a later step fails, what was written before it stays, and the entry is reported as failed. A scheduled entry this publishes has its push notification settled at once (`Push_Notifications::settle_rest_publish()`), as the core entries route does.

With Co-Authors Plus, the new author's nicename is looked up first (`get_coauthor_by()`), and nothing is written unless it leads to that user, so a guest author holding the same nicename is never credited. The writes run with term counting deferred (left deferred if it already was). The coverages' newest-entry times aren't refreshed on each save: `Newest_Entry::on_status_change` is unhooked for the loop, and each coverage of an entry that was or is published or scheduled is refreshed once afterwards.

It answers 200 with `{ results: [ { entryId, updated, error, slug } ] }`, whether or not some entries failed; `slug` is the saved slug, present only when one was sent.

Tests: `tests/test-entry-details.php` and `tests/test-entry-author.php`. The entries-view route is covered by `tests/test-entries-view.php`, restoring entries by `tests/test-entry-restore.php`, and Archive Mode's locks by `tests/test-archive-mode.php`.

## Quick Edit

Quick Edit (`src/admin/components/quick-edit-modal.tsx`) opens an entry in the block editor inside a centered modal laid out like P2's comment editor: one toolbar row on top, the canvas, Cancel and Save at the bottom. On screens narrower than 600px it is a bottom sheet as tall as core's Modal allows (the screen less a 40px strip of the page above it), so the canvas has room to write in however short the entry is. Once the editor is ready (the entry has loaded and the editor's own setup requests have finished) the WordPress `Modal` header is hidden, so every control in it is ours. Until then the Modal keeps its own header, close button and a "Fetching entry…" loading state, so an entry that never loads can still be closed and the frame is never blank. The toolbar, canvas and footer then fade in; the fade is a keyframe animation, not a transition, because the editor mounts already ready and a transition would have no frame to start from. There are no document settings, so status, date, author and coverage are changed elsewhere. Save calls the editor's `savePost()`, keeps the modal open and refreshes the list.

### Adding an entry

Add Entry opens the same modal with no entry yet, titled "Add Entry". The editor needs a post to work on before anything is saved, so the modal first asks `POST rolling-coverage/v1/coverages/<id>/entries` (`Post_Type::handle_create_entry()`, the entry post type's `create_posts`, which contributors have) for an empty auto-draft credited to the user and assigned to the coverage, as `post-new.php` does for a post, showing "Preparing entry…" meanwhile; an error shows in the modal in its place. It then loads the auto-draft through the core entries route like any entry and opens with the caret in the title, as the post editor opens a new post: `PostTitle` focuses itself only while nothing has focus, and the Modal has focused its frame by then, so the modal calls the title's own focus method as it mounts.

The footer offers Save Draft and Publish, or Submit for Review in place of Publish for users the REST API gives no `wp:action-publish` link (contributors), both disabled until the entry has a title or some content. Each sets the status it saves right before saving, so a status left behind by a failed save never rides along, and saves through the editor's `savePost()`, so the core entries route applies the user's capabilities and the status hooks run as for a save in the editor (feeds, push notifications). The first successful save closes the modal, since the entry is in the list now, and refreshes the list; the editor's own save snackbar ("Draft saved." with View Preview, or "Entry published." with View Entry) arrives a tick later and shows on the page, so this close leaves the notices alone, unlike Cancel. Cancel with nothing typed closes at once; with edits it asks to discard them, as for an existing entry. Either way, cancelling deletes the auto-draft, which is still empty on the server, so an abandoned entry leaves nothing behind; a delete the server refuses (the coverage ended meanwhile) leaves it to core's cleanup. Moving to another coverage with the modal open, with the browser's Back button, closes it: `EntryView` stays mounted across coverages, and the entry would otherwise be saved into the previous one.

An auto-draft is not an entry yet, on either side:

- The list never shows one: the entries-view route only ever queries the statuses in `Post_Type::ALLOWED_STATUSES`, and the coverage's count leaves it out.
- It is not coverage activity: `update_coverage_last_modified()`, which every hook that marks activity in a coverage funnels through, leaves the coverage's last-modified time alone for it.
- Its dates float until it is first saved. `normalize_entry_gmt_dates()` leaves an auto-draft's zero GMT dates in place, so core's `$clear_date` rule dates the entry at its first save, as a draft or published, rather than at the moment the modal opened; from then on the entry keeps its date across publish like any other.
- One that is neither saved nor cancelled (the tab was closed, say) is deleted after a week by core's daily `wp_scheduled_auto_draft_delete` event, which the route schedules as `post-new.php` does.

The route refuses an ended coverage with Archive Mode's "can't be added" error, a trashed coverage with `rolling_coverage_coverage_trashed`, and an unknown one with a 404. The first save is gated the same way: `Archive_Mode::block_rest_writes()` counts an auto-draft's coverage as new, so a coverage that ended while the modal was open refuses the save with the same error, shown in the modal.

Tests: `tests/test-new-entry.php`.

- **The block toolbar is pinned to the toolbar row** (`quick-edit-block-toolbar.tsx`), the way the post editor's Top Toolbar mode pins it. `EditorProvider` ignores `hasFixedToolbar` in its settings and reads the `core.fixedToolbar` preference, so the modal wraps the editor in a child data registry with its own `core/preferences` store where that flag is `true`. The page's own preferences store is off limits: WordPress core attaches the user's persistence layer to it on every page that loads `wp-preferences`, so a write there would pin the toolbar in the user's real post editor at once. A second instance of core's store would leak too: every instance saves through one shared persistence layer, and each save writes that instance's whole state over the user's saved preferences. So the child registry's `core/preferences` is an in-memory stand-in (`utils/quick-edit-preferences.ts`) that never saves. The trade-off is that the user's saved post-editor preferences (hidden block types, icon labels, focus mode, caret behavior) do not apply inside Quick Edit, and editor controls that write preferences, such as the link control's Advanced drawer, write to this throwaway store instead.
- **The block inspector is a popover under the gear** (`quick-edit-inspector.tsx`), not a sidebar. The gear is disabled until a block is selected. The popover closes when the selection goes away or focus leaves it, except when focus lands on the gear or in another popover: the color and font-size controls inside the inspector open their pickers that way. The gear cancels its own `mousedown` so a click never moves focus; Safari would otherwise close and reopen the popover in one click.
- **The inserter is a popover under "+"** (`quick-edit-toolbar.tsx`) and inserts after the selected block, or at the end.
- **Unsaved edits.** Closing with unsaved edits asks to discard them, in a small dialog like the edit-anyway confirm. Once the editor is ready, Escape, a click outside and the modal's close button are off, so every close goes through that check.
- **Everything renders inside `EditorProvider`.** The provider runs `core/editor` and `core/block-editor` together in one sub-registry beneath the child registry above; core-data and notices resolve to the page registry. That sub-registry copies the two editor stores' private selectors and actions from its direct parent only, so the child registry registers both stores as well; without them the editor throws on mount. That layering is why the unsaved-changes guard, `useEntityRecord().hasEdits`, can read from outside the provider while undo, redo and the block toolbar have to sit inside it.
- `EditorProvider` stays inside the `Modal` so the block editor's own modals (media editor, pattern rename and duplicate) nest in it instead of closing Quick Edit. A `Popover.Slot` inside the provider keeps every popover (block library, document overview, inspector and the pickers inside it, block toolbar dropdowns, the in-canvas quick inserter) within the modal frame and its focus trap, so the stylesheet has no rules for the body-level fallback container.
- Save results come through the notices store: `SnackbarNotices` from `@wordpress/notices` renders them inside the modal above the footer. `wp.editor.EditorSnackbars`, which did the same, is deprecated in WordPress 7.0 and removed in 7.2. A failed save's error notice carries a fixed ID, so the next save removes it: a new entry's first successful save closes the modal without clearing the page's snackbars (the editor's own success notice is on its way), and an error left from an earlier attempt would otherwise show on the page beside it.
- **Setup.** The admin page isn't a post editor screen, so core blocks are registered on first open (`ensureEditorInitialized()`), and the editor settings come from `get_block_editor_settings()` in the script data, with template mode off.

## Entry sources

Every entry records where it came from in the `rolling_coverage_entry_source` meta (`Post_Type::META_ENTRY_SOURCE`): `slack` for entries ingested from Slack, `wordpress` for entries written in the editor. Entries with no meta count as `wordpress`. `getEntrySource()` (`src/admin/utils/fields.ts`) applies that default, so any slug it doesn't recognize also reads as `wordpress`.

The entries list has no Source column. The source shows as a marker before the title, rendered by the `title` field:

- An unpinned entry shows its source's logo, a 10px glyph centered in the marker box (`.newspack-rolling-coverage-entry-title__source`). The box is 24px wide and one line of the title tall (`1lh`), so the marker centers on the title's first line whether the title wraps or not.
- A pinned entry shows only the pin, never a pin and a logo. The pin's color carries the source instead: the regular text color for WordPress, the source's brand color for anything else (`#e3066a` for Slack, from `.newspack-rolling-coverage-entry-title__pin--slack`).
- The marker's text ("From Slack", "Pinned, from WordPress") is hidden text for screen readers and the tooltip on hover, so color is never the only cue.

The `source` field stays defined, with no `render` and `enableHiding: false`. DataViews builds filters from every field, whether or not it's shown, so Source is offered under **Add filter** but never as a column.

## Slack authors

An entry ingested from Slack is credited to the WordPress user whose Slack member ID or handle matches the message's author, and to the Slack bot user (`Slack_Config::get_or_create_bot_user_id()`) when nobody's does. The value is set in the **Slack handle** field of the **Rolling Coverage** section on the profile and user edit screens, and stored in the `rolling_coverage_slack_handle` user meta (`Slack_Author_Resolver::META_SLACK_HANDLE`). The section shows only for users who can `edit_posts`, since nobody else can be credited.

- A value that starts with `U` or `W` and is uppercase letters with at least one digit (`Slack_Author_Resolver::is_member_id()`) is a member ID; anything else is a handle. Values are stored trimmed and without a leading `@`.
- Saving a value that another user who can `edit_posts` already has is refused with an error on the profile screen, so a message has one person it could be credited to. Users without `edit_posts` are ignored, so a value left on a demoted user's profile can come back alongside someone else's when that user regains `edit_posts`; the lower user ID is then credited.
- A member ID typed in lowercase is a handle, so it never matches its owner's messages. Slack's **Copy member ID** gives it in uppercase.
- At ingest, `Slack_Author_Resolver::resolve_author()` compares the message's member ID with stored member IDs, exactly. It then compares the author's Slack display name, full name and username, in that order, with stored handles only, ignoring case as the database collation does. Names are free text their owner can change, so a name shaped like a member ID never reaches a member-ID mapping. Only users who can `edit_posts` are credited.
- Names need Slack's `users.info` lookup (1s budget on the webhook). When it fails, only a member ID can match.
- Images uploaded with the message are owned by the same user as the entry.
- The ingest log's `author` field records what matched (`member_id`, `display_name`, `real_name`, `name` or `bot`), so entries credited through a name can be found if one turns out to be wrong.

Trust model:

- Anyone in the Slack workspace can change their name to match someone's handle. A handle therefore tells who posted only as far as the channel's members can be trusted to use their own names. Slack never lets anyone change a member ID, which is why the field recommends one.
- The field is self-service: anyone who can `edit_posts` can enter any value on their own profile, including a colleague's member ID, and nothing checks it against Slack. A value is first come, first served, and an administrator settles a dispute by clearing it on the other profile.
- On multisite, the meta is shared across the network, but the duplicate check only sees users of the current site.

Tests: `tests/test-slack-author-resolver.php`, and the end-to-end cases in `tests/test-slack-webhook.php`.

## Adding a source

The taxonomy already anticipates other chat platforms (Beeper, WhatsApp, Telegram). A new source needs each of these:

1. A `SOURCE_*` constant in `src/admin/utils/fields.ts`, recognized by `getEntrySource()`.
2. An option in the `source` field's `elements`, so the filter offers it.
3. A logo for the unpinned marker. Draw it to fill its frame edge to edge, like `SlackIcon` (`src/admin/shared/icons/`), so it renders at 10px like the others. The WordPress icon from `@wordpress/icons` fills 20 of its 24 units, so it renders at `size={ 12 }`.
4. A pin color: a `.newspack-rolling-coverage-entry-title__pin--<source>` modifier in `src/admin/styles/components/_dataviews.scss` set to the source's brand color, applied in the `title` field's `render`. Without one, a pinned entry from the new source looks like a pinned WordPress entry.
5. Marker labels for both states ("From …" and "Pinned, from …").

Until a source has them, its entries show the WordPress marker, yet "Source is WordPress" leaves them out, because the filter matches the stored slug.

The server needs no change beyond the ingest path writing the slug to the meta. The entries endpoint's `source` and `source_exclude` filters accept any slug, with `wordpress` special-cased to include entries that have no meta.

## Slack webhooks on a password-protected site

Slack sends events, slash commands and interactions to three REST routes, `rolling-coverage/v1/slack/events`, `/slack/commands` and `/slack/interactions` (`Slack_Webhook_Controller::WEBHOOK_ROUTES`), registered once Slack is configured. Each checks Slack's signature in its permission callback and answers an unsigned request with a 401.

The [Password Protected](https://wordpress.org/plugins/password-protected/) plugin refuses REST requests from visitors who haven't entered the site password, unless its "Allow REST API" setting is on. Slack can't enter the password, so `Slack_Webhook_Controller::filter_password_protected_is_active()` turns that protection off for the webhook routes alone, on the plugin's `password_protected_is_active` filter at priority 100. Everything else stays protected, the Slack admin routes included. The route is the one WordPress parsed from the request URL (the `rest_route` query var, whether the URL uses `/wp-json/` or `?rest_route=`), compared without regard to case or a trailing slash, as the REST API matches routes.

Tests: `tests/test-slack-webhook.php`, against a stand-in for the plugin's REST gate in `tests/mocks/class-password-protected.php`.

## Placements

A coverage's placements are every published place where the plugin's blocks show it. The coverage header and the All Coverages row actions use them to send editors to those places.

### What counts

`Placements` (`includes/class-placements.php`) looks for three blocks:

- **Rolling Coverage**, by its `coverageId`. It is listed with its layout in brackets: the shared layout's title when `layoutId` points at a published pattern (Ticker, Flash, or a custom layout's title), or "Detached" when the block has inner blocks of its own. A capped feed (`latestOnly`) adds how many entries it shows, as in "Rolling Coverage (Ticker, latest 5)". A block with no layout of either kind, or whose layout has nothing the site can render (unpublished, gone, or without a Rolling Coverage block holding inner blocks), renders the built-in Bulletin template, so it reads "Rolling Coverage (Bulletin)", whatever the Bulletin pattern is now called. A layout without a title reads "Untitled layout". Layout titles are looked up once per layout and request. Blocks that read the same on one place, such as two Ticker feeds, are listed once.
- **Coverage Status** and **Follow Coverage**, listed by their block names. Custom (`coverageId > 0`) counts for the chosen coverage while it exists and isn't trashed; otherwise the block is Automatic, as it is on the site.

Blocks inside a Rolling Coverage block are part of its layout and show its coverage, so they are not listed on their own. The Check for Updates block only lives there.

The map stores each block as a tag (`feed:<layout>:<count>`, `status` or `follow`), in the order rows list them: feeds that show every entry first, then capped feeds, Coverage Status and Follow Coverage. The REST field turns the tags into the labels above.

The places it looks in, all published and for the active theme:

| Place | Row | View | Edit |
| --- | --- | --- | --- |
| Posts and pages of any viewable post type, except entries and attachments. Password-protected ones are skipped. | The post's title | Its permalink | The block editor |
| Templates (`wp_template`), customized or from the theme's files, through `get_block_templates()`. Only when the theme uses block templates. | The template's title | Front Page: the home page. Blog Home: the posts page. Others: none. | The Site Editor |
| Template parts (`wp_template_part`), when the theme uses them | The part's title | None | The Site Editor |
| Synced patterns (`wp_block`) that published content uses, directly or through other patterns | The pattern's title | None | On block themes, the Site Editor for users who can edit the theme; otherwise the block editor |
| Widget areas with block widgets, for registered areas only | The area's name | None | The Widgets screen |

The title links to the Edit destination only for users who can edit that place; otherwise it is plain text.

### Automatic Status and Follow Coverage blocks

An Automatic block shows the coverage of the page being viewed (see `src/blocks/coverage-status/DEVELOPMENT.md`), so where it counts depends on where it sits:

- **In a post's own content**, or in a synced pattern the post uses, it shows the post's first uncapped feed, or a breakout post's coverage. It joins that post's row, so a page with a feed and an Automatic Status block is one row listing "Rolling Coverage (Stream)" and "Coverage Status".
- **In a template, template part or widget area**, it shows the coverage on single posts. The rows only cover breakout posts: the single post template (the first of `single-post`, `single`, `singular` and `index` the theme has), the template parts it holds, and every widget area with an Automatic block are each listed once, with their type reading "Template · Breakout posts" (screen readers hear "Template, on this coverage's breakout posts"), for coverages with at least one published breakout post. View opens the newest one. Each breakout post isn't listed on its own. The check looks at the `MAX_BREAKOUT_POSTS` (500) newest entries that have a published breakout post, so a coverage whose only breakout posts are older than that gets no breakout rows.
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

- **Posts and patterns:** a save that changes what a published post or synced pattern contributes: the coverages its blocks show, and each block's layout and cap, its Automatic blocks, its uncapped feeds, the patterns and template parts it uses, its post type, password or date (which orders the rows and picks `page_id()`). A new title, or an edit to the text around a capped feed, keeps the map. Publishing, unpublishing or permanently deleting a post that holds one of the blocks, or uses a pattern in the stored set, marks it too.
- **Templates and template parts:** any save, status change or deletion, whatever they hold. They are saved rarely, and which of them a breakout post renders with depends on their slugs and the parts they hold; deleting a customization hands the slug back to the theme's file.
- **Widgets:** any change to `widget_block` or `sidebars_widgets`.
- **Themes and plugins:** `after_switch_theme`, and `upgrader_process_complete` for theme and plugin updates (not translations).
- **Coverages:** a coverage moving into or out of the trash, or being deleted, since a Custom block whose coverage is trashed falls back to Automatic. Pausing, ending or resuming one doesn't.
- **Breakout entries:** an entry with a breakout post moving to another coverage, since an Automatic block in the breakout post's own content follows it.

On multisite, a change made while switched to another site marks that site's map and schedules its rebuild at once, in that site's own cron, so the scan runs on that site with its own post types, widget areas and patterns.

Activating the plugin schedules the first build; deactivating it deletes the map, the token, the lock and any pending event.

Not tracked: theme files edited without an update, and a breakout post's entry being unpublished. Both are picked up by the next rebuild.

### REST field

`placements` on the coverage term, for users who can `edit_posts` (others get an empty list), in both the `view` and `edit` contexts. It is only worked out when the request names it in `_fields`; otherwise it is an empty list, so the block editor's coverage lookups stay cheap. The coverages list (`useCoverages.ts`) and the single coverage fetch (`getCoverage()` in `src/admin/utils/coverage-api.ts`) both name it. Each row has `id`, `title`, `type` (what the place is, such as "Page" or "Template part"), `blocks` (the labels above), `viewUrl`, `editUrl`, `isMain` and `breakout`.

The older `pageUrl` field stays: the canonical URL, or else the newest post with an uncapped feed, read from the stored map as it is.

### The button

A coverage with at least one placement gets a "Placements" button in its header and a "Placements" row action, however many places there are. Both open `PlacementsDrawer` (`src/admin/components/placements-drawer.tsx`), a Newspack `Drawer` listing every row as a description list. Each entry is a WP UI `Field.VisualLabel` over its value in a `Stack` with an 8px gap, as form fields lay out: the place's type over its title, with View beside the title, then "Blocks" over one block per line. The title links to the place's editor when the user can edit it, with an "Open in the editor" tooltip, and screen readers hear the title followed by "(opens in the editor)"; otherwise it is plain text. Labels show in capitals. The main page's type reads "Page · Main" (screen readers hear "Page, Main page"), or "Main page" on the canonical URL's own row. A post whose post type is no longer registered shows the post type's name as stored. Rows are a WP UI `Stack` with a 16px gap, separated by a Newspack `Divider`.

The header button lives in `entry-view.tsx`; the row action (`placements`) in `src/admin/actions/coverage-actions.ts`.

Tests: `tests/test-placements.php`, plus the page lookup and its rebuild in `tests/test-taxonomy.php`.

## Leaving the entry editor

Entries are managed in the coverages screen, not in core's entries list, which stays registered but hidden (`show_in_menu` is false). Two exits from the editor point at that list, so both are redirected:

- The back button. Core hardcodes its link to `edit.php?post_type=rolling_cov_entry` and shows it only when nothing else fills the slot. `src/entry-editor/back-to-coverage.tsx` fills it with `__experimentalMainDashboardButton` from `@wordpress/edit-post`, the one API for replacing it. It links to `#/coverages/<id>` for the entry's first coverage as currently edited (the first ID in the taxonomy's REST attribute), or `#/coverages` when it has none. Its label, read by screen readers and shown as a tooltip, is "Back to Coverage", or "Back to All Coverages" when the entry has no coverage. It renders only where core's own button does: fullscreen mode at a medium viewport or wider. `coveragesUrl` already ends in `#/coverages`, so the entry's route is that URL plus `/<id>`; `Admin::get_coverages_url()` builds the same URLs server side, so change both together. The two editor registrations (back button, Push Notifications panel) are separate plugins so one failing can't unmount the other.
- The redirect after trashing. Core sends the editor to `edit.php?trashed=1&post_type=…&ids=<id>`. `Admin::redirect_entry_list()` (on `load-edit.php`) sends any plain GET for the entries list to the screen above, using `ids` to find the trashed entry's coverage. Requests carrying an `action` are left alone. After a trash it adds `rolling_coverage_trashed=1` to the page URL (not `trashed`, which core strips from admin URLs before the script runs); the admin app (`announceTrashedEntry()`) shows the "Entry trashed." snackbar for it and removes the arg with `history.replaceState`. There is no Undo: restoring would need the already-loaded list to refresh.

Tests: `tests/test-entry-editor-navigation.php`.

`Admin::enqueue_entry_editor()` loads the `entry-editor` bundle on the entry editor and passes it `window.newspackRollingCoverageEntryEditor` (the coverages URL, the taxonomy's REST base, and whether the Push Notifications panel applies).

The bundle's other registration, the Push Notifications panel, is described in `src/blocks/coverage-follow/DEVELOPMENT.md`.

The entry post type registers core's `item_*` labels (`Post_Type::register()`), so the editor's notices name an entry, as in "Entry published." and "Entry updated.", rather than a post.
