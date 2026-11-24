# Scoreboard block: development notes

The Scoreboard block (`newspack-rolling-coverage/scoreboard`) shows a coverage's score: two team names, a score for each and a status line, which it can show as a Coverage Status badge. Editor code lives in `src/blocks/scoreboard/`, with the score's shape and helpers in `src/blocks/shared/score.ts`. Server rendering lives in `includes/blocks/class-scoreboard-block.php`, and the score itself in `includes/class-score.php`.

For how publishers use the block, see `README.md` in this directory. The admin's score row is under "Score" in `src/admin/DEVELOPMENT.md`.

## The score

`Score` keeps the score in the `rolling_coverage_score` term meta (`Score::META_KEY`), an object:

```json
{
	"teams": [
		{ "name": "Riverbend FC", "score": 2 },
		{ "name": "Toledo Beacon", "score": 1 }
	],
	"status": "Full time",
	"badge": "archived"
}
```

- A coverage without the meta has no score; `Score::get()` returns null. Most coverages aren't matches.
- The meta is registered with a JSON schema (`Score::schema()`): exactly two teams, each a name of 1 to 40 characters and a whole score from 0 to 999, a status of up to 40 characters, which can be empty, and an optional `badge`, one of `active`, `paused` or `archived` (`Score::badges()`, the keys of `Coverage_Status_Block::BADGE_CLASSES`). `badge` is the Coverage Status badge the status line takes in a scoreboard with `showStatusAsBadge`; it means nothing about the coverage's own status. Names also need one character that isn't a space or an angle bracket. `Score::sanitize()` normalizes what is stored: plain text with tags and comments taken out and whitespace collapsed, cut to `MAX_TEXT_LENGTH`, and never entity-encoded, since the view script and the admin set it as text and every HTML output escapes it (`esc_html()` in `get_value()`, `escapeHTML()` in the editor's bindings); scores clamped to 0 to `MAX_SCORE`; `badge` kept when it is one of `Score::badges()` and `active` otherwise, so every stored score has one; and null for anything without two named teams. Through the route, an unknown `badge` fails the schema's `enum` with a 400 instead. A lone `<`, as in "U<23", stays, and a name made only of tags keeps its text without the angle brackets. The admin shows the score the save response returns, so what it shows is what was stored.
- It is read and written through the core coverages route, `/wp/v2/rolling-coverage/{id}`, as `meta.rolling_coverage_score`. With no `auth_callback`, writing it needs what editing the coverage needs (`edit_term`, which is `manage_categories` for this taxonomy), as with the status. Sending `null` deletes it. Reading it is public, like the coverage's other public meta.
- Writes send the whole object. Two editors changing the score at the same moment can overwrite each other's step; the last save wins.

## Attributes

- `coverageId`: 0 for Automatic, or a chosen coverage (Custom).
- `showStatusAsBadge`: show the status line as the Coverage Status badge of the score's `badge` (Settings panel, "Show status as a badge"). Default off.

Everything else is block supports on the wrapper (colors, typography, padding, margin, border, block gap) and the inner blocks' own settings.

## Inner blocks and bindings

The block saves its inner blocks (`save` returns `InnerBlocks.Content`) and renders dynamically. New blocks get `scoreboardTemplate()` from `template.ts`: a flex group (no wrap, centered, block gap `spacing|40`) holding five paragraphs, the first team's name, its score, a dash, the second team's score and its name, then a centered paragraph for the status line. The names fill the space either side (`selfStretch: fill`), so the scores sit in the middle, and a long name wraps within its half at phone widths. Every choice is a block setting from the theme's presets, read when the block is inserted:

- **Font family.** Names and scores take the font family preset headings use: the site's global styles for headings, then the theme's, then the body's (`headingFontFamily()`, from core-data's global styles records). Without a preset, as on the classic Newspack Theme, they get no `fontFamily`; their `use-header-font` class gives the classic theme's heading font.
- **Sizes.** On a block theme scale (one with `xx-large`), names at Large and scores at XX-Large; on the classic Newspack Theme's scale, Normal and Huge. Names and scores are bold.
- **Status line.** X-Small (Small on the classic theme), in the muted text color the layouts' dates use (`mutedTextColor()`: `contrast-3` on the block theme, `medium-gray` on the classic theme), and no color where the palette has neither.
- **Dash.** A static paragraph holding "–", a size down from the scores (X-Large, or Large on the classic theme), in the heading font at normal weight and the text color. It carries the `newspack-rolling-coverage-scoreboard__dash` class (`Scoreboard_Block::DASH_CLASS`, `DASH_CLASS` in `template.ts`), which the server reads to keep it from screen readers (see "Rendering").

Each paragraph binds its `content` to the `newspack-rolling-coverage/score` source with a `key`: `team1Name`, `team1Score`, `team2Score`, `team2Name` or `status` (`Scoreboard_Block::KEYS`, `SCORE_KEYS`). Editors can restyle, move, remove or add blocks; any paragraph or heading inside the scoreboard can bind to a key.

- **Server.** `Scoreboard_Block::get_value()` reads the score of the coverage in the `newspack-rolling-coverage/coverageId` context, the key a Rolling Coverage feed sets for its layout's blocks, and returns the part escaped, or null without a score. Outside a feed, `add_coverage_context()` (`render_block_context`) puts the scoreboard's own coverage in that key for its direct inner blocks, and `showStatusAsBadge` in `newspack-rolling-coverage/statusAsBadge` (`STATUS_BADGE_CONTEXT`); core passes both on to blocks nested deeper. The source lists both keys in its `uses_context`, which core adds to the blocks that support bindings.
- **Editor.** `bindings.ts` registers the same source with the same two context keys. It reads the coverage's term record in the `view` context and shows its score, or the sample from `sample.ts` when the block has no coverage or the coverage no score. `edit.tsx` provides the coverage and `showStatusAsBadge` in context with `BlockContextProvider`, so the preview follows the setting.

### The status line as a badge

With `newspack-rolling-coverage/statusAsBadge` true in context, the `status` part is the Coverage Status block's badge markup instead of plain text: `<span class="newspack-ui__badge …">Full time</span>`, its classes from `Coverage_Status_Block::badge_class_names()` for the score's `badge` (`badgeClasses()` in `status-badges.ts` in the editor and view script), with the dot. The paragraph keeps its own settings, but the badge sets its own font, size and colors, as on the Coverage Status block, so the paragraph's muted color and size don't reach it. There are no custom badge colors here: the Coverage Status block's `backgroundColors` belong to that block. An empty status line stays empty, with no badge. Core inserts the value as HTML (it passes through `wp_kses_post()`), which is why every part is escaped.

`mark_part()` (`render_block`) adds `data-rc-score="{key}"` to each bound block's outer tag, for the view script to update, and `hidden` when its text is empty, such as a status line not set yet.

## Which coverage it shows

`render_block()` and `add_coverage_context()` ask `Page_Coverages::coverage_for_block()`, as Coverage Status does: the surrounding feed's coverage, else a chosen coverage while it exists and isn't trashed, else the first uncapped feed in the post being viewed or a breakout post's coverage, on single views only. See "Which coverage it shows" in `src/blocks/coverage-status/DEVELOPMENT.md`. The editor mirrors the rules with `useBlockCoverage()`.

Inside a feed it is a coverage-level item (`Entry_Bindings::is_coverage_item()`, `isCoverageItem()` in `template.ts`), so it renders once with the feed's coverage, and `feed-insertion.ts` keeps it out of entries and the pinned card.

## Rendering

With no coverage, the block renders nothing. Otherwise it renders the inner blocks in the wrapper, with the attributes below. A coverage without a score yet gets the wrapper `hidden`, its bound parts empty and hidden, so an open or cached page, such as one built before kickoff, shows a score added later at its next check (`applyScore()` fills the parts and unhides it). An ended coverage without a score renders nothing, since nothing checks it.

- `data-coverage-id` and `data-status` (the coverage's status),
- `data-status-badge="true"` with `showStatusAsBadge`, so the view script keeps the status line a badge, even one rendered empty,
- `data-score-url`, the coverage's score route,
- `data-poll-interval`, seconds between the page's own checks: `POLL_INTERVAL` (30, the feed's default) or the site's minimum poll interval when that is longer,
- an empty `div.screen-reader-text` with `aria-live="polite"` and `aria-atomic="true"`, for the view script's announcement.

`hide_dashes()` adds `aria-hidden="true"` to every element with the dash class in the inner blocks, on every render, Lite Site and feeds included. A paragraph can't save `aria-hidden`, and a CSS `::before` would still be read and couldn't be styled with block settings, so the class marks it. Screen readers then read the row as the names and scores alone, as the live region announces them. The dash isn't bound, so the view script, which finds parts by `data-rc-score`, never touches it.

On a Lite Site page (`Lite_Feed::is_lite_render()`) and in syndication feeds, which run no script, it renders the inner blocks in a plain wrapper, without the data attributes or the live region, and nothing without a score. A status line shown as a badge keeps its markup there, but those pages don't load the newspack-ui styles, so it reads as plain text.

## Live updates

`view.ts` keeps every scoreboard on the page current, in two ways.

- **With a feed for the coverage on the page.** Every poll reply carries the coverage's `score` (`Rolling_Coverage_Block::poll_response()`), and the feed's view script passes it on in the `newspack-rolling-coverage:poll` event (`src/blocks/shared/poll-event.ts`). The view script applies it to the scoreboards with the same `data-coverage-id`. A null score hides them.
- **Without one.** For each coverage among the scoreboards, `followScore()` checks `GET rolling-coverage/v1/coverages/{id}/score` on a timer, first as soon as the page's scripts have run, since a page cache can serve an old copy of the page. The URL is the same for every reader of the coverage, and the reply carries the same `Cache-Control` as a poll reply (`Rolling_Coverage_Block::poll_max_age()`), so the edge answers most readers, as for the feed's shared poll. The wait is `data-poll-interval` or the reply's `minPollInterval`, whichever is longer, and it backs off while checks fail with the feed's `pollWait()` (`poll-backoff.ts`). It skips a check while a feed for the coverage is checking on a timer (`data-check-state` other than `idle`, read with `readCheck()`), since that feed's polls carry the score. It waits while the tab is hidden and checks again when it shows, and stops once the coverage has ended (`archived`), whether the page rendered that way or a reply says so.

`applyScore()` (`apply-score.ts`) writes each part's text into its `data-rc-score` element, hides an empty one, and shows a hidden scoreboard again. In a scoreboard with `data-status-badge`, the status line instead gets the badge (`applyBadge()`): it adds the span to a line rendered empty, then sets its classes for the score's `badge` and its text, so a change of badge style or text swaps in place. When either team's score differs from the one it showed last (the rendered one, the first time), it writes "Riverbend FC 3, Toledo Beacon 1" (`scoreAnnouncement()`) into the live region. With several scoreboards of one coverage on the page, only the first announces, so each change is heard once. A change of names, status line or badge style updates silently. Nothing animates.

## Tests

- `tests/test-score.php`: the meta's sanitizing, the badge style and its default, saving and removing it through the coverages route, the capability, schema validation, the score route and its cache header, and the score in poll replies.
- `tests/test-scoreboard-block.php`: rendering the bound parts, the dash hidden from screen readers, nothing without a coverage, hidden until a score is added (and nothing for an ended coverage without one), the hidden empty status line, the status line as a badge and as text, Automatic, the feed's context, Lite Site pages, and the coverage-level item.
- `tests/js/scoreboard-apply.test.ts`: applying a score, when it is announced, hiding and showing the scoreboard, and swapping, adding and clearing the status badge.
- `tests/js/scoreboard-template.test.ts`: the inserted parts and their order, the defaults on block and classic theme presets, and the heading font lookup.

From this plugin's directory, run `../../../n test-php` (add `--filter <name>` for one test). The tests register the block from `src/blocks/scoreboard/block.json` when the build isn't there.
