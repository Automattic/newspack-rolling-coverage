# Follow Coverage block: development notes

The Follow Coverage block (`newspack-rolling-coverage/coverage-follow`) lets readers follow a coverage and get a push notification for each new entry, through OneSignal. Editor code lives in `src/blocks/coverage-follow/`, with its button template in `src/blocks/shared/follow-buttons.ts`. Server rendering lives in `includes/blocks/class-coverage-follow-block.php`, and sending in `includes/class-push-notifications.php`.

For how publishers use the block, see `README.md` in this directory.

## Attributes

- `coverageId`: 0 for Automatic, or a chosen coverage (Custom).

The block adds no markup of its own and has no class name support (`className` and `customClassName` are off, as is `reusable`). Everything readers see is its inner button.

## Inner blocks

`FOLLOW_BUTTONS_TEMPLATE` in `src/blocks/shared/follow-buttons.ts` is a `core/buttons` block holding one `core/button` with:

- `tagName: 'button'`, so the bound value never renders as a link. It only carries the follow tag to the view script.
- Its `url` bound to the `newspack-rolling-coverage/entry` source with the key `followTag`.

`edit.tsx` locks the template with `templateLock: 'all'`. Publishers can restyle the button and change its text, but not remove it or add blocks. Administrators can unlock the template and delete the button; the template rebuilds it on the next editor load. `save` returns the inner blocks' content.

## Server rendering

`Coverage_Follow_Block::render_block()` returns the inner button only when there is a coverage to follow and `should_render()` passes:

- **Which coverage.** `coverage_context()` asks `Page_Coverages::coverage_for_block()`, the same rules as the Coverage Status block: the surrounding Rolling Coverage block's coverage; else a chosen coverage that exists and isn't trashed; else, on single views, the first uncapped feed on the page or a breakout post's source entry's coverage.
- **`should_render( $status )`.** OneSignal is configured (`Push_Notifications::is_onesignal_configured()`) and the coverage is not archived.
- **Context for the button.** `add_coverage_context()` runs on `render_block_context` and hands the coverage ID and status to the blocks inside a Follow Coverage block, and to no other block.

The button's markup comes from `Entry_Bindings`:

- `get_value()` resolves `followTag` to `coverage_{id}` (`Push_Notifications::follow_tag()`), or to nothing when `should_render()` fails.
- `filter_button()` drops a bound button whose value is empty. Otherwise it adds `data-rc-follow`, `data-tag`, `data-label-following`, `data-blocked-message`, `data-error-message` and `aria-pressed="false"` to the `<button>`. The messages are translated on the server; the view script reads them from these attributes.

Inside a Rolling Coverage layout, the block counts as coverage-level (`Entry_Bindings::is_coverage_item()`) and renders once with the feed's coverage. `Rolling_Coverage_Block::render_coverage_blocks()` drops it when `should_render()` fails. None of the built-in layouts include it. In the editor, `src/blocks/rolling-coverage/feed-insertion.ts` keeps it out of a layout's pinned card and entry group.

## View script

`view.ts` wires every `button[data-rc-follow]` through `window.OneSignalDeferred`. OneSignal's synced tags (`coverage_{id}` set to `'1'`) are the source of truth for whether a reader follows; nothing is cached locally. A click flips every button sharing the tag at once, and reverts with the error message if the SDK doesn't answer within `SDK_WAIT_TIMEOUT_MS`. When the browser has already denied permission, it doesn't prompt, since the browser would not show the prompt again; it reverts and shows the blocked message.

The plugin does not load the OneSignal SDK. It relies on the OneSignal plugin's front-end SDK and its `OneSignalDeferred` queue.

## How notifications are sent

`Push_Notifications` sends one notification per entry, only to readers who follow that entry's coverage.

- **Opt-in.** The `_rolling_coverage_notify_on_publish` post meta, registered for the REST API (`register_meta()`). The "Push Notifications" panel sets it on an unpublished entry: `PushNotificationsControl` (`src/entry-editor/push-notifications-control.tsx`), shown in the block editor's sidebar by the `entry-editor` script (`enqueue_editor_panel()`, only when OneSignal is configured). The panel is the only opt-in UI: there is no classic meta box, since Newspack sites use the block editor. The meta key is protected (underscore prefix) so the Custom Fields box can't write it back; REST writes pass through the explicit `auth_callback`. It warns when no coverage of the entry has a canonical URL, since the notification links there, reading the read-only `rolling_coverage_has_notifiable_coverage` REST field.
- **Chat-sourced entries.** `Entry_Ingestion_Service::ingest()` fires `newspack_rolling_coverage_entry_ingested` for entries created from a chat source (Slack is the only one). `opt_in_ingested_entry()` opts them in, unless the entry has no words (an image alone), and schedules the send at once when the entry is already published.
- **Trigger.** `maybe_notify()` runs on `transition_post_status` to `publish`. It skips entries that already have `os_notification_id` meta, which OneSignal sets after a send, so a re-publish never notifies twice. During a REST request (the block editor, Slack), it schedules the send on the `newspack_rolling_coverage_send_notification` cron hook after 60 seconds (`REST_SEND_DELAY`) instead of sending, since OneSignal never sends during a REST request. REST requests always defer, so the opt-in the request carries decides. The `newspack_rolling_coverage_defer_notification` filter can force deferral for other publishes. A scheduled entry going live from cron is not a REST request, so `maybe_notify()` sends synchronously then, as it does for CLI and core Quick Edit publishes. The entries REST route writes the opt-in after the status change, so `maybe_notify()` can read a stale one: `settle_rest_publish()` (`rest_after_insert_rolling_cov_entry`) then reschedules the send for the next cron run (delay 0, with `spawn_cron()` unless WP-Cron is disabled) when the saved opt-in is ticked, and cancels it when it isn't.
- **Send.** `send()` takes a per-entry lock (`SEND_LOCK_PREFIX`, 60-second TTL), re-reads the meta uncached, and calls `onesignal_create_notification()` once per coverage with a canonical URL. It clears the opt-in after a send. A coverage without a canonical URL sends nothing and keeps the opt-in.
- **Audience and link.** `override_notification_fields()` filters `onesignal_send_notification` for that send. It replaces `included_segments` with a tag filter (`coverage_{id}` equals `'1'`), so only followers are notified, and sets `web_push_topic` to the same tag, so a newer update replaces an older one in the browser. The URL deep-links to the entry on the coverage's canonical URL (`Social_Sharing::get_entry_deep_link()`).

## Testing locally without OneSignal

`Push_Notifications::is_onesignal_configured()` gates both the block and sending. It requires:

1. OneSignal's v3 code to be loaded, detected by `function_exists( 'onesignal_create_notification' )` (`is_onesignal_v3_active()`).
2. The `OneSignalWPSetting` option with non-empty `app_id` and `app_rest_api_key`.

When either is missing, the block renders nothing, the Push Notifications panel is hidden and nothing is sent. The editor shows a notice instead (`onesignalConfigured` from `localize_editor_config()`). With both met but no OneSignal Web SDK on the page, the button renders, and clicks revert after 10 seconds with the error message.

The PHPUnit suite meets both conditions with `tests/mocks/onesignal.php`, which records notifications instead of sending them, and `configure_onesignal()` in `tests/class-rolling-coverage-testcase.php`.

## Tests

- `tests/test-coverage-follow-block.php`: coverage resolution, rendering inside a feed (once, above the entries, with its script enqueued), archived coverages, rendering nothing without OneSignal, no markup of its own, and context not leaking to later bound buttons.
- `tests/test-push-notifications.php`: opt-in, follower-only audience, duplicate guards, REST and cron scheduling, the send lock, and Slack-sourced entries.

From this plugin's directory, run `../../../n test-php` (add `--filter <name>` for one test). The tests register the blocks from `dist/`, so build first if it is stale.
