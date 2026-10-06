# Follow Coverage

A button that lets readers follow a coverage. Followers get a push notification when an entry that sends one is published. See [Sending notifications](#sending-notifications).

## Requirements

Push notifications go through the OneSignal plugin (version 3). It needs an App ID and a REST API key configured. Without them, the block is hidden on the site, and the editor shows "Push notifications aren't set up, so this button won't appear on the site."

## Where to put it

Place it near the headline and the Live badge of a coverage page or story. It is not part of any built-in Rolling Coverage layout, so add it yourself.

- **Outside a Rolling Coverage block.** Add it to a page or template. It follows the coverage you pick, or the coverage of the page.
- **Inside a Rolling Coverage block.** Add it to the Feed or to a group at the coverage level, such as a footer. It follows that block's coverage. It can't go inside an entry or the pinned card.

The block is in the inserter's Newspack category, or Rolling Coverage on sites without Newspack.

## Settings

The Coverage panel appears only outside a Rolling Coverage block.

| Setting | What it does |
| --- | --- |
| Automatic | "Follows the coverage on this page, or a breakout post’s coverage." |
| Custom | "Always follows this coverage." Pick the coverage from the list. |

Automatic uses the first Rolling Coverage block in this post or page's content, not one placed in the site's template, and ignores a block that only previews the latest entries. It works on single posts and pages only, not on archives or password-protected posts. With no coverage to follow, the button doesn't appear.

If a coverage you chose is deleted, the block falls back to the page's coverage. The editor shows a notice.

The Settings panel shows up only when something stops the button from appearing: push notifications aren't set up, or the coverage has ended.

## The button

The block holds a standard Button block. Style it, change its text, and set its colors and size like any other button. The button's text replaces "Follow". Once a reader follows, it reads "Following", which can't be changed. Readers click it again to unfollow.

The button is part of the block and rebuilds itself when the editor reloads, so leave it in place.

## What readers see

- The button reads Follow, then Following after they follow.
- The browser asks for permission to send notifications the first time.
- If the reader has blocked notifications, the button returns to Follow and a message appears: "Notifications are blocked in your browser. Allow them in your browser's site settings, then try again."
- If something fails, the button returns to its previous state and a message appears: "Something went wrong. Please try again."
- Once the coverage ends, the button no longer appears when the page loads.

## Sending notifications

A notification goes out when an entry is published and one of these is true:

- **Notify subscribers when this entry publishes** is ticked in the entry's Push Notifications panel, in the entry editor's sidebar. The panel shows on entries that aren't published yet, and only when OneSignal is set up.
- The entry came from Slack. Slack entries are included automatically, unless they contain only an image.

Only readers who followed that coverage are notified. The notification links to the entry on the coverage's canonical URL, so the coverage needs one. If it doesn't, the Push Notifications panel warns you and no notification is sent. An entry notifies once.

An entry that goes live from a schedule, or is published outside the editor (for example with Quick Edit), notifies at once. An entry published from the entry editor or from Slack notifies on the site's next scheduled-task run. The plugin's entries list doesn't publish entries.

## Limits

- Readers must use a browser that supports push notifications and must allow them.
- The button follows one coverage. Add a second block for another coverage.
