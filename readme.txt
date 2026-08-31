=== Deadwood - Abandoned and Removed Plugin Detector ===
Contributors: albertnoah
Tags: security, abandoned plugins, plugin audit, maintenance, site health
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds plugins on your site that the author walked away from, or that WordPress.org quietly removed, and tells you the day it happens.

== Description ==

WordPress tells you when a plugin has an update waiting. It never tells you when a plugin stopped having updates at all, and it says nothing whatsoever when a plugin is pulled from the WordPress.org directory.

That second case is the one worth knowing about. When the directory closes a plugin, often because a vulnerability was reported and never fixed, your copy keeps running exactly as before. No notice appears in your dashboard. The update screen stays quiet, because there is no longer anything to update from. The plugin simply sits there, unmaintained, until somebody happens to look it up by hand.

Deadwood looks it up for you, every week.

= What it checks =

For every plugin and theme installed on the site, Deadwood asks the WordPress.org directory what it currently knows, and reports one of six answers:

* **Pulled from the directory.** The plugin was listed and has been removed. This is always treated as serious, and the report gives the closure date and the stated reason where WordPress.org publishes them.
* **No longer maintained.** Still listed, but the author has not shipped a release in years.
* **Slowing down.** Releases have become infrequent enough to be worth an eye.
* **Healthy.** Listed, and shipping.
* **Not from the directory.** Commercial, custom or host bundled code. This is not a fault and is never scored as one.
* **Could not be checked.** The lookup failed. This is reported as its own result and never counted as a pass.

= On being honest about risk =

An old release date is a leading indicator, not a verdict. A small, focused plugin can be genuinely finished, and plenty of five year old code runs perfectly. Deadwood says so on the report rather than pretending every quiet plugin is a crisis.

So staleness on its own scores moderately. The high scores are reserved for staleness that arrives with corroborating evidence: a support forum where nobody answers, or a compatibility claim that has fallen several WordPress releases behind. Every score is broken down into the specific findings behind it, and every finding shows the observation it rests on, so you can disagree with any individual judgement.

The same care runs the other way. A plugin that was never in the directory looks identical, through the API, to one that was thrown out of it. Getting that distinction wrong would flag every commercial plugin you own as a security incident, so Deadwood checks the two apart properly instead of guessing from a missing record.

= For people who run more than one site =

There is a WP-CLI command, and it is the real interface for anyone managing a fleet:

`wp deadwood scan`
`wp deadwood scan --all --format=json`
`wp deadwood scan --fail-on=closed`

The last one exits non zero when anything on the site has been pulled from the directory, which makes it usable as a gate in a deployment pipeline or a nightly cron across many installs.

The report also exports to CSV, and the finding appears in Tools then Site Health, which is where most people already look and what most agencies screenshot.

= What it does not do =

No account, no external service, no API key, no telemetry. Deadwood talks to exactly one host, api.wordpress.org, which is the same host your site already contacts for update checks. Nothing about your site is sent anywhere.

It also does not scan for known vulnerabilities. Tools that do that are useful and you should run one. They answer a different question: they can only tell you about a flaw once somebody has found, reported and published it. Abandonment is the condition that arrives years earlier, and nothing reports it.

== Installation ==

1. Install and activate.
2. Go to Tools then Deadwood.
3. Press Scan now.

The first scan takes a moment, since it asks the directory about each installed item in turn and deliberately paces the requests. After that it runs weekly in the background and emails you only when something changes.

== Frequently Asked Questions ==

= Does this send my plugin list anywhere? =

No. Lookups go to api.wordpress.org, one slug at a time, which is the same service WordPress itself queries when it checks for updates. There is no Deadwood server.

= A plugin I paid for is listed as "Not from the directory". Is that bad? =

No, and it is not scored. Commercial plugins are distributed by their vendors rather than through WordPress.org, so the directory has no record of them. Deadwood says so plainly instead of treating it as a finding.

= Why is a plugin I know is fine marked as unmaintained? =

Because the author has not shipped a release in a long time, which is the only thing that status claims. Open the finding and it will show you the exact date. Whether that matters depends on the plugin, and the report is built to help you make that call rather than make it for you.

= Will it email me every week? =

Only when something has changed since the last scan. A weekly message repeating what you already know is how a plugin teaches you to ignore it, and then the one message that mattered gets ignored too.

= How often does it check? =

Weekly, through WP-Cron. You can run a scan by hand at any time from the Tools page or over WP-CLI.

== Screenshots ==

1. The report, worst first, with the evidence behind every finding.
2. A plugin that was pulled from the directory, showing the closure reason.
3. The Site Health entry.

== Changelog ==

= 1.0.0 =
* First release.
