# Newspack Rolling Coverage: Agent Instructions

This file covers what is specific to `newspack-rolling-coverage`. Where a shared
workspace `AGENTS.md` exists (e.g. `../../AGENTS.md`), shared conventions
(Docker, the `n` script, coding standards, git rules) live there and take
precedence.

## Project overview

Newspack Rolling Coverage is a live-blog plugin. It provides:

- A `rolling_coverage` taxonomy (one term per news event).
- A `rolling_cov_entry` custom post type (individual updates in the feed).
- Six Gutenberg blocks for rendering the feed and its supporting UI.
- A React/TypeScript admin app for managing coverages and entries.
- Integrations: Slack ingestion, OneSignal push, Newspack Ads, WordPress AI.

See `README.md` for the full technical reference and `readme.txt` for the
end-user feature overview.

## Common gotchas

- **Composer uses a `classmap`, not PSR-4.** Every PHP file under `includes/` is
  auto-discovered, but the map is static. After adding a file, run
  `composer dump-autoload`.
- **New feature classes must be registered in `Initializer::includes()`**
  (`includes/class-initializer.php`). The autoloader alone does not wire a class
  into the lifecycle.
- **`npm run lint` runs SCSS, JS, PHP, and TypeScript.** For PHP alone use
  `npm run lint:php`; for types alone use `npm run typecheck`.
- **`@wordpress/dataviews` is imported from `@wordpress/dataviews/wp`** in the
  admin app.
- **There are no JS unit tests.** `npm test` is an intentional no-op
  (`echo 'No JS unit tests in this repository.'`). PHP tests are authoritative.
- **Block entries are auto-discovered from `block.json`** by
  `getWebpackEntryPoints('script')()`; only the admin app is a hardcoded webpack
  entry (`src/admin/index.tsx`).
- **REST namespace is shared.** `NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE`
  (`rolling-coverage/v1`) is used by both the core plugin routes and the Slack
  integration (`Slack::REST_NAMESPACE` has the same string).
- **Sensitive meta is gated on read, not by `auth_callback`.** On entry post
  meta (`Post_Type`), `auth_callback` is absent, so WordPress gates writes via
  the post's `edit_post` capability and does **not** gate reads. The actual read
  protection is `filter_rest_response()`, which strips `Post_Type::RESTRICTED_META`
  in the non-`edit` context. On term meta (`Taxonomy`), both mechanisms are used:
  `auth_callback` gates writes (`manage_options`) and `filter_rest_response()`
  strips restricted keys on read.
- **Pin ordering is global.** `orderby_pinned_first()` runs on `posts_orderby`
  for any `rolling_cov_entry` query. Opt out with the
  `rolling_coverage_skip_pin_order` query var (the frontend feed and sync cursor
  deliberately do).
- **Archive mode locks entries.** Entries in archived coverages cannot be
  deleted, restored, pinned, or broken out, and cannot be assigned *into* an
  archived coverage. Removing an entry from an archived coverage is still
  possible server-side (only blocked client-side). Check
  `Archive_Mode::is_entry_locked()` before adding new mutations.
- **`.distignore`, not `.gitignore`, controls the distributable.** Sources,
  tests, tooling, and developer docs are excluded; compiled `dist/`, `includes/`,
  `languages/`, and production `vendor/` ship.

## Dominant pattern for new PHP classes

```php
<?php
/**
 * Feature description.
 *
 * @package Newspack_Rolling_Coverage
 */

namespace Newspack_Rolling_Coverage;

defined( 'ABSPATH' ) || exit;

class My_Feature {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_things' ] );
	}
}
```

Then:

1. Add `My_Feature::init();` to `Initializer::includes()`
   (`includes/class-initializer.php`).
2. Run `composer dump-autoload`.
3. Run `npm run lint`.

## PHP conventions

- All code lives in the `Newspack_Rolling_Coverage` namespace.
- Classes are namespaced files named `class-<slug>.php`; subdirectories use the
  same namespace (`includes/ai/`, `includes/slack/`, `includes/blocks/`,
  `includes/sources/`).
- Feature classes use the static `init()` pattern and register hooks there.
- REST route callbacks and permission callbacks are static methods.
- Follow WordPress + VIP coding standards (`phpcs.xml`), with docblocks on every
  class, method, and property.

## Frontend conventions

- Admin app: React function components in TypeScript (`src/admin/`), hash-based
  routing (`react-router` v7), `@wordpress/components` and `@wordpress/dataviews`.
- API calls go through `src/admin/utils/*-api.ts` using `@wordpress/api-fetch`.
- Blocks: `block.json` + `index.tsx` (registration), `edit.tsx` (editor), and
  `view.ts` (frontend) when the block needs frontend behavior (the
  `coverage-archived-notice` block has no `view.ts`). Server render callbacks
  live in `includes/blocks/`.
- Dynamic blocks return `null` or `<InnerBlocks.Content />` from `save`.
- SCSS styles are colocated per component; shared admin styles live in
  `src/admin/styles/`.

## Testing

```bash
npm run test:php          # ./vendor/bin/phpunit
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest  # install WP test lib
```

- Tests extend `Rolling_Coverage_TestCase` (`tests/class-rolling-coverage-testcase.php`)
  which re-registers the CPT, taxonomy, and meta because WordPress unregisters
  them between tests.
- Use the `create_coverage()` / `create_entry()` / `log_in_as()` / `dispatch()`
  helpers in the base class.
- Mocks: `tests/mocks/newspack-theme.php`, `tests/mocks/onesignal.php`.
- Slack outbound HTTP is mocked with the `pre_http_request` filter.

## Recipes

### Add a REST route

1. Create or extend a class with a static `register_routes()` method.
2. Hook it to `rest_api_init` in the class `init()`.
3. Use `NEWSPACK_ROLLING_COVERAGE_REST_NAMESPACE` and provide
   `permission_callback`, `args`, and sanitize/validate callbacks.
4. Add tests in `tests/` using the base class `dispatch()` helper.

### Add a block

1. Create `src/blocks/<slug>/` with `block.json`, `index.tsx`, `edit.tsx`, and
   `view.ts` as needed.
2. Add a server class in `includes/blocks/` and register it in `Initializer`.
3. Run `npm run build`; the block is auto-discovered from `block.json`.

### Add a chat source adapter

1. Produce a `Source_Event_Payload` and call
   `Entry_Ingestion_Service::ingest()`, which handles dedup, the mutex, and
   assignment.
2. Reuse `Post_Type::META_ENTRY_SOURCE` and `Post_Type::META_SOURCE_REF` for the
   platform slug and canonical dedup key.
3. Register admin routes with `manage_options` permission and webhook routes
   behind signature verification.

## Git and releases

- Use Conventional Commits (`feat:`, `fix:`, `chore:`, `docs:`, `test:`, etc.).
- Never commit unless explicitly asked.
- Releases run through `semantic-release` (`newspack-scripts`) on the `release`
  branch; `alpha`, `hotfix/*`, and `epic/*` are prereleases; `trunk` is
  development only.
