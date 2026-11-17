# Scoreboard block: development notes

The Scoreboard block (`newspack-rolling-coverage/scoreboard`) shows a coverage's score: two team names, a score for each and a status line. Editor code lives in `src/blocks/scoreboard/`, with the score's shape and helpers in `src/blocks/shared/score.ts`. Server rendering lives in `includes/blocks/class-scoreboard-block.php`, and the score itself in `includes/class-score.php`.

For how publishers use the block, see `README.md` in this directory. The admin's score row is under "Score" in `src/admin/DEVELOPMENT.md`.

## The score

`Score` keeps the score in the `rolling_coverage_score` term meta (`Score::META_KEY`), an object:

```json
{
	"teams": [
		{ "name": "Riverbend FC", "score": 2 },
		{ "name": "Toledo Beacon", "score": 1 }
	],
	"status": "Full time"
}
```

- A coverage without the meta has no score; `Score::get()` returns null. Most coverages aren't matches.
- The meta is registered with a JSON schema (`Score::schema()`): exactly two teams, each a name of 1 to 40 characters and a whole score from 0 to 999, and a status of up to 40 characters, which can be empty. `Score::sanitize()` normalizes what is stored: plain text cut to `MAX_TEXT_LENGTH`, scores clamped to 0 to `MAX_SCORE`, and null for anything without two named teams.
- It is read and written through the core coverages route, `/wp/v2/rolling-coverage/{id}`, as `meta.rolling_coverage_score`. With no `auth_callback`, writing it needs what editing the coverage needs (`edit_term`, which is `manage_categories` for this taxonomy), as with the status. Sending `null` deletes it. Reading it is public, like the coverage's other public meta.
- Writes send the whole object. Two editors changing the score at the same moment can overwrite each other's step; the last save wins.

## Attributes

- `coverageId`: 0 for Automatic, or a chosen coverage (Custom).

Everything else is block supports on the wrapper (colors, typography, padding, margin, border, block gap) and the inner blocks' own settings.

## Inner blocks and bindings

The block saves its inner blocks (`save` returns `InnerBlocks.Content`) and renders dynamically. New blocks get `scoreboardTemplate()` from `template.ts`: a flex group (no wrap, centered, block gap `spacing|40`) holding four paragraphs, the first team's name, its score, the second team's score and its name, then a centered paragraph for the status line. The names fill the space either side (`selfStretch: fill`), so the scores sit in the middle, and a long name wraps within its half at phone widths. Every choice is a block setting from the theme's presets, read when the block is inserted:

- **Font family.** Names and scores take the font family preset headings use: the site's global styles for headings, then the theme's, then the body's (`headingFontFamily()`, from core-data's global styles records). Without a preset, as on the classic Newspack Theme, they get no `fontFamily`; their `use-header-font` class gives the classic theme's heading font.
- **Sizes.** On a block theme scale (one with `xx-large`), names at Large and scores at XX-Large; on the classic Newspack Theme's scale, Normal and Huge. Names and scores are bold.
- **Status line.** X-Small (Small on the classic theme), in the muted text color the layouts' dates use (`mutedTextColor()`: `contrast-3` on the block theme, `medium-gray` on the classic theme), and no color where the palette has neither.

Each paragraph binds its `content` to the `newspack-rolling-coverage/score` source with a `key`: `team1Name`, `team1Score`, `team2Score`, `team2Name` or `status` (`Scoreboard_Block::KEYS`, `SCORE_KEYS`). Editors can restyle, move, remove or add blocks; any paragraph or heading inside the scoreboard can bind to a key.

- **Server.** `Scoreboard_Block::get_value()` reads the score of the coverage in the `newspack-rolling-coverage/coverageId` context, the key a Rolling Coverage feed sets for its layout's blocks, and returns the part escaped, or null without a score. Outside a feed, `add_coverage_context()` (`render_block_context`) puts the scoreboard's own coverage in that key for its direct inner blocks; core passes it on to blocks nested deeper.
- **Editor.** `bindings.ts` registers the same source. It reads the coverage's term record in the `view` context and shows its score, or the sample from `sample.ts` when the block has no coverage or the coverage no score. `edit.tsx` provides the coverage in context with `BlockContextProvider`.

`mark_part()` (`render_block`) adds `data-rc-score="{key}"` to each bound block's outer tag, for the view script to update, and `hidden` when its text is empty, such as a status line not set yet.

## Which coverage it shows

`render_block()` and `add_coverage_context()` ask `Page_Coverages::coverage_for_block()`, as Coverage Status does: the surrounding feed's coverage, else a chosen coverage while it exists and isn't trashed, else the first uncapped feed in the post being viewed or a breakout post's coverage, on single views only. See "Which coverage it shows" in `src/blocks/coverage-status/DEVELOPMENT.md`. The editor mirrors the rules with `useBlockCoverage()`.

Inside a feed it is a coverage-level item (`Entry_Bindings::is_coverage_item()`, `isCoverageItem()` in `template.ts`), so it renders once with the feed's coverage, and `feed-insertion.ts` keeps it out of entries and the pinned card.

## Rendering

With no coverage, or a coverage without a score, the block renders nothing. Otherwise it renders the inner blocks in the wrapper, with:

- `data-coverage-id` and `data-status` (the coverage's status),
- `data-score-url`, the coverage's score route,
- `data-poll-interval`, seconds between the page's own checks: `POLL_INTERVAL` (30, the feed's default) or the site's minimum poll interval when that is longer,
- an empty `div.screen-reader-text` with `aria-live="polite"` and `aria-atomic="true"`, for the view script's announcement.

On a Lite Site page (`Lite_Feed::is_lite_render()`) and in syndication feeds, which run no script, it renders the inner blocks in a plain wrapper, without the data attributes or the live region.

## Live updates

`view.ts` keeps every scoreboard on the page current, in two ways.

- **With a feed for the coverage on the page.** Every poll reply carries the coverage's `score` (`Rolling_Coverage_Block::poll_response()`), and the feed's view script passes it on in the `newspack-rolling-coverage:poll` event (`src/blocks/shared/poll-event.ts`). The view script applies it to the scoreboards with the same `data-coverage-id`. A null score hides them.
- **Without one.** For each coverage among the scoreboards, `followScore()` checks `GET rolling-coverage/v1/coverages/{id}/score` on a timer, first as soon as the page's scripts have run, since a page cache can serve an old copy of the page. The URL is the same for every reader of the coverage, and the reply carries the same `Cache-Control` as a poll reply (`Rolling_Coverage_Block::poll_max_age()`), so the edge answers most readers, as for the feed's shared poll. The wait is `data-poll-interval` or the reply's `minPollInterval`, whichever is longer, and it backs off while checks fail with the feed's `pollWait()` (`poll-backoff.ts`). It skips a check while a feed for the coverage is checking on a timer (`data-check-state` other than `idle`, read with `readCheck()`), since that feed's polls carry the score. It waits while the tab is hidden and checks again when it shows, and stops once the coverage has ended (`archived`), whether the page rendered that way or a reply says so.

`applyScore()` (`apply-score.ts`) writes each part's text into its `data-rc-score` element, hides an empty one, and shows a hidden scoreboard again. When either team's score differs from the one it showed last (the rendered one, the first time), it writes "Riverbend FC 3, Toledo Beacon 1" (`scoreAnnouncement()`) into the live region. A change of names or status line updates silently. Nothing animates.

## Tests

- `tests/test-score.php`: the meta's sanitizing, saving and removing it through the coverages route, the capability, schema validation, the score route and its cache header, and the score in poll replies.
- `tests/test-scoreboard-block.php`: rendering the bound parts, nothing without a score, the hidden empty status line, Automatic, the feed's context, Lite Site pages, and the coverage-level item.
- `tests/js/scoreboard-apply.test.ts`: applying a score, when it is announced, and hiding and showing the scoreboard.
- `tests/js/scoreboard-template.test.ts`: the inserted defaults on block and classic theme presets, and the heading font lookup.

From this plugin's directory, run `../../../n test-php` (add `--filter <name>` for one test). The tests register the block from `src/blocks/scoreboard/block.json` when the build isn't there.
