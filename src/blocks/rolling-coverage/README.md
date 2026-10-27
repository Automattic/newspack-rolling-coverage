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
| Bulletin | The default. Entries stacked with their time and Share, title, and content. | Most live blogs. |
| Stream | Like Bulletin, with entries without titles, each headed by its author's photo, name, and how long ago it was posted. | Fast-moving events with many short updates without titles, such as a press conference or a court hearing. |
| Rail | Entries hang off a timeline, each signed by its author's name beside Share. | Events where the order of updates and the gaps between them matter, such as a storm moving through. |
| Clock | Each entry starts with the time it was posted and is signed by its author's name beside Share. | Coverage where the time of each update is the point, such as election results or travel delays. |
| Margin | Each entry split into a margin and its content. The margin holds the time (under Pinned on the pinned entry), the title, the author's name, and Share below it. | Coverage with headlines readers scan down the side, such as a long-running story. |
| Minute | Closer together, with each entry reduced to its content. | Sports and other minute-by-minute coverage. Start each entry with the minute: "70' Goal! Kowalski scores from the edge of the box." |
| Byline | Each entry signed by its author's photo and name. | Coverage with several reporters filing, where who reported each entry matters. |
| Ticker | A strip with the coverage's status and name beside the latest headlines, each under its time, and a link to the coverage page below, with a thin rule between them. The headlines stay on one line, whatever Number of entries is set to. Up to three share the width; more keep a minimum width, and once they don't fit the line scrolls sideways and fades at any edge with more to scroll. In a narrow space, such as a tablet or a column beside a sidebar, the status and name sit above the headlines. In the narrowest spaces, such as a phone, even three headlines scroll. A small gap (0.25em) separates the status and each time from the line below. The Feed's Block spacing sets the space on either side of a rule. Wide width. Hides when the coverage ends. | The homepage, under the header, to point readers to breaking news. |
| Split | The full feed at wide width, with every pinned entry in a column beside the entries, one under another. Each pinned entry and each entry is signed by its author's name beside Share, and Load more sits under the entries. | A coverage page that leads with a pinned summary, such as "What we know", beside the feed. |
| Wire | A narrow list of the five latest entries, ending in a link to the coverage page. | A sidebar or another narrow column. |
| Digest | A bordered box with the coverage name, the three latest entries next to their times, and a link to the coverage page. | A sidebar, or a box inside a related story that links to the main coverage. |
| Flash | A full-width bar in the site's accent color with the latest entry, its time, and a link to the coverage page, side by side at every width. The bar always stays one line. It shows as many entries side by side as the bar has room for, up to three, whatever Number of entries or Show is set to: three in a wide bar, such as the site header on a desktop screen, two in a narrower one, such as on a tablet or in a content column, and one on phones. The rest are hidden. Each entry keeps to one line: its time shows whole, the Full story label takes at most half the entry, and the text after them is cut short to fit. With Show set to Latest, "See all entries" leads to the rest. On small phones (480px and below) it also hides the time. The Live badge takes the theme's page background and text colors, and follows the theme's style variations. Change it under the Coverage Status block's Color settings. Hides when the coverage ends. | The site header, so a major breaking story shows on every page. |
| Alert | A box on the theme's secondary background color, like a pinned entry's (a light gray, or a dark gray on dark style variations), holding the coverage's status, its name, and a link to the coverage page on the right, side by side at every width. It shows no entries. The name keeps to one line, cut short to fit. The Live badge takes the theme's accent color (Primary on the classic Newspack Theme) with the text color the theme pairs with it, and follows the theme's style variations. Change it under the Coverage Status block's Color settings. Hides when the coverage ends. | Inside a related story, to point readers to the live coverage without repeating its updates. |

Stream, Rail, Clock, Margin, Split, and Byline show each entry's author. Author name and Author avatar in the Entries panel show or hide the author's name and profile picture, each on its own, so an entry can show the picture without the name. Rail, Clock, Margin, and Split hide the picture when you pick them; Stream and Byline show it. An entry the Slack bot posted shows no author until it is reassigned to a reporter. Author avatar works whether Avatar Display under Settings > Discussion is on or off, so a site that hides profile pictures elsewhere, such as in comments, can still show them in its coverage. Author avatar starts on Show, so a site that turned Avatar Display off to keep Gravatar images off its pages should set it to Hide. While Avatar Display is off, the editor previews real entries with the default avatar in place of each author's picture.

Stream, Minute, and Flash don't show entry titles, except a title from the first line of a Slack message, which a site turns on with Highest Heading in the Slack settings: Stream and Minute show it above the entry's text, and Flash shows it when the entry has no other text. The editor's preview of these layouts doesn't show it.

Ticker, Wire, Digest, and Flash show only the latest entries. They set Show to Latest and a matching Number of entries when you pick them. Alert shows no entries, but sets Show to Latest too, since the link to the coverage page only shows with Latest. You can change either afterward, except Show on Ticker and Alert: Ticker's headlines run on one line, which has no room for pinned entries or older pages, and Alert has no entries to show, so the setting is hidden while it's set to Latest. Alert hides Number of entries too.

Built-in layouts are shared: every story using one follows the same copy, which keeps up with plugin updates until someone edits it. Picking one saves nothing to the site; the layout gets a pattern of its own the first time someone selects Edit Layout. See [Edit, detach, and change a layout](#edit-detach-and-change-a-layout).

You can also insert a layout from the inserter's Patterns tab, in the Rolling Coverage category. It becomes a Rolling Coverage block that uses that layout and asks for a coverage.

## Settings

Select the block to open its settings in the sidebar. Some panels appear only when they apply.

### Layout

Says whether the block uses a shared layout, naming it (such as "Uses the shared Rail layout"), or its own detached copy.

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
| Older entries | With Show set to All: what happens after the first page. Load on scroll (default) loads the next page as readers reach the end of the feed. “Load more” button shows a Load more button below the entries, where the layout's [Load More](../load-more/README.md) block sits; each press adds a page, and the button goes away once every entry is shown. Don’t load shows the first page only. |
| Number of entries | With Show set to Latest: how many entries to show, from 1 to 100. |
| Link to all updates | With Show set to Latest, in layouts that have one (Ticker, Wire, Digest, Flash, Alert): Show or Hide the link to the coverage page. The link is hidden on the coverage page itself. It starts as "See all entries", or "See all" followed by the site's plural name for entries ("See all updates"). It is an ordinary paragraph, so you can reword it. |
| Link text | With Link to all updates set to Show, in a shared layout that has the link: this block's own wording for it, such as "Follow the storm". Leave it empty to use the layout's text, which shows in the field and below it. The layout stays shared, so later changes to it still reach this block. Detaching the layout writes the wording into the detached copy. |
| Entries per page | With Show set to All: how many entries show first, and how many each load of older entries adds. From 1 to 100. Default 20. |
| Author name | In layouts that show the author's name: Show or Hide each entry's author's name, the one they chose under "Display name publicly as" in their profile. Default Show. |
| Author avatar | In layouts that show the author's profile picture: Show or Hide the picture, whatever Author name or Avatar Display under Settings > Discussion is set to. Picking Rail, Clock, Margin, or Split sets it to Hide, and switching from one of them to a built-in layout not in that list sets it back to Show. |
| Poll interval (seconds) | Hidden when Show is set to All and the layout holds a [Check for Updates](../check-updates/README.md) block. How often the page checks for new entries. Default 30. The site can set a longer minimum, which wins over a shorter value here; the setting then says how long the site waits. |

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

Readers who already have the page open when the coverage ends see the notice at the page's next check for updates, and the page stops checking. The notice uses the settings from when the page loaded, so a change to its text or URL reaches those readers only after a reload, and so does a breakout post published after the notice appears.

### AI

Appears when AI is set up on the site. Generate Key Takeaways sums up the coverage's latest entries, shows the summary in Generated Output, and copies it to the clipboard, ready to paste into the page. Generate again as new entries come in for an updated summary. Copy Generated Output copies it again. Administrators also see Edit the prompt, which opens Rolling Coverage > AI in a new tab.

### Ads

Appears when Newspack Ads is active.

| Setting | What it does |
| --- | --- |
| Advertising | Enabled or Disabled. Shows ads between entries. Not available when Show is set to Latest. |
| Ads interval | Shows an ad after every N entries (4 by default), counting from the top of the feed with pinned entries included. Up to 3 ads appear among the entries that load with the page and the older ones readers load after them: with an interval of 4, after entries 4, 8 and 12, and none further down. New entries that arrive while the page is open keep that spacing, with an ad after every N entries and no limit, however many arrive at once. |

Ads need the Rolling Coverage: Entry placement enabled in Newspack Ads, and ads enabled in the coverage's own settings. The panel tells you when either is missing.

Ads don't appear where the post shows in an RSS feed, including feeds that news apps read. Feed readers can't load them, and apps place their own.

Alignment and the HTML anchor are in the block toolbar and the Advanced section like any other block. Colors, type, and spacing of the feed come from the layout. See [Edit, detach, and change a layout](#edit-detach-and-change-a-layout).

## What readers see

### Live updates

The page checks for new entries as soon as it opens, then every poll interval. The site's cache can hand a reader a copy of the page saved a few minutes earlier, and that first check usually brings it up to date. On a page few readers have open, the check can find nothing newer than the page, and the page catches up at the next check instead. For people who can edit posts, the page is never a saved copy, so it waits one interval before its first check. It stops while the tab is in the background and checks again when the reader returns, to the tab or with the browser's Back button. When checks fail, as when the site is under heavy load, the page waits longer before each new try, up to 5 minutes, even when the reader returns to the tab, and goes back to the poll interval as soon as a check works. A check made while the reader's device has no network connection doesn't add to the wait.

- If the reader is at the top, new entries appear at once.
- If the reader has scrolled down, the page does not move. A button shows the count (for example "3 new entries"). Selecting it scrolls the page to the new entries, past any pinned entries above them, with the newest at the top of the screen, just below the site's header when the header stays in view, and they come in once the page gets there. Readers whose device is set to reduce motion go straight there instead. Edited entries update in place, and new entries waiting behind the button come in with their latest edits.
- New entries fade in as they appear, and so do older entries as they load. A new entry at the top of a feed that lists entries one above another also opens its own space, so the entries below glide down rather than jump. Several new entries arriving together open their space together, a little more slowly than one, so the list doesn't rush down. In a grid or a row, such as Split or Ticker, new entries only fade in.
- An entry published while the page is open shows "New" until the reader has seen it. Entries brought in by the page's first check that works show without it, as they would on a freshly loaded page. The first check runs as the page opens, or when the reader first switches to a page opened in a background tab. Pages for people who can edit posts, and feeds with a Check for Updates block, show the label on every new entry from their first check on. Four seconds after the reader first sees it, the label fades away, and a line the label had to itself closes as it fades, moving up what sits below it. Switching to another tab before then restarts the wait. This covers new entries brought in by the new entries button and, in a feed opened at a shared entry, the newer entries Jump to latest brings in. An edit keeps the label on an entry that has it. Older entries that load and entries published again don't show it.
- In the layout, "New" is a [New Label](../new-label/README.md) block in the entry that shows only on an entry that arrived while the reader had the page open, until they've seen it. You can move it, change its text or restyle it like any other part of the layout. If you remove it, or use a custom layout without it, new entries show no label; screen readers still announce them as new. To get the label in an edited built-in layout, restore it (see [Restore a built-in layout](#restore-a-built-in-layout)). In Minute the label is for screen readers only, so its new entries show no label, and Flash shows it at the end of the bar.
- Readers whose device is set to reduce motion still see entries fade in and the label fade away. A new entry's space opens at once instead of gradually, and a label on a line of its own closes at once.
- An entry that is unpublished, for example moved to draft or trashed, disappears from the page. When Show isn't set to Latest, it fades out first if the reader can see it, unless their device is set to reduce motion. Published again or restored, it comes back without a reload: as a new entry when it is the newest, otherwise in its place by date.
- To have the page check only when readers ask, add a [Check for Updates](../check-updates/README.md) block to the layout. The page then makes no background checks, which suits readers on slow or metered connections.
- Older entries load as the reader scrolls to the end, or with the Load more button, depending on Older entries. The button takes the theme's button style and dims slightly while a page loads. If a load fails, the button stays so the reader can try again. Stream centers it; the other layouts line it up with the entries' text. To restyle or move it, edit the layout's [Load More](../load-more/README.md) block.
- A tab left open for a long time, or a page served from a cache, after the feed's layout or settings changed many times reloads when new or older entries arrive, so they show in the feed's own layout. If the reload lands on the same cached copy, it tries again a minute later.
- A paused or ended coverage does not check for new entries on its own. With a Check for Updates block, readers can still check a paused coverage.
- Times follow the site's time format.

Feeds that show only the latest entries update in place without that button: the newest entry appears and the oldest drops off. They have no ads and load no older entries.

When a reader opens a link to one entry, the feed opens at that entry and shows a "Jump to latest" button that takes them to the live feed, with the number of newer entries when there are any.

The new entries count and Jump to latest show on the same button. It sits at the top of the screen and takes the theme's button style. It isn't part of the layout, so it doesn't appear in the editor. To change its text, go to Rolling Coverage > All Coverages, select Settings, and on the Labels tab set Button label under Jump to Latest. The label can be up to 40 characters. Leave it empty to use "Jump to latest".

Readers see entries called "entries" by default. To call them something else, such as "updates", go to Rolling Coverage > All Coverages, select Settings, and on the Labels tab set Singular and Plural under Entry Name, each as it reads mid-sentence ("update", "updates"). Both are needed, up to 30 characters each. The name then shows in the counts ("3 new updates", "1 newer update"), the empty feed ("No updates yet."), the share button's label for screen readers, the text a new layout's link to the coverage page starts with ("See all updates", which layouts already in use keep as they are), the notice above an out-of-date entry and the feed's screen reader announcements. Leave both empty to go back to "entry" and "entries".

### Entries behind a content gate

When a Newspack content gate covers entries, each gated entry shows only its free preview in the feed, without the gate's prompt. Every reader sees the preview, including readers the gate lets through, because the feed is the same for everyone. This holds for new and older entries as they arrive, on Lite Site pages, and in the RSS item of the post or page holding the feed. Readers the gate lets through can read the whole entry where it has a page of its own: its breakout post, or the entry's own page when the coverage has no Canonical URL.

In RSS, gated entries follow the gate's feed setting. They show in full when Restrict content in feeds is turned off (Audience > Access Control > Advanced Settings), or in a custom RSS feed whose Restricted articles in this feed is set to Include restricted articles in full. With Remove restricted articles from the feed, the post holding the feed stays in the RSS feed unless a gate covers it too, and its gated entries show their free preview.

A gate can cover entries through its Post types rule, through a category or tag an entry carries, or by having only exclusion rules. To keep entries whole, use the gate's Post types rule:

- Set to Include: leave Entries unchecked.
- Set to Exclude: check Entries.
- Not turned on: turn it on, set it to Exclude, uncheck Posts, and check Entries.

A gate set to Match any rule, with its Post types rule set to Include, can still cover entries through a category or tag they carry.

### Pinned entries

An entry pinned in the coverage stays at the top of the feed with a "Pinned" label, whatever its date. With more than one pinned, the one edited most recently is on top, so a summary kept up to date stays first. Pinning, archiving or reassigning an entry, and updating its full story, count as edits too, so each puts the entry on top. Most layouts show a pin beside the label, and Margin shows the label alone. Bulletin shows the pin alone, at the far right of the entry's top row, and Stream shows no pin, as its card already sets the entry apart; screen readers still announce the entry as pinned in both. Bulletin, Minute and Rail set the pinned entry on a light gray panel (the theme's secondary background color, as in Alert), in the entries' text color; Rail's has rounded corners and shows its time under the pin. In Clock and Byline, the Pinned label, and in Rail the pin and line beside the pinned entry, take the same color as the Full story label: the theme's primary color, or its secondary color when the primary is too pale or too dark to read on the page background, or the text color when neither is readable. Split shows no Pinned label, as its column holds only pinned entries, each under a heavy line; screen readers still announce them as pinned. Some layouts keep the pinned entry in view while the reader scrolls; at wide widths Split keeps its whole column in view, and when the column is taller than the screen it scrolls with the page until its end shows, then stays. On tablets and phones, where Split's pinned entries sit above the other entries, they scroll away with the page. When an entry is pinned, pages already open show it at the top of the pinned entries at their next check, and an edit to a pinned entry moves it there too. When an entry is unpinned, pages already open move it back to its place by date at their next check. If that place is further down than the reader has loaded, the entry shows up there once older entries load. With Older entries set to Don’t load, it goes to the end of the feed instead.

Feeds set to Latest ignore pinning and show the newest entries only.

### Share

Selecting Share on an entry opens the device's share sheet with a link to that entry. Where the browser has no share sheet, Share copies the link instead and confirms with "Link copied." A feed placed inside another feed's entry shares its own entries the same way.

### Entries with a breakout post

An entry made into a post with "Create Breakout Post" shows as usual until that post is published. From then on, the feed points readers to the post. How depends on the layout:

- **Full Story Entry.** Bulletin, Stream, Rail, Clock, Margin, Minute, Byline, Split, Wire, Digest and Flash each have a Full Story Entry next to their Pinned Entry and Entry. An entry whose post is published shows as the Full Story Entry instead of the Entry: a card in place of the entry's title and text, bordered and lightly tinted in the accent color in Bulletin, Stream, Rail, Clock, Minute and Byline. Split and Margin show no card. The card holds the Full story label, the post's featured image (when it has one), the post's title and the start of its summary, up to 25 words, with the entry's own date, author and Share. The whole card links to the post, and grows slightly when a mouse points at it, while Share and any other link in the card keep their own targets. A card with a button of its own, such as Bulletin's Read more, links through its title and that button instead. What each layout puts in the card:
  - Bulletin: a square card. The Full story label, the time and Share in one row, then the title, the summary and a Read more button linking to the post, in the accent color, with the featured image to their right (below them on phones). It has the pinned entry's padding, and the same space between the text and the image. The image takes an 11rem column at 4:3, the title is the size of the entries' titles, and the summary is set like the entries' text. Like the pinned entry, no separator sits above or below it, including the one that would close the entry before it. A pinned entry whose post is published shows as this card too, with the pin, in the accent color, at the far end of its top row: "Full story · time · Share … [pin]". Screen readers announce it as pinned.
  - Stream: the author's byline with the Full story label and an arrow in the accent color at the far end of the same row (below the byline when the row wraps on a narrow screen), the featured image at its own proportions across the full width with a little space above and below it, then the title, at the size of Bulletin's titles, the summary, set like the entries' text, and Share.
  - Rail, Clock, Margin and Byline: the Full story label, the post's title, the featured image at its own proportions across the full width of the layout's content column, then the summary. In Rail and Clock the entry's author and Share follow inside the card, with an arrow in the accent color at the far end of their row, and the card has rounded corners and a little space above and below the image; in the others the entry's date, author and Share stay where the layout puts them for an entry. Margin keeps its own shape instead of a card: the post's title sits in the margin like every entry's, under the time, with the author and Share below; the Full story label, at the Pinned label's size, the image, cropped to 16:9, the summary (set like the entries' text) and a gray Read more button take the content's place; and a heavy line in the label's color rules it off, like the pinned entry's in black. A pinned entry whose post is published shows this way too, with the Pinned label above.
  - Minute: the Full story label, the title and the summary, set like the entries' text, then an arrow in the accent color at the right, with no image, ruled off from the entry above like every entry. A pinned entry whose post is published shows as this card too, with the pin, in the accent color, before the label.
  - In Byline the card has rounded corners, a little space above and below it and around its image, the image cropped to 16:9, and an arrow beside the Full story label. In Rail, Clock and Byline the summary is small, in the entries' text color; elsewhere it's set like the entries' text.
  - Split: no card. Beside the entry's time, the Full story label, the post's title, the featured image cropped to 16:9, the summary set like the entries' text and a gray Read more button, with the entry's author and Share below.
- **Without a Full Story Entry.** An edited built-in layout saved before the Full Story Entry existed, or a custom layout without one, shows the entry as a normal entry, with its own title and text, and no card. A Read more link bound to the post still links to it. To get the Full Story Entry in a built-in layout, restore it (see [Restore a built-in layout](#restore-a-built-in-layout)).
- **Pinned entries.** A pinned entry with a published post shows as the Pinned Entry, with the entry's own text, except in Bulletin, Stream, Minute, Rail, Clock, Margin and Byline, where it shows as the Full Story Entry: with the pin in Bulletin, Minute and Rail (above the time), with no pin in Stream (screen readers still announce it as pinned), with the pin and Pinned label above the time in Clock, with the pin and Pinned label at the end of the byline row in Byline, and with the Pinned label above the time in Margin. In a custom layout, add a pinned row to the Full Story Entry to have it take pinned entries too: copy the group named "Pinned" from a Pinned Entry that has one, such as Bulletin's, into the Full Story Entry. The row must hold only the "Pinned" label and icons, and it shows only on pinned entries. Pinned entries shown this way still stay at the top of the feed. In Split, every pinned entry sits in the column beside the feed, in its Pinned group: the Pinned Entry for an entry without a published post, and the Pinned Full Story for one with, which shows the post as Split's Full Story Entry does, without the time. When no entry is pinned, the feed takes the full width.
- **Wire and Digest** show the time, then Byline's card without the image, its title at the size of their headlines (above the card in Wire, beside it in Digest). They show the latest entries, where pinning doesn't apply. Set to show all entries, a pinned entry whose post is published keeps the pinned entry's place, with the post's title under the Full story label and its summary.
- **Compact layouts.** Ticker always shows the post in the entry's place, in the layout's own style: the post's title, linked to the post, where the layout shows a title (a post without a title keeps the entry's, still linked to the post), and its summary where the layout shows an excerpt.
- **Summary.** It is the excerpt written for the post, or else its opening words, cut to the site's excerpt length (the Full Story Entry's Post Excerpt cuts it to 25 words). Blocks set to show only to some readers are left out of those words, so every reader sees the same excerpt. Edits to the post show right away, but when only who can see a block changes, such as a content gate's settings, the opening words can take up to an hour to catch up.
- **Full story label.** It tells readers the post is a written-up story rather than an ordinary update. In the Full Story Entry it is the paragraph named "Full Story Label", small and bold. Ticker's headline carries it on a line of its own above the title. Flash's line shows it in capitals, in the bar's text color, between the time and the post's title, followed by its summary, cut short to fit. The label takes the theme's primary color (accent on a block theme), or its secondary color (accent-2) when the primary is too pale or too dark to read on the page background, or the text color when neither is readable; inside a block with both a background and a text color of its own, it takes that block's text color, such as Flash's bar. To change the label for every story, go to Rolling Coverage > All Coverages, select Settings, and on the Labels tab set Full story label under Full Story. Leave it empty to use "Full story". To give one story a label of its own, such as "Analysis", open its post in the editor and set Full story label in the Rolling Coverage panel of the post's settings sidebar. The panel shows only on posts made with "Create Breakout Post". Leave it empty to use the label every other story shows. Either label can be up to 40 characters. On the site the label is the post's own, else the site-wide one; the editor's previews always show the site-wide label.
- A password-protected post shows its title over the entry's own text, shortened, since readers could already see that text in the feed. A password-protected entry whose post isn't protected shows the post's excerpt, as any other card does.
- A post behind a content gate or a membership restriction shows its title and the excerpt written for it. Without one, it shows the opening the content gate lets every reader see, or the entry's own text when the gate shows none (as with a membership restriction), so none of its restricted text reaches readers who don't have access. To choose what such a post shows in the feed, write an excerpt for it.
- In the Full Story Entry and in compact layouts, an archived entry doesn't show the note that it's out of date, since the post shows rather than the entry's original text.

While the post stays published, changes to its title, excerpt, content, password, slug, publish date or featured image, and setting, changing, or clearing its own Full story label, reach open pages the next time they check for new entries. A change to the site-wide label shows on pages loaded after it. If the post is unpublished or deleted, the entry shows its own title and content again, on open pages too. The entry itself is never changed.

#### Edit the Full Story Entry

The Full Story Entry is part of the layout, so you edit it like the rest of it. Use Edit Layout, or detach the layout in a story (see [Edit, detach, and change a layout](#edit-detach-and-change-a-layout)). Open List View, then Feed, and select "Full Story Entry". Inside it, select "Card" to change its look (Margin and Flash have no card):

| Setting | What it does |
| --- | --- |
| Border | The card's border. Starts as a thin line in the label's color. |
| Background | The card's fill. Starts as a light tint of the label's color. Choosing another color replaces the tint. |
| Radius | The card's corner radius. Square in Bulletin and Minute, rounded elsewhere. |
| Padding | The space between the card's edge and its content. |
| Block spacing | The space between the parts of the card. |

The blocks inside the card are ordinary blocks, so you can select and restyle them or remove the ones you don't want: the "Full Story Label" paragraph, the featured image, the post title, the post excerpt, and the date, author and Share. The label, image, title and excerpt show the post's data on the site, so there's nothing to type into them.

The Full Story Entry shows in the editor next to the Pinned Entry and Entry. Edit Layout previews it with sample entries. A detached layout previews the story's real entries, and hides the Pinned Entry and the Full Story Entry when none of them needs one.

Core's Columns block, which Bulletin uses to put the image beside the title, stacks at the width of the screen, not of the column. In a narrow column, such as a sidebar on a wide screen, the image stays beside the title.

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

On sites with the Lite Site plugin, a feed keeps updating on the text-only copy of the page too: new entries arrive, and older ones load, as the block is set. Every layout looks the same there, in one text-only style close to Stream: each entry opens with its time, then its title and its content, whatever the layout shows on the full page. Pinned entries and Full story entries show as cards, and a thin rule separates the other entries. A pinned entry's "Pinned" label is for screen readers only, as its card sets it apart. A Full story card is tinted with Lite Site's primary color, shows the Full story label, with an arrow, at the end of its top row, and links to the post as a whole. The label and the card's border take that color when it's dark enough to read on the tint, and the Coverage Status badge takes it while the coverage is live. The Load more and Check for updates buttons look like Lite Site's own. Entries arrive and leave without fades, and new ones show no "New" label. An entry whose breakout post is published shows the Full story label, the post's title, linked to the post's own lite page (or to the post when Lite Site has no lite page for it), and the post's excerpt instead, without the archived entry's note. When the post has no title, the entry's title (or its opening words) links to the post instead, without the label. Ads, Share, and Follow Coverage don't appear there. The coverage name, the Coverage Status badge (without "Updated … ago"), and the link to the coverage page still show.

This needs a Lite Site release after 0.1.0. With 0.1.0 or older, the lite page shows the entries as plain text, as they were when the page was saved for Lite Site, without this style: new entries don't arrive, and older ones don't load.

### Live blog markup

A block that shows all entries counts as the coverage's live feed on its page, including the live blog markup search engines read. A block set to Latest never does.

### Updated date

While the coverage is live or paused, a post with its live feed shows when it was last updated, so it doesn't look as old as its publish date while entries keep coming in. The post's byline adds "Updated" with that time, and its card in Homepage Posts shows it in place of the publish date. The time is when an entry was last published or edited, or the post's own last edit when that came later. A feed added through a synced pattern doesn't change the post's dates.

This happens even when the site's **Last updated date** is set to **Hide**, and without waiting for its **Minimum hours after publish**. The time reads as "time ago" when **Date format** is set to **Relative**. Once the coverage ends, the site's own date settings apply again. When **Last updated date** is set to **Show**, **Hide last updated date** in the post's settings hides it on that post. Pages keep their usual dates. Bylines change with a Newspack plugin version that supports this, and cards with a Newspack Blocks version that does.

## Edit, detach, and change a layout

When a block uses a shared layout, the block toolbar shows Edit Layout and Detach.

- **Edit Layout** opens the shared layout in a new tab (the Site Editor on block themes for people who can edit the site's design, the pattern editor otherwise). Editing it changes every story that uses it, and from then on plugin updates no longer change it (see [Restore a built-in layout](#restore-a-built-in-layout)). The editor previews sample entries, so the Pinned Entry, Entry and Full Story Entry always show. Only people who can edit patterns see this button.
- **Detach** copies the layout into this story. From then on, changes you make to this block affect this story only, and changes to the shared layout no longer reach it. The Layout panel reads "Uses its own layout, detached from the shared one."
- **Change Layout** in the Layout panel opens "Choose a layout". Picking another layout replaces the current one, including a detached copy.

If a story points at a custom layout that was deleted, readers see the Bulletin layout, and the editor asks you to Choose a layout again. A built-in layout whose pattern was deleted shows the plugin's version instead.

## Restore a built-in layout

A built-in layout keeps up with the plugin until someone edits it, so a plugin update brings its new design to every story that uses it. Saving a change to the layout makes it the site's own, and updates no longer change it. Renaming it doesn't count as a change.

To get the plugin's version of an edited layout back, remove its pattern. On block themes, use Delete in the Site Editor's Patterns, which can't be undone; on classic themes, use Trash on the Patterns screen (`wp-admin/edit.php?post_type=wp_block`). Every story that used the layout then shows the plugin's version, which keeps up with the plugin again. Reload any editor that was open before you removed it. An edited layout saved before the Full Story Entry existed has none: entries with a published breakout post show as normal entries in it until you restore the layout.

## Make a custom layout

A custom layout is a synced pattern in the Rolling Coverage pattern category whose only top-level block is a Rolling Coverage block. Published patterns in that category appear in "Choose a layout" after the built-in layouts, sorted by title. The picker reads up to 100 patterns from the category, built-in ones included.

An empty pattern in the Rolling Coverage category opens with a Rolling Coverage block in place, as an editable layout with sample entries, starting from Bulletin. A pattern that already has blocks keeps them, and a Rolling Coverage block you remove stays removed while the pattern stays in the Rolling Coverage category. It comes back only if the pattern leaves the category and rejoins it empty, or you open another empty pattern. Don't pick a coverage inside a pattern, and don't add other blocks beside the Rolling Coverage block: the pattern then no longer counts as a layout.

### Block themes

1. Go to Appearance > Editor > Patterns. Select Add Pattern, then Add Pattern in the menu.
2. Enter a Name. In Categories, pick Rolling Coverage. Leave Synced on. Select Add. The pattern opens with a Rolling Coverage block showing the Bulletin layout.
3. Edit the layout with the block settings.
4. Select Save.

To start from a built-in layout instead, open the Rolling Coverage category in Patterns, open the layout's Actions menu (⋮) and select Duplicate. The copy keeps the category and stays synced. Rename it and select Duplicate. A built-in layout is listed there once someone has selected Edit Layout for it, so if it isn't, pick it in a story and select Edit Layout first.

### Classic themes

1. Go to the Patterns screen (`wp-admin/edit.php?post_type=wp_block`) and select Add Pattern.
2. Enter a Name, leave Synced on, and select Create.
3. In the Settings sidebar, under Pattern Categories, add Rolling Coverage. The category is only in the sidebar, not in the Create pattern dialog. A Rolling Coverage block showing the Bulletin layout appears in the pattern.
4. Edit the layout with the block settings.
5. Select Publish.

### Use it

In a story, add or select a Rolling Coverage block, open "Choose a layout" (Choose, or Change Layout in the Layout panel) and pick your layout. It is listed after Alert. If it isn't listed, reload the story's editor.

Things to know:

- Picking a built-in layout also changes block settings: Ticker, Wire, Digest, Flash, and Alert set Show to Latest and Number of entries; Ticker, Split, and Flash set the alignment; Ticker, Flash, and Alert set When ended to Hide. A custom layout, including a copy of a built-in one, changes none of them, and the block keeps its current values. Set them on the block in the story.
- Share links to the entry itself and is part of an entry in the built-in layouts. To remove it, delete it. Entries in the built-in layouts have no Read more: once an entry's breakout post is published, the Full Story Entry's card leads to it, with a Read more button in Bulletin's and Margin's (see Entries with a breakout post).
- New entries show a label only where the layout holds a [New Label](../new-label/README.md) block in each entry, as the built-in layouts do. A new layout starts from Bulletin, which has one in each entry; keep it, or add the block to an entry that lacks it.
- Follow Coverage is not part of the built-in layouts.
