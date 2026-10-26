# New Label

Marks an entry that arrives while the reader has the page open. The label shows on that entry until the reader has seen it, then fades away. Entries already on the page when it loads, entries brought in by the page's first check that works (on most feeds; see [Live updates](../rolling-coverage/README.md#live-updates)), older entries that load later, and entries published again never show it.

## Where to put it

Place it inside an entry in a Rolling Coverage layout: the Pinned Entry, the Entry or the Full Story Entry. Every built-in layout except Alert holds one in each. The inserter only offers it inside a Rolling Coverage block, and among the blocks above or below the entries it shows nothing.

When the label fades away, nothing else in the entry should jump. On a line of its own, put it directly in a Stack. Sharing a line with other blocks in a Row, make it the last block on that line.

The block is in the inserter's Newspack category, or Rolling Coverage on sites without Newspack.

## Entries without a label

An entry whose layout holds no New Label shows no mark, and screen readers still announce it as new. To get the label back in an edited built-in layout, restore the layout (see [Restore a built-in layout](../rolling-coverage/README.md#restore-a-built-in-layout)), or add the block yourself.

## Settings

- **Text.** Type the label's text in place. Left empty, it reads "New", in the site's language.
- **Visually hidden** (in the block's Settings panel). The label is for screen readers only: readers see a new entry arrive, with no label. Minute has it on.
- **Color and Typography.** Set the text color, size, font, appearance, line height, letter spacing, letter case and alignment like any text block. The built-in layouts show it in the accent color, small and bold, in the theme's heading font; Flash shows it in the bar's text color.

## In the editor

The label shows only on the sample entry that previews the newest entry, as only new entries show it on the site. A visually hidden label doesn't show on the canvas; select it in List View.

## What readers see

See [Live updates](../rolling-coverage/README.md#live-updates) for when an entry counts as seen. The label never shows in RSS feeds or on Lite Site pages.
