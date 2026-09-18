# Integration Audit — Cross-Feature Issues

**Status:** Open — for later remediation
**Scope:** Cross-feature edge cases and invalid implementations only. Feature set and design are intentional/client-approved; spec-vs-code divergence is NOT tracked here.
**Method:** Five parallel read-only seam audits plus a dedicated verification pass (attempted refutation + compensating-guard search + missed-seam sweep).

> Line numbers are approximate and may drift; function/constant names are authoritative.

---

## Build-state caveats (not code defects)

These make the current working copy unbootable but are stale build artifacts, not implementation bugs:

- `vendor/composer/autoload_classmap.php` (generated Sep 2) does not contain `Archive_Mode` or `Coverage_Archived_Notice_Block` (added Sep 17), and there is no PSR-4 fallback for `Newspack_Rolling_Coverage`. `Initializer::includes()` calls `Archive_Mode::init()` → fatal `Class not found`. Fix: `composer dump-autoload`.
- `dist/blocks/coverage-archived-notice/` was never built. `Coverage_Archived_Notice_Block::register_block()` points at a non-existent path; `register_block_type()` then treats the path as a block name and fails silently. Fix: `npm run build`.

Both are handled by `npm run release:archive`, but no CI check catches them (CI never builds `dist/` before PHPUnit).

---

## P0 — Data loss / corruption

### P0-1. Cleanup cron can permanently delete unrelated entries
**Where:** `Post_Type::cleanup_orphaned_entries()` (`includes/class-post-type.php`, `CLEANUP_CRON_HOOK`).
**Two independent triggers:**
- If `get_terms()` returns empty or a `WP_Error` (coerced to `[]`), the `NOT IN` tax clause becomes a SQL no-op (core `WP_Tax_Query::get_sql_for_clause()` returns empty SQL for empty terms). The query then matches every entry with `META_ORIGINAL_COVERAGE_ID EXISTS` and force-deletes them (`wp_delete_post($id, true)`).
- Semantically, it treats *any* entry that lost its term assignment but retained original-coverage meta as orphaned — including entries deliberately unassigned via REST (`rolling-coverage: []`) or a failed reassignment — and hard-deletes them. It never checks that the original coverage was actually deleted.

**Fix direction:** short-circuit when the term list is empty/errored (never build an empty `NOT IN`); scope deletion to entries whose original coverage no longer exists; trash before permanent delete or require an explicit flag.

### P0-2. Breakout "read more" text is silently wiped
**Where:** `Post_Type::map_row()` omits `rolling_coverage_breakout_read_more_text`; `toEntry()` (`src/admin/utils/entries-api.ts`) drops it; `BreakoutModal` loads `''`; `saveBreakoutSettings()` POSTs `''` over the stored value. The reader fallback then shows "Read more".
**Fix direction:** include the meta in `map_row`/`toEntry` (or have the modal fetch it); avoid writing an unchanged/empty value.

### P0-3. Archive Mode lock has multiple bypasses
**Where:** `Archive_Mode` (`includes/class-archive-mode.php`).
- `block_rest_writes()` only inspects the REST `rolling-coverage` request field → classic-editor `tax_input` and the omitted-field early-return allow assigning/publishing into an archived coverage.
- `restrict_archived_entry_caps()` guards only `delete_post` and skips posts already in `trash` → permanent-delete, untrash, and REST `PUT {status:'draft'}` free locked trashed entries. Only the plugin's own `restore_entry()` checks the lock.
- `cleanup_orphaned_entries()` force-deletes locked entries.
- A draft already assigned to an archived coverage can transition to `publish`; `Push_Notifications::maybe_notify()` has no status guard → followers get notified.
- `Breakout::on_breakout_post_status_change()` calls `wp_update_post()` on the source entry without a lock check.

**Fix direction:** extend `is_entry_locked()` to include coverage `trash`; add `set_object_terms`/`added_term_relationship`, `pre_untrash_post`, `pre_delete_post` guards; cover classic `tax_input`; block the publish transition into archived coverage; make cron/breakout respect the lock; gate push on coverage status.

---

## P1 — Features break each other

### P1-1. Two blocks for one coverage destroy each other's template config
**Where:** `Rolling_Coverage_Block::persist_block_config()` prunes the single `rolling_coverage_template_hash` term meta on every SSR. A host page embedding two blocks for the same `coverageId` with different templates deletes the other's `rc_tpl_*` option; that block's readers fall back to the default template on poll (`load_block_config()`).
**Fix direction:** key config by coverage + block identity, or stop pruning on render.

### P1-2. Pinned-first ordering breaks pagination and structured data
**Where:** `orderby_pinned_first` runs on queries that don't set `SKIP_PIN_ORDER_VAR`:
- Reader load-more (`get_entries` `before` branch) → pinned entries re-fetched (duplicates; `view.ts` appends without ID dedupe) or date gaps skip newer non-pinned entries.
- `Schema::build_updates()` → `liveBlogUpdate` is pinned-first, not reverse-chronological, and pinned entries can push newer ones out of the page.
- Also omitted in `run_page_mode()` and `Social_Sharing::resolve_entry_by_slug()`.

**Fix direction:** set `SKIP_PIN_ORDER_VAR` for load-more, schema, and the public lookup; dedupe by ID on append.

### P1-3. Public entries listing is corrupted by `filter_rest_query`
**Where:** `Post_Type::filter_rest_query()` **replaces** the caller's `tax_query`, so `?rolling_coverage=<id>` / category / tag filters silently return entries from all visible coverages. It also filters via raw `meta_query NOT LIKE 'trash'`, which uses an INNER JOIN → coverages with no status meta row (notably `{slug}-recovery` terms created by `restore_entry`) vanish from `/wp/v2/rolling-coverage-entries`.
**Fix direction:** merge the visibility clause into the existing tax_query instead of replacing it; use `NOT EXISTS OR NOT LIKE` for the status meta.

### P1-4. `entries-view` leaks other authors' private/draft/trash entries
**Where:** `Post_Type::run_page_mode()` / `run_sync_mode()` permission is `edit_posts`; query defaults to `ALLOWED_STATUSES` (includes `private`, `draft`, `pending`, `future`, `trash`) with no per-post or per-author capability filter. `map_row()` returns full fields.
**Fix direction:** apply an author/capability filter like core's posts controller, or honor `edit_others_posts`.

### P1-5. Push deep links never scroll to the entry
**Where:** `Push_Notifications::resolve_coverage_url()` emits `#{post_name}`, but the DOM anchor is `{prefix}-entry-{ID}`. `handleDeepLink()` (`src/blocks/rolling-coverage/view.ts`) returns early when the slug is already in the DOM and relies on native `#anchor` scroll, which resolves to nothing. Social sharing uses the correct ID anchor.
**Fix direction:** emit the ID-based hash (`newspack-rolling-coverage-entry-{ID}`) or add a slug→element scroll fallback.

### P1-6. Sync cursor holes (readers and admins)
**Where:** `Rolling_Coverage_Block::get_entries()` and `Post_Type::run_sync_mode()` share the `rolling_coverage_last_modified` short-circuit.
- `last_modified <= cursor` drops **same-second** new entries (writer stores the post's own `post_modified_gmt`, not `now`).
- `on_set_object_terms()` writes `post_modified_gmt` unconditionally → can move `last_modified` **backwards** and hide entries; reassignment never touches the **old** coverage.
- **Future-dated entries poison both cursors (confirmed via seed):** a scheduled entry's `post_modified_gmt` is in the future. Two effects: (a) the `transition_post_status` / `on_set_object_terms` hooks write that future value into `rolling_coverage_last_modified`; and (b) `Post_Type::coverage_sync_cursor()` builds the admin page cursor from the newest `post_modified_gmt` across **all** `SYNC_STATUSES`, including `future`, so the cursor itself is anchored in the future. Both make the 10s admin sync poll short-circuit (`last_modified <= cursor`), so **no real-time sync notices fire** even when new entries are added, until wall-clock time passes the future timestamp. `wp_schedule` entries are the trigger.
- Pin/unpin and breakout create/status writes never advance the cursor.
- Readers get no removal signal (trashed/unpublished/deleted entries remain as ghost DOM nodes).
- Admin `removed` is dead code; `mergeSyncDelta()` drops page>1 new rows while the cursor still advances; page 1 grows past `perPage`; `totalItems`/`totalPages` never update.

**Fix direction:** monotonic `max()` writes; touch old+new coverage on reassignment; add writers for pin/breakout; use `<` not `<=`; emit removals to readers; implement `removed` for admins; fix merge pagination.

### P1-7. Coverage-level changes have no live signal
**Where:** `Taxonomy::handle_trash_coverage()` / `handle_restore_coverage()` / `handle_delete_coverage()` don't touch `last_modified` or notify. Open readers only 404 on `trash` (paused/archived keep serving), and `view.ts` swallows poll errors while leaving DOM intact. Other admins' coverage rows are stale until reload (`useCoverages` has no interval).
**Fix direction:** have the reader poll reflect coverage status changes; surface coverage changes to admin clients.

---

## P2 — Permissions / edge cases

- **P2-1. Capability model inconsistent.** `capabilities.canEditPosts`/`canManageTerms` are bootstrapped (`class-admin.php`) but unused. Coverages menu is `edit_posts` while list fetch uses `context=edit` (needs `manage_categories`) → authors get a broken page. Coverage Trash/Restore/Delete and Slack Connection actions lack cap `isEligible` → 403 after click. Coverage modal always writes `ads_disabled` (needs `manage_options`) → editors get a partial write + false error. Coverage create is allowed at `edit_posts` (term inserted, then meta write fails → orphan).
- **P2-2. Coverage status is free-form** (no enum/sanitize). A near-miss value renders a live feed while client polling stays permanently off and no `coverageEndTime` is snapshotted; `archived` terminality is unenforced; `archived → trash → active` bypasses it.
- **P2-3. `trash` not guarded in ingestion / push / AI.** Only exact `archived` is checked (`Entry_Ingestion_Service::ingest`), push has no status check, AI only checks `term_exists`. Slack ingests into trashed coverages (entries resurface on restore); AI summarizes trashed-coverage content; push can notify for trashed/archived coverage. `is_entry_locked()` ignores `trash`.
- **P2-4. Slack robustness.** Trashed entries aren't deduped (`post_status => 'any'` excludes `trash` in core) → re-delivery duplicates. No `term_exists` re-validation → unpurgeable orphans. Fresh-lock skip returns HTTP 200 → a crashed first process loses the message. `update_channel`/`remove_channel` read-modify-write can resurrect an unlinked channel. `on_term_deleted` removes only the first matching channel and is only registered when Slack is configured at init. Real Slack author never surfaced (comment references a filter that doesn't exist).
- **P2-5. Multi-coverage divergence.** `get_coverage_status_rest_field()` reads the first term (name ASC) while `Archive_Mode::is_entry_locked()` checks all → the admin UI offers actions the server 403s. Push sends once per coverage for a multi-coverage entry.
- **P2-6. Post-meta auth asymmetry.** Slack/source post-meta omit `auth_callback` (default `__return_true`), so an editor can overwrite `rolling_coverage_source_ref` and poison dedupe, or spoof provenance. Term-meta equivalents require `manage_options`.
- **P2-7. Ads load-more is dead under defaults.** `is_capped_ad_position()` uses an absolute cap (`<= ads_interval * 3`) while load-more starts at `entry_offset = entriesPerPage` (default 20) → never places an ad. SSR (1-based) / load-more / poll (independent modulo, uncapped) offsets are inconsistent; the editor help text promises 3 ads for initial + load-more.
- **P2-8. Schema cache/invalidation.** `nrc_*` transient is never invalidated on coverage rename or host-post slug change (stale `headline`/`url` up to a week). `build_updates()` includes individually-archived entries' full `articleBody`, uses the bot author for Slack entries, and (per P1-2) pinned-first ordering.
- **P2-9. Social sharing.** `rc_source` is not validated for source-post public viewability (trashed/private source redirects readers to 404/protected). Public REST accepts an arbitrary existing `host_post_id` (echoed into share URLs).
- **P2-10. Pinned option never pruned.** `rolling_coverage_pinned_entries` retains permanently-deleted entry IDs forever (autoloaded), and they are injected into every CPT query's `CASE/FIELD` clause.
- **P2-11. Bulk partial success.** `runCoverageBulk`/`runEntryBulk`/`runArchiveBulk` treat any failure as total failure and skip refresh, leaving stale rows after partial server-side changes. The restore flow handles partial success differently (inconsistent semantics).
- **P2-12. No uninstall path.** No `uninstall.php`. Deactivation only flushes rewrites, unschedules the cleanup cron, and cleans the Slack monitor log file. Left behind: bot token + signing secret (plaintext), Slack settings/channel map/bot user/bot WP user, `rolling_coverage_pinned_entries`, AI settings, every `rc_tpl_*` option, all term/post meta, `nrc_*` transients, `rolling_coverage_ai_available`, per-user Slack transients, ingest mutex options, and the uploads log dir (`.htaccess`/`index.php`).
- **P2-13. Admin asset workaround latent fatal.** `Admin::enqueue_assets()` unconditionally `add_action('enqueue_block_editor_assets', ['Newspack\Blocks','enqueue_block_editor_assets'])` after removing it; if newspack-plugin is absent, a later fire of that action fatals. No `class_exists` guard; priority drift breaks the removal silently.
- **P2-14. `handle_delete_coverage()` has no status precondition** → a `manage_categories` user can permanently delete an active coverage via REST, bypassing the UI's trash-first gate, immediately orphaning entries.

---

## False positives eliminated (compensating guards found)

- **Ads for trashed coverage** — every call site is behind a `'trash'` guard.
- **Schema for trashed coverage** — `build_metadata()` returns null for `'trash'`.
- **Ad interval divide-by-zero** — all call sites clamp `max(1, …)`.
- **Schema leaking password-protected bodies** — `has_password => false` + `post_password_required` guard.
- **Archive toggle not advancing the cursor** — `handle_set_entry_archived()` calls `wp_update_post()`, which advances `last_modified`.
- **Slack draft ingestion missing the cursor** — `on_set_object_terms()` writes `last_modified` after term assignment, correctly compensating insert-then-assign order.
- **Missing inner-block `dist/` causing a fatal** — every manual enqueue site is guarded by `if ( $block_type )`; the block simply renders empty.

---

## Remediation plan (centralize shared invariants)

The recurring root cause is that features re-implement the same rules inconsistently. Recommended approach:

1. **Coverage-visibility invariant** — one helper in `Taxonomy` (`live/paused/archived/trash`, enum-validated status) consumed by block SSR + reader REST, `filter_rest_query`, `entries-view`, ingestion, push, ads, AI, schema, social. Clear `END_TIME_META_KEY` on unarchive; define terminality.
2. **Lock invariant** — extend `Archive_Mode::is_entry_locked()` to `trash`; add term-relationship and untrash/delete guards; cover classic `tax_input`; block publish into archived coverage; make cron/breakout respect it.
3. **Cursor invariant** — new `Sync_Cursor` helper: monotonic writes, old+new coverage touch, complete writer coverage, `<` comparison, removals, merge pagination.
4. **Data-integrity fixes** — cron empty-terms guard + scoped orphans; `read_more_text` preservation; `filter_rest_query` merge; `SKIP_PIN_ORDER_VAR` for load-more/schema; per-block template keys; `entries-view` capability/author filter; post-meta `auth_callback`.
5. **Push/social/ads/AI/schema correctness** — ID anchor, status gating, single send, `rc_source` validation, ad offsets, AI guard, schema invalidation, `uninstall.php`.
6. **Capability alignment** — consume `canManageTerms`, fix menu-vs-fetch cap, split `ads_disabled` write, align `isEligible` with server callbacks.
7. **Regression guard** — integration tests for each invariant (archive lock matrix, cursor same-second/regression, Slack dedupe incl. trash, cron empty-terms, `filter_rest_query` merge, template thrash) + a CI step that builds `dist/` before PHPUnit.
