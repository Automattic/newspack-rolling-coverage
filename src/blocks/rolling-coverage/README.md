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

| Layout | What it looks like | Best for |
| --- | --- | --- |
| Bulletin | The default. Entries stacked with their time, title, content, and links. | Most live blogs. |
| Stream | Like Bulletin, with more space between entries without titles. | Fast-moving events with many short updates without titles, such as a press conference or a court hearing. |
| Rail | Entries hang off a timeline. | Events where the order of updates and the gaps between them matter, such as a storm moving through. |
| Clock | Each entry starts with the time it was posted. | Coverage where the time of each update is the point, such as election results or travel delays. |
| Margin | Each entry split into a margin and its content. | Coverage with headlines readers scan down the side, such as a long-running story. |
| Minute | Closer together, with each entry reduced to its content. | Sports and other minute-by-minute coverage. Start each entry with the minute: "70' Goal! Kowalski scores from the edge of the box." |
| Byline | Each entry signed by its author. | Coverage with several reporters filing, where who reported each entry matters. |
| Ticker | A strip with the coverage's status and name beside the latest headlines, each under its time, and a link to the coverage page below, with a thin rule between them. The headlines stay on one line, whatever Number of entries is set to. Up to three share the width; more keep a minimum width, and once they don't fit the line scrolls sideways and fades at any edge with more to scroll. In a narrow space, such as a tablet or a column beside a sidebar, the status and name sit above the headlines. In the narrowest spaces, such as a phone, even three headlines scroll. A small gap (0.25em) separates the status and each time from the line below. The Feed's Block spacing sets the space on either side of a rule. Wide width. Hides when the coverage ends. | The homepage, under the header, to point readers to breaking news. |
| Split | The full feed at wide width, with the pinned entry's summary in a column beside the entries. | A coverage page that leads with a pinned summary, such as "What we know", beside the feed. |
| Wire | A narrow list of the five latest entries, ending in a link to the coverage page. | A sidebar or another narrow column. |
| Digest | A bordered box with the coverage name, the three latest entries next to their times, and a link to the coverage page. | A sidebar, or a box inside a related story that links to the main coverage. |
| Flash | A full-width bar in the site's accent color with the latest entry, its time, and a link to the coverage page, side by side at every width. The bar always stays one line. With one entry, it takes all the room; with two, each takes half; with three or more, each takes a third and the rest are hidden. Each entry keeps to one line, cut short to fit. Flash shows at most three entries at a time, whatever Number of entries or Show is set to. With Show set to Latest, "See all entries" leads to the rest. At 782px wide and below it shows one entry, and on small phones (480px and below) it also hides the time. The Live badge takes the theme's page background and text colors, and follows the theme's style variations. Change it under the Coverage Status block's Color settings. Hides when the coverage ends. | The site header, so a major breaking story shows on every page. |
| Alert | A box on the theme's secondary background color, like a pinned entry's (a light gray, or a dark gray on dark style variations), holding the coverage's status, its name, and a link to the coverage page on the right, side by side at every width. It shows no entries. The name keeps to one line, cut short to fit. The Live badge takes the theme's accent color (Primary on the classic Newspack Theme) with the text color the theme pairs with it, and follows the theme's style variations. Change it under the Coverage Status block's Color settings. Hides when the coverage ends. | Inside a related story, to point readers to the live coverage without repeating its updates. |

Ticker, Wire, Digest, and Flash show only the latest entries. They set Show to Latest and a matching Number of entries when you pick them. Alert shows no entries, but sets Show to Latest too, since the link to the coverage page only shows with Latest. You can change either afterward, except Show on Ticker and Alert: Ticker's headlines run on one line, which has no room for pinned entries or older pages, and Alert has no entries to show, so the setting is hidden while it's set to Latest. Alert hides Number of entries too.

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
| Link to all updates | With Show set to Latest, in layouts that have one (Ticker, Wire, Digest, Flash, Alert): Show or Hide the link to the coverage page. The link is hidden on the coverage page itself. It starts as "See all entries", or "See all" followed by the site's plural name for entries ("See all updates"). It is an ordinary paragraph, so you can reword it. |
| Link text | With Link to all updates set to Show, in a shared layout that has the link: this block's own wording for it, such as "Follow the storm". Leave it empty to use the layout's text, which shows in the field and below it. The layout stays shared, so later changes to it still reach this block. Detaching the layout writes the wording into the detached copy. |
| Entries per page | With Show set to All: how many entries show first, and how many each load of older entries adds. From 1 to 100. Default 20. |
| Poll interval (seconds) | Hidden when Show is set to All and the layout holds a [Check for Updates](../check-updates/README.md) block. How often the page checks for new entries. Default 10. The site can set a longer minimum, which wins over a shorter value here. |

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

Appears when AI is set up on the site. Generate Key Takeaways writes a summary of the coverage's entries into Generated Output. Copy copies it. Administrators set the prompts under Rolling Coverage > AI.

### Ads

Appears when Newspack Ads is active.

| Setting | What it does |
| --- | --- |
| Advertising | Enabled or Disabled. Shows ads between entries. Not available when Show is set to Latest. |
| Ads interval | Shows an ad after every N entries (4 by default), counting from the top of the feed with pinned entries included. Up to 3 ads appear among the entries that load with the page and the older ones readers load after them: with an interval of 4, after entries 4, 8 and 12, and none further down. New entries that arrive while the page is open get an ad after every N of them, with no limit. |

Ads need the Rolling Coverage: Entry placement enabled in Newspack Ads, and ads enabled in the coverage's own settings. The panel tells you when either is missing.

Alignment and the HTML anchor are in the block toolbar and the Advanced section like any other block. Colors, type, and spacing of the feed come from the layout. See [Edit, detach, and change a layout](#edit-detach-and-change-a-layout).

## What readers see

### Live updates

The page checks for new entries every poll interval. It stops while the tab is in the background and checks again when the reader returns.

- If the reader is at the top, new entries appear at once.
- If the reader has scrolled down, the page does not move. A button shows the count (for example "3 New Entries"). Selecting it brings the new entries in. Edited entries update in place.
- An entry that is unpublished, for example moved to draft or trashed, disappears from the page.
- To have the page check only when readers ask, add a [Check for Updates](../check-updates/README.md) block to the layout. The page then makes no background checks, which suits readers on slow or metered connections.
- Older entries load as the reader scrolls to the end, or with the Load More button, depending on Older entries. The button takes the theme's button style. If a load fails, the button stays so the reader can try again.
- A tab left open for a long time, or a page served from a cache, after the feed's layout or settings changed many times reloads when new or older entries arrive, so they show in the feed's own layout. If the reload lands on the same cached copy, it tries again a minute later.
- A paused or ended coverage does not check for new entries on its own. With a Check for Updates block, readers can still check a paused coverage.
- Times follow the site's time format.

Feeds that show only the latest entries update in place without that button: the newest entry appears and the oldest drops off. They have no ads and load no older entries.

When a reader opens a link to one entry, the feed opens at that entry and shows a "Jump to Latest" button that takes them to the live feed, with the number of newer entries when there are any.

The new entries count and Jump to Latest show on the same button. It sits at the top of the screen and takes the theme's button style. It isn't part of the layout, so it doesn't appear in the editor. To change its text, go to Rolling Coverage > All Coverages, select Settings, and on the Labels tab set Button label under Jump to Latest. The label can be up to 40 characters. Leave it empty to use "Jump to Latest".

Readers see entries called "entries" by default. To call them something else, such as "updates", go to Rolling Coverage > All Coverages, select Settings, and on the Labels tab set Singular and Plural under Entry Name, each as it reads mid-sentence ("update", "updates"). Both are needed, up to 30 characters each. The name then shows in the counts ("3 New Updates", "1 Newer Update"), the empty feed ("No updates yet."), the share button's label for screen readers, the text a new layout's link to the coverage page starts with ("See all updates", which layouts already in use keep as they are), the notice above an out-of-date entry and the feed's screen reader announcements. On English-language sites the counts capitalize each word, as buttons do; other languages keep the words as typed. Leave both empty to go back to "entry" and "entries".

### Pinned entries

An entry pinned in the coverage stays at the top of the feed with a "Pinned" label, whatever its date. Some layouts keep the pinned entry in view while the reader scrolls.

Feeds set to Latest ignore pinning and show the newest entries only.

### Share

Selecting Share on an entry opens the device's share sheet with a link to that entry. Where the browser has no share sheet, Share copies the link instead and confirms with "Link copied." A feed placed inside another feed's entry shares its own entries the same way.

### Entries with a breakout post

An entry made into a post with "Create Breakout Post" shows as usual until that post is published. From then on, every layout shows the entry as a card for the post, in the layout's own style:

- Where the layout shows the entry's title, it shows the post's title, linked to the post. A post without a title keeps the entry's title, still linked to the post.
- Where the layout shows the entry's content or excerpt, it shows the post's excerpt instead: the excerpt written for the post, or else its opening words. Blocks set to show only to some readers are left out of those words, so every reader sees the same excerpt.
- Layouts without a title name the post another way: Stream and Minute show the post's title, linked, above its excerpt, and Flash shows the post's title.
- A "Full story" label tells readers the card is a written-up story rather than an ordinary update. It sits on its own line just above the post's title, in small bold text in the theme's accent color, or in the card's own text color on a card with a background and text color of its own, such as Bulletin's pinned card. Ticker's headline and Flash's line start with it instead ("Full story: " then the title). A pinned entry shows both its "Pinned" label and this one. To change the label for every story, go to Rolling Coverage > All Coverages, select Settings, and on the Labels tab set Full story label under Full Story. Leave it empty to use "Full story". To give one story a label of its own, such as "Analysis", open its post in the editor and set Full story label in the Rolling Coverage panel of the post's settings sidebar. The panel shows only on posts made with "Create Breakout Post". Leave it empty to use the label every other story shows. Either label can be up to 40 characters.
- A password-protected post shows its title over the entry's own text, shortened, since readers could already see that text in the feed.
- A post behind a content gate or a membership restriction shows its title and the excerpt written for it. Without one, it shows the opening the content gate lets every reader see, or the entry's own text when the gate shows none, so none of its restricted text reaches readers who don't have access. To choose what such a post shows in the feed, write an excerpt for it.
- The entry's time, author, "Pinned" label, Read more, and Share stay as they are. Read more links to the post, and Share to the entry. An archived entry doesn't show the note that it's out of date, since its card shows the post rather than the entry's original text.

Changes to the post's title, excerpt, content, password, permalink, or Full story label reach open pages the next time they check for new entries. If the post is unpublished or deleted, the entry shows its own title and content again. The entry itself is never changed.

### What compact layouts show of an entry

Layouts that show a short excerpt of each entry, such as Wire, Digest, and Flash, take it from the entry's text when no excerpt was written for it (an entry whose breakout post is published shows the post's instead; see Entries with a breakout post): every block with words, including lists, headings, and quotes, cut to the length the layout's excerpt allows. Photos, videos, audio, and embeds add nothing to it, so a caption or an embedded link never reads as the entry's words. Neither do blocks hidden with Hide block, or the labels of buttons, file downloads, and other controls.

Ticker gives an entry without a title a headline made of the opening words of its first block with words, so a heading or an opening line stands alone. A block that ends with a colon, such as "Roads closed as of 4pm:" over a list, reads on into what follows it. An excerpt written for the entry is used instead when there is one.

### Photo, video, and other media entries

An entry that holds only a photo, gallery, video, audio clip, or embed has no words of its own, so it is described by its media instead: Photo, Gallery, Video, Audio, or Embed. When the media has a caption, the caption follows, for example "Photo: Crowds at the finish line". A photo without a caption uses its alt text. A gallery without a caption uses the caption or alt text of its first image that has one. An embed without a caption says what it embeds: a published post on this site, such as the entry's breakout post, is named by that post's title, and anything else by the site it comes from, for example "Embed from x.com".

- Layouts that show a short excerpt of each entry, such as Wire, Digest, and Flash, show this description as the excerpt, under the entry's title when it has one.
- Ticker uses this description as the headline of an entry without a title.

An entry with any text of its own, or with an excerpt written for it, shows that text as usual.

Entries posted from Slack follow the same rules:

- A message with text shows its text as usual.
- An image posted without text becomes a photo entry. The image's description in Slack becomes its alt text, so the entry reads "Photo: " followed by that description. Without a description, the entry reads only "Photo".
- Several images become a gallery. Posted without text, the entry reads "Gallery: " followed by the first description among its images, or only "Gallery" when none has one.

To give readers more than "Photo" or "Gallery", add a description to the image in Slack before you post it, or write a line of text with the images.

### Lite Site pages

On sites with the Lite Site plugin, a feed keeps updating on the text-only copy of the page too: new entries arrive, and older ones load, as the block is set. Each entry shows as text, with its time, a "Pinned" label when it's pinned, its title and its content, whatever the layout shows on the full page. An entry whose breakout post is published shows the "Full story" label, the post's title, linked to the post's own lite page, and the post's excerpt instead. Ads, Share, and Follow Coverage don't appear there. The coverage name, the Coverage Status badge (without "Updated … ago"), and the link to the coverage page still show.

This needs a Lite Site release after 0.1.0. With 0.1.0 or older, the lite page shows the entries as they were when the page was saved for Lite Site: new entries don't arrive, and older ones don't load.

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

In a story, add or select a Rolling Coverage block, open "Choose a layout" (Choose, or Change Layout in the Layout panel) and pick your layout. It is listed after Alert. If it isn't listed, reload the story's editor.

Things to know:

- Picking a built-in layout also changes block settings: Ticker, Wire, Digest, Flash, and Alert set Show to Latest and Number of entries; Ticker, Split, and Flash set the alignment; Ticker, Flash, and Alert set When ended to Hide. A custom layout, including a copy of a built-in one, changes none of them, and the block keeps its current values. Set them on the block in the story.
- Read more links to the entry's breakout post and only shows once that post is published. Share links to the entry itself. Both are parts of an entry in the built-in layouts. To remove Share, delete it. Read more is locked: select it, open Options (⋮) > Unlock, clear Lock removal, select Apply, then delete it.
- Follow Coverage is not part of the built-in layouts.
