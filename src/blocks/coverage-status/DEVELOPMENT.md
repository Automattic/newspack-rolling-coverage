# Coverage Status block: development notes

The Coverage Status block (`newspack-rolling-coverage/coverage-status`) shows whether a coverage is live, paused or ended, as a badge with an optional "Updated" time. Editor code lives in `src/blocks/coverage-status/`, with shared helpers in `src/blocks/shared/`. Server rendering lives in `includes/blocks/class-coverage-status-block.php`.

For how publishers use the block, see `README.md` in this directory.

## Attributes

- `coverageId`: 0 for Automatic, or a chosen coverage (Custom).
- `labels`: per-status text overrides, keyed `active`, `paused`, `archived`.
- `backgroundColors`: per-status badge backgrounds, same keys. Each is a hex color or a theme color name (`accent`, `base`).
- `showDot`: the dot (and pulse, while live) on the badge. Default on.
- `showLastUpdated`: the "Updated 2 minutes ago" text. Default off.
- `hideWhenEnded`: render nothing once the coverage ends.

Text color, margin and block gap come from block supports. The wrapper is a flex layout set in `block.json`. A newly inserted block gets a muted text color from the theme palette (`mutedTextColor()`), written as a non-persistent change so it doesn't land in the undo stack.

## Which coverage it shows

`Coverage_Status_Block::render_block()` asks `Page_Coverages::coverage_for_block()`, in this order:

1. **Inside a Rolling Coverage block**, the feed's coverage, from the `newspack-rolling-coverage/coverageId` context. A Status block among a layout's coverage-level blocks gets it from `Rolling_Coverage_Block::render_coverage_blocks()`. Its own `coverageId` is ignored there.
2. **Custom** (`coverageId > 0`), while that coverage exists and isn't trashed (`Page_Coverages::is_followable()`). A chosen coverage that is gone falls back to Automatic.
3. **Automatic** (`coverageId` 0): the first uncapped Rolling Coverage block in the post being viewed, including ones inside synced patterns (`feed_coverage_ids()`), or, for a breakout post, its source entry's coverage (`breakout_coverage_id()`). Automatic resolves only on single views (`page_id()`) and never for a password-protected post.

With no coverage, the block renders nothing. Capped feeds (`latestOnly`) never count for Automatic, because they only preview a coverage.

The editor mirrors these rules in `useBlockCoverage()` (`src/blocks/shared/block-coverage.ts`). Inside a Rolling Coverage block, `edit.tsx` of that block provides the coverage through `BlockContextProvider`.

## Default gap

The gap between the badge and the "Updated" time is `spacing|20`, declared once as the block's default style: `supports.__experimentalStyle.spacing.blockGap` in `block.json`. Core merges that into Global Styles, so saved blocks get the gap without an inline value and themes or users can override it. A block's own Block Spacing still wins. Themes that don't enable `settings.spacing.blockGap` (classic themes without a theme.json, such as Newspack Theme) don't print it and use core's flex gap.

`test_default_gap_is_spacing_20_in_global_styles` guards it. The badge's own gap between its dot and label (6px) comes from newspack-ui and is deliberate; don't change it.

## Labels

Each status's text is, in order: the block's `labels` value, the site-wide label, or the built-in one (Live, Paused, Ended). `Coverage_Status_Block::labels()` does the merge.

Site-wide labels live in the `rolling_coverage_status_labels` option, managed by `Status_Labels` (`includes/blocks/class-status-labels.php`):

- `GET` and `POST rolling-coverage/v1/settings/status-labels`, for users with `edit_others_posts`. The plugin's settings screen uses it (`src/admin/components/settings-modal.tsx`).
- Labels are plain text, capped at 40 characters (`MAX_LENGTH`). Posting an empty string for a status restores the built-in label; omitting a status leaves it unchanged.
- The editor gets the merged labels as `statusLabels` from `localize_editor_config()`.

## Background colors and text contrast

A custom background is a theme color name or a hex color.

A theme color name (`THEME_COLORS`: `accent`, `base`, the block theme's palette slugs) renders its background as the theme's color variables: the block theme's preset, then the classic Newspack Theme's custom property, then a plain value, so the badge follows the theme's style variations, dark ones included. A stored hex can't: it keeps the color the palette had when it was saved. Built-in layouts that color the badge use these names (Flash `base`, Alert `accent`).

The text is the theme's paired variable from `THEME_COLORS` (`accent` with `accent-contrast`, else `base`, as the block theme's buttons do; `base` with `contrast`) whenever the palette defines that pair, so the badge keeps the text color the theme designed. When it doesn't, `palette_color()` reads the slug's current color from `wp_get_global_settings()`, the site's custom palette before the theme's, as the preset variable resolves. That includes the active style variation, which lives in the site's global styles. `Apca::text_color()` then picks black or white against it, as for a hex background, so a theme with a light accent and no `accent-contrast` (Twenty Twenty-Four, for one) still gets dark text. The Newspack Block Theme prints `accent-contrast` as CSS rather than a palette color, so its accent badge takes this path too, with the same APCA pick the theme makes. The text is picked per page render, so after a variation switch it follows from the next page load, like the theme's own buttons. When the palette has neither the pair nor a hex for the slug (the classic Newspack Theme has no `accent` or `base` presets; a palette value such as `var()` or `rgb()` isn't read), the paired variable's fallbacks apply.

The editor's color picker needs a literal color to show and mark as selected, so it shows a theme color as its palette swatch, or the plain fallback when the palette has none (`themeSwatch()` in `edit.tsx`); picking any color there stores a hex. The preview reads the same palette slugs and their pairs from the editor settings, custom before theme, and passes them to `badgeStyleObject()`, so its text matches the site.

A hex background goes through `Apca::normalize()`, which accepts `#rgb`, `#rgba`, `#rrggbb` and `#rrggbbaa`, drops any alpha, and returns an empty string for anything else, which leaves the badge's default style. This keeps the value safe for an inline style.

The text color is never chosen by the publisher. For a hex background, `Apca::text_color()` (`includes/blocks/class-apca.php`) picks black or white, whichever has the higher APCA contrast against the background. `badge_style()` then sets the background, that text color, and a dot color mixed from the two so the dot stays visible.

`src/blocks/shared/apca.ts` ports the same algorithm and constants for the editor preview (`badgeStyleObject()` in `src/blocks/shared/status-badges.ts`, which also mirrors `THEME_COLORS`). The two must agree, or the editor shows a different text color from the site; change them together. `tests/test-apca.php` checks the PHP side against reference values.

The badge classes per status are `Coverage_Status_Block::BADGE_CLASSES`, mirrored by `BADGE_CLASSES` in `status-badges.ts`.

## Live updates

The block never fetches on its own. `view.ts` listens for the `newspack-rolling-coverage:poll` event (`src/blocks/shared/poll-event.ts`), which the Rolling Coverage block's view script fires on `document` after each poll with `coverageId`, `status` and `newestEntry`. For each Status block with the same `data-coverage-id`, it swaps the badge classes, the label and the inline style, and updates the "Updated" time.

To make that swap possible, the server prints every status's label as `data-label-{status}` and every custom style as `data-style-{status}` on the wrapper.

Without a polling Rolling Coverage block for the same coverage on the page, the badge stays as rendered. Only the relative "Updated" time refreshes, once a minute (`REFRESH_INTERVAL_MS` in `src/blocks/shared/relative-dates.ts`). The Rolling Coverage block only polls while the coverage was live when the page rendered, so a page rendered while paused will not show the coverage going live until it reloads.

## Newest entry

The "Updated" time reads `Newest_Entry::get()` and `get_iso()` (`includes/class-newest-entry.php`). `Newest_Entry` keeps the newest published entry's date in the `rolling_coverage_newest_entry` term meta, updated as entries are published, unpublished, moved between coverages or deleted.

`Coverage_Status_Block::register_rest_fields()` adds a read-only `newestEntry` field (ISO 8601 or null) to the coverage term's REST record, so the editor preview shows the same time as the site. It returns null for users without `edit_posts`. Poll responses carry the same value.

The "Updated" text renders hidden unless the coverage is live and has an entry, so the view script only has to show it and fill in the time.

On a Lite Site page (`Lite_Feed::is_lite_render()`), `render_block()` leaves the "Updated" text out: Lite Site strips the markup that hides it and never runs the script that keeps it current. The badge is a snapshot, like the rest of the page. See "Lite Site pages" in `src/blocks/rolling-coverage/DEVELOPMENT.md`.

## Hide when ended

With `hideWhenEnded`, `render_block()` returns nothing for an archived coverage, and `view.ts` removes the block when a poll reports `archived`.

## Tests

- `tests/test-coverage-status-block.php`: coverage resolution (feed context, Custom, Automatic, breakout posts, capped feeds, synced patterns, single views only), labels, `hideWhenEnded`, the dot, custom backgrounds, the "Updated" time, the `newestEntry` REST field, the flex layout support and the default gap.
- `tests/test-status-labels.php`: the labels option and REST route.
- `tests/test-apca.php`: color normalizing and contrast.
- `tests/test-newest-entry.php`: keeping the newest entry date current.

From this plugin's directory, run `../../../n test-php` (add `--filter <name>` for one test). The tests register the blocks from `dist/`, so build first if it is stale.
