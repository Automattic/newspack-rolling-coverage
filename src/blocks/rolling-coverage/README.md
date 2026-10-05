# Rolling Coverage block

The Rolling Coverage block shows the entries of a coverage on any page or story. New entries arrive on the page while readers are looking at it, and readers can load older entries as they go. Use it to run a live blog, a developing-story box, or a headline ticker.

## Add the block

1. In the editor, add the Rolling Coverage block (Newspack category, or Rolling Coverage on sites without Newspack).
2. In the placeholder, type in the "Search for a coverage…" field and select a coverage.
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
| Ticker | A strip with the coverage's status and name beside the three latest headlines, and a link to the coverage page, with a thin rule between them. The Feed's Block spacing sets the space on either side of a rule. Wide width. Hides when the coverage ends. |
| Split | The full feed at wide width, with the pinned entry's summary in a column beside the entries. |
| Wire | A narrow list of the five latest entries, ending in a link to the coverage page. |
| Digest | A bordered box with the coverage name, the three latest entries next to their times, and a link to the coverage page. |
| Flash | A full-width bar in the site's accent color with the latest entry, its time, and a link to the coverage page. Hides when the coverage ends. |

Ticker, Wire, Digest, and Flash show only the latest entries. They set Show to Latest and a matching Number of entries when you pick them. You can change either afterward.

The first time someone who can publish picks a built-in layout, the site saves it as a shared layout. Every story using it then follows that one copy. A Contributor who picks one before that gets a copy for that story only. See [Edit, detach, and change a layout](#edit-detach-and-change-a-layout).

You can also insert a layout from the inserter's Patterns tab, in the Rolling Coverage category. It becomes a Rolling Coverage block that uses that layout and asks for a coverage.

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
| Canonical URL | The page readers land on when they open a link to one of this coverage's entries. It is shared by every block connected to this coverage. Saved to the coverage when you save the page. Editors and administrators can change it. Shown once a coverage is selected. |
| Use This Page | Fills Canonical URL with this page's address. Save the page first to get its address. Hidden when Show is set to Latest. |

### Entries

| Setting | What it does |
| --- | --- |
| Show | All shows every entry, one page at a time. Latest shows only the most recent entries. |
| Older entries | With Show set to All: what happens after the first page. Load on scroll (default) loads the next page as readers reach the end of the feed. Load More button shows a Load More button below the entries; each press adds a page, and the button goes away once every entry is shown. Don’t load shows the first page only. |
| Number of entries | With Show set to Latest: how many entries to show, from 1 to 100. |
| Link to all updates | With Show set to Latest, in layouts that have one (Ticker, Wire, Digest, Flash): Show or Hide the link to the coverage page. The link is hidden on the coverage page itself. |
| Entries per page | With Show set to All: how many entries show first, and how many each load of older entries adds. From 1 to 100. Default 20. |
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

Notice appears only when When ended is set to Show. Notice text and Link appear only when Notice is set to Show, and URL and Link text only when Link is set to Show.

### AI

Appears when AI is set up on the site. Generate Key Takeaways writes a summary of the coverage's entries into Generated Output. Copy copies it. Editors and administrators set the prompts under Rolling Coverage > AI.

### Ads

Appears when Newspack Ads is active.

| Setting | What it does |
| --- | --- |
| Advertising | Enabled or Disabled. Shows ads between entries. Not available when Show is set to Latest. |
| Ads interval | Show an ad after every N entries, up to 3 ads across the first load and older entries combined. New entries that arrive while the page is open are not capped. |

Ads need the Rolling Coverage: Entry placement enabled in Newspack Ads, and ads enabled in the coverage's own settings. The panel tells you when either is missing.

Alignment and the HTML anchor are in the block toolbar and the Advanced section like any other block. Colors, type, and spacing of the feed come from the layout. See [Edit, detach, and change a layout](#edit-detach-and-change-a-layout).

## What readers see

### Live updates

The page checks for new entries every poll interval. It stops while the tab is in the background and checks again when the reader returns.

- If the reader is at the top, new entries appear at once.
- If the reader has scrolled down, the page does not move. A "New Posts" button shows the count (for example "3 New Posts"). Selecting it brings the new entries in. Edited entries update in place.
- Older entries load as the reader scrolls to the end, or with the Load More button, depending on Older entries. The button takes the theme's button style. If a load fails, the button stays so the reader can try again.
- A paused or ended coverage does not check for new entries.
- Times follow the site's time format.

Feeds that show only the latest entries update in place without the New Posts button: the newest entry appears and the oldest drops off. They have no ads and load no older entries.

When a reader opens a link to one entry, the feed opens at that entry and shows a control that takes them to the live feed, with the number of newer posts when there are any.

### Pinned entries

An entry pinned in the coverage stays at the top of the feed with a "Pinned" label, whatever its date. Some layouts keep the pinned entry in view while the reader scrolls.

Feeds set to Latest ignore pinning and show the newest entries only.

### Live blog markup

A block that shows all entries counts as the coverage's live feed on its page, including the live blog markup search engines read. A block set to Latest never does.

## Edit, detach, and change a layout

When a block uses a shared layout, the block toolbar shows Edit Layout and Detach.

- **Edit Layout** opens the shared layout in a new tab (the Site Editor on block themes for people who can edit the site's design, the pattern editor otherwise). Editing it changes every story that uses it. The editor previews this story's coverage, or sample entries when the coverage has none yet. Only people who can edit patterns see this button.
- **Detach** copies the layout into this story. From then on, changes you make to this block affect this story only, and changes to the shared layout no longer reach it. The Layout panel reads "Uses its own layout, detached from the shared one."
- **Change Layout** in the Layout panel opens "Choose a layout". Picking another layout replaces the current one, including a detached copy.

If a story points at a layout that was deleted, readers see the Bulletin layout, and the editor asks you to Choose a layout again.

## Restore a built-in layout

Built-in layouts are saved as patterns, so editing one changes it for good. To get the plugin's version back, remove the pattern, reload the editor, and pick the layout again. On block themes, use Delete in the Site Editor's Patterns, which can't be undone; on classic themes, use Trash on the Patterns screen (`wp-admin/edit.php?post_type=wp_block`). The site recreates the layout from the plugin's current version, which is also how to get an updated design after a plugin update. Stories using the removed layout show Bulletin until they pick a layout again.

## Make a custom layout

A custom layout is a synced pattern in the Rolling Coverage pattern category whose only top-level block is a Rolling Coverage block. Published patterns in that category appear in "Choose a layout" after the built-in layouts, sorted by title. The picker reads up to 100 patterns from the category, built-in ones included.

The Rolling Coverage category appears once someone picks a built-in layout in a story. If it isn't there yet, pick a layout in any story first, then open the pattern editor. If you create the Rolling Coverage category yourself instead, save the pattern and reload the editor before you add the Rolling Coverage block.

Set the category before you add the Rolling Coverage block. With the category set, the block opens as an editable layout with sample entries, starting from Bulletin. Without it, the block asks for a coverage instead. Don't pick a coverage inside a pattern, and don't add other blocks beside the Rolling Coverage block: the pattern then no longer counts as a layout.

### Block themes

1. Go to Appearance > Editor > Patterns. Select Add Pattern, then Add Pattern in the menu.
2. Enter a Name. In Categories, pick Rolling Coverage. Leave Synced on. Select Add.
3. Add a Rolling Coverage block and edit the layout with the block settings.
4. Select Save.

To start from a built-in layout instead, open the Rolling Coverage category in Patterns, open a layout's Actions menu (⋮) and select Duplicate. The copy keeps the category and stays synced. Rename it and select Duplicate.

### Classic themes

1. Go to the Patterns screen (`wp-admin/edit.php?post_type=wp_block`) and select Add Pattern.
2. Enter a Name, leave Synced on, and select Create.
3. In the Settings sidebar, under Pattern Categories, add Rolling Coverage. The category is only in the sidebar, not in the Create pattern dialog.
4. Add a Rolling Coverage block and edit the layout with the block settings.
5. Select Publish.

### Use it

In a story, add or select a Rolling Coverage block, open "Choose a layout" (Choose, or Change Layout in the Layout panel) and pick your layout. It is listed after Flash. If it isn't listed, reload the story's editor.

Things to know:

- Picking a built-in layout also changes block settings: Ticker, Wire, Digest, and Flash set Show to Latest and Number of entries; Ticker, Split, and Flash set the alignment; Ticker and Flash set When ended to Hide. A custom layout, including a copy of a built-in one, changes none of them, and the block keeps its current values. Set them on the block in the story.
- Read more links to the entry's breakout post and only shows on entries that have one. Share links to the entry itself. Both are parts of an entry in the built-in layouts. To remove Share, delete it. Read more is locked: select it, open Options (⋮) > Unlock, clear Lock removal, select Apply, then delete it.
- Follow Coverage is not part of the built-in layouts.
