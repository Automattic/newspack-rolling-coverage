# Scoreboard

Shows a liveblog's score: the two team names with a score for each on one row, a dash between the scores, and a short status line such as "Second half" or "Full time" beneath. The status line can show as a badge, in the same styles as the Coverage Status block's badges. Open pages pick up a new score without a reload.

The score belongs to the liveblog. Set it at the top of the liveblog's entries list, in Rolling Coverage > All Liveblogs. See [Setting the score](#setting-the-score).

## Where to put it

- **Above the feed.** Add it to a match's page or post, above the Rolling Coverage block, or to a template.
- **Inside a Rolling Coverage block.** Add it to the block's layout, such as its header. It shows that block's liveblog, and the Liveblog panel is hidden. It can't go inside an entry or the pinned entry.

The block is in the inserter's Newspack category, or Rolling Coverage on sites without Newspack.

## Which liveblog it shows

The Liveblog panel is at the top of the block's settings. It appears only when the block is outside a Rolling Coverage block.

| Setting | What it does |
| --- | --- |
| Automatic | "Shows the liveblog on this page, or a breakout post’s liveblog." Uses the first Rolling Coverage block in this post or page's content that shows every entry, else the first one that shows only the latest entries, not one placed in the site's template. On a breakout post, uses the liveblog the post came from. |
| Custom | "Always shows this liveblog." Pick the liveblog from the list. |

Automatic works on single posts and pages only, not on archives or password-protected posts. A block that shows only the latest entries counts only when the page has no feed that shows every entry.

The block shows nothing on the site when there is no liveblog to show. Most liveblogs aren't matches, so a liveblog has no score until someone adds one. Until then the scoreboard stays hidden, and open pages show it as soon as a score is added, without a reload. An ended liveblog without a score shows nothing.

## Setting the score

At the top of a liveblog's entries list:

1. Open the **More actions** menu in the header and select **Add Score**.
2. Type the **First team** and **Second team** names. The first team shows on the left.
3. Select **Add Score**.

The score row then shows a number for each team, with + and − buttons, a **Status** field and a **Badge** menu. The + and − buttons save straight away. A typed number, like the status line, saves when you press Enter or leave the field. If a save fails, the row goes back to the last saved score and a message says why.

**Badge** picks the style the status line takes in Scoreboard blocks that show it as a badge (see [Status as a badge](#status-as-a-badge)). Its choices are the Coverage Status block's badges, named with the site's labels: Live, Paused and Ended unless the site has renamed them in Rolling Coverage > Settings. It saves as soon as you pick one. A new score starts with the Live style.

Each score is a whole number from 0 to 999, and the status line holds up to 40 characters. For points that come in bigger steps, such as a three-pointer, type the new total. Put the period or the clock in the status line, such as "Q3 4:12" or "OT", and for tennis keep the sets as the score and the games in the status line. Judged sports, events with more than two competitors and scores below zero don't fit two whole numbers; report those scores in the entries, and keep the overall leaderboard in a pinned entry or the summary.

The menu at the end of the row holds **Edit Teams**, to rename the teams, and **Remove Score**. Removing the score hides every Scoreboard block for the liveblog.

Only editors and administrators, who can edit liveblogs, can change the score. Others see it as text.

## Styling

The block is built from paragraphs inside a group, so each part has its own settings. Select a team name, a score, the dash or the status line to change its size, weight, color or alignment. Screen readers skip the dash, and read the row as the team names and scores. Select the Scoreboard block itself to set a background, text color, border, padding or margin for the whole scoreboard.

### Status as a badge

Turn on **Show status as a badge** in the block's **Settings** panel to show the status line as a badge, such as an Ended badge reading "Full time". The badge looks like the Coverage Status block's badge of the style picked in the score row's **Badge** menu, so a match page can show one status instead of two. It is off on new blocks.

With it on, the block has the Coverage Status block's badge settings:

- **Dot**, in the **Settings** panel: **Show** or **Hide** the dot, which pulses on a Live badge. The default is **Show**.
- **Live background**, **Paused background** and **Ended background**, in the **Styles** tab under **Color**: a background for each badge style. The text color is chosen for contrast.

Its size and font come from the badge, not from the status line's own settings.

New blocks take their look from the theme: the team names and scores in the font the theme uses for headings, bold, with the scores largest and a slightly smaller dash between them. The dash keeps the text color, and the small status line takes the theme's muted text color. On a phone, long team names wrap onto a second line within their half of the row.

## In the editor

The block shows the liveblog's real score. With Automatic in a template, or when the liveblog has no score yet, it shows a sample score, Home team 2, Away team 1, so you can style it. A notice says when the liveblog has no score. With **Show status as a badge** on, the status line shows as the badge it will be on the site (Live for the sample).

## What readers see

The score updates on open pages without a reload:

- With a Rolling Coverage block for the same liveblog on the page, the score updates each time that block checks for new entries.
- Without one, the page checks the score itself every 30 seconds, or less often when the site sets a longer minimum. It stops while the browser tab is hidden and once the liveblog has ended.

When the score changes, screen readers hear the new score, such as "Riverbend FC 3, Toledo Beacon 1". A change to the status line, its badge style or the team names isn't announced, though the badge's text and style update on open pages like the rest of the score. Nothing on the scoreboard moves or animates.

On Lite Site's text-only pages, the scoreboard shows the score from when the page was saved for Lite Site.

## Limits

- It shows one liveblog at a time.
- An ended liveblog's score is shown as it was when the page loaded.
