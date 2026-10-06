# Check for Updates

A button readers press to load a coverage's new entries. A Rolling Coverage feed that holds it stops checking for new entries on its own, so the page makes no background requests. That suits readers on slow or metered connections, and pages people leave open for hours.

## Where to put it

Add it to a Rolling Coverage block's layout, among the blocks that sit above or below the entries, such as the header next to the Live badge or a footer. It can't go inside an entry or the pinned card. It is not part of any built-in layout, so add it yourself.

The block is in the inserter's Newspack category, or Rolling Coverage on sites without Newspack.

Adding the block is what switches the feed: with it, the feed checks only when a reader presses the button, and the Rolling Coverage block's Poll interval setting goes away. Remove the block and the feed checks on its own again.

It does nothing elsewhere:

- **Outside a Rolling Coverage block** it doesn't appear on the site.
- **In a feed that shows only the latest entries** (Show set to Latest, as in the Ticker and Flash layouts) it doesn't appear, and the feed keeps checking on its own.
- **Once the coverage ends** it doesn't appear.

## The button

The block holds a standard Button block. Style it, change its text, and set its colors and size like any other button. The button is part of the block and rebuilds itself when the editor reloads, so leave it in place.

## What readers see

- Pressing the button checks for new entries. While it runs, the button reads "Checking…".
- New entries appear at the top of the feed, below any pinned entries, and edits and removals apply at the same time. Screen readers hear how many arrived, for example "2 new entries added".
- When nothing is new, the button reads "No New Entries" for a few seconds. When the check fails, it reads "Couldn't Check", and screen readers hear "Couldn't check for updates. Try again."
- After a large burst of changes, the page reloads to show them all.
- If the site sets a minimum poll interval, a second press waits until it has passed. A press in a background tab runs once the tab is back in view.
- "Entries" follows the entry name set in Rolling Coverage > All Coverages > Settings, for example "No New Updates".

Because the page doesn't check on its own, these update only when a reader presses the button:

- A Coverage Status block beside the feed.
- The feed's Hide when ended setting.

On a shared entry's page, the button appears once the reader jumps to the live feed, and the count of newer entries stays as the page loaded it.
