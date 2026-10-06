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
| Synced patterns (`wp_block`) that published content uses, directly or through other patterns | The pattern's title | None | The Site Editor, or the block editor for users who can't edit the theme |
| Widget areas with block widgets, for registered areas only | The area's name | None | The Widgets screen |

Edit links only show for users who can edit that place.

### Automatic Status and Follow Coverage blocks

An Automatic block shows the coverage of the page being viewed (see `src/blocks/coverage-status/DEVELOPMENT.md`), so where it counts depends on where it sits:

- **In a post's own content**, or in a synced pattern the post uses, it shows the post's first uncapped feed, or a breakout post's coverage. Its tag joins that post's row, so a page with a feed and an Automatic Status block is one row tagged "Full" and "Status".
- **In a template, template part or widget area**, it shows the coverage on single posts. The rows only cover breakout posts: the single post template (the first of `single-post`, `single`, `singular` and `index` the theme has), the template parts it holds, and every widget area with an Automatic block are each listed once, as "…, on this coverage's breakout posts", for coverages with at least one published breakout post. View opens the newest one. Each breakout post isn't listed on its own.
- **In a synced pattern's row**, it doesn't count, since it shows whichever page uses the pattern.

An Automatic block in other templates, such as the page template, isn't listed.

### Order and the main page

Posts come first, newest first, then templates, template parts, widget areas and synced patterns, then the breakout rows. When the coverage has a canonical URL, the row whose View link has the same path and query is marked as the main page and moves to the top. A canonical URL that matches no place gets a row of its own at the top, with only a View link.

### The stored map

Scanning every post on each request would be too slow, so `Placements::get_map()` builds a map once and keeps it in the `rolling_coverage_placements` option: the places of each coverage, the template and widget rows for breakout posts, the synced patterns that show a coverage, and the newest post with an uncapped feed for each coverage (`page_id()`, which `Taxonomy::get_coverage_page_url()` uses when there is no canonical URL). Titles, links and labels are filled in when the REST field is read, so renaming a layout or a post's permalink changing needs no rebuild. Whether a coverage has a published breakout post is checked then too, with one query for all coverages.

These changes mark the map out of date, and the request that made them rebuilds it on `shutdown`; readers keep the stored map until then:

- A published post, template, template part or synced pattern holding one of the blocks, or using a synced pattern that shows a coverage, is saved, published, unpublished or deleted.
- Block widgets or the widget areas they sit in change (`widget_block`, `sidebars_widgets`).
- The theme is switched or updated.
- A coverage's status changes or a coverage is deleted, since a Custom block whose coverage is trashed falls back to Automatic.

Theme files edited without an update aren't noticed until the next of these changes.

### REST field

`placements` on the coverage term, for users who can `edit_posts` (others get an empty list), in both the `view` and `edit` contexts. Each row has `id`, `title`, `type` (what the place is, such as "Page" or "Template part"), `tags`, `viewUrl`, `editUrl`, `isMain` and `breakout`. The coverages list asks for it in `_fields` (`src/admin/hooks/useCoverages.ts`).

The older `pageUrl` field stays: the canonical URL, or else the newest post with an uncapped feed.

### The button

`getPlacementsLink()` (`src/admin/utils/placements.ts`) decides what the header button and the row action do:

- **No placements:** no button and no row action.
- **One placement with a View link:** "View Page", a link that opens it in a new tab.
- **Anything else:** "View Pages", which opens `PlacementsDrawer` (`src/admin/components/placements-drawer.tsx`), a Newspack `Drawer` listing every row with its tags and View and Edit links. A single place without a page of its own, such as a template part, also opens the drawer, so its Edit link is reachable.

The header button lives in `entry-view.tsx`; the row actions (`view-page`, `view-pages`) in `src/admin/actions/coverage-actions.ts`.

Tests: `tests/test-placements.php`, plus the page lookup and its rebuild in `tests/test-taxonomy.php`.
