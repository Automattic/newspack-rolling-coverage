# Docs screenshots

The screenshots in `site/public/screenshots/` all show the same demo site, "The Riverbend Gazette", taken at the same size and settings. This folder lets anyone retake one so it matches the rest:

- `seed.php` turns a local WordPress site into that demo site, and resets it when you run it again.
- `shot.mjs` (run as `npm run shot`) takes one screenshot at the house settings, with an optional spotlight, and writes the WebP into `site/public/screenshots/`.

When you change a screen publishers see, retake only the screenshots that show it. [`SHOTS.md`](SHOTS.md) lists every screenshot, what it shows and the command that retakes it.

## Set up a screenshot site

Use a local WordPress site set aside for screenshots, not the site you develop on. Any kind of local site works (Docker, Local, Studio, wp-env) as long as you can run WP-CLI against it. It has to be a single site; the seed refuses multisite networks.

The site needs:

- The [Newspack plugin](https://github.com/Automattic/newspack-plugin/releases), active.
- The [Newspack Block Theme](https://github.com/Automattic/newspack-block-theme/releases), installed. The seed activates it.
- A git checkout of Rolling Coverage, active, with its assets built (`npm ci && npm run build` and `composer install` in the plugin folder). The plugin zip doesn't include this folder.
- No posts, pages or users of its own besides the administrator. Delete WordPress's "Hello world!" post and "Sample Page", and any sample posts and users your setup tool created: the front page lists the newest posts, and the editor's author menus list every author. The seed warns when it finds published posts it didn't create.
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
- Nothing on the site is in the way: a coverage, page, post or user with one of the demo names or email addresses, or a customized header or footer.
- The uploads and mu-plugins folders are writable, and the files the seed copies are readable.

If anything fails, it stops and lists what it found.

The first run creates an administrator, James Smith (username `james`), and prints its password once, right after creating the account. Sign in as James Smith to take screenshots; the capture command refuses any other account. The seed also runs as James Smith, so the media, layouts, template parts, header menu and styles it creates belong to that account; posts, pages and entries keep their demo authors. To set a new password:

```bash
wp user update james --user_pass=<new password>
```

The seed creates, and marks as its own:

- The coverages and their statuses. Live: "Kettle River rises above flood stage", "Open Studios" and "Bridge Street water main break". Paused: "Winter storm warning" and "School board budget vote". Ended: "Kettle Greenway Half Marathon", "Riverbend FC v Fresno Verde" and "Riverbend FC v Toledo Beacon".
- Their entries, authors (with the Gazette's monogram avatars), all from the Gazette's newsroom, photos with captions and credits, full story posts with standfirsts, and pinned entries. Entry times are set relative to when you run the seed, so "2 days ago" reads the same whenever you seed.
- The pages and posts that show them, such as `/live-kettle-river-flood/`, `/riverbend-fc-v-toledo-beacon-live-updates/`, `/weather-desk/` and `/follow-the-river/`, the front page, and two draft pages for editor screenshots: "Kettle River flood" and "Kettle River flood: live updates".
- The built-in layouts, the categories in the header menu that the site doesn't have yet, the header menu, the header and footer template parts, the site logo and the site icon. The footer shows the Gazette's icon, social links, tagline, address and section menu, and a copyright line. Built-in layouts the site already has are reused, so if someone has edited one, images that show it can differ.
- A connected Slack workspace, "The Riverbend Gazette", with four channel mappings and the Slack Bot user. These are settings only: nothing talks to Slack. If the site already has Slack settings, the seed leaves Slack alone.
- A must-use plugin, `wp-content/mu-plugins/newspack-rc-screenshots.php`, copied from `fakes.php`. It makes the AI and Push Notifications screens appear without keys when those plugins aren't active, answers Key Takeaways with sample text, blocks every request WordPress's HTTP API makes to a host other than the site itself, blocks all email, turns away requests to the Slack webhooks, and gives every account a fixed avatar. It does nothing on a site the seed hasn't seeded.

It also changes these settings, and keeps their old values to put back on removal: the site title, the active theme (with the widgets and menu locations of the theme it replaces), the front page, the site logo, the site icon, the theme's styles (The Riverbend Gazette's green and blue as the accent colors, with Inter for headings and Source Serif 4 for text), and the list of pinned entries. Changing the styles adds no revisions. Other plugins can react to a theme switch in ways removal can't undo, and cron events or webhooks they queued while the seed ran may still fire after removal.

Run `remove` before activating the WordPress AI plugin or OneSignal on the screenshot site: the stand-ins are already loaded while the plugin activates, and the two would clash.

The site's own administrator account stays, and still appears in user lists and author menus, so give it a neutral name such as "Admin".

Run the same command again at any time to reset the demo content. A rerun keeps James Smith and the authors, and recreates everything else. On a site seeded before the demo authors changed, run `remove` once first: a rerun keeps the old authors too. The next seed creates James Smith again and prints a new password. To remove everything the seed created and put the settings back:

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

An admin screen, with the Add Liveblog button spotlighted:

```bash
npm run shot -- getting-started/create-your-first-coverage-1 --site https://screenshots.local \
  --url "wp-admin/admin.php?page=rolling-coverage" --spotlight 'button:has-text("Add Liveblog")'
```

An editor screen, starting from the list of draft pages: the script opens the "Kettle River flood" draft, selects the Coverage Status block and spotlights the Automatic and Custom choice in its Liveblog panel. Leave out the last three options to do those steps yourself in the browser.

```bash
npm run shot -- blocks/coverage-status-2 --site https://screenshots.local \
  --url "wp-admin/edit.php?post_type=page&post_status=draft" \
  --click 'a.row-title >> text="Kettle River flood"' \
  --click '[data-type="newspack-rolling-coverage/coverage-status"]' \
  --spotlight '.block-editor-block-inspector .components-toggle-group-control'
```

A panel with its fields filled in: the script opens Add Liveblog, types a name and a description, then clicks a help text so no field shows a focus ring.

```bash
npm run shot -- getting-started/create-your-first-coverage-2 --site https://screenshots.local \
  --url "wp-admin/admin.php?page=rolling-coverage" \
  --click 'button:has-text("Add Liveblog")' \
  --fill 'internal:label="Name"s' --value 'Depot Street Bridge closure: live updates' \
  --fill 'internal:label="Description"s' --value 'Closures and diversions' \
  --click 'text=An internal note about this liveblog'
```

A hover, and a spotlight around two elements at once (the box that contains both):

```bash
npm run shot -- guides/scratch-1 --site https://screenshots.local \
  --url "wp-admin/admin.php?page=rolling-coverage" --out /tmp/hover-test.webp \
  --hover 'button:has-text("Add Liveblog")' \
  --spotlight 'button:has-text("Add Liveblog")' --spotlight 'text=Bridge Street water main break'
```

Scrolling a signed-out page to an element, then down a little further, after waiting for an animation. This is a try-out, so `--out` writes it to a scratch file instead of a docs image:

```bash
npm run shot -- guides/scratch-1 --site https://screenshots.local \
  --url live-kettle-river-flood/ --logged-out --out /tmp/scroll-test.webp \
  --scroll 'text=River falls below 20 feet' --scroll 200 --wait 500
```

A page as a signed-out reader sees it:

```bash
npm run shot -- getting-started/put-the-feed-on-a-page-4 --site https://screenshots.local \
  --url live-kettle-river-flood/ --logged-out
```

### Options

| Option | What it does |
| :--- | :--- |
| `--site <url>` | The screenshot site's address, including any folder WordPress is installed in. `https://` is added when you leave it out. You can set `SHOT_SITE` instead. |
| `--url <path>` | The page to open, relative to the site's address. Defaults to `wp-admin/`. |
| `--logged-out` | Opens the page in a fresh browser with no one signed in, so there's no admin bar. |
| `--spotlight <selector>` | Spotlights the first element that matches, instead of Alt-clicking. Repeat it to spotlight the one box that contains all the matches, such as a field and its button. |
| `--click <selector>` | Clicks an element before the capture, and waits for the page to settle. Repeat it for several clicks, in order. |
| `--fill <selector>` and `--value <text>` | Types the text into the first field that matches, such as a name in a panel or a paragraph in the entry editor. When the field is a select menu, picks the choice with that label instead. Put each `--value` right after its `--fill`. |
| `--hover <selector>` | Rests the pointer on the first element that matches, to show a tooltip or a hover state. The pointer stays there for the capture. Repeat it for several hovers. |
| `--scroll <selector or px>` | With a selector, scrolls the first element that matches into the middle of the window. With a number of pixels, scrolls down by that much, or up when it's negative. Write a negative number with an equals sign, as `--scroll=-2000`: a bare `--scroll -2000` fails to parse. It scrolls an open modal first, then the block editor's canvas, then the page. |
| `--press <key>` | Presses a key, such as `Escape`, `Enter` or `Control+a`, using Playwright's key names. Repeat it for several keys. |
| `--wait <ms>` | Waits that many milliseconds, for an animation or a slow panel. Repeat it where you need it. |
| `--eval <js>` | Runs JavaScript in the page and in each frame inside it, such as the editor canvas, for a step no other option covers. Errors in the main page are printed. Repeat it for several scripts. |
| `--mouse <x,y>` | Leaves the pointer at this point in the window, in pixels from the top left of the 1512×982 viewport. By default it moves to the bottom right corner after a click or fill. |
| `--now` | Captures as soon as the page settles, without opening a window or waiting for Enter. Use it with `--click` and `--spotlight` for screens you can reach without help. Without a saved sign-in it needs `WP_USER` and `WP_PASSWORD`, since it can't ask. |
| `--out <file>` | Writes the image somewhere else, for trying things out. A relative path starts from the folder you ran `npm run` in. |

Clicks, fills, hovers, scrolls, key presses, waits and scripts all run in the order you give them, whichever options they use.

Selectors can be CSS, or Playwright's text selectors such as `button:has-text("Add Entry")` and `text=Bridge Street water main break`. To find a field by its label, use `internal:label="<label>"s`, where the `s` makes the match exact. They also find elements inside the block editor's canvas.

The script also captures without waiting when it isn't run from a terminal.

### Selector tips

- Playwright's `:text-is()` doesn't match row-action menu items whose text sits in a child element. Use `:has-text()`, or `:has(:text-is("..."))`.
- A bare `[role="menuitem"]` or `[role="menu"]` also matches hidden elements in the admin bar. Scope it, for example `div[role="menuitem"]:has-text("Edit")`.
- "Choose a layout" opens scrolled to the block's current layout. Add `--scroll=-5000` to show the top of the list.

### Clean up after a session

Signing in through wp-admin makes WordPress's Quick Draft create an auto-draft post. Delete the strays after a session:

```bash
ids=$(wp post list --post_type=post --post_status=auto-draft --format=ids); [ -n "$ids" ] && wp post delete $ids --force
```

## House settings

Every screenshot uses these, and the script applies them for you:

- A 1512×982 viewport at twice the pixel density, saved as a 3024×1964 WebP at quality 85, without metadata. `npm test` checks that every screenshot the docs pages use is a WebP of that size.
- Light mode, the visible viewport only (never the full page), and no scrollbars.
- Signed in as James Smith, who has a flat blue avatar. Update notices, cookie banners, the Newspack debug notice and similar noise are hidden from the moment the page loads.
- The reader sign-up prompt is closed on front-end pages.
- The editor isn't in fullscreen mode and shows no block breadcrumbs, welcome guides or pattern chooser. Top toolbar is on, so a selected block's toolbar sits in the editor's header instead of over the page. These apply in the post, page, pattern and Site Editor alike, and the script saves them for the account you sign in with each time it runs.
- At most one spotlighted box, around one element or around all the `--spotlight` matches: everything outside it and an 8px margin is dimmed with 45% black.

Before you commit an image, look at it.

## Troubleshooting

- **The seed refuses to run:** read the list it prints. Nothing was changed.
- **Chrome doesn't start:** install Google Chrome, or set `CHROME_PATH` to a Chrome or Chromium binary.
- **Signed in as the wrong user:** the script stops without saving anything. Run it again and sign in as James Smith. A good sign-in is saved in `~/.cache/newspack-rc-shot/`, one file per site, readable only by you.
- **The site uses http or a self-signed certificate:** that's fine; the script accepts both.

## Credits

- **Photos** in `assets/media/` (all but the site logo and site icon). Their captions and credits are in `content.json`:
  - From [Pexels](https://www.pexels.com/), free to use under the [Pexels license](https://www.pexels.com/license/): `flood-street.jpg` by Sveta K, `polling.jpg` by Osviel Rodriguez Valdés, `marathon.jpg` by Stephen Leonardi, and `stadium.jpg` by Leticia Golubov, and these seven, resized to 1600 pixels on the long edge:
    - `late-winner.jpg`: "People on Stadium during Soccer Game" by Altan Kendirci, [Pexels](https://www.pexels.com/photo/people-on-stadium-during-soccer-game-19918607/). A sign, a club banner and the big screen are blurred.
    - `flooded-field.jpg`: "Flooded Field" by Toon de Vos, [Pexels](https://www.pexels.com/photo/flooded-field-6208447/).
    - `flooded-riverside-walkway.jpg`: "Flooded Riverbank Walkway" by Andrey Karpov, [Pexels](https://www.pexels.com/photo/flooded-riverbank-walkway-7821835/).
    - `power-line-crew.jpg`: "Road Works near Utility Pole" by Robert So, [Pexels](https://www.pexels.com/photo/road-works-near-utility-pole-16339549/). The equipment maker's name on the trucks is blurred.
    - `road-works.jpg`: "Road Construction Worker with Jackhammer" by Thomas Fuhrmann, [Pexels](https://www.pexels.com/photo/road-construction-worker-with-jackhammer-33484883/).
    - `snowy-evening-street.jpg`: "Dwelling house near snowy road and leafless trees in winter" by Sandy Torchon, [Pexels](https://www.pexels.com/photo/dwelling-house-near-snowy-road-and-leafless-trees-in-winter-4166335/).
    - `snowy-street.jpg`: "Houses with Parked Cars Covered in Snow" by Lalada, [Pexels](https://www.pexels.com/photo/houses-with-parked-cars-covered-in-snow-6220207/).
  - Found through [Openverse](https://openverse.org/), dedicated to the public domain under [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/) or marked with the [Public Domain Mark 1.0](https://creativecommons.org/publicdomain/mark/1.0/), so they need no credit, though the site credits each under its caption, to rawpixel where the photographer isn't named. Each is resized, and `paper-lanterns.jpg` is cropped to remove its frame.
    - `ball-on-grass.jpg`: "Soccer ball grass field", photographer not named, CC0, [rawpixel](https://www.rawpixel.com/image/5911893/image-public-domain-free-grass).
    - `kettle-river-sunset.jpg`: "autumn sunset on the river" by azxa661, Public Domain Mark, [Flickr](https://www.flickr.com/photos/197207264@N02/52581985117).
    - `library-shelves.jpg`: "Book shelves library", photographer not named, CC0, [rawpixel](https://www.rawpixel.com/image/6021855/photo-image-book-public-domain-room).
    - `paper-lanterns.jpg`: "Lanterns for mid-autumn festival" by GeorgeTan#3, Public Domain Mark, [Flickr](https://www.flickr.com/photos/190270151@N02/51498605534).
    - `reporters-notebook.jpg`: "Research" by neil conway, Public Domain Mark, [Flickr](https://www.flickr.com/photos/30934989@N06/5625707813).
    - `stacked-chairs.jpg`: "Stacked chairs along wall covered", photographer not named, CC0, [rawpixel](https://www.rawpixel.com/image/3294353/free-photo-image-architecture-banister-boardwalk).
    - `string-lights.jpg`: "String lights night", photographer not named, CC0, [rawpixel](https://www.rawpixel.com/image/3283840/free-photo-image-christmas-lights-background-festive-bokeh).
    - `studio-rafters.jpg`: "Ceiling house wooden beams rafters", photographer not named, CC0, [rawpixel](https://www.rawpixel.com/image/3284145/free-photo-image-building-lamp-lighting-home).
    - `water-main-trench.jpg`: "Bøgeskov Høvej 25, vejarbejde - DSC_2223_Balancer" by Lav Ulv, CC0, [Flickr](https://www.flickr.com/photos/99460566@N06/35776145673).
- **The site logo and site icon**, `assets/media/riverbend-gazette-wordmark.png` and `assets/media/site-icon.png`, are The Riverbend Gazette's wordmark and icon, made for Newspack's demo sites.
- **Source Serif 4**, the variable WOFF2 files in `assets/fonts/source-serif-4/`, is by Adobe, from [adobe-fonts/source-serif](https://github.com/adobe-fonts/source-serif) release 4.005, under the [SIL Open Font License 1.1](https://openfontlicense.org/) (`assets/fonts/source-serif-4/LICENSE.md`). The site loads it from this folder. Inter comes with the Newspack Block Theme.
- **Avatars** in `assets/avatars/` are The Riverbend Gazette's monogram avatars, one per seeded author, made for Newspack's demo sites and resized to 192 pixels. `default.svg`, a flat blue square, is the avatar of James Smith and every other account.
