# Follow Coverage

A button that lets readers get alerts for a liveblog. Readers who turn alerts on get a push notification when an entry that sends one is published. See [Sending notifications](#sending-notifications).

## Requirements

Push notifications go through the OneSignal plugin (version 3). It needs an App ID and a REST API key configured. Without them, the block is hidden on the site, and the editor shows "Push notifications aren't set up, so this button won't appear on the site."

## Where to put it

Place it near the headline and the Live badge of a liveblog page or story. It is not part of any built-in Rolling Coverage layout, so add it yourself.

- **Outside a Rolling Coverage block.** Add it to a page or template. It follows the liveblog you pick, or the liveblog of the page.
- **Inside a Rolling Coverage block.** Add it to the Feed or to a group at the liveblog level, such as a footer. It follows that block's liveblog. It can't go inside an entry or the pinned card.

The block is in the inserter's Newspack category, or Rolling Coverage on sites without Newspack.

## Settings

The Liveblog panel appears only outside a Rolling Coverage block.

| Setting | What it does |
| --- | --- |
| Automatic | "Follows the liveblog on this page, or a breakout post’s liveblog." |
| Custom | "Always follows this liveblog." Pick the liveblog from the list. |

Automatic uses the first Rolling Coverage block in this post or page's content that shows every entry, else, on a breakout post, the liveblog the post came from, else the first one that shows only the latest entries, not one placed in the site's template. It works on single posts and pages only, not on archives or password-protected posts. With no liveblog to follow, the button doesn't appear.

If a liveblog you chose is deleted, the block falls back to the page's liveblog. The editor shows a notice.

The Settings panel shows up only when something stops the button from appearing: push notifications aren't set up, or the liveblog has ended.

## The button

The block holds a standard Button block. Style it, change its text, and set its colors and size like any other button. The button's text replaces "Get alerts". Once alerts are on, it reads "Alerts on" with a check, and "Stop alerts" when a reader hovers over it or moves keyboard focus to it. Those two labels can't be changed.

The button keeps the width of its longest label, so a heading or text beside it doesn't move when the label changes.

The button is part of the block and rebuilds itself when the editor reloads, so leave it in place.

## What readers see

- The button reads Get alerts. While the browser and push service respond, it shows a small spinner for at least a second. Then it reads Alerts on, with a check.
- A notice confirms the change: "Alerts on" or "Alerts off". Screen readers hear which liveblog: "You’ll get alerts for “Kettle River flood”." or "Alerts for “Kettle River flood” are off." On a page with buttons for more than one liveblog, the notice shows that full sentence too. On a site without the Newspack plugin, screen readers announce it and nothing appears on screen.
- Hovering over Alerts on, or moving keyboard focus to it, shows Stop alerts. Selecting it turns the alerts off. Right after alerts go on, the button keeps reading Alerts on until the pointer or focus moves away.
- In a Rolling Coverage feed, the button shrinks slightly while it is pressed with a mouse, unless the reader's device is set to reduce motion.
- The browser asks for permission to send notifications the first time.
- If the reader closes the browser's prompt without answering, the button returns to Get alerts with no message.
- If the reader has blocked notifications, the button returns to Get alerts and an error notice appears: "Notifications are blocked in your browser. Allow them in your browser's site settings, then try again."
- If something fails, the button returns to its previous state and an error notice appears: "Something went wrong. Please try again."
- An error notice stays until the reader closes it. On a site without the Newspack plugin, error messages appear under the button instead.
- Once the liveblog ends, the button no longer appears when the page loads.
- The button doesn't appear on Lite Site's text-only pages.

## Sending notifications

A notification goes out when an entry is published and one of these is true:

- **Notify subscribers when this entry publishes** is ticked in the entry's Push Notifications panel, in the entry editor's sidebar. The panel shows on entries that aren't published yet, and only when OneSignal is set up.
- The entry came from Slack. Slack entries are included automatically, unless they contain only an image.

Only readers who followed that liveblog are notified. The notification links to the entry on the liveblog's canonical URL, so the liveblog needs one. If it doesn't, the Push Notifications panel warns you and no notification is sent. An entry notifies once.

The notification shows the entry's title, or the liveblog name for an untitled entry, and the entry's excerpt or first words, leaving out content shown only to signed-in readers. An entry behind a content gate or a password shows "Read the latest update." in place of its words.

An entry that goes live from a schedule notifies at once. An entry published from the entry editor, with Publish in a liveblog's entries list, or from Slack notifies on the site's next scheduled-task run.

## Limits

- Readers must use a browser that supports push notifications and must allow them.
- The button follows one liveblog. Add a second block for another liveblog.
