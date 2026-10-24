# Docs screenshots

The screenshots in `site/public/screenshots/` all show the same demo site, "The News Paper", taken at the same size and settings. This folder lets anyone retake one so it matches the rest:

- `seed.php` turns a local WordPress site into that demo site, and resets it when you run it again.
- `shot.mjs` (run as `npm run shot`) takes one screenshot at the house settings, with an optional spotlight, and writes the WebP into `site/public/screenshots/`.

When you change a screen publishers see, retake only the screenshots that show it.

## Set up a screenshot site

Use a local WordPress site set aside for screenshots, not the site you develop on. Any kind of local site works (Docker, Local, Studio, wp-env) as long as you can run WP-CLI against it. It has to be a single site; the seed refuses multisite networks.

The site needs:

- The [Newspack plugin](https://github.com/Automattic/newspack-plugin/releases), active.
- The [Newspack Block Theme](https://github.com/Automattic/newspack-block-theme/releases), installed. The seed activates it.
- A git checkout of Rolling Coverage, active, with its assets built (`npm ci && npm run build` and `composer install` in the plugin folder). The plugin zip doesn't include this folder.
- An environment type of `local` or `development`. Set it in `wp-config.php` with `define( 'WP_ENVIRONMENT_TYPE', 'local' );`. WordPress assumes `production` when it isn't set, which is the case on many Docker images and Studio sites, and the seed refuses those unless you add `force` to the command.

On your computer you need Node 24 and [Google Chrome](https://www.google.com/chrome/).

The admin menu shows whatever plugins the site has active. The existing screenshots also have plugins such as Newspack Newsletters, Newspack Ads and Newspack Network in the menu; activate them if a screenshot needs them.

## Seed the demo content

From the WordPress folder, point WP-CLI at `seed.php` in your checkout:

```bash
wp eval-file wp-content/plugins/newspack-rolling-coverage/site/screenshots/seed.php
```

Before it changes anything, the seed checks that:

- The site is a single site with an environment type of `local` or `development` (or you added `force`).
- The Newspack plugin and Rolling Coverage are active, and the active Rolling Coverage is the checkout this `seed.php` belongs to.
- The Newspack Block Theme is installed and can run on the site's versions of WordPress and PHP.
- The site has an administrator of its own, who can create patterns and save unfiltered HTML.
- Nothing on the site is in the way: a coverage, page, post or user with one of the demo names or email addresses, or a customized header.
- The uploads and mu-plugins folders are writable, and the files the seed copies are readable.

If anything fails, it stops and lists what it found.

The first run creates an administrator, James Smith (username `james`), and prints its password once, right after creating the account. Sign in as James Smith to take screenshots; the capture command refuses any other account. The seed also runs as James Smith, so the media, layouts, template parts, header menu and styles it creates belong to that account; posts, pages and entries keep their demo authors. To set a new password:

```bash
wp user update james --user_pass=<new password>
```

The seed creates, and marks as its own:

- The coverages and their statuses. Live: "Live: Storm Ingrid reaches the coast", "City Council Election Night" and "Downtown Water Main Break". Paused: "Winter Storm Warning" and "School Board Budget Vote". Ended: "Riverside Marathon 2026", "Live: Riverbend FC v Fresno Verde" and "Coast FC vs. Raleigh United".
- Their entries, authors (with fixed avatars), photos, full story posts and pinned entries. Entry times are set relative to when you run the seed, so "2 days ago" reads the same whenever you seed.
- The pages and posts that show them, such as `/live-storm-ingrid/`, `/coast-fc-vs-raleigh-united-live-updates/`, `/weather-desk/` and `/follow-the-storm/`, the front page, and two draft pages for editor screenshots: "Storm Ingrid" and "Storm Ingrid: live updates".
- The built-in layouts, the categories in the header menu that the site doesn't have yet, the header menu, the header template parts, and the site icon. Built-in layouts the site already has are reused, so if someone has edited one, images that show it can differ.
- A connected Slack workspace, "The News Paper", with four channel mappings and the Slack Bot user. These are settings only: nothing talks to Slack. If the site already has Slack settings, the seed leaves Slack alone.
- A must-use plugin, `wp-content/mu-plugins/newspack-rc-screenshots.php`, copied from `fakes.php`. It makes the AI and Push Notifications screens appear without keys when those plugins aren't active, answers Key Takeaways with sample text, blocks every request WordPress's HTTP API makes to a host other than the site itself, blocks all email, turns away requests to the Slack webhooks, and gives every account a fixed avatar. It does nothing on a site the seed hasn't seeded.

It also changes these settings, and keeps their old values to put back on removal: the site title, the active theme (with the widgets and menu locations of the theme it replaces), the front page, the site icon, the theme's styles (the Bulletin alt variation, with Times for headings and Helvetica for text), and the list of pinned entries. Changing the styles adds no revisions. Other plugins can react to a theme switch in ways removal can't undo, and cron events or webhooks they queued while the seed ran may still fire after removal.

Run `remove` before activating the WordPress AI plugin or OneSignal on the screenshot site: the stand-ins are already loaded while the plugin activates, and the two would clash.

The site's own administrator account stays, and still appears in user lists and author menus, so give it a neutral name such as "Admin".

Run the same command again at any time to reset the demo content. A rerun keeps James Smith and the authors, and recreates everything else. To remove everything the seed created and put the settings back:

```bash
wp eval-file wp-content/plugins/newspack-rolling-coverage/site/screenshots/seed.php remove
```

Removal deletes only what the seed marked, and refuses to run on a site with no seed state unless you add `force`. Posts that someone else wrote as one of the demo accounts move to the site's first other administrator, and it never deletes the site's last administrator. If the site has been connected to a real Slack workspace since seeding, removal leaves the Slack settings alone.

If a seed stops partway, run `remove`, fix what it reported, and seed again.

For the Key Takeaways error screenshot, turn on the sample error with `wp option update newspack_rc_screenshots_ai_error 1`, and turn it off again with `wp option delete newspack_rc_screenshots_ai_error`. For screens that need Slack disconnected, click Disconnect on the Slack Connection screen; rerun the seed to connect it again.

## Take a screenshot

From `site/`, install the dependencies once:

```bash
npm ci
```

Then name the image and give the site's address:

```bash
npm run shot -- blocks/rolling-coverage-2 --site https://screenshots.local --url "wp-admin/post-new.php?post_type=page"
```

1. Chrome opens at the page. The first time, the terminal asks for a username and password: use James Smith's, or set `WP_USER` and `WP_PASSWORD`. The browser loads only the site itself; requests to other hosts are blocked.
2. Get the page into the state the screenshot needs: open a drawer, select a block, scroll.
3. To spotlight one element, hold Alt (Option on a Mac) and click it. It gets a dashed outline, which doesn't show in the image. On Linux desktops that use Alt-click to move windows, use `--spotlight` instead.
4. Press Enter in the terminal.

The script saves `site/public/screenshots/blocks/rolling-coverage-2.webp` and prints the tag to paste into the page:

```mdx
<Screenshot label="..." src="/screenshots/blocks/rolling-coverage-2.webp" width={3024} height={1964} />
```

Write the label as alt text that says what the image shows and what is highlighted.

### Examples

An admin screen, with the Add Coverage button spotlighted:

```bash
npm run shot -- getting-started/create-your-first-coverage-1 --site https://screenshots.local \
  --url "wp-admin/admin.php?page=rolling-coverage" --spotlight 'button:has-text("Add Coverage")'
```

An editor screen, starting from the list of draft pages: the script opens the "Storm Ingrid" draft, selects the Coverage Status block and spotlights its Coverage setting. Leave out the last three options to do those steps yourself in the browser.

```bash
npm run shot -- blocks/coverage-status-2 --site https://screenshots.local \
  --url "wp-admin/edit.php?post_type=page&post_status=draft" \
  --click 'a.row-title >> text="Storm Ingrid"' \
  --click '[data-type="newspack-rolling-coverage/coverage-status"]' \
  --spotlight '.block-editor-block-inspector .components-toggle-group-control'
```

A page as a signed-out reader sees it:

```bash
npm run shot -- getting-started/put-the-feed-on-a-page-4 --site https://screenshots.local \
  --url live-storm-ingrid/ --logged-out
```

### Options

| Option | What it does |
| :--- | :--- |
| `--site <url>` | The screenshot site's address, including any folder WordPress is installed in. `https://` is added when you leave it out. You can set `SHOT_SITE` instead. |
| `--url <path>` | The page to open, relative to the site's address. Defaults to `wp-admin/`. |
| `--logged-out` | Opens the page in a fresh browser with no one signed in, so there's no admin bar. |
| `--spotlight <selector>` | Spotlights the first element that matches, instead of Alt-clicking. |
| `--click <selector>` | Clicks an element before the capture, and waits for the page to settle. Repeat it for several clicks, in order. |
| `--now` | Captures as soon as the page settles, without opening a window or waiting for Enter. Use it with `--click` and `--spotlight` for screens you can reach without help. Without a saved sign-in it needs `WP_USER` and `WP_PASSWORD`, since it can't ask. |
| `--out <file>` | Writes the image somewhere else, for trying things out. A relative path starts from the folder you ran `npm run` in. |

Selectors can be CSS, or Playwright's text selectors such as `button:has-text("Add Entry")` and `text=Downtown Water Main Break`. They also find elements inside the block editor's canvas.

The script also captures without waiting when it isn't run from a terminal.

## House settings

Every screenshot uses these, and the script applies them for you:

- A 1512×982 viewport at twice the pixel density, saved as a 3024×1964 WebP at quality 85, without metadata. `npm test` checks that every screenshot the docs pages use is a WebP of that size.
- Light mode, the visible viewport only (never the full page), and no scrollbars.
- Signed in as James Smith, who has a flat blue avatar. Update notices, cookie banners, the Newspack debug notice and similar noise are hidden from the moment the page loads.
- The reader sign-up prompt is closed on front-end pages.
- The editor isn't in fullscreen mode and shows no block breadcrumbs, welcome guides or pattern chooser. The script saves these editor preferences for the account you sign in with each time it runs.
- At most one spotlight: everything outside the element and an 8px margin is dimmed with 45% black.

Before you commit an image, look at it.

## Troubleshooting

- **The seed refuses to run:** read the list it prints. Nothing was changed.
- **Chrome doesn't start:** install Google Chrome, or set `CHROME_PATH` to a Chrome or Chromium binary.
- **Signed in as the wrong user:** the script stops without saving anything. Run it again and sign in as James Smith. A good sign-in is saved in `~/.cache/newspack-rc-shot/`, one file per site, readable only by you.
- **The site uses http or a self-signed certificate:** that's fine; the script accepts both.

## Credits

- **Photos** in `assets/media/` (all but the site icon) are from [Pexels](https://www.pexels.com/), free to use under the [Pexels license](https://www.pexels.com/license/): `flood-street.jpg` by Sveta K, `tidal-turbines.jpg` by Lucy Meadows, `polling.jpg` by Osviel Rodriguez Valdés, `late-winner.jpg` by George Zografidis, `marathon.jpg` by Stephen Leonardi, and `stadium.jpg` by Leticia Golubov.
- **The site icon**, `assets/media/site-icon.png`, was drawn for this folder with EB Garamond, which the Newspack Block Theme ships under the SIL Open Font License.
- **Avatars** in `assets/avatars/` are flat colors made for this folder.
