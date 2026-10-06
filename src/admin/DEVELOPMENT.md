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

## Quick Edit

Quick Edit (`src/admin/components/quick-edit-modal.tsx`) opens an entry in the block editor inside a centered modal laid out like P2's comment editor: one toolbar row on top, the canvas, Cancel and Save at the bottom. Once the entry has loaded the WordPress `Modal` header is hidden, so every control in it is ours. While it loads, the Modal keeps its own header and close button, so an entry that never loads can still be closed.

- **The block toolbar is pinned to the toolbar row** (`quick-edit-block-toolbar.tsx`), the way the post editor's Top Toolbar mode pins it. `EditorProvider` ignores `hasFixedToolbar` in its settings and reads the `core.fixedToolbar` preference, so the modal wraps the editor in a child data registry with its own `core/preferences` store where that flag is `true`. The page's own preferences store is off limits: WordPress core attaches the user's persistence layer to it on every page that loads `wp-preferences`, so a write there would pin the toolbar in the user's real post editor within seconds. Inside Quick Edit the `core` preference scope starts from defaults, and inspector panel toggles made here never reach the user's saved preferences.
- **The block inspector is a popover under the gear** (`quick-edit-inspector.tsx`), not a sidebar. The gear is disabled until a block is selected. The popover closes when the selection goes away or focus leaves it, except when focus lands on the gear or in another popover: the color and font-size controls inside the inspector open their pickers that way. The gear cancels its own `mousedown` so a click never moves focus; Safari would otherwise close and reopen the popover in one click.
- **The inserter is a popover under "+"** (`quick-edit-toolbar.tsx`) and inserts after the selected block, or at the end.
- **Everything renders inside `EditorProvider`.** The provider runs `core/editor` and `core/block-editor` together in one sub-registry beneath the child registry above; core-data and notices resolve to the page registry. That is why the unsaved-changes guard, `useEntityRecord().hasEdits`, can read from outside the provider while undo, redo and the block toolbar have to sit inside it.
- `EditorProvider` stays inside the `Modal` so the block editor's own modals (media editor, pattern rename and duplicate) nest in it instead of closing Quick Edit. A `Popover.Slot` inside the provider keeps every popover (block library, document overview, inspector and the pickers inside it, block toolbar dropdowns, the in-canvas quick inserter) within the modal frame and its focus trap, so the stylesheet has no rules for the body-level fallback container.
- Save results come through the notices store: `SnackbarNotices` from `@wordpress/notices` renders them inside the modal above the footer. `wp.editor.EditorSnackbars`, which did the same, is deprecated in WordPress 7.0 and removed in 7.2.
