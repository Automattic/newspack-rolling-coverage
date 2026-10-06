# Check for Updates block: development notes

The Check for Updates block (`newspack-rolling-coverage/check-updates`) switches a Rolling Coverage feed to checking for new entries only when a reader presses its button. Editor code lives in `src/blocks/check-updates/`, with its button template in `src/blocks/shared/check-updates-buttons.ts`. Server rendering lives in `includes/blocks/class-check-updates-block.php`; the feed's side lives in `includes/blocks/class-rolling-coverage-block.php` and `src/blocks/rolling-coverage/view.ts`.

For how publishers use the block, see `README.md` in this directory.

## Attributes and supports

The block has no attributes. `customClassName` and `reusable` are off. The default `wp-block-newspack-rolling-coverage-check-updates` class stays on: the view script finds the block by it.

## Inner blocks

`CHECK_UPDATES_BUTTONS_TEMPLATE` is a `core/buttons` block holding one `core/button` with `tagName: 'button'`, so it never navigates. `edit.tsx` locks the template with `templateLock: 'all'`: publishers can restyle the button and change its text, but not remove it or add blocks. `save` returns the inner blocks' content.

## The block is the switch

- **`Rolling_Coverage_Block::checks_on_request()`** is true when the feed is uncapped, the coverage isn't archived, and the layout's header or footer holds the block at any depth (`holds_block()` over `layout_parts()`). The wrapper then carries `data-new-entries="button"`.
- **`Entry_Bindings::is_coverage_item()`** counts the block as coverage-level, so `layout_parts()` places it in the header or footer and it renders once, not in each entry.
- **`render_coverage_blocks()`** drops the block when the feed checks on its own (capped, archived), as it drops a Follow Coverage block that can't render.
- **`Check_Updates_Block::render_block()`** wraps the inner button in the block's wrapper, rendered `hidden` for the view script to show, so a page without the script never shows a button that does nothing. Without the coverage ID in its context, that is outside a Rolling Coverage block, and in syndication feeds, it renders nothing.

## Editor

- `ALL_ALLOWED_BLOCKS` (`layout.ts`) lets the block into the Feed group. `feed-insertion.ts` keeps it, like Follow Coverage, out of entries and the pinned card.
- `isCoverageItem()` (`template.ts`) matches it, as the server does.
- `edit.tsx` hides the block in previews where the site drops it (`isCheckUpdatesHidden`: capped or archived), and hides Poll interval when the layout holds it (`holdsBlockType()`).

## View script

In `src/blocks/rolling-coverage/view.ts`, a feed with `data-new-entries="button"` (`checksOnRequest`):

- Never schedules a poll (`schedulePoll()` returns early), nor polls when the tab shows.
- Inserts polled entries at once, rather than queueing them behind the "N New Entries" control.
- Reveals the block and runs one `poll()` per press. `poll()` returns a `PollOutcome`: `ok`, `failed`, `reloading` or `skipped`.
- Reads `insertedCount` before and after to tell "No New Entries" from new ones. An overflow always reloads in this mode, bypassing the 60-second guard, and the button stays busy until the page goes.
- Waits out `minPollInterval` between presses, and for a hidden tab to show.
- Hides the block once a poll reports the coverage `archived`, not `paused`, which can resume. Focus moves to the entries only if the button had it.
- Leaves the block hidden in a shared-entry view. After Jump to Latest swaps in the live feed, `initBlock()` runs again and reveals it.

With no polls, Coverage Status blocks (`POLL_EVENT`) and `hideWhenEnded` update only on a press, and a shared view's newer count stays as rendered.

## Tests

`tests/test-check-updates.php` covers the switch: a feed without the block, a feed with it (attribute, hidden wrapper before the entries), a capped feed and an ended coverage (no attribute, block dropped), and the block outside a feed. The plugin has no JS tests.
