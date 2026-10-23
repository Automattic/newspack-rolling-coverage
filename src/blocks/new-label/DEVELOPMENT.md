# New Label block: development notes

The New Label block (`newspack-rolling-coverage/new-label`) is the label a layout places in an entry to mark it as new when it arrives while the page is open. Editor code lives in `src/blocks/new-label/`. Server rendering lives in `includes/blocks/class-new-label-block.php`. The marks' lifecycle (reveal, seen, clear) lives in `src/blocks/rolling-coverage/new-entry-marks.ts`, and the label's feed-level styles in `src/blocks/rolling-coverage/style.scss`.

For how publishers use the block, see `README.md` in this directory. For where the built-in layouts place it and the placement rules, see the New label under Template structure contract in `../rolling-coverage/DEVELOPMENT.md`.

## Attributes and supports

- `content` (string, default `''`, `role: content`): the label's text, edited in place with `RichText` (`allowedFormats: []`, so plain text). Empty means the translated "New", shown as the editor's placeholder and rendered by the server.
- `visuallyHidden` (boolean, default `false`): renders the label with `screen-reader-text`, in the editor too. A visually hidden label counts as no visible label, so the entry counts as seen by how much of it is in view (`isVisibleMarker()`).
- Supports: text color, and typography (font size, line height, text alignment, font family, weight, style, letter case, letter spacing). `html` and `reusable` are off. `ancestor` limits it to a Rolling Coverage block, which also wraps a layout in the layout pattern editor.

`save` returns `null`; the block is dynamic.

## Rendering

`New_Label_Block::render_block()`:

- While an entry renders (`Rolling_Coverage_Block::is_rendering_entry()`), outputs a `<p>` with the block's wrapper attributes (the supports' classes and styles, `className`, and `screen-reader-text` when `visuallyHidden`), `hidden`, and `data-rc-new-marker` (`Entry_Bindings::NEW_MARKER_ATTR`). It renders the same on every entry, so the label never shows where the view script doesn't run, Lite Site pages included. The text is `content` with its tags stripped, escaped with `esc_html()`, or the translated "New" when that leaves nothing or only spaces (no-break spaces included). `content` is already HTML, so its entities aren't decoded: a typed `&lt;` is stored as `&amp;lt;` and renders as typed, and `esc_html()` doesn't encode an entity twice.
- In a syndication feed (`is_feed()`), renders nothing, since no script runs there to manage the label and a reader may ignore `hidden`.
- Anywhere else, such as among the coverage-level blocks or outside a feed, renders nothing.

The view script keys on `data-rc-new-marker`, not on a class or the block name: `NewEntryMarks` removes `hidden` on a new entry's own marker and sets it again once the mark clears.

## Entries without a label

Besides its arrival, the label is the only mark a new entry gets. An entry whose template holds no New Label gets only the screen-reader "New" (`newspack-rolling-coverage-new-status`) that `NewEntryMarks` adds, and one whose label is visually hidden, as in Minute, shows nothing beyond its arrival.

## Editor

- `RichText` has `disableLineBreaks`, so the label stays on one line. While `content` is empty, `editor.scss` shows the "New" placeholder at full strength rather than core's dimmed placeholder, so the canvas matches the site; the text isn't stored, so it follows the site's language.
- `newLabel()` in `template.ts` builds the block for the built-in layouts: `use-header-font`, the `small` font size, weight 700, the readable accent as the text color unless `color: null` (Flash), an optional text alignment, and `visuallyHidden` for Minute. It leaves `content` empty, so the layouts' server copies carry no text to translate.
- `isNewLabel()` matches the block by name (`NEW_LABEL_BLOCK_NAME`). `withoutNewLabel()` (`layout.ts`'s previews) and `hiddenNewLabelIds()` (`edit.tsx`) use it to show the label only on the sample previewing the newest unpinned entry.
- `ENTRY_ALLOWED_BLOCKS` (`template.ts`) lets the block into the Feed group.

## Tests

`tests/test-new-label-block.php` covers the label rendering hidden and tagged on regular, pinned and Full story entries, custom text escaped with its entities as typed, empty or space-only text giving "New", Visually hidden, syndication feeds, and rendering outside an entry. `tests/js/new-label-hiding.test.ts` covers `hiddenNewLabelIds()`, and `tests/js/layout-templates.test.ts` the built-in layouts' placements. `tests/js/new-entry-marks.test.ts` covers the marks' lifecycle and marking in batches (`markAll()`, `release()`).
