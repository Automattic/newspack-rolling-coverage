=== Newspack Rolling Coverage ===
Contributors: automattic
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: trunk
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Tags: live blog, rolling coverage, breaking news, newspack, live updates

Live blog and rolling coverage of ongoing news events, with real-time updates, Slack ingestion, archive mode, and AI summaries.

== Description ==

Newspack Rolling Coverage helps newsrooms cover developing stories with a continuous feed of short, timestamped entries. Publish updates as they happen, embed the feed on any post or page, and let readers follow along as the story unfolds — no page reload required.

= Live coverage feeds =

Add the Rolling Coverage block to any post or page to display a live feed of entries for a coverage. Readers see new entries appear automatically as they are published, and can load earlier entries as they scroll back through the story.

= Editorial workflow =

Create a Rolling Coverage for each ongoing event, then add entries to it. Entries can be published immediately, scheduled, saved as drafts, or submitted for review, and each entry is displayed with its title, date, content, and featured image inside a customizable block template.

The Rolling Coverage admin screen gives you a filterable, sortable list of every entry across your coverage, with live updates as colleagues publish. You can quickly edit an entry, open it in the full editor, pin it to the top of the feed, archive it, or turn it into a standalone article.

= Real-time reader updates =

The feed polls for new and updated entries and inserts them as they arrive. New entries published while a reader is on the page are surfaced with a "new posts" prompt, and updated entries are refreshed in place so readers always see the latest version.

= Slack ingestion =

Connect a Slack channel to a coverage and let reporters file updates directly from Slack. Messages become entries automatically, with an option to publish immediately or hold them as drafts for review. You can ignore messages that begin with a prefix such as `~~`, and every incoming request is verified with Slack's signing secret.

= Archive mode =

When an event concludes, archive the coverage. Archiving freezes the feed, records an end time, shows readers an archived notice, and locks the entries from further changes — while keeping the story fully readable.

= Follow and push notifications =

Readers can follow a coverage to receive push notifications when it is updated. With the OneSignal plugin installed and configured, notifications are sent only to followers of the relevant coverage, and each notification links straight to the new entry. Push notifications require a canonical URL to be set on the coverage.

= Breakout posts =

When a single update deserves its own article, create a breakout post. Rolling Coverage copies the entry into a new draft post, links the two together, and adds a "Read more" link in the feed once the article is published.

= Social sharing and deep links =

Each entry has a shareable link. When a reader opens an entry link, they are taken to the coverage on the embedding page and scrolled to the right entry. Readers who arrive from an older link see a helpful call-to-action pointing them to the latest coverage.

= AI key takeaways =

When the WordPress AI plugin and a provider are configured, generate a concise summary of the most important developments in a coverage from the block editor. The summary prompt is customizable from the AI settings screen.

= Advertising =

Sites using Newspack Ads can insert ad units between entries at a configurable interval, and can disable advertising on individual coverages.

= Structured data =

Coverage feeds output schema.org `LiveBlogPosting` structured data, helping search engines and news aggregators understand the story and its updates.

= About Newspack =

The Newspack Rolling Coverage plugin is part of Newspack, a suite of tools to help small to mid-sized news organizations publish and generate revenue with WordPress. Newspack is a collaborative project by WordPress.com and the Google News Initiative. You can learn more about Newspack by [visiting our website](https://newspack.com/).

== Installation ==

1. Upload the Newspack Rolling Coverage plugin to your website, and activate it.
2. Visit the Rolling Coverage menu in WP Admin and create your first coverage.
3. Add the Rolling Coverage block to a post or page and select the coverage to display.
4. Start adding entries, and optionally connect a Slack channel under Rolling Coverage > Slack Connection.

== Frequently Asked Questions ==

= What is the difference between a coverage and an entry? =

A coverage represents one ongoing news event. Entries are the individual updates posted to that coverage.

= Can I publish entries from Slack? =

Yes. Connect a Slack channel to a coverage under Rolling Coverage > Slack Connection, or use the `/rolling-coverage-connect` slash command from Slack. New messages in that channel can be ingested automatically, either published immediately or saved as drafts.

= What happens when I archive a coverage? =

Archiving freezes the feed and locks its entries from further editing, deleting, pinning, or reassignment. A notice is shown to readers, and the follow button is hidden. You can change the coverage status back to active at any time.

= Do readers need to reload the page to see new entries? =

No. The coverage feed polls for new entries and inserts them automatically, and visitors who are scrolled back through the history continue to see their position.

= Can I summarize a coverage automatically? =

Yes, when the WordPress AI plugin and an AI provider are configured. Use the AI panel in the Rolling Coverage block to generate key takeaways, or use the AI settings screen to customize the prompt.

= Which push notification provider is supported? =

OneSignal. The feed's follow button and the per-entry notification option require the OneSignal plugin to be installed and configured.

== Changelog ==

= 0.1.0 =
* Initial release.
