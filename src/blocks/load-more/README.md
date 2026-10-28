# Load More

The button readers press to load a liveblog's older entries. It shows when a Rolling Coverage block's Older entries setting is “Load more” button, and sits wherever the layout places it.

## Where to put it

Every built-in layout that lists the full feed holds one, after the entries. Leave it there, or move it among the blocks below the entries, such as above a footer. It can't go inside an entry or the pinned card, and the inserter only offers it inside a Rolling Coverage block.

A layout without one, such as a custom layout made before the block existed, still gets a Load more button below the entries.

The block is in the inserter's Newspack category, or Rolling Coverage on sites without Newspack.

It doesn't appear:

- **When Older entries is set to Load on scroll or Don’t load.**
- **In a feed that shows only the latest entries** (Show set to Latest, as in the Ticker and Flash layouts).
- **Once every entry is shown.** The button goes away after the last page loads.

When you edit a layout pattern, it always shows, so you can style it.

## The button

The block holds a standard Buttons block with one button. Style the button, change its text, and set its colors and size like any other button. Select the Buttons block to change its justification (left, center or right) or the space above it. The button is part of the block and rebuilds itself when the editor reloads, so leave it in place.

Each built-in layout sets these to suit it:

- Stream centers the button.
- Every other layout puts it on the left, where the entries' text starts: past the time in Rail, Clock and Split, past the avatar in Byline, and in line with the entry text in Margin. On phones, where Clock, Margin and Split stack their columns, it moves to the left edge; Rail and Byline keep their columns side by side, so the button stays in line with the text.
- Rail and Minute add space above the button, so it sits as far below the last entry as in the other layouts.

A button centered or set to the right keeps that place rather than lining up with the entries' text.

## What readers see

- Each press adds the next page of older entries below the ones shown. While it loads, the button reads “Loading…” and dims slightly.
- Focus moves to the first new entry, so keyboard and screen reader users continue from there.
- If a load fails, the button stays so the reader can try again, and screen readers hear that it failed.
