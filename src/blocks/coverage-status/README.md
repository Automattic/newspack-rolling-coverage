# Coverage Status

Shows whether a coverage is Live, Paused, or Ended, as a small badge. It can also show when the coverage last got a new entry, as in "Updated 2 minutes ago".

## Where to put it

- **Next to a headline.** Add it to a page or template, beside the title or in a header, so readers see at a glance whether the story is still moving.
- **Inside a Rolling Coverage block.** Add it to the block's layout. It shows that block's coverage, and the Coverage panel is hidden.

The block is in the inserter's Newspack category, or Rolling Coverage on sites without Newspack.

## Which coverage it shows

The Coverage panel is at the top of the block's settings. It appears only when the block is outside a Rolling Coverage block.

| Setting | What it does |
| --- | --- |
| Automatic | "Shows the coverage on this page, or a breakout post’s coverage." Uses the first Rolling Coverage block in this post or page's content, not one placed in the site's template. On a breakout post, uses the coverage the post came from. |
| Custom | "Always shows this coverage." Pick the coverage from the list. |

Automatic works on single posts and pages only, not on archives or password-protected posts. It ignores a Rolling Coverage block that only previews the latest entries. With no coverage to show, the block shows nothing on the site.

If a coverage you chose is deleted, the block falls back to the page's coverage. The editor shows a notice.

With Automatic in a template, the editor shows a sample Live badge, because the page isn't known there.

## Settings

All settings are in the Settings panel.

| Setting | Options | What it does |
| --- | --- | --- |
| Labels | Default, Custom | Default uses the site-wide labels. Custom shows a text field for each status: Live label, Paused label, and Ended label. A field left empty uses the site-wide label. |
| Last updated | Show, Hide | Shows "Updated … ago" next to the badge. It appears only while the coverage is live and has at least one published entry. Hide is the default. |
| When ended | Show, Hide | Hide removes the block from the site once the coverage ends. The editor shows a notice when this applies. Show is the default. |
| Dot | Show, Hide | Turns the dot on the Live badge on or off. Paused and Ended badges have no dot. Show is the default. |

Site-wide labels are set in Rolling Coverage > All Coverages, under the Settings button. Each label can be up to 40 characters. A label set on a block overrides the site-wide one.

## Styling

- **Badge colors.** In the Styles tab, under Color, set Live background, Paused background, and Ended background. The text color is chosen automatically for contrast.
- **Text color.** The Text color setting changes the "Updated … ago" text. New blocks start with a muted color from the theme palette when it has one.
- **Spacing.** The Dimensions settings control margin. On themes that support block spacing, they also control the gap between the badge and the "Updated … ago" text, which defaults to the theme's small spacing step. Other themes, including the Newspack Theme, use WordPress's standard gap.

## What readers see

A badge that reads Live, Paused, or Ended, and optionally the "Updated … ago" text. Live badges pulse when the dot is on.

The badge changes without a page reload, but only when a Rolling Coverage block for the same coverage is on the same page. A Custom block pointing to a coverage that isn't on the page keeps the status it had when the page loaded. The "Updated … ago" text counts up once a minute either way.

On Lite Site's text-only pages, the badge shows the status from when the page was saved for Lite Site, and "Updated … ago" doesn't appear.

## Limits

- It shows one coverage at a time.
- Updates need a Rolling Coverage block for the same coverage on the page, and that block must have been Live when the page loaded.
- Archive pages never get an Automatic coverage. Choose Custom there.
