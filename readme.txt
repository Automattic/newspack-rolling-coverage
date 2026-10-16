=== Newspack Rolling Coverage ===
Contributors: automattic
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: trunk
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Tags: liveblog, rolling coverage, breaking news, newspack, live updates

Liveblog and rolling coverage of ongoing news events, with real-time updates, Slack ingestion, archive mode, and AI summaries.

== Description ==

Rolling Coverage publishes a continuous feed of short, timestamped entries for each developing story. Entries are authored from a few well-known tools and delivered to readers in real time without a page reload. Rolling Coverage Blocks can be placed in multiple spots across a site — a post, a sidebar, a homepage marquee — with each placement configurable to show readers a tailored view of the same coverage. Archive mode locks a coverage once an event concludes and optional AI-generated summaries help readers stay oriented as the story grows.

For the full documentation, see the README in the GitHub repository: https://github.com/Automattic/newspack-rolling-coverage/blob/trunk/README.md

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
