=== Newspack Rolling Coverage ===
Contributors: automattic
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: trunk
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Tags: liveblog, rolling coverage, breaking news, newspack, live updates

Liveblog and rolling coverage of ongoing news events, with real-time updates, Slack ingestion, archive mode, and AI summaries.

== Description ==

Rolling Coverage publishes a continuous feed of short, timestamped entries for each developing story. Entries are authored from a few well-known tools and delivered to readers in real time without a page reload. Rolling Coverage Blocks can be placed in multiple spots across a site — a post, a sidebar, a homepage marquee — with each placement configurable to show readers a tailored view of the same coverage. Ending a coverage locks its entries once an event concludes, and optional AI-generated summaries help readers stay oriented as the story grows.

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

Yes. Connect a Slack channel to a coverage under Rolling Coverage > Slack Connection, or use the `/rolling-coverage-connect` slash command from Slack. Each channel's Publish Mode decides what happens to new messages: they're published right away, saved as drafts, or wait for someone to react with an emoji.

= What happens when a coverage ends? =

Setting a coverage's status to Ended stops the feed from checking for new entries and locks its entries: they can't be trashed, pinned, reassigned, or turned into breakout posts, and no new entries can be added. Their text can still be edited. By default, a notice tells readers the coverage has ended, and the follow button is hidden. You can set the status back to Live at any time.

= Do readers need to reload the page to see new entries? =

No. The feed checks for new entries and adds them as they arrive. On a feed that shows all entries, readers who have scrolled down keep their place and see a button with the number of new entries instead, and a Check for Updates block in the layout makes the feed check only when a reader presses it. A paused or ended coverage doesn't check on its own.

= Can I summarize a coverage automatically? =

Yes, when the WordPress AI plugin and an AI provider are configured. Use the AI panel in the Rolling Coverage block to generate key takeaways, or use the AI settings screen to customize the prompt.

= Which push notification provider is supported? =

OneSignal. The feed's follow button and the per-entry notification option require the OneSignal plugin to be installed and configured.

== Changelog ==

See https://github.com/Automattic/newspack-rolling-coverage/releases
