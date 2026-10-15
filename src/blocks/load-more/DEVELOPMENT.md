# Load More block: development notes

The Load More block (`newspack-rolling-coverage/load-more`) places a Rolling Coverage feed's Load more button in its layout, so the button's justification, spacing and style are block settings. Editor code lives in `src/blocks/load-more/`, with its button template in `src/blocks/shared/load-more-buttons.ts`. Server rendering lives in `includes/blocks/class-load-more-block.php`; the feed's side lives in `includes/blocks/class-rolling-coverage-block.php` and `src/blocks/rolling-coverage/view.ts`.

For how publishers use the block, see `README.md` in this directory.

## Attributes and supports

The block has no attributes. `customClassName` and `reusable` are off. `ancestor` limits it to a Rolling Coverage block, which also wraps a layout in the layout pattern editor. `render_block()` adds `newspack-rolling-coverage-load-more` (`CONTROL_CLASS`) to the wrapper, the class the view script and `style.scss` find the feed's button by, whatever classes its supports add. `usesContext` lives in `block.json` only; tests that register the block without the build read it from there.

## Inner blocks

`loadMoreButtonsTemplate()` builds a `core/buttons` block holding one `core/button` with `tagName: 'button'`, so it never navigates. Its argument sets the Buttons block's own settings: each built-in layout passes its justification or top margin (`loadMoreBlock()` in `template.ts`). `edit.tsx` locks the template with `templateLock: 'all'`: publishers can restyle the button, change its text, and change the Buttons block's justification and margin, but not remove the button or add blocks. `save` returns the inner blocks' content.

## Rendering

- **`Entry_Bindings::is_coverage_item()`** counts the block as coverage-level, so `layout_parts()` places it in the header or footer and it renders once, not in each entry. The built-in layouts put it last in the Feed, so it renders after the entries.
- **`render_coverage_blocks()`** drops the block unless the feed loads older entries with a button (`older_entries()` returns `button`, which a capped feed never does). A block it keeps takes the entries' grid placement (`place_beside_entries()`) and, when its Buttons block justifies left, the entries' text inset (`starts_at_inline_start()`, `with_load_more_inset()`).
- **`Rolling_Coverage_Block::render_block()`** adds its own Load more button after the entries only when the layout's header and footer hold no Load More block, so layouts without one keep working.
- **`Load_More_Block::render_block()`** wraps the inner button in the block's wrapper, rendered `hidden` for the view script to show while older entries remain. Core renders nothing for a button left without text, so when the inner blocks hold no `<button>` the block puts one reading "Load more" inside the Buttons block where it rendered, keeping its justification and margin, or on its own where it didn't; otherwise the layout would hold a control with nothing to press, and the feed's own button stays off. Without the coverage ID in its context, that is outside a Rolling Coverage block, and in syndication feeds, it renders nothing.
- The view script finds the first `.newspack-rolling-coverage-load-more` the feed owns and its `button`, whichever renders it, and keeps the label's nodes to restore after "Loading…", so a formatted label keeps its formatting. See "Server rendering and polling" in `../rolling-coverage/DEVELOPMENT.md` for loading.

## The inset

`load_more_inset()` reads the entry group's first Columns block and returns how far in the entries' text starts: the first column's width, the gap between columns, and the second column's inline-start border and padding (Rail's timeline). Columns that all set percentage widths overflow by their gaps and shrink in proportion, so the inset subtracts the first column's share, `max(0px, total% - 100% + (columns - 1) * gap) * width / total`. Inline-start spacing outside the text column, on the Columns block or a group around it, isn't counted; no built-in layout sets any. The sum assumes columns size their border box, as the Newspack themes set (`box-sizing: border-box`); on a theme that leaves columns at `content-box`, a first column's own padding would push the text further in. A unitless `0` is written `0px`, since it isn't a length in `calc()`. `with_load_more_inset()` sets the result as `--newspack-rolling-coverage-load-more-inset`, and also as `--newspack-rolling-coverage-load-more-inset-narrow` when the row sets `isStackedOnMobile` to false. `style.scss` turns them into `margin-inline-start` on the Feed's Load more, the narrow value below 782px, where core stacks columns, and the other from 782px. Block settings can't express a length taken from another block, so this is CSS. `loadMoreInset()` and `loadMoreInsetStyle()` in `template.ts` mirror it for the editor, and reject the same values (anything but a plain length, or one opening a comment with `/*`), since the editor writes the inset into a `<style>` element. The inputs are physical (`padding.left`, `border.left`), as Rail's timeline is, so in a right-to-left language the inset follows that timeline's own sides.

## Editor

- `ALL_ALLOWED_BLOCKS` (`layout.ts`) lets the block into the Feed group. `feed-insertion.ts` keeps it, like Follow Coverage, out of entries and the pinned card.
- `isCoverageItem()` (`template.ts`) matches it, as the server does.
- The Rolling Coverage block's `edit.tsx` hides the block where the site doesn't show it (`isLoadMoreHidden`: Older entries isn't the button, or the preview has no more entries), except in the layout pattern editor, where it always shows for publishers to style. Where the layout holds the block, the editor previews it in place of its own Load more preview (`holdsLoadMore`).
- An editable layout gives each top-level Load More block the entries' grid column, and a left-justified one their inset, through `layoutCss`, as the server does; a synced preview renders one above or below the entries in an `EntryBlockPreview` with the `newspack-rolling-coverage-load-more` class and the same properties (`renderSyncedChromeItem()`). The inset is measured on the template the previews show, after Author name and Author avatar drop their blocks (`withAuthorSettings()`), as the server measures it. The editor's own Load more preview shows only where the layout holds no block, Older entries is the button and more entries remain.

## Tests

`tests/test-load-more.php` covers the block taking the place of the feed's own button, the block dropping out for Load on scroll, Don't load and a capped feed, a button left without text, the block outside a feed, the inset for a side-by-side row and a stacking one, percentage columns, and a centered or right-justified button keeping its place. `tests/test-pinned-stack.php` covers grid placement for the block and the feed's own button, and the feed's own button's inset. The plugin has no JS tests.
