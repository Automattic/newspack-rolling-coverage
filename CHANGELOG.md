# Changelog

All notable changes to this project are documented in this file. Entries are
generated automatically by [semantic-release](https://github.com/semantic-release/semantic-release)
from [Conventional Commits](https://www.conventionalcommits.org/).

## 0.1.0 (2026-09-24)

### Features

* bootstrap `rolling_coverage` taxonomy and `rolling_cov_entry` custom post type (CPT, meta, REST fields, pinning, author scoping)
* admin DataViews app for managing coverages and entries, with live sync and notices
* Rolling Coverage Gutenberg block with real-time polling and scroll-aware rendering
* Quick Edit modal (full block editor in a modal) for entries
* Breakout posts: clone an entry into a draft post with bidirectional linking and a "read more" link
* Slack integration: channel mapping, ingestion, slash commands, manifest, and live monitor
* AI-powered key takeaways generation and WordPress Abilities API registration
* Newspack Ads integration for the coverage feed, with coverage-level disable
* Entry delete and restore handling, including orphaned-entry cleanup and recovery coverages
* Reader analytics events (`coverage_entry_seen`, `coverage_poll_error`)
* Schema.org `LiveBlogPosting` structured data output
* Social sharing and deep links back to the embedding page
* Push notifications via OneSignal, scoped to coverage followers
* Archive mode: coverage/entry archiving and entry locking

### Bug Fixes

* 

### Tests

* unit tests for ingestion, archiving, REST endpoints, and feeds
