# Check for Updates

A button readers press to load a liveblog's new entries. A Rolling Coverage feed that holds it stops checking for new entries on its own, so the page makes no background requests. That suits readers on slow or metered connections, and pages people leave open for hours.

## Where to put it

Add it to a Rolling Coverage block's layout, among the blocks that sit above or below the entries, such as the header next to the Live badge or a footer. The header works best: new entries appear at the top of the feed, next to it. It can't go inside an entry or the pinned card, and the inserter only offers it inside a Rolling Coverage block. It is not part of any built-in layout, so add it yourself. A layout can hold more than one, for example one above and one below a long feed; they share each check.

The block is in the inserter's Newspack category, or Rolling Coverage on sites without Newspack.

Adding the block is what switches the feed: with it, the feed checks only when a reader presses the button, and the Rolling Coverage block's Poll interval setting goes away. Remove the block and the feed checks on its own again.

It doesn't appear:

- **In a feed that shows only the latest entries** (Show set to Latest, as in the Ticker and Flash layouts). The feed keeps checking on its own.
- **Once the liveblog ends.** A paused liveblog keeps it, so readers can see whether the liveblog has resumed.

## The button

The block holds a standard Button block. Style it, change its text, and set its colors and size like any other button. The button is part of the block and rebuilds itself when the editor reloads, so leave it in place.

## What readers see

- Pressing the button checks for new entries. While it runs, the button reads "Checking…" and dims slightly.
- New entries appear at the top of the feed, below any pinned entries, and edits and removals apply at the same time. The button reads how many arrived for a few seconds, for example "2 entries added", and screen readers hear "2 new entries added".
- When nothing is new, the button reads "No new entries" for a few seconds. When the check fails, it reads "Couldn’t check", and screen readers hear "Couldn’t check for updates. Try again."
- After a large burst of changes, the page reloads to show them all.
- If the site sets a minimum poll interval, a second press waits until it has passed. A press in a background tab runs once the tab is back in view.
- "Entries" follows the entry name set in Rolling Coverage > All Liveblogs > Settings, for example "No new updates".

Because the page doesn't check on its own, these update only when a reader presses the button:

- A Coverage Status block beside the feed.
- The notice at the top of the feed when the liveblog ends.
- The feed's Hide when ended setting.

An [Update Timer](../update-timer/README.md) block for the liveblog stays hidden, as there is no check to count down to.

On a shared entry's page, the button appears once the reader jumps to the live feed, and the count of newer entries stays as the page loaded it.
