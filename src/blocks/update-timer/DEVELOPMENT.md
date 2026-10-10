# Update Timer: development

## Rendering

`Update_Timer_Block::render_block()` (`includes/blocks/class-update-timer-block.php`) resolves the coverage with `Page_Coverages::coverage_for_block()`, as Coverage Status and Follow Coverage do: the surrounding feed's context, else a followable chosen coverage, else the page's first full feed or a breakout post's coverage. It renders nothing in RSS feeds, on Lite Site renders (`Lite_Feed::is_lite_render()`, which prints no view script), without a coverage, or for an ended one. Otherwise it renders the wrapper `hidden`, with `data-coverage-id`, an 18-unit SVG spinner (`pathLength="100"`, so the stylesheet draws its half arc as `50 50`) and an empty text span. Nothing shows without JavaScript. The feed preloads the block's style and view script (next to Coverage Status), so a timer in an entry that a poll or load more brings in has them.

Inside a feed it is a coverage-level item (`Entry_Bindings::is_coverage_item()`), so it renders once with the feed's coverage, and the editor keeps it out of entries and the pinned card (`feed-insertion.ts`, with Follow Coverage and Check for Updates).

## Following the feed

The block has no polling of its own. It follows the Rolling Coverage feed's check state: the root attributes and the `newspack-rolling-coverage:check` event described in the feed's [DEVELOPMENT.md](../rolling-coverage/DEVELOPMENT.md) (`src/blocks/shared/check-event.ts`).

- **Which feed.** `chooseFeed()` takes the enclosing feed when its `data-coverage-id` matches the timer's. Otherwise it keeps the feed it follows while that one isn't `idle` or capped (`data-latest`), else takes the first non-`idle` feed on the page for the coverage without `data-latest` (as the server's Automatic mode skips capped feeds), then any non-`idle` one. It re-chooses on every check event, so a timer moves to another feed when its own goes idle or leaves the page, and a switch clears the previous feed's result. A detached timer stops its tick.
- **Load order.** On load each timer reads its feed's root attributes, so it doesn't matter which view script runs first.
- **States.** The timer copies the feed's state to its own `data-check-state` and is hidden while `idle`. `checking` shows "Checking…". `waiting` ticks the text every 250ms; a result carried by the report replaces the countdown text for three seconds when it brought entries or failed; a check that found nothing shows no result.

## The spinner

It copies the Newspack UI loading spinner rather than using it, since the plugin runs without Newspack: `currentcolor`, stroke 1.5 of 18, no track, a half arc rotating every 900ms. It turns whenever the timer shows, in the editor too; only the text follows the feed's state. The `showSpinner` attribute (Settings > Spinner, default `true`) leaves the SVG out of the render and the preview when `false`. With `prefers-reduced-motion: reduce` the stylesheet hides it, since a spinner that runs for as long as the coverage is live can't otherwise be paused.

## Labels

`nextCheckLabel()` and `newEntriesFoundLabel()` in `src/blocks/rolling-coverage/entry-name.ts`. The entry name comes from the followed feed's `data-entry-name`, so the block needs no setting of its own.

## Editor preview

The canvas shows the countdown at the full interval of the feed the timer would follow: the Rolling Coverage block around it, else the first one in the post for its coverage (uncapped first), else the block's default of 30 seconds. A site minimum poll interval (`newspackUpdateTimerBlock.minPollInterval`, from `Rolling_Coverage_Block::get_min_poll_interval()`) raises it, as it does on the site. The lookup covers every block the editor has loaded, synced patterns and a shown template included. With no coverage to follow (Automatic in a template), the preview shows the default.
