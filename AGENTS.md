# AGENTS.md — AI Provider for Osaurus

Working guide for AI coding agents (and humans) on this repository. Read this
before making changes. Conventions here are deliberate — most exist because a
WordPress.org review or a CI failure taught us the lesson once already.

## What this is

A WordPress plugin that registers **Osaurus** (a local Apple-Silicon LLM
runtime with OpenAI-compatible HTTP endpoints) as a provider for the WordPress
AI Client shipped in WordPress 7.0.

- **Slug:** `ai-provider-for-osaurus` (permanent — set at wp.org approval)
- **Published:** WordPress.org plugin directory
- **Source of truth:** GitHub `andreilupu/ai-provider-for-osaurus`, branch `main`
- **Deploy target:** WordPress.org SVN — written **only** by CI, never by hand
- **Namespace / prefix:** `OsaurusAi\Connector\` (PHP), `osaurus_ai_connector_` (options/functions), `ai-provider-for-osaurus/v1` (REST), `ai-provider-for-osaurus` (text domain)

## Repository layout

What ships to WordPress.org (everything else is excluded by `.distignore`):

```
ai-provider-for-osaurus.php   # Entry point: hooks, HTTP filters, REST routes
uninstall.php                 # Deletes options on uninstall (single + multisite)
readme.txt                    # The wp.org-facing readme (NOT Readme.md)
assets/img/osaurus.svg        # Provider logo (shipped)
assets/js/connector-settings.js  # Connectors admin React module
src/autoload.php              # PSR-4 autoloader (no Composer at runtime)
src/Provider/OsaurusProvider.php
src/Models/OsaurusTextGenerationModel.php
src/Metadata/OsaurusModelMetadataDirectory.php
```

Repo-only (never shipped — listed in `.distignore`):

- `.wordpress-org/` — wp.org **listing** artwork (banner, icon, screenshots). Deployed to SVN `/assets/`, not into the plugin.
- `assets-src/` — SVG design sources for the listing artwork.
- `.github/`, `Readme.md` (GitHub readme), `AGENTS.md`, `CLAUDE.md`, `blog-article.md`, dotfiles, dev tooling.

`.distignore` is the **single source of truth** for what reaches SVN trunk (the
10up deploy action honors it). **If you add a new dev-only file or directory,
add it to `.distignore`** or it will ship to users.

## Local development

- Develop against any local WordPress 7.0+ (the author uses **WP Studio**).
  Symlink or clone the repo into `wp-content/plugins/` and activate.
- **There is no `wp-env` / Docker setup, and we don't want one.** Do not add
  `.wp-env.json` or wp-env references.
- No build step — the plugin ships as-is (the JS is a plain module, no bundler).
- PHP floor is **7.4**. Do not use 8.0+ syntax (`str_contains`,
  `str_starts_with`, `match`, named args, enums, `?->`). Use `strpos` etc.

## Release process (proven, 2026-06-02)

Releases are fully automated. The entire ritual:

1. Bump the version in **all three** places to the same value (they must match
   or the deploy guard rejects the tag):
   - `ai-provider-for-osaurus.php` header `Version:`
   - `ai-provider-for-osaurus.php` `const PLUGIN_VERSION`
   - `readme.txt` `Stable tag:`
2. Update `readme.txt` changelog + (if relevant) upgrade notice.
3. Open a PR into `main` and merge it (see "Git workflow" — never push to main).
4. Tag and push from `main`:
   ```bash
   git tag <x.y.z> && git push origin <x.y.z>
   ```
   A plain version tag (no `v` prefix) triggers `.github/workflows/deploy.yml`,
   which deploys trunk + `tags/<x.y.z>/` + listing assets to SVN in one shot.
5. Watch it: `gh run watch` (or `gh run list --workflow "Deploy to WordPress.org"`).
   The wp.org API/listing lags a few minutes behind the SVN commit.

To update **only** the listing artwork (no code release): change files under
`.wordpress-org/` on `main`; `assets.yml` syncs them. (This only works once the
plugin has had at least one real deploy — it can't bootstrap an empty SVN repo.)

## CI workflows (`.github/workflows/`)

- **deploy.yml** — on a version tag, guards that tag == `Version` header ==
  `Stable tag`, then runs `10up/action-wordpress-plugin-deploy` (`ASSETS_DIR: .wordpress-org`). Pure SVN, no wp-env.
- **assets.yml** — on push to `main` touching `.wordpress-org/**`, runs the 10up
  asset-update action (artwork only).
- **plugin-check.yml** — official `wordpress/plugin-check-action` (SHA-pinned),
  run against a `.distignore`-filtered staged mirror so it checks exactly the
  shipped artifact, not repo-only files.

### ⚠️ Plugin Check is intentionally RED — do not "fix" it

It fails at `wp-env` boot due to upstream bug
[WordPress/plugin-check-action#579](https://github.com/WordPress/plugin-check-action/issues/579):
GitHub's newer `ubuntu-24.04` runner image (Node 24.16 / libuv 1.52.1) makes
wp-env's bundled `got` silently exit 0 on the URL-plugin download path without
starting Docker. **This hits everyone using the action right now, including
Automattic's own plugins.** Decision: leave it red and wait for the upstream
wp-env fix rather than maintain a workaround. When upstream patches it, just
bump the SHA pin in `plugin-check.yml`. (An inline Node-20 + wp-cli workaround
exists and was rejected as not worth the maintenance.)

## Git workflow

- **Never commit or push directly to `main`.** Always branch → PR → merge. The
  environment enforces this; a direct push to `main` is blocked.
- Tags are the exception: tagging/pushing `<x.y.z>` on `main` is how releases go
  out. Re-pointing a tag (delete + re-create) is fine **only** before that
  version has been published to SVN.
- Commit messages: imperative subject, explain *why* in the body when not
  obvious. Co-author trailer for AI-assisted commits.

## Coding conventions (these came from a real wp.org review)

- **Prefixes:** every function/class/const/option you define is namespaced
  (`OsaurusAi\Connector\`) or prefixed `osaurus_ai_connector_`. Never define a
  global symbol prefixed `wp_`, `__`, or `_`. (Consuming core handles like
  `wp_enqueue_script('wp-core-data')` is fine — that's a core handle, not your
  symbol.)
- **`declare(strict_types=1);`** at the top of every PHP file; the `namespace`
  statement must come immediately after (nothing between them).
- **`ABSPATH` guard** in every PHP file (`if ( ! defined('ABSPATH') ) { exit; }`,
  placed after the `namespace` line in namespaced files).
- **Escape all output**; pass literal strings to `__()` / `esc_html__()` with
  the `ai-provider-for-osaurus` text domain (literal, so `wp i18n make-pot` can
  extract them — never a variable).
- **External services must be documented** in `readme.txt` under a top-level
  `== External services ==` section: what the service is, what data is sent and
  when, and the hosts contacted. We only contact the configured Osaurus URL.
- **Shell/CI gotcha:** `grep -oP` with a variable-length lookbehind
  (`(?<=...\s{1,50})`) fails with "lookbehind assertion is not fixed length".
  Use `sed` for version extraction (see `deploy.yml`).

## Architecture notes

- The plugin extends the AI Client SDK's OpenAI-compatible base classes, so
  streaming / tool calls / JSON output come "for free". Implementation is just:
  a provider, a text-generation model (overrides URL construction), and a model
  metadata directory (parses `GET /v1/models`).
- **Hook order matters:** `register_provider` on `init:5`, `register_fallback_auth`
  on `init:15` (after our provider, before core's key-pass at 20).
- `declare_credentials_for_osaurus` on the `wpai_has_ai_credentials` filter is
  what makes the editor treat Osaurus as an active connector despite there being
  no API key — don't remove it.
- **SDK version guards:** the `description` arg on `ProviderMetadata` needs SDK
  ≥ 1.2.0; `logoPath` needs ≥ 1.3.0. Keep the `version_compare` guards.
- The logo path is built via `plugin_basename()` (symlink-aware), not
  `plugin_dir_path()`, so it satisfies core's `WP_PLUGIN_DIR` check even when the
  plugin folder is symlinked (e.g. WP Studio).
- Base URL resolution order: `OSAURUS_BASE_URL` constant → `osaurus_ai_connector_base_url` option → default `http://127.0.0.1:1337/v1`.

## Before claiming done

- `php -l` every changed PHP file.
- For workflow/`.distignore` changes, dry-run the staged mirror and confirm the
  shipped file set is exactly what you expect.
- For a release, watch the deploy run to green and verify SVN
  (`svn ls https://plugins.svn.wordpress.org/ai-provider-for-osaurus/{trunk,tags,assets}/`).
- Outward-facing/irreversible actions (publishing to wp.org) are confirmed with
  the user before running.
