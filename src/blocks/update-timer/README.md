# Update Timer

Shows readers when a live coverage next checks for new entries, and what each check found. A small ring drains as the next check gets closer, next to "Next check in 7s". While the check runs, the ring spins and reads "Checking…". Then for a few seconds it reads "2 new entries", "No new entries" or "Couldn’t check", and the countdown starts again.

## Where to put it

- **Next to the Live badge.** Add it to a page or template, beside a Coverage Status block, so readers can see the page keeps itself up to date.
- **Inside a Rolling Coverage block.** Add it to the Feed or to a group at the coverage level, such as the layout's header. It follows that block's coverage, and the Coverage panel is hidden. It can't go inside an entry or the pinned card.

The block is in the inserter's Newspack category, or Rolling Coverage on sites without Newspack.

## Which coverage it follows

The Coverage panel appears only outside a Rolling Coverage block.

| Setting | What it does |
| --- | --- |
| Automatic | "Counts down to the next check of the coverage on this page." Uses the first Rolling Coverage block in this post or page's content, or a breakout post's coverage. |
| Custom | "Always counts down for this coverage." Pick the coverage from the list. |

The timer counts down a Rolling Coverage block's own checks, so it appears only when a Rolling Coverage block for the same coverage is on the page. If a coverage you chose is deleted, the block falls back to the page's coverage, or stays hidden if the page has none. The editor shows a notice.

## When it doesn't appear

It stays hidden while there is nothing to count down to:

- The coverage is paused or has ended. If it pauses while the page is open and goes live again, the timer comes back.
- The Rolling Coverage block holds a [Check for Updates](../check-updates/README.md) button, so it checks only when readers ask.
- The reader has switched to another tab. When they come back, the page checks at once and the timer shows it.
- There is no Rolling Coverage block for its coverage on the page.

It doesn't appear on Lite Site's text-only pages.

## Styling

The ring and the text take the block's text color. Typography settings change the text size, and the ring scales with it. The Dimensions settings control margin.

## What readers see

- The seconds count down once a second. The ring drains smoothly, or in one-second steps for readers who ask their device for reduced motion. While a check runs, the ring stops spinning for those readers.
- "Entries" follows the entry name set in Rolling Coverage > All Coverages > Settings, for example "2 new updates".
- Screen readers don't hear the countdown. The Rolling Coverage block already announces new entries.
- If the site sets a minimum poll interval longer than the block's Poll interval, the timer counts down the longer one, as the page does.
