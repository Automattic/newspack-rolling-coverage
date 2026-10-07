# AI agent instructions

Guidance for AI coding agents working in this repository. `CLAUDE.md` points here.

## Keep the docs in step with the code

A change that alters how something works updates its docs in the same pull request. The docs describe the plugin as it is, so a doc left behind describes behavior that no longer exists.

- **Blocks** (`src/blocks/<block>/`) have two docs:
  - `README.md` is for publishers: what the block does and how to use it in the editor. Update it when a setting, option, label or visible behavior changes.
  - `DEVELOPMENT.md` is for developers: attributes, rendering, REST routes, and why the code is built the way it is. Update it when the structure or a contract changes.
  - The inner blocks `share` and `breakout-post-link` have no docs of their own; they are covered under "Share and Read more" in `src/blocks/rolling-coverage/DEVELOPMENT.md`.
- **Admin screens and the entry editor** (`src/admin/`, `src/entry-editor/`) share `src/admin/DEVELOPMENT.md`. Update the section for what you changed, or add one.
- **PHP in `includes/`** updates the docs of the feature it serves: a block's docs for `includes/blocks/`, `src/admin/DEVELOPMENT.md` for everything else.
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

- CI runs `lint:js`, PHPCS and PHPUnit. It does not run `typecheck` or `lint:scss`, so those two only catch errors when you run them.
- `npm run lint` runs Stylelint, ESLint, PHPCS and `typecheck` in that order and stops at the first failure. Run each one on its own to see every result.
- PHPCS checks PHP compatibility against `testVersion 7.2-` in `phpcs.xml` and rejects typed properties, so don't use them. Its ruleset doesn't recognize every newer feature, and the code already uses PHP 8.0 syntax such as union types and arrow functions.

Tests:

- **PHP:** PHPUnit tests live in `tests/`. `npm run test:php` needs a WordPress test install, which `bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]` sets up. Inside the Newspack workspace, run the workspace's `n test-php` from this directory instead.
- **JS:** there are no JS unit tests.
