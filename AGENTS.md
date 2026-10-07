# AI agent instructions

Guidance for AI coding agents working in this repository. `CLAUDE.md` points here.

## Keep the docs in step with the code

A change that alters how something works updates its docs in the same pull request. The docs describe the plugin as it is, so a doc left behind describes behavior that no longer exists.

- **Blocks** (`src/blocks/<block>/`) each have two docs:
  - `README.md` is for publishers: what the block does and how to use it in the editor. Update it when a setting, option, label or visible behavior changes.
  - `DEVELOPMENT.md` is for developers: attributes, rendering, REST routes, and why the code is built the way it is. Update it when the structure or a contract changes.
- **Admin screens** (`src/admin/`) share `src/admin/DEVELOPMENT.md`. Update the section for the screen you changed, or add one for a new screen.
- Describe the end state, not the history of the change. Rewrite or remove sentences the change makes untrue instead of appending to them.
- Quote UI labels exactly as they appear on screen.

## Build, lint and test

The plugin uses npm (not pnpm) and Composer.

```bash
npm ci && composer install   # Install dependencies
npm run build                # Build dist/ (never committed)
npm run watch                # Rebuild on change
```

Lint before pushing. CI runs the same checks on every pull request.

```bash
npm run lint         # Everything below
npm run lint:js      # ESLint
npm run typecheck    # TypeScript; ESLint and the build don't catch type errors
npm run lint:scss    # Stylelint
npm run lint:php     # PHPCS with this repo's phpcs.xml
npm run fix:js       # Auto-fix JS (also format:scss, fix:php)
```

- PHP must stay compatible with PHP 7.2 (`phpcs.xml` sets `testVersion 7.2-`), so no typed properties.
- Change dependencies with npm 11 (`npx npm@11 install <package>`). Older npm versions rewrite `package-lock.json`.
- Inside the Newspack workspace, `lint:js` can pick up the workspace's Prettier config and flag files you didn't touch. Lint from a standalone clone when that happens, and don't commit the reformatting.

Tests:

- **PHP:** PHPUnit tests live in `tests/`. `npm run test:php` needs a WordPress test install, which `bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host]` sets up. Inside the Newspack workspace, run the workspace's `n test-php` from this directory instead.
- **JS:** there are no JS unit tests.
