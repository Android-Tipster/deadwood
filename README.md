# Deadwood

Finds plugins on a WordPress site that the author walked away from, or that WordPress.org quietly removed, and tells you the day it happens.

WordPress tells you when a plugin has an update waiting. It never tells you when a plugin stopped having updates at all, and it says nothing whatsoever when a plugin is pulled from the directory.

That second case is the one worth knowing about. When the directory closes a plugin, often because a vulnerability was reported and never fixed, your copy keeps running exactly as before. No notice appears in the dashboard. The update screen stays quiet, because there is no longer anything to update from. The plugin just sits there until somebody happens to look it up by hand.

## Why this exists

A census of the entire WordPress.org plugin directory, taken on 2026-08-31, found:

| | |
|---|---|
| Plugins in the directory | 52,628 sampled of 67,014 |
| Not updated in 2 or more years | 24,283 (46%) |
| Of those, carrying 1,000+ active installs | 1,305 |
| Live sites running that abandoned code | **8,170,000** |
| Largest single case | `limit-login-attempts`, 300,000 installs, last release 2023 |

That last one is login security software, on three hundred thousand sites, whose author last shipped anything three and a half years ago.

Vulnerability scanners do not cover this. Patchstack, WPScan and Wordfence all match against known CVEs, so a plugin only appears once somebody has found, reported and published a flaw in it. Abandonment is the condition that arrives years earlier, and nothing reports it.

## What it does

For every installed plugin and theme it asks the directory what it currently knows, and returns one of six answers:

- **closed** The plugin was listed and has been removed. Always serious. Captures the closure date and the stated reason.
- **abandoned** Still listed, no release in two years or more.
- **aging** Releases have slowed past a year.
- **healthy** Listed and shipping.
- **unlisted** Never in the directory. Commercial, custom or host bundled. Not a fault, never scored as one.
- **unknown** The check could not be completed. Never counted as a pass.

Every score breaks down into named findings, and every finding shows the observation it rests on.

## Design notes

Three decisions carry most of the weight.

**An old release date is a leading indicator, not a verdict.** A small plugin can be genuinely finished. So staleness alone scores moderately, and the high scores need corroboration: a support forum nobody answers, or a compatibility claim several WordPress releases behind.

**Unknown is a distinct status from healthy.** A lookup that fails reports as a failure. "I could not check" and "I checked and it is fine" are different sentences.

**A folder name is not an identity.** This is where the tool nearly shipped a serious bug. On its first live run it reported BlazeAI, active on a production site, as removed from the directory. The `blaze` slug really was closed, on 19 July 2018, reason "Unused" - but that was an unrelated plugin that happened to hold the name years earlier. BlazeAI simply installs into a folder called `blaze`.

So slugs come from WordPress's own update transient, which is the authoritative mapping, and the folder name is only a fallback. When the slug had to be guessed, the scanner refuses to draw either confident conclusion from it: not "never listed" (which would libel every commercial plugin) and not "removed" (which is alarming, specific, and in that case false). Both become `unknown` with the collision spelled out.

## Install

Copy the folder into `wp-content/plugins/`, activate, then go to Tools then Deadwood and press Scan now.

After the first scan it runs weekly in the background and emails only when something has changed. A weekly message repeating what you already know is how a plugin teaches you to ignore it, and then the one message that mattered gets ignored too.

## WP-CLI

```sh
wp deadwood scan
wp deadwood scan --all --format=json
wp deadwood scan --fail-on=closed    # exits non zero, usable as a deploy gate
wp deadwood status                   # stored summary, no network calls
```

## Tests

```sh
./run-tests.sh          # 54 offline
./run-tests.sh --live   # 61, adds real calls to api.wordpress.org
```

The suite runs against WordPress function stubs in `tests/bootstrap.php`, and `wp_remote_get` there performs real requests, so the live checks exercise the registry against the actual directory rather than an imagined version of it.

If your PHP CLI lacks curl and openssl, point the runner at the extension directory:

```sh
PHP_EXT_DIR=/path/to/php/ext ./run-tests.sh --live
```

## Auditing a remote site

`tools/audit-remote.php` runs the shipped scanner against a live site's plugin list over the REST API, read only, without installing anything:

```sh
php tools/audit-remote.php https://example.com user app_password "Label"
```

It is necessarily less precise than the installed plugin, because the REST plugin list carries no directory slug, so every slug is a guess from the folder name and every answer that depends on identity comes back as `unknown`. That is the honest result rather than a confident one.

## Privacy

No account, no external service, no API key, no telemetry. It talks to `api.wordpress.org`, the same host your site already contacts for update checks. There is no Deadwood server.

## License

GPL-2.0-or-later.
