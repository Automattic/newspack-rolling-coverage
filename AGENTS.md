# AI agent instructions

Guidance for AI coding agents working in this repository. `CLAUDE.md` points here.

## Coverage in code, liveblog on screen

What the code calls a coverage (a `rolling_coverage` term and its entries) is a **liveblog** in anything a publisher or reader sees: UI strings, translator comments, block descriptions, `readme.txt`, block `README.md` files and the publisher pages in `site/`. "All Liveblogs", "Add Liveblog", "this liveblog has ended".

- Code keeps "coverage": identifiers, routes (`/coverages`), meta keys, options, hooks, REST schema descriptions, and the developer docs (`DEVELOPMENT.md`, `.github/CONTRIBUTING.md`, `site/content/docs/developers/`). Sites and other plugins depend on those names.
- The brand stays "Rolling Coverage": the plugin, its admin menu, the Rolling Coverage block, the Slack app, and the Coverage Status and Follow Coverage block titles.
- "Coverage" as a mass noun stays where it reads naturally: "Coverage of this news event has concluded", the "Live coverage" status label.
- Page slugs such as `create-your-first-coverage` stay, so existing links keep working.

## Keep the docs in step with the code

A change that alters how something works updates its docs in the same pull request. The docs describe the plugin as it is, so a doc left behind describes behavior that no longer exists.

- **Blocks** (`src/blocks/<block>/`) have two docs:
  - `README.md` is for publishers: what the block does and how to use it in the editor. Update it when a setting, option, label or visible behavior changes.
  - `DEVELOPMENT.md` is for developers: attributes, rendering, REST routes, and why the code is built the way it is. Update it when the structure or a contract changes.
  - The inner blocks `share` and `breakout-post-link` have no docs of their own; they are covered under "Share and Read more" in `src/blocks/rolling-coverage/DEVELOPMENT.md`.
- **Admin screens and the entry editor** (`src/admin/`, `src/entry-editor/`) share `src/admin/DEVELOPMENT.md`. Update the section for what you changed, or add one.
- **PHP in `includes/`** updates the docs of the feature it serves: a block's docs for `includes/blocks/`, `src/admin/DEVELOPMENT.md` for everything else.
- **`.github/CONTRIBUTING.md`** is the plugin-wide reference: it lists every REST route, hook, constant, option, meta key, cron event and capability, and summarizes each feature with links to its docs. Update it when you add, rename or remove one of those, or when a feature it summarizes changes what it does.
- **The docs site** (`site/`) is where publishers read all of this. A change to something publishers see also updates the matching page in `site/content/docs/`. See "Docs site" below.
- Describe the end state, not the history of the change. Rewrite or remove sentences the change makes untrue instead of appending to them.
- Quote UI labels exactly as they appear on screen.

## Build, lint and test

The plugin uses npm (not pnpm) and Composer.

```bash
npm ci && composer install   # Install dependencies
npm run build                # Build dist/ (never committed)
npm run watch                # Rebuild on change
```

- Composer autoloads `includes/` with a classmap. After adding a PHP class file, run `composer dump-autoload`, or the site and PHPUnit won't find the class.
- Change dependencies with the npm that ships with the Node version in `.nvmrc` (npm 11 at the time of writing). Older npm versions rewrite `package-lock.json`.

Lint before pushing:

```bash
npm run lint:js      # ESLint and Prettier
npm run typecheck    # TypeScript; ESLint and the build don't catch type errors
npm run lint:scss    # Stylelint
npm run lint:php     # PHPCS with this repo's phpcs.xml
npm run fix:js       # Auto-fix JS (also format:scss, fix:php)
```

- CI runs `lint:js`, `npm test`, PHPCS and PHPUnit. It does not run `typecheck` or `lint:scss`, so those two only catch errors when you run them.
- `npm run lint` runs Stylelint, ESLint, PHPCS and `typecheck` in that order and stops at the first failure. Run each one on its own to see every result.
- PHPCS checks PHP compatibility against `testVersion 7.2-` in `phpcs.xml` and rejects typed properties, so don't use them. Its ruleset doesn't recognize every newer feature, and the code already uses PHP 8.0 union types and PHP 7.4 arrow functions.

Tests:

- **PHP:** PHPUnit tests live in `tests/`. `npm run test:php` needs a WordPress test install, which `bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]` sets up. Inside the Newspack workspace, run the workspace's `n test-php` from this directory instead.
- **JS:** `npm test` runs `tests/js/shared-poll-check.ts`, which checks the view script's copy of the poll cursor rules against `tests/fixtures/poll-cursor-cases.json`, the cases PHPUnit checks the server's copy against. It then runs the Jest tests in `tests/js/` (`npm run test:js`): the layout template checks, which fail when a built-in layout's template changes without `npm run build:layouts` rewriting the server copies in `includes/blocks/layouts/`, the entries list's live sync tests, and the check of when a new layout pattern gets its Rolling Coverage block (`tests/js/layout-start.test.ts`).

## Docs site

`site/` is the publisher documentation site: an [Astro](https://astro.build/) and [Fumadocs](https://fumadocs.dev/) static site with its own npm project, lockfile and `.nvmrc` (Node 24). The plugin's `npm ci`, JS and SCSS lint, typecheck and build never touch it, and it never ships in the plugin zip (`/site` in `.distignore`). The plugin's PHPCS does check the PHP in `site/screenshots/`.

```bash
cd site
npm ci
npm run dev           # Local site at http://localhost:4321
npm run build         # Static build in site/dist/
npm test              # Content and color contrast checks
npm run check:build   # Checks site/dist/ after a build
npm run shot -- <section>/<name>-<n> --site <url>   # Retakes a screenshot
```

- Pages are MDX files in `site/content/docs/`; each folder's `meta.json` sets the sidebar order. The homepage is `site/src/components/home.tsx`, and the About page is `site/src/components/about.tsx`.
- **Writing rules**, enforced by `npm test` where they can be:
  - Publishers first: plain words, short steps, one action per step. US English, sentence-case headings, no em dashes.
  - Technical words (hooks, REST API, attributes, meta) belong only in `site/content/docs/developers/`, which explains what each thing is for before how to use it.
  - Quote UI labels exactly as they appear on screen, checked against the plugin source.
  - Every step that changes what's on screen gets a `<Screenshot label="..." />` naming the screen and what's highlighted.
- **Screenshots** live in `site/public/screenshots/<section>/<page>-<n>.webp` and all show the same demo site.
  - A change to a screen publishers see retakes the screenshots that show it, with the seed and capture command in `site/screenshots/`. [`site/screenshots/README.md`](site/screenshots/README.md) has the steps.
  - House settings, for checking a pull request's images: 3024×1964 WebP (a 1512×982 viewport at 2x), light mode, viewport only, the Newspack Block Theme with the seeded demo content, signed in as the seeded "James Smith" administrator, and at most one spotlight. `npm test` checks that every screenshot the docs pages use is a WebP of that size.
  - The seed runs only against a local or development single site set aside for screenshots, unless run with `force`, and puts back what it changed when run with `remove`. The capture command keeps its sign-in outside the checkout, in `~/.cache/newspack-rc-shot/`.
- **Hosting:** Spacefast, in the Automattic team, at `https://rolling-coverage.space.fast/`. Spacefast builds the site from this repository (root directory `site`) whenever the deploy branch changes. The deploy branch is `trunk`.
- **Updating the live site:** push to (or merge into) the deploy branch. Each build shows on the Space's Builds page in Spacefast and as the `Spacefast Builds` check on GitHub.
- **When a build doesn't start** (it can sit queued), sign in to Spacefast as a member of the Automattic team and sync the repository, then retry the latest build:

  ```bash
  npx -y spacefast@latest git sync --space rolling-coverage --team automattic
  npx -y spacefast@latest redeploy --space rolling-coverage --team automattic
  ```

  Don't publish `site/dist` by hand with `spacefast publish`: it uploads only part of the build, and the live site loses most of its pages.

- **Access:** members of the Automattic team on Spacefast, plus a password for invited publishers. The password is managed in the Space's Access settings and never goes in this repository.
- Spacefast's local `.spacefast/` folder is never committed (`site/.gitignore`).
- The root `docs/` folder is gitignored and is not where the site goes.
