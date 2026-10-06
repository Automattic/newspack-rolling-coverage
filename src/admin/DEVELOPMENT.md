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

The taxonomy already anticipates other chat platforms (Beeper, WhatsApp, Telegram). A new source needs each of these. Until it has them, its entries show the WordPress marker, yet "Source is WordPress" leaves them out, because the filter matches the stored slug:

1. A `SOURCE_*` constant in `src/admin/utils/fields.ts`, recognized by `getEntrySource()`.
2. An option in the `source` field's `elements`, so the filter offers it.
3. A logo for the unpinned marker. Draw it to fill its frame edge to edge, like `SlackIcon` (`src/admin/shared/icons/`), so it renders at 10px like the others. The WordPress icon from `@wordpress/icons` fills 20 of its 24 units, so it renders at `size={ 12 }`.
4. A pin color: a `.newspack-rolling-coverage-entry-title__pin--<source>` modifier in `src/admin/styles/components/_dataviews.scss` set to the source's brand color, applied in the `title` field's `render`. Without one, a pinned entry from the new source looks like a pinned WordPress entry.
5. Marker labels for both states ("From …" and "Pinned, from …").

The server needs no change beyond the ingest path writing the slug to the meta. The entries endpoint's `source` and `source_exclude` filters accept any slug, with `wordpress` special-cased to include entries that have no meta.
