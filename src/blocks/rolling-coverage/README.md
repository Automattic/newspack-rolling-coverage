# Rolling Coverage block

The Rolling Coverage block shows the entries of a coverage on any page or story. New entries arrive on the page while readers are looking at it, and older entries load as they scroll. Use it to run a live blog, a developing-story box, or a headline ticker.

## Add the block

1. In the editor, add the Rolling Coverage block (Newspack category, or Rolling Coverage on sites without Newspack).
2. In the placeholder, search for a coverage under "Search for a coverage…" and select it.
3. Select Choose and pick a layout from "Choose a layout". See [Layouts](#layouts).

The entries of the coverage appear in the editor as a preview. If the coverage has no published entries yet, the block shows the layout on its own, with a notice.

You can change the coverage later in the Coverage panel of the block's settings.

## Layouts

A layout is how the feed looks: spacing, colors, type, and which parts each entry shows. The picker lists the built-in layouts first, then any custom layouts. Each card shows a preview with sample entries.

| Layout | What it looks like |
| --- | --- |
| Bulletin | The default. Entries stacked with their time, title, content, and links. |
| Stream | Like Bulletin, with more space between entries without titles. |
| Rail | Entries hang off a timeline. |
| Clock | Each entry starts with the time it was posted. |
| Margin | Each entry split into a margin and its content. |
| Minute | Closer together, with each entry reduced to its content. |
| Byline | Each entry signed by its author. |
| Ticker | A strip with the coverage's status and name beside the three latest headlines, and a link to the coverage page. Wide width. Hides when the coverage ends. |
| Split | The full feed at wide width, with the pinned entry's summary in a column beside the entries. |
| Wire | A narrow list of the five latest entries, ending in a link to the coverage page. |
| Digest | A bordered box with the coverage name, the three latest entries next to their times, and a link to the coverage page. |
| Flash | A full-width bar in the site's accent color with the latest entry, its time, and a link to the coverage page. Hides when the coverage ends. |

Ticker, Wire, Digest, and Flash show only the latest entries. They set Show to Latest and a matching Number of entries when you pick them. You can change either afterward.

The first time anyone picks a built-in layout, the site saves it as a shared layout. Every story using it then follows that one copy. See [Edit, detach, and change a layout](#edit-detach-and-change-a-layout).

## Settings

Select the block to open its settings in the sidebar. Some panels appear only when they apply.

### Layout

Says whether the block uses the shared layout or its own detached copy.

| Setting | What it does |
| --- | --- |
| Change Layout | Opens "Choose a layout" to pick another layout. The block's coverage and other settings stay. |

### Coverage

| Setting | What it does |
| --- | --- |
| Coverage | The coverage the block shows. |
| Canonical URL | The page readers land on when they open a link to one of this coverage's entries. It is shared by every block connected to this coverage. Shown once a coverage is selected. |
| Use This Page | Fills Canonical URL with this page's address. Save the page first to get its address. Hidden when Show is set to Latest. |

### Entries

| Setting | What it does |
| --- | --- |
| Show | All shows every entry and loads more as readers scroll. Latest shows only the most recent entries. |
| Number of entries | With Show set to Latest: how many entries to show, from 1 to 100. |
| Link to all updates | With Show set to Latest: Show or Hide the link to the coverage page. The link is hidden on the coverage page itself. |
| Entries per page | With Show set to All: how many entries load first, and how many each scroll adds. From 1 to 100. Default 20. |
| Poll interval (seconds) | How often the page checks for new entries. Default 10. The site can set a longer minimum, which wins over a shorter value here. |

### Ended

The panel title shows the site's label for an ended coverage ("Ended" unless the site changed it). It controls what the block does once the coverage's status is set to Ended in All Coverages.

| Setting | What it does |
| --- | --- |
| When ended | Show keeps the block on the page. Hide removes the whole block. |
| Notice | Show or Hide a notice at the top of the feed telling readers the coverage has ended. |
| Notice text | The notice wording. When empty, the block says the coverage has concluded and the feed is archived. |
| Link | Show or Hide a link in the notice that points readers to where the story continues. |
| URL | Where the link goes. When empty, it goes to the coverage's latest breakout post, if there is one. |
| Link text | The link's wording. When empty, "Read more". |

Notice, Link, URL, and Link text appear only when When ended is set to Show.

### AI

Appears when AI is set up on the site. Generate Key Takeaways writes a summary of the coverage's entries into Generated Output. Copy puts it on your clipboard. Site administrators set the prompts on the AI settings page.

### Ads

Appears when Newspack Ads is active.

| Setting | What it does |
| --- | --- |
| Advertising | Enabled or Disabled. Shows ads between entries. Not available when Show is set to Latest. |
| Ads interval | Show an ad after every N entries. Up to 3 ads in the first load and in each load of older entries. New entries are not capped. |

Ads need the Rolling Coverage: Entry placement enabled in Newspack Ads, and ads enabled in the coverage's own settings. The panel tells you when either is missing.

Alignment and the HTML anchor are in the block toolbar and the Advanced section like any other block. Colors, type, and spacing of the feed come from the layout. See below.

## What readers see

### Live updates

The page checks for new entries every poll interval. It stops while the tab is in the background and checks again when the reader returns.

- If the reader is at the top, new entries appear at once.
- If the reader has scrolled down, the page does not move. A "New Posts" button shows the count (for example "3 New Posts"). Selecting it brings the new entries in. Edited entries update in place.
- Older entries load as the reader scrolls to the end.
- A paused or ended coverage does not check for new entries.
- Times follow the site's time format.

Feeds that show only the latest entries update in place without the button: the newest entry appears and the oldest drops off. They have no ads and no infinite scroll.

When a reader opens a link to one entry, the feed opens at that entry and shows a control that takes them to the live feed, with the number of newer posts when there are any.

### Pinned entries

An entry pinned in the coverage stays at the top of the feed with a "Pinned" label, whatever its date. Some layouts keep the pinned entry in view while the reader scrolls.

Feeds set to Latest ignore pinning and show the newest entries only.

### Live blog markup

A block that shows all entries counts as the coverage's live feed on its page, including the live blog markup search engines read. A block set to Latest never does.

## Edit, detach, and change a layout

When a block uses a shared layout, the block toolbar shows Edit Layout and Detach.

- **Edit Layout** opens the shared layout in a new tab (the Site Editor on block themes, the pattern editor otherwise). Editing it changes every story that uses it. Entries in that editor are samples. Only people who can edit patterns see this button.
- **Detach** copies the layout into this story. From then on, changes you make to this block affect this story only, and changes to the shared layout no longer reach it. The Layout panel reads "Uses its own layout, detached from the shared one."
- **Change Layout** in the Layout panel opens "Choose a layout". Picking another layout replaces the current one, including a detached copy.

If a story points at a layout that was deleted, readers see the Bulletin layout, and the editor asks you to Choose a layout again.

## Restore a built-in layout

Built-in layouts are saved as patterns, so editing one changes it for good. To bring one back:

- **Restore the first version.** Open the pattern, open Revisions, and restore the oldest one. WordPress saved it when the plugin created the layout, so it is the layout as first created.
- **Rebuild it from the plugin.** Move the pattern to the trash, then delete it permanently, then pick the layout again. The site recreates it from the plugin's current version. Use this after a plugin update to get the updated design. Stories using the deleted layout show Bulletin until they pick a layout again.

## Make a custom layout

<!-- CUSTOM LAYOUT FLOW: pending live test -->

A custom layout is a synced pattern in the Rolling Coverage pattern category, holding one Rolling Coverage block. Anything saved that way appears in "Choose a layout" after the built-in layouts, sorted by title. The picker lists up to 100 published patterns from that category.

Draft steps, to be confirmed:

1. Open the pattern editor.
   - Block themes: Site Editor, then Patterns.
   - Classic themes have no Patterns menu. Go to `wp-admin/edit.php?post_type=wp_block` and select Add New. There is no Duplicate action there.
2. Create a new pattern. Turn on Synced. Add it to the Rolling Coverage category. The category exists once any built-in layout has been picked.
3. Build the layout inside one Rolling Coverage block, using blocks such as Group, Post Title, Post Content, and Post Date. Style them in the editor with the block settings.
4. Save. Open a story, select Change Layout, and pick the new layout.

On block themes you can instead duplicate a built-in layout from the Patterns screen and edit the copy.

Notes for building a layout:

- Read more links to the entry's breakout post and only shows on entries that have one. Share links to the entry itself. Both are parts of an entry in the built-in layouts; remove them from the layout if you do not want them.
- Follow Coverage is not part of the built-in layouts.

<!-- END CUSTOM LAYOUT FLOW -->
