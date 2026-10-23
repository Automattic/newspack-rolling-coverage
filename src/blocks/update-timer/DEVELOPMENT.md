# Update Timer: development

## Rendering

`Update_Timer_Block::render_block()` (`includes/blocks/class-update-timer-block.php`) resolves the coverage with `Page_Coverages::coverage_for_block()`, as Coverage Status and Follow Coverage do: the surrounding feed's context, else a followable chosen coverage, else the page's first full feed or a breakout post's coverage. It renders nothing in RSS feeds, on Lite Site renders (`Lite_Feed::is_lite_render()`, which prints no view script), without a coverage, or for an ended one. Otherwise it renders the wrapper `hidden`, with `data-coverage-id`, an 18-unit SVG ring (a track circle, then the `__ring-arc` circle with `pathLength="100"`, so the stylesheet and the view script measure the arc in hundredths) and an empty text span. Nothing shows without JavaScript. The feed preloads the block's style and view script (next to Coverage Status), so a timer in an entry that a poll or load more brings in has them.

Inside a feed it is a coverage-level item (`Entry_Bindings::is_coverage_item()`), so it renders once with the feed's coverage, and the editor keeps it out of entries and the pinned card (`feed-insertion.ts`, with Follow Coverage and Check for Updates).

## Following the feed

The block has no polling of its own. It follows the Rolling Coverage feed's check state: the root attributes and the `newspack-rolling-coverage:check` event described in the feed's [DEVELOPMENT.md](../rolling-coverage/DEVELOPMENT.md) (`src/blocks/shared/check-event.ts`).

- **Which feed.** `chooseFeed()` takes the enclosing feed when its `data-coverage-id` matches the timer's. Otherwise it keeps the feed it follows while that one isn't `idle` or capped (`data-latest`), else takes the first non-`idle` feed on the page for the coverage without `data-latest` (as the server's Automatic mode skips capped feeds), then any non-`idle` one. It re-chooses on every check event, so a timer moves to another feed when its own goes idle or leaves the page, and a switch clears the previous feed's result. A detached timer stops its tick.
- **Load order.** On load each timer reads its feed's root attributes, so it doesn't matter which view script runs first.
- **States.** The timer copies the feed's state to its own `data-check-state` and is hidden while `idle`. `checking` shows "Checking…". `waiting` ticks the text every 250ms; a result carried by the report replaces the countdown text for three seconds when it brought entries or failed; a check that found nothing shows no result.

## The ring

It copies the look of the Newspack UI spinner (the `spinner` mixin in newspack-plugin's `src/newspack-ui/scss/_mixins.scss`) rather than using `.newspack-ui__spinner`, a full overlay, since the plugin runs without Newspack: a 2-unit stroke of 18 in `currentcolor` over a track at 30% opacity, scaling with the text at `1.125em`. It is `aria-hidden`: the text carries the same information.

`syncRing()` (`ring.ts`) moves it with the feed's state, from `render()` on every report. Both motions use the Web Animations API, so the view script can start the countdown part way through and stop either at once.

- **`waiting`:** the arc's `stroke-dashoffset` runs from 100 to 0, linear, over the feed's `data-check-interval`, starting at the share of the wait already gone, so the ring is full when the check is due. A report of the same wait keeps the running fill.
- **`checking`:** the fill stops, the stylesheet draws a quarter arc and the SVG turns once every `SPIN_MS` (900ms, `src/blocks/shared/motion.ts`, the Newspack UI spinner's speed), linear, until the next report.
- **`idle`:** nothing runs. The feed reports `idle` while the tab is hidden and when the coverage pauses or ends, and the timer is hidden then too.

With `prefers-reduced-motion: reduce` the ring never turns: while checking it shows the full ring, still. The countdown still fills, since it is a slow progress indicator that changes with the text rather than movement across the page.

The editor preview shows the ring still and empty, as at the start of a wait, beside the full interval. The `showSpinner` attribute (Settings > Ring, default `true`) leaves the SVG out of the render and the preview when `false`.

## Labels

`nextCheckLabel()` and `newEntriesLabel()` in `src/blocks/rolling-coverage/entry-name.ts`. The entry name comes from the followed feed's `data-entry-name`, so the block needs no setting of its own.

## Editor preview

The canvas shows the countdown at the full interval of the feed the timer would follow: the Rolling Coverage block around it, else the first one in the post for its coverage (uncapped first), else the Rolling Coverage block's default Poll interval (30 seconds, read from its `block.json`). A site minimum poll interval (`newspackUpdateTimerBlock.minPollInterval`, from `Rolling_Coverage_Block::get_min_poll_interval()`) raises it, as it does on the site. The lookup covers every block the editor has loaded, synced patterns and a shown template included. With no coverage to follow (Automatic in a template), the preview shows the default.
